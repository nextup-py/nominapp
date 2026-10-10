<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Payroll;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Gestiona la asignación de horarios a empleados con soporte de vigencia por fechas. */
class ScheduleAssignmentService
{
    /**
     * Asigna un horario a un empleado a partir de una fecha dada.
     *
     * Si existe una asignación activa sin fecha de cierre, la cierra el día anterior
     * a `valid_from` antes de crear la nueva. Lanza `ValidationException` si hay
     * superposición con una asignación ya cerrada que no puede resolverse
     * automáticamente.
     *
     * @param  Carbon  $validFrom  Fecha de inicio de vigencia.
     * @param  Carbon|null  $validUntil  Fecha de fin (null = indefinido).
     *
     * @throws ValidationException
     */
    public static function assign(
        Employee $employee,
        Schedule $schedule,
        Carbon $validFrom,
        ?Carbon $validUntil = null,
        ?string $notes = null
    ): EmployeeScheduleAssignment {
        if ($validUntil && $validUntil->lt($validFrom)) {
            throw ValidationException::withMessages([
                'valid_until' => 'La fecha de fin no puede ser anterior a la fecha de inicio.',
            ]);
        }

        return DB::transaction(function () use ($employee, $schedule, $validFrom, $validUntil, $notes) {
            // Cerrar la asignación abierta (sin valid_until) si su valid_from es anterior
            $employee->scheduleAssignments()
                ->whereNull('valid_until')
                ->where('valid_from', '<', $validFrom)
                ->update(['valid_until' => $validFrom->copy()->subDay()]);

            // Detectar superposición con asignaciones ya cerradas
            $overlap = $employee->scheduleAssignments()
                ->where('valid_from', '<=', $validUntil ?? '9999-12-31')
                ->where(fn ($q) => $q
                    ->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $validFrom)
                )
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'valid_from' => 'El rango de fechas se superpone con una asignación existente.',
                ]);
            }

            return EmployeeScheduleAssignment::create([
                'employee_id' => $employee->id,
                'schedule_id' => $schedule->id,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
        });
    }

    /**
     * Retorna el horario vigente de un empleado para una fecha dada.
     * Equivalente a `Employee::getScheduleForDate()` pero sin cargar la relación en el modelo.
     *
     * @param  Carbon|null  $date  Fecha de consulta (por defecto hoy).
     */
    public static function getForDate(Employee $employee, ?Carbon $date = null): ?Schedule
    {
        return $employee->getScheduleForDate($date);
    }

    /**
     * Cierra la asignación activa de un empleado en una fecha dada.
     * No hace nada si el empleado no tiene asignación abierta.
     *
     * @param  Carbon  $closedOn  Último día de vigencia (inclusive).
     * @return int Número de registros actualizados (0 o 1).
     */
    public static function closeActive(Employee $employee, Carbon $closedOn): int
    {
        return $employee->scheduleAssignments()
            ->whereNull('valid_until')
            ->where('valid_from', '<=', $closedOn)
            ->update(['valid_until' => $closedOn]);
    }

    /**
     * Recalcula las jornadas ya registradas de un empleado entre dos fechas (hasta hoy como máximo) para que
     * tomen el horario recién asignado. No pisa decisiones de RR.HH.: omite las jornadas con horas extra o
     * tardanza aprobadas, ajuste manual o doble turno aceptado como normal, y las de períodos con nómina ya
     * generada para ese empleado.
     *
     * @return array{recalculated: int, protected: int, payroll_locked: int}
     */
    public static function recalculateDays(Employee $employee, Carbon $from, ?Carbon $until = null): array
    {
        $to = $until && $until->lt(Carbon::today()) ? $until : Carbon::today();
        $result = ['recalculated' => 0, 'protected' => 0, 'payroll_locked' => 0];

        if ($to->lt($from)) {
            return $result;
        }

        $periods = Payroll::query()
            ->where('employee_id', $employee->id)
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
            ->get(['payroll_periods.start_date', 'payroll_periods.end_date'])
            ->map(fn ($p) => [Carbon::parse($p->start_date)->toDateString(), Carbon::parse($p->end_date)->toDateString()]);

        AttendanceDay::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get()
            ->each(function (AttendanceDay $day) use ($periods, &$result): void {
                $date = $day->date->toDateString();

                if ($periods->contains(fn (array $range) => $date >= $range[0] && $date <= $range[1])) {
                    $result['payroll_locked']++;
                } elseif ($day->overtime_approved || $day->tardiness_deduction_approved || $day->manual_adjustment || $day->second_shift_regular) {
                    $result['protected']++;
                } else {
                    // En un recálculo normal se conservan las horas esperadas ya guardadas; acá se pide
                    // el cálculo inicial para que la jornada tome el horario recién asignado.
                    $day->is_calculated = false;
                    AttendanceCalculator::apply($day);
                    $day->save();
                    $result['recalculated']++;
                }
            });

        return $result;
    }
}
