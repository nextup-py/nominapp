<?php

namespace App\Services;

use App\Models\Deduction;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeLeave;
use App\Models\Holiday;
use App\Models\Payroll;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Descuentos de una licencia con el interruptor «genera descuento» (deducción LIC001, creada a demanda):
 * - Permiso parcial (por horas): una deducción por las horas tomadas, fechada el día del permiso.
 * - Licencia de día completo (por ejemplo, sin goce de sueldo): una deducción por cada día laborable del período
 *   (sin feriados ni días sin turno), igual que las suspensiones, con el salario diario del empleado.
 *
 * Nunca se genera ni se revierte con una nómina del empleado ya generada para esas fechas: ese descuento no se
 * cobraría (o no se podría deshacer). Cada descuento queda trazado en `employee_leave_deduction_days`.
 */
class EmployeeLeaveDeductionService
{
    /**
     * Genera los descuentos de la licencia.
     *
     * @return array{count: int, amount: float, warning: ?string} Cantidad de deducciones creadas, monto total y, si
     *                                                            no se pudo generar, el motivo para mostrarlo al usuario.
     */
    public function apply(EmployeeLeave $leave): array
    {
        $none = ['count' => 0, 'amount' => 0.0, 'warning' => null];

        if (! $leave->generates_deduction) {
            return $none;
        }

        if ($leave->isPartialDay() ? $leave->employee_deduction_id : $leave->deductionDays()->exists()) {
            return $none; // ya generado: aprobar dos veces no duplica
        }

        if ($this->payrollBlocker($leave) !== null) {
            return [...$none, 'warning' => 'No se generó el descuento porque el empleado ya tiene nómina generada para esas fechas: cargalo a mano como deducción puntual.'];
        }

        $deduction = Deduction::leaveDeduction();

        return $leave->isPartialDay()
            ? $this->applyPartial($leave, $deduction)
            : $this->applyFullDays($leave, $deduction);
    }

    /**
     * Elimina los descuentos de la licencia (al anularla).
     *
     * @return int Cantidad de deducciones eliminadas.
     */
    public function revert(EmployeeLeave $leave): int
    {
        $ids = $leave->deductionDays()->pluck('employee_deduction_id')->filter();

        if ($leave->employee_deduction_id) {
            $ids->push($leave->employee_deduction_id);
        }

        $leave->deductionDays()->delete();
        $leave->forceFill(['employee_deduction_id' => null])->saveQuietly();

        return $ids->isEmpty() ? 0 : EmployeeDeduction::whereIn('id', $ids)->delete();
    }

    /** Cantidad de deducciones que tiene hoy la licencia (para avisar antes de anular). */
    public function deductionCount(EmployeeLeave $leave): int
    {
        return $leave->deductionDays()->count() + ($leave->employee_deduction_id ? 1 : 0);
    }

    /**
     * Motivo por el que no se pueden generar ni deshacer descuentos: el empleado tiene nómina cuyo período solapa las
     * fechas de la licencia. Null si no hay impedimento.
     */
    public function payrollBlocker(EmployeeLeave $leave): ?string
    {
        $exists = Payroll::where('employee_id', $leave->employee_id)
            ->whereHas('period', fn ($q) => $q
                ->where('start_date', '<=', $leave->end_date->toDateString())
                ->where('end_date', '>=', $leave->start_date->toDateString()))
            ->exists();

        return $exists ? 'El empleado ya tiene nómina generada para el período de la licencia.' : null;
    }

    /**
     * Días laborables del período de la licencia: sin feriados y con turno ese día (horario fijo, rotación o turno puntual).
     *
     * @return Collection<int, Carbon>
     */
    public function workingDates(EmployeeLeave $leave): Collection
    {
        $dates = collect();

        for ($cursor = $leave->start_date->copy()->startOfDay(); $cursor->lte($leave->end_date); $cursor->addDay()) {
            if (Holiday::isHoliday($cursor->toDateString())) {
                continue;
            }

            if (AttendanceCalculator::resolveShiftDataFor($leave->employee, $cursor)['check_in'] === null) {
                continue;
            }

            $dates->push($cursor->copy());
        }

        return $dates;
    }

    /** @return array{count: int, amount: float, warning: ?string} */
    private function applyPartial(EmployeeLeave $leave, Deduction $deduction): array
    {
        $amount = $leave->calculatedDeductionAmount();

        if ($amount <= 0) {
            return ['count' => 0, 'amount' => 0.0, 'warning' => 'No se generó el descuento: el empleado no tiene contrato activo con salario para calcularlo.'];
        }

        $typeLabel = EmployeeLeave::getTypeOptions()[$leave->type] ?? $leave->type;
        $period = $leave->start_date->format('d/m/Y').' '.$leave->start_time.' - '.$leave->end_time;

        $employeeDeduction = EmployeeDeduction::create([
            'employee_id' => $leave->employee_id,
            'deduction_id' => $deduction->id,
            'start_date' => $leave->start_date->toDateString(),
            'end_date' => $leave->start_date->toDateString(),
            'custom_amount' => $amount,
            'notes' => "Permiso parcial: {$typeLabel} ({$period})",
        ]);

        $leave->forceFill(['employee_deduction_id' => $employeeDeduction->id])->saveQuietly();

        return ['count' => 1, 'amount' => $amount, 'warning' => null];
    }

    /** @return array{count: int, amount: float, warning: ?string} */
    private function applyFullDays(EmployeeLeave $leave, Deduction $deduction): array
    {
        $dates = $this->workingDates($leave);
        $perDay = round($leave->employee->getAbsenceDeductionAmount(), 2);

        if ($dates->isEmpty() || $perDay <= 0) {
            return ['count' => 0, 'amount' => 0.0, 'warning' => 'No se generó el descuento: no hay días laborables en el período o el empleado no tiene salario definido.'];
        }

        $typeLabel = EmployeeLeave::getTypeOptions()[$leave->type] ?? $leave->type;

        foreach ($dates as $date) {
            $employeeDeduction = EmployeeDeduction::create([
                'employee_id' => $leave->employee_id,
                'deduction_id' => $deduction->id,
                'start_date' => $date->toDateString(),
                'end_date' => $date->toDateString(),
                'custom_amount' => $perDay,
                'notes' => "Licencia sin goce de sueldo: {$typeLabel} ({$date->format('d/m/Y')})",
            ]);

            $leave->deductionDays()->create(['date' => $date->toDateString(), 'employee_deduction_id' => $employeeDeduction->id]);
        }

        return ['count' => $dates->count(), 'amount' => round($perDay * $dates->count(), 2), 'warning' => null];
    }
}
