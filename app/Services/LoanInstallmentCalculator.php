<?php

namespace App\Services;

use App\Models\Deduction;
use App\Models\Employee;
use App\Models\EmployeeDeduction;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\PayrollPeriod;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Calcula y registra las cuotas de préstamos que vencen en el período de nómina
 * o que quedaron atrasadas de períodos que nunca se generaron.
 *
 * Por cada cuota encontrada crea (o actualiza) un registro en `employee_deductions`
 * usando el código PRE001, de modo que DeductionCalculator las procese junto al
 * resto de deducciones del empleado.
 *
 * Los adelantos de salario son manejados por AdvanceCalculator.
 */
class LoanInstallmentCalculator
{
    /** @var int|null ID de la deducción PRE001 (Cuota de Préstamo), cacheado por instancia. */
    private ?int $loanDeductionId = null;

    /**
     * Identifica las cuotas de préstamos a descontar en el período, crea sus EmployeeDeduction
     * y retorna la colección de instancias para trazabilidad posterior.
     *
     * Toma las cuotas `pending` con `due_date <= period->end_date` (las del período y las
     * atrasadas de períodos sin nómina). Las del período se descuentan siempre; las atrasadas
     * se cobran de la más antigua a la más nueva solo hasta completar el tope del salario
     * (`loan_installment_cap_percent`, Art. 245 CLT) junto con las del período: lo que no
     * entra queda `pending` y se arrastra a la siguiente nómina.
     *
     * @return array{installments: Collection}
     */
    public function calculate(Employee $employee, PayrollPeriod $period): array
    {
        $processedInstallments = collect();

        $deductionId = $this->getLoanDeductionId();

        if ($deductionId === null) {
            Log::warning("LoanInstallmentCalculator: deducción PRE001 no encontrada — cuotas de préstamos omitidas para CI {$employee->ci} {$employee->first_name}. Verificá el seeder.", [
                'employee_id' => $employee->id,
                'period_id' => $period->id,
            ]);

            return ['installments' => $processedInstallments];
        }

        $installments = LoanInstallment::query()
            ->whereHas('loan', fn ($q) => $q
                ->where('employee_id', $employee->id)
                ->where('status', 'disbursed'))
            ->where('status', 'pending')
            ->whereNull('payroll_id')
            ->whereDate('due_date', '<=', $period->end_date)
            ->with('loan')
            ->orderBy('due_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($this->applyOverdueCap($employee, $period, $installments) as $installment) {
            $notes = "Préstamo - Cuota {$installment->installment_number}/{$installment->loan->installments_count}";

            // Idempotente: si ya tiene EmployeeDeduction del ciclo anterior (regeneración),
            // actualiza el monto; si no, crea uno nuevo.
            if ($installment->employee_deduction_id) {
                $employeeDeduction = EmployeeDeduction::find($installment->employee_deduction_id);

                if ($employeeDeduction) {
                    $employeeDeduction->update([
                        'custom_amount' => (float) $installment->amount,
                        'end_date' => $this->deductionEndDate($installment, $period),
                        'notes' => $notes,
                    ]);
                } else {
                    // El registro fue eliminado (payroll previo borrado); crear uno nuevo
                    $employeeDeduction = $this->createEmployeeDeduction(
                        $employee->id, $deductionId, $installment, $notes, $period
                    );
                }
            } else {
                $employeeDeduction = $this->createEmployeeDeduction(
                    $employee->id, $deductionId, $installment, $notes, $period
                );
            }

            $installment->update(['employee_deduction_id' => $employeeDeduction->id]);

            $processedInstallments->push($installment);
        }

        return ['installments' => $processedInstallments];
    }

    /**
     * Marca las cuotas como pagadas y actualiza outstanding_balance del préstamo.
     *
     * Llamado por PayrollService después de crear la nómina.
     *
     * @param  array  $installmentIds  IDs de las cuotas a marcar como pagadas.
     * @param  int|null  $payrollId  ID de la nómina que cubre estas cuotas.
     * @return int Número de cuotas marcadas como pagadas.
     */
    public function markInstallmentsAsPaid(array $installmentIds, ?int $payrollId = null): int
    {
        if (empty($installmentIds)) {
            return 0;
        }

        $installments = LoanInstallment::whereIn('id', $installmentIds)
            ->where('status', 'pending')
            ->with('loan')
            ->get();

        $now = now();

        foreach ($installments as $installment) {
            $notes = ($installment->notes ? $installment->notes."\n" : '')
                .'Pagado automáticamente vía nómina.';

            $installment->update([
                'status' => 'paid',
                'paid_at' => $now,
                'notes' => $notes,
                'payroll_id' => $payrollId,
            ]);

            // Decrementar outstanding_balance por el capital pagado en esta cuota
            if ($installment->loan && $installment->loan->status === 'disbursed') {
                $installment->loan->decrement('outstanding_balance', (float) $installment->capital_amount);
            }
        }

        // Verificar una sola vez por préstamo si ya está completamente pagado
        $installments->pluck('loan_id')->unique()->each(
            fn (int $loanId) => Loan::find($loanId)?->checkIfPaid()
        );

        return $installments->count();
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

    /**
     * Crea un EmployeeDeduction puntual (start_date = end_date = due_date)
     * para que DeductionCalculator lo procese en ese período específico.
     */
    private function createEmployeeDeduction(
        int $employeeId,
        int $deductionId,
        LoanInstallment $installment,
        string $notes,
        PayrollPeriod $period,
    ): EmployeeDeduction {
        return EmployeeDeduction::create([
            'employee_id' => $employeeId,
            'deduction_id' => $deductionId,
            'start_date' => $installment->due_date,
            'end_date' => $this->deductionEndDate($installment, $period),
            'custom_amount' => (float) $installment->amount,
            'notes' => $notes,
        ]);
    }

    /**
     * Fecha fin del EmployeeDeduction: la del vencimiento para cuotas del período
     * (puntual) o el fin del período para atrasadas, así DeductionCalculator las recoge.
     * El inicio sigue siendo `due_date`, que mantiene única la clave empleado+deducción+inicio.
     */
    private function deductionEndDate(LoanInstallment $installment, PayrollPeriod $period): Carbon
    {
        return $installment->due_date->lt($period->start_date)
            ? Carbon::parse($period->end_date)
            : $installment->due_date;
    }

    /**
     * Aplica el tope del Art. 245 CLT a las cuotas atrasadas.
     *
     * Las cuotas con vencimiento dentro del período se conservan siempre (ya se validó el tope
     * al activar el préstamo). Las atrasadas entran en orden de antigüedad mientras la suma
     * no supere el tope; al primer rechazo se corta para no saltarse el orden.
     *
     * @param  Collection<int, LoanInstallment>  $installments  Ordenadas por vencimiento.
     * @return Collection<int, LoanInstallment>
     */
    private function applyOverdueCap(Employee $employee, PayrollPeriod $period, Collection $installments): Collection
    {
        $base = $employee->getLoanDeductionBase();

        if ($base <= 0) {
            return $installments;
        }

        $cap = round($base * app(PayrollSettings::class)->loan_installment_cap_percent / 100, 2);
        $inPeriod = $installments->filter(fn (LoanInstallment $i) => ! $i->due_date->lt($period->start_date));
        $used = (float) $inPeriod->sum('amount');
        $accepted = $inPeriod->values();

        foreach ($installments->filter(fn (LoanInstallment $i) => $i->due_date->lt($period->start_date)) as $overdue) {
            if ($used + (float) $overdue->amount > $cap) {
                Log::info("LoanInstallmentCalculator: cuotas atrasadas de CI {$employee->ci} {$employee->first_name} se arrastran por el tope del {$cap} (Art. 245 CLT).", [
                    'employee_id' => $employee->id,
                    'period_id' => $period->id,
                    'deferred_from_installment_id' => $overdue->id,
                ]);

                break;
            }

            $used += (float) $overdue->amount;
            $accepted->push($overdue);
        }

        return $accepted->sortBy([['due_date', 'asc'], ['id', 'asc']])->values();
    }

    /**
     * Retorna el ID de la deducción PRE001, cacheado para el ciclo de nómina.
     */
    private function getLoanDeductionId(): ?int
    {
        if ($this->loanDeductionId === null) {
            $this->loanDeductionId = Deduction::where('code', 'PRE001')->value('id');
        }

        return $this->loanDeductionId;
    }
}
