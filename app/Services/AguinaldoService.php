<?php

namespace App\Services;

use App\Models\Aguinaldo;
use App\Models\AguinaldoItem;
use App\Models\AguinaldoPeriod;
use App\Models\Employee;
use App\Models\Payroll;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AguinaldoService
{
    protected AguinaldoPDFGenerator $pdfGenerator;

    public function __construct(AguinaldoPDFGenerator $pdfGenerator)
    {
        $this->pdfGenerator = $pdfGenerator;
    }

    /**
     * Genera aguinaldos para todos los empleados del período que aún no los tienen.
     * Solo cuenta nóminas del año que ya no están en borrador (`draft`).
     * Retorna el número de aguinaldos generados exitosamente.
     */
    public function generateForPeriod(AguinaldoPeriod $period): int
    {
        $count = 0;
        $year = $period->year;
        $companyId = $period->company_id;

        // Pre-fetch de employee_ids que ya tienen aguinaldo — evita N+1 en el check de duplicados
        $existingEmployeeIds = Aguinaldo::where('aguinaldo_period_id', $period->id)
            ->pluck('employee_id')
            ->flip(); // para O(1) lookup con isset()

        $employees = Employee::query()
            ->whereHas('branch', fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'active')
            ->get();

        foreach ($employees as $employee) {
            if (isset($existingEmployeeIds[$employee->id])) {
                continue;
            }

            $payrolls = Payroll::with(['period', 'items'])
                ->join('payroll_periods', 'payrolls.payroll_period_id', '=', 'payroll_periods.id')
                ->where('payrolls.employee_id', $employee->id)
                ->where('payrolls.status', '!=', 'draft')
                ->whereYear('payroll_periods.start_date', $year)
                ->orderBy('payroll_periods.start_date')
                ->select('payrolls.*')
                ->get();

            if ($payrolls->isEmpty()) {
                continue;
            }

            $aguinaldo = null;

            try {
                $aguinaldo = DB::transaction(function () use ($period, $employee, $payrolls) {
                    $now = now();
                    ['total' => $totalEarned, 'items' => $itemsData] = $this->calculateItemsFromPayrolls($payrolls);

                    $aguinaldo = Aguinaldo::create([
                        'aguinaldo_period_id' => $period->id,
                        'employee_id' => $employee->id,
                        'total_earned' => $totalEarned,
                        'months_worked' => count($payrolls),
                        'aguinaldo_amount' => round($totalEarned / 12, 0),
                        'status' => 'pending',
                        'generated_at' => $now,
                        'payment_method' => $employee->activeContract?->payment_method ?? 'cash',
                    ]);

                    AguinaldoItem::insert(
                        array_map(fn ($item) => array_merge($item, [
                            'aguinaldo_id' => $aguinaldo->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]), $itemsData)
                    );

                    return $aguinaldo;
                });

                // PDF generado FUERA de la transacción para evitar mezclar I/O con DB
                $pdfPath = $this->pdfGenerator->generate($aguinaldo->load('items'));
                $aguinaldo->update(['pdf_path' => $pdfPath]);

                $count++;
            } catch (\Throwable $e) {
                Log::error("Error al generar aguinaldo — CI {$employee->ci} {$employee->first_name} {$employee->last_name}: {$e->getMessage()}", [
                    'employee_id' => $employee->id,
                    'period_id' => $period->id,
                ]);
            }
        }

        return $count;
    }

    /**
     * Regenera el aguinaldo de un empleado: recalcula montos, reemplaza items y regenera el PDF.
     */
    public function regenerateForEmployee(Aguinaldo $aguinaldo): void
    {
        $period = $aguinaldo->period;
        $employee = $aguinaldo->employee;
        $year = $period->year;

        $payrolls = Payroll::with(['period', 'items'])
            ->join('payroll_periods', 'payrolls.payroll_period_id', '=', 'payroll_periods.id')
            ->where('payrolls.employee_id', $employee->id)
            ->where('payrolls.status', '!=', 'draft')
            ->whereYear('payroll_periods.start_date', $year)
            ->orderBy('payroll_periods.start_date')
            ->select('payrolls.*')
            ->get();

        if ($payrolls->isEmpty()) {
            throw new \RuntimeException("El empleado no tiene nóminas en {$year}.");
        }

        DB::transaction(function () use ($aguinaldo, $payrolls) {
            $now = now();
            ['total' => $totalEarned, 'items' => $itemsData] = $this->calculateItemsFromPayrolls($payrolls);

            $aguinaldo->items()->delete();
            AguinaldoItem::insert(
                array_map(fn ($item) => array_merge($item, [
                    'aguinaldo_id' => $aguinaldo->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]), $itemsData)
            );

            $aguinaldo->update([
                'total_earned' => $totalEarned,
                'months_worked' => count($payrolls),
                'aguinaldo_amount' => round($totalEarned / 12, 0),
                'generated_at' => $now,
                'payment_method' => $employee->activeContract?->payment_method ?? 'cash',
            ]);
        });

        // Regenerar PDF fuera de la transacción
        try {
            $pdfPath = $this->pdfGenerator->generate($aguinaldo->load('items'));
            $aguinaldo->update(['pdf_path' => $pdfPath]);
        } catch (\Throwable $e) {
            Log::warning('PDF no regenerado tras recálculo de aguinaldo', [
                'aguinaldo_id' => $aguinaldo->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Devuelve una query lista para usar en tablas Filament de provisión mensual de aguinaldo.
     *
     * Cada fila es un Employee con columnas virtuales calculadas por GROUP BY:
     *   - months_worked : cantidad de nóminas en el rango
     *   - total_earned  : sum(base_salary + ips_perceptions) hasta el mes indicado
     *   - provision     : total_earned / 12 (redondeado a 2 decimales)
     *
     * @param  int  $upToMonth  Mes límite incluido (1-12)
     */
    public function provisionQuery(AguinaldoPeriod $period, int $upToMonth): Builder
    {
        return Employee::query()
            ->select([
                'employees.id',
                'employees.ci',
                'employees.first_name',
                'employees.last_name',
                'employees.branch_id',
                DB::raw('COUNT(payrolls.id) AS months_worked'),
                DB::raw('SUM(payrolls.base_salary + payrolls.ips_perceptions) AS total_earned'),
                DB::raw('ROUND(SUM(payrolls.base_salary + payrolls.ips_perceptions) / 12, 2) AS provision'),
            ])
            ->join('branches', 'branches.id', '=', 'employees.branch_id')
            ->join('payrolls', 'payrolls.employee_id', '=', 'employees.id')
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
            ->where('branches.company_id', $period->company_id)
            ->where('payrolls.status', '!=', 'draft')
            ->whereYear('payroll_periods.start_date', $period->year)
            ->whereMonth('payroll_periods.start_date', '<=', $upToMonth)
            ->groupBy(
                'employees.id',
                'employees.ci',
                'employees.first_name',
                'employees.last_name',
                'employees.branch_id',
            )
            ->orderBy('employees.last_name');
    }

    /**
     * Calcula los ítems mensuales y el total devengado a partir de las nóminas.
     * Retorna ['total' => float, 'items' => array].
     */
    private function calculateItemsFromPayrolls(Collection $payrolls): array
    {
        $totalEarned = 0;
        $items = [];

        foreach ($payrolls as $payroll) {
            // Base legal del aguinaldo: salario + percepciones salariales (affects_ips) + horas extra.
            // Las percepciones no salariales (viáticos, subsidios) se excluyen via ips_perceptions.
            $monthTotal = $payroll->base_salary + $payroll->ips_perceptions;
            $totalEarned += $monthTotal;

            // Desglose para el PDF: separar horas extra del resto de percepciones salariales
            $extraHoursAmount = $payroll->items
                ->where('type', 'perception')
                ->where('perception_type', 'extra_hours')
                ->sum('amount');

            $perceptionsWithoutExtras = $payroll->ips_perceptions - $extraHoursAmount;

            $items[] = [
                'month' => ucfirst($payroll->period->start_date->translatedFormat('F')),
                'base_salary' => $payroll->base_salary,
                'perceptions' => $perceptionsWithoutExtras,
                'extra_hours' => $extraHoursAmount,
                'total' => $monthTotal,
            ];
        }

        return ['total' => $totalEarned, 'items' => $items];
    }
}
