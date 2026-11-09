<?php

namespace App\Services;

use App\Models\Deduction;
use App\Models\Employee;
use App\Models\EmployeeDeduction;
use App\Models\MerchandiseWithdrawal;
use App\Models\MerchandiseWithdrawalInstallment;
use App\Models\PayrollPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Calcula y registra las cuotas de retiros de mercadería que vencen en el período de nómina
 * o que quedaron atrasadas de períodos que nunca se generaron (todas en la misma nómina,
 * sin tope: la mercadería no está sujeta al límite del Art. 245 CLT).
 *
 * Por cada cuota encontrada crea (o actualiza) un registro en `employee_deductions`
 * usando el código MER001, de modo que DeductionCalculator las procese junto al
 * resto de deducciones del empleado.
 */
class MerchandiseInstallmentCalculator
{
    /** @var int|null ID de la deducción MER001, cacheado por instancia. */
    private ?int $merchandiseDeductionId = null;

    /**
     * Identifica cuotas `pending` con `due_date <= period->end_date` (del período y atrasadas), crea sus EmployeeDeduction
     * y retorna la colección de instancias para trazabilidad posterior.
     *
     * @return array{installments: Collection}
     */
    public function calculate(Employee $employee, PayrollPeriod $period): array
    {
        $processedInstallments = collect();

        $deductionId = $this->getMerchandiseDeductionId();

        if ($deductionId === null) {
            Log::warning("MerchandiseInstallmentCalculator: deducción MER001 no encontrada — cuotas de mercadería omitidas para CI {$employee->ci} {$employee->first_name}. Verificá el seeder.", [
                'employee_id' => $employee->id,
                'period_id' => $period->id,
            ]);

            return ['installments' => $processedInstallments];
        }

        $installments = MerchandiseWithdrawalInstallment::query()
            ->whereHas('withdrawal', fn ($q) => $q
                ->where('employee_id', $employee->id)
                ->where('status', 'approved'))
            ->where('status', 'pending')
            ->whereNull('payroll_id')
            ->whereDate('due_date', '<=', $period->end_date)
            ->with('withdrawal')
            ->orderBy('due_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($installments as $installment) {
            $notes = "Mercadería - Cuota {$installment->installment_number}/{$installment->withdrawal->installments_count}";

            if ($installment->employee_deduction_id) {
                $employeeDeduction = EmployeeDeduction::find($installment->employee_deduction_id);

                if ($employeeDeduction) {
                    $employeeDeduction->update([
                        'custom_amount' => (float) $installment->amount,
                        'end_date' => $this->deductionEndDate($installment, $period),
                        'notes' => $notes,
                    ]);
                } else {
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
     * Marca las cuotas como pagadas y actualiza outstanding_balance del retiro.
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

        $installments = MerchandiseWithdrawalInstallment::whereIn('id', $installmentIds)
            ->where('status', 'pending')
            ->with('withdrawal')
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

            if ($installment->withdrawal && $installment->withdrawal->status === 'approved') {
                $installment->withdrawal->decrement('outstanding_balance', (float) $installment->amount);
            }
        }

        // Verificar si algún retiro quedó completamente pagado
        $installments->pluck('merchandise_withdrawal_id')->unique()->each(
            fn (int $id) => MerchandiseWithdrawal::find($id)?->checkIfPaid()
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
        MerchandiseWithdrawalInstallment $installment,
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
     * Fecha fin del EmployeeDeduction: el vencimiento para cuotas del período (puntual) o el
     * fin del período para atrasadas, así DeductionCalculator las recoge. El inicio sigue
     * siendo `due_date`, que mantiene única la clave empleado+deducción+inicio.
     */
    private function deductionEndDate(MerchandiseWithdrawalInstallment $installment, PayrollPeriod $period): Carbon
    {
        return $installment->due_date->lt($period->start_date)
            ? Carbon::parse($period->end_date)
            : $installment->due_date;
    }

    /**
     * Retorna el ID de la deducción MER001, cacheado para el ciclo de nómina.
     */
    private function getMerchandiseDeductionId(): ?int
    {
        if ($this->merchandiseDeductionId === null) {
            $this->merchandiseDeductionId = Deduction::where('code', 'MER001')->value('id');
        }

        return $this->merchandiseDeductionId;
    }
}
