<?php

namespace App\Services;

use App\Models\Deduction;
use App\Models\PayrollPeriod;
use App\Settings\PayrollSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Simula la nómina de un período con el mismo cálculo que la generación real, sin dejar nada guardado.
 *
 * Corre `PayrollService::generateForPeriod()` dentro de una transacción que siempre se revierte: recibos,
 * ítems, deducciones puntuales, estado de cuotas y adelantos y pagos de vacaciones desaparecen. Los PDF no
 * se generan (son lo único que la base no puede deshacer). Usa la asistencia tal como está hoy, así que a
 * mitad de mes la simulación es parcial.
 */
class PayrollSimulationService
{
    public function __construct(
        private readonly PayrollService $payrollService,
        private readonly PayrollPreflightService $preflight,
    ) {}

    /**
     * Calcula la nómina del período indicado y revierte todo.
     *
     * @return array{
     *     period: array{start: string, end: string, frequency: string, company_id: int|null},
     *     rows: list<array{ci: string, name: string, branch: string, payment_method: string, base_salary: float, perceptions: float, ips: float, other_deductions: float, deductions: float, net: float, items: list<array{type: string, description: string, amount: float}>}>,
     *     totals: array{employees: int, base_salary: float, perceptions: float, ips: float, other_deductions: float, deductions: float, net: float, net_cash: float, net_transfer: float},
     *     skipped: list<array{name: string, ci: int|string, reason: string}>,
     *     warnings: array<int, array{key: string, label: string, count: int}>
     * }
     *
     * @throws \DomainException si el período ya tiene recibos reales
     * @throws \InvalidArgumentException si el período existente no admite generar recibos
     */
    public function simulate(?int $companyId, string $frequency, CarbonInterface $start, CarbonInterface $end): array
    {
        DB::beginTransaction();

        try {
            $period = PayrollPeriod::firstOrCreate(
                [
                    'company_id' => $companyId,
                    'frequency' => $frequency,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                ],
                ['name' => 'Simulación '.$start->format('m/Y'), 'status' => 'draft'],
            );

            if ($period->payrolls()->exists()) {
                throw new \DomainException('Ese período ya tiene recibos generados: la simulación solo se hace sobre períodos sin recibos.');
            }

            $warnings = $this->preflight->check($period);
            $result = $this->payrollService->generateForPeriod($period, withPdf: false);

            $ipsName = Deduction::query()->where('code', app(PayrollSettings::class)->ips_deduction_code)->value('name');
            $payrolls = $period->payrolls()->with(['employee.branch', 'items'])->get();

            $rows = $payrolls
                ->map(function ($payroll) use ($ipsName) {
                    $deductionItems = $payroll->items->where('type', 'deduction');
                    $ips = (float) $deductionItems->where('description', $ipsName)->sum('amount');
                    $deductions = (float) $payroll->total_deductions;

                    return [
                        'ci' => (string) $payroll->employee->ci,
                        'name' => trim($payroll->employee->last_name.', '.$payroll->employee->first_name),
                        'branch' => (string) ($payroll->employee->branch?->name ?? ''),
                        'payment_method' => (string) $payroll->payment_method,
                        'base_salary' => (float) $payroll->base_salary,
                        'perceptions' => (float) $payroll->total_perceptions,
                        'ips' => $ips,
                        'other_deductions' => round($deductions - $ips, 2),
                        'deductions' => $deductions,
                        'net' => (float) $payroll->net_salary,
                        'items' => $payroll->items
                            ->map(fn ($item) => ['type' => (string) $item->type, 'description' => (string) $item->description, 'amount' => (float) $item->amount])
                            ->values()
                            ->all(),
                    ];
                })
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();

            return [
                'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'frequency' => $frequency, 'company_id' => $companyId],
                'rows' => $rows,
                'totals' => $this->totals($rows),
                'skipped' => $result['skipped'],
                'warnings' => $warnings,
            ];
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Totales de la simulación, con el neto separado por forma de pago.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{employees: int, base_salary: float, perceptions: float, ips: float, other_deductions: float, deductions: float, net: float, net_cash: float, net_transfer: float}
     */
    private function totals(array $rows): array
    {
        $collection = collect($rows);

        return [
            'employees' => $collection->count(),
            'base_salary' => (float) $collection->sum('base_salary'),
            'perceptions' => (float) $collection->sum('perceptions'),
            'ips' => (float) $collection->sum('ips'),
            'other_deductions' => (float) $collection->sum('other_deductions'),
            'deductions' => (float) $collection->sum('deductions'),
            'net' => (float) $collection->sum('net'),
            'net_cash' => (float) $collection->where('payment_method', 'cash')->sum('net'),
            'net_transfer' => (float) $collection->where('payment_method', '!=', 'cash')->sum('net'),
        ];
    }
}
