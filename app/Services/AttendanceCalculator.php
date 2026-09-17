<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\RotationAssignment;
use App\Models\Schedule;
use App\Models\ShiftOverride;
use App\Models\ShiftTemplate;
use App\Settings\PayrollSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceCalculator
{
    // Definir constantes para los tipos de eventos
    private const EVENT_CHECK_IN = 'check_in';

    private const EVENT_CHECK_OUT = 'check_out';

    private const EVENT_BREAK_START = 'break_start';

    private const EVENT_BREAK_END = 'break_end';

    // Definir constantes para los estados
    private const STATUS_PRESENT = 'present';

    private const STATUS_ON_LEAVE = 'on_leave';

    private const STATUS_ABSENT = 'absent';

    private const STATUS_HOLIDAY = 'holiday';

    private const STATUS_WEEKEND = 'weekend';

    /**
     * Calcula y actualiza los campos básicos de asistencia para el día dado.
     */
    public static function apply(AttendanceDay $day): void
    {
        // Validar datos iniciales
        if (! $day->employee) {
            Log::warning("AttendanceDay ID {$day->id} sin empleado asignado, cálculo omitido", [
                'attendance_day_id' => $day->id,
                'employee_id' => $day->employee_id,
                'date' => $day->date,
            ]);

            return;
        }

        // Verificar si el empleado está de vacaciones o tiene permiso aprobado
        self::checkVacationStatus($day);
        self::checkLeaveStatus($day);

        // Si el empleado está de vacaciones o tiene permiso, marcar como "on_leave" y detener cálculos
        if ($day->on_vacation || $day->justified_absence) {
            $day->status = self::STATUS_ON_LEAVE;
            self::clearAttendanceData($day);
            self::markAsCalculated($day); // ← Agregar

            return;
        }

        // Verificar si el día es feriado o domingo
        self::checkHolidayAndWeekend($day);

        // Verificar si el día es descanso planificado según rotación o horario fijo
        $isScheduledDayOff = self::isScheduledDayOff($day);

        // Obtener eventos del día
        $events = $day->events()->orderBy('recorded_at')->get();

        // Si es feriado o fin de semana SIN eventos, marcar apropiadamente
        if ($events->isEmpty()) {
            // Sin marcaciones pero el supervisor cargó horas extras manualmente:
            // preservar status y horas; no sobreescribir con ausente/feriado/fin de semana.
            if ($day->manual_adjustment) {
                self::markAsCalculated($day);

                return;
            }

            if ($day->is_holiday) {
                $day->status = self::STATUS_HOLIDAY;
            } elseif ($day->is_weekend || $isScheduledDayOff) {
                $day->status = self::STATUS_WEEKEND;
            } else {
                $day->status = self::STATUS_ABSENT;
            }
            self::clearAttendanceData($day);
            self::markAsCalculated($day); // ← Agregar

            return;
        }

        // Si es feriado, fin de semana o franco rotativo CON eventos, es trabajo extraordinario
        if ($day->is_holiday || $day->is_weekend || $isScheduledDayOff) {
            $day->is_extraordinary_work = true;
        }

        // Calcular horarios y descansos
        self::calculateAttendanceDetails($day, $events);

        // Si llegó hasta aquí con eventos, el empleado estuvo presente
        $day->status = self::STATUS_PRESENT;

        // Marcar como calculado
        self::markAsCalculated($day); // ← Agregar
    }

    /**
     * Marca el día como calculado con timestamp.
     */
    private static function markAsCalculated(AttendanceDay $day): void
    {
        $day->is_calculated = true;
        $day->calculated_at = now();
    }

    /**
     * Aplica el cálculo de asistencia para un rango de fechas.
     * Solo calcula registros existentes, no genera nuevos.
     */
    public static function applyForDateRange(Carbon $startDate, Carbon $endDate): void
    {
        $chunkSize = config('payroll.processing.chunk_size', 100);

        DB::transaction(function () use ($startDate, $endDate, $chunkSize) {
            // Calcular/recalcular todos los registros existentes en el rango
            AttendanceDay::whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->chunk($chunkSize, function ($days) {
                    foreach ($days as $day) {
                        try {
                            self::apply($day);
                            $day->save();
                        } catch (\Exception $e) {
                            Log::error("Error procesando AttendanceDay {$day->id}: {$e->getMessage()}");
                            // Continúa con el siguiente registro
                        }
                    }
                });
        });
    }

    /**
     * Limpia los datos de asistencia cuando no aplican.
     */
    private static function clearAttendanceData(AttendanceDay $day): void
    {
        $day->check_in_time = null;
        $day->check_out_time = null;
        $day->break_minutes = 0;
        $day->total_hours = null;
        $day->net_hours = null;
        $day->extra_hours = null;
        $day->extra_hours_diurnas = null;
        $day->extra_hours_nocturnas = null;
        $day->late_minutes = null;
        $day->early_leave_minutes = null;
        $day->overtime_limit_exceeded = false;
    }

    /**
     * Verifica si el día es feriado o domingo y actualiza las banderas correspondientes.
     */
    private static function checkHolidayAndWeekend(AttendanceDay $day): void
    {
        $date = Carbon::parse($day->date);

        // Verificar si es domingo
        $day->is_weekend = $date->isSunday();

        // Verificar si es feriado
        $day->is_holiday = Holiday::whereDate('date', $date->toDateString())->exists();
    }

    /**
     * Calcula los detalles de asistencia: horarios, descansos, horas trabajadas, etc.
     */
    private static function calculateAttendanceDetails(AttendanceDay $day, $events): void
    {
        // Solo actualizar valores esperados en el PRIMER cálculo.
        // En recálculos, mantener los valores originales (aunque sean NULL).
        // La jerarquía de resolución: rotación → horario fijo → legacy schedule_id.
        if (! $day->is_calculated) {
            $shiftData = self::resolveExpectedShiftData($day);
            $day->expected_check_in = $shiftData['check_in'];
            $day->expected_check_out = $shiftData['check_out'];
            $day->expected_break_minutes = $shiftData['break_minutes'];
            $day->expected_hours = self::calculateExpectedHours(
                $day->expected_check_in,
                $day->expected_check_out
            );
        }

        // Usar los valores esperados guardados (no los actuales del empleado)
        $scheduledCheckIn = $day->expected_check_in;
        $scheduledCheckOut = $day->expected_check_out;

        // Calcular tiempos de entrada, salida y descansos
        $checkIn = self::getFirstEventTime($events, self::EVENT_CHECK_IN);
        $checkOut = self::getLastEventTime($events, self::EVENT_CHECK_OUT);
        $breakMinutes = self::calculateBreakMinutes($events);

        // Asignar valores REALES calculados (estos SÍ se recalculan siempre)
        $day->check_in_time = optional($checkIn)->format('H:i:s');
        $day->check_out_time = optional($checkOut)->format('H:i:s');
        $day->break_minutes = $breakMinutes;

        // Calcular horas trabajadas
        [$totalHours, $netHours] = self::calculateWorkedHours($checkIn, $checkOut, $breakMinutes);
        $day->total_hours = $totalHours;
        $day->net_hours = $netHours;

        // Calcular horas extra (basado en expected_hours guardadas).
        // Si el día tiene ajuste manual, respetar los valores cargados por el supervisor.
        if ($day->manual_adjustment) {
            return;
        }

        $day->extra_hours = self::calculateExtraHours($totalHours, $day->expected_hours);

        // Desglosar horas extra en diurnas/nocturnas y verificar límites legales (diario y semanal)
        if ($day->extra_hours > 0) {
            [$diurnas, $nocturnas] = self::splitOvertimeHours(
                $day->check_out_time,
                $scheduledCheckOut,
                $day->extra_hours
            );
            $day->extra_hours_diurnas = $diurnas;
            $day->extra_hours_nocturnas = $nocturnas;

            $settings = app(PayrollSettings::class);
            $dailyExceeded = $day->extra_hours > $settings->overtime_max_daily_hours;

            // Suma de horas extra aprobadas en la misma semana ISO (excluyendo el día actual)
            $weekStart = Carbon::parse($day->date)->startOfWeek();
            $weekEnd = Carbon::parse($day->date)->endOfWeek();
            $weeklyOtherHours = AttendanceDay::where('employee_id', $day->employee_id)
                ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->where('id', '!=', $day->id ?? 0)
                ->sum('extra_hours');
            $weeklyExceeded = ((float) $weeklyOtherHours + (float) $day->extra_hours) > $settings->overtime_max_weekly_hours;

            $day->overtime_limit_exceeded = $dailyExceeded || $weeklyExceeded;
        } else {
            $day->extra_hours_diurnas = 0;
            $day->extra_hours_nocturnas = 0;
            $day->overtime_limit_exceeded = false;
        }

        // Calcular minutos de llegada tarde (basado en expected_check_in guardada)
        $day->late_minutes = self::calculateLateMinutes($scheduledCheckIn, $day->check_in_time);

        // Calcular minutos de salida anticipada (basado en expected_check_out guardada)
        $day->early_leave_minutes = self::calculateEarlyLeaveMinutes($scheduledCheckOut, $day->check_out_time);
    }

    /**
     * Calcula las horas esperadas según los horarios programados.
     */
    private static function calculateExpectedHours(?string $checkIn, ?string $checkOut): ?float
    {
        if ($checkIn && $checkOut) {
            $minutes = Carbon::parse($checkIn)->diffInMinutes(Carbon::parse($checkOut));

            return round($minutes / 60, 2);
        }

        return null;
    }

    /**
     * Obtiene la hora del primer evento de un tipo específico.
     */
    private static function getFirstEventTime($events, string $eventType): ?Carbon
    {
        return $events->where('event_type', $eventType)->first()?->recorded_at;
    }

    /**
     * Obtiene la hora del último evento de un tipo específico.
     */
    private static function getLastEventTime($events, string $eventType): ?Carbon
    {
        return $events->where('event_type', $eventType)->last()?->recorded_at;
    }

    /**
     * Calcula los minutos totales de descanso.
     * Empareja break_start con break_end de forma segura.
     */
    private static function calculateBreakMinutes($events): int
    {
        $breakEvents = $events->filter(
            fn ($e) => in_array($e->event_type, [self::EVENT_BREAK_START, self::EVENT_BREAK_END])
        )->values();

        $totalMinutes = 0;
        $breakStart = null;

        foreach ($breakEvents as $event) {
            if ($event->event_type === self::EVENT_BREAK_START) {
                $breakStart = $event->recorded_at;
            } elseif ($event->event_type === self::EVENT_BREAK_END && $breakStart) {
                $totalMinutes += Carbon::parse($breakStart)->diffInMinutes($event->recorded_at);
                $breakStart = null; // Reset para el siguiente par
            }
        }

        return $totalMinutes;
    }

    /**
     * Calcula las horas totales y netas trabajadas.
     */
    private static function calculateWorkedHours(?Carbon $checkIn, ?Carbon $checkOut, int $breakMinutes): array
    {
        if ($checkIn && $checkOut) {
            $totalMinutes = $checkIn->diffInMinutes($checkOut);
            $netMinutes = max(0, $totalMinutes - $breakMinutes);

            return [
                round($totalMinutes / 60, 2),
                round($netMinutes / 60, 2),
            ];
        }

        return [null, null];
    }

    /**
     * Calcula las horas extra trabajadas.
     */
    private static function calculateExtraHours(?float $totalHours, ?float $expectedHours): ?float
    {
        if ($totalHours !== null && $expectedHours !== null) {
            $extraHours = $totalHours - $expectedHours;

            return $extraHours > 0 ? round($extraHours, 2) : 0;
        }

        return null;
    }

    /**
     * Calcula los minutos de llegada tarde.
     */
    private static function calculateLateMinutes(?string $scheduledCheckIn, ?string $actualCheckIn): ?int
    {
        if ($scheduledCheckIn && $actualCheckIn) {
            $expected = Carbon::parse($scheduledCheckIn);
            $actual = Carbon::parse($actualCheckIn);

            return $actual->greaterThan($expected) ? $expected->diffInMinutes($actual, true) : 0;
        }

        return null;
    }

    /**
     * Calcula los minutos de salida anticipada.
     */
    private static function calculateEarlyLeaveMinutes(?string $scheduledCheckOut, ?string $actualCheckOut): ?int
    {
        if ($scheduledCheckOut && $actualCheckOut) {
            $expected = Carbon::parse($scheduledCheckOut);
            $actual = Carbon::parse($actualCheckOut);

            return $actual->lessThan($expected) ? $expected->diffInMinutes($actual, true) : 0;
        }

        return null;
    }

    /**
     * Desglosa las horas extra en diurnas (06:00-20:00) y nocturnas (20:00-06:00).
     * El período de overtime va desde la hora de salida esperada hasta la hora de salida real.
     */
    private static function splitOvertimeHours(?string $checkOutTime, ?string $scheduledCheckOut, float $extraHours): array
    {
        if (! $checkOutTime || ! $scheduledCheckOut || $extraHours <= 0) {
            return [$extraHours, 0];
        }

        $dayEnd = Carbon::parse(config('payroll.shift_boundaries.day_end', '20:00'));
        $overtimeStart = Carbon::parse($scheduledCheckOut);
        $overtimeEnd = Carbon::parse($checkOutTime);

        // Si el checkout es antes del scheduled (no debería pasar con extra_hours > 0), todo diurno
        if ($overtimeEnd->lte($overtimeStart)) {
            return [$extraHours, 0];
        }

        // Calcular cuánto del período de overtime cae en horario diurno vs nocturno
        // Si todo el overtime es antes de las 20:00 → todo diurno
        if ($overtimeStart->gte($dayEnd)) {
            // Todo el overtime está después de las 20:00 → todo nocturno
            return [0, $extraHours];
        }

        if ($overtimeEnd->lte($dayEnd)) {
            // Todo el overtime está antes de las 20:00 → todo diurno
            return [$extraHours, 0];
        }

        // El overtime cruza la frontera de las 20:00 → dividir
        $diurnoMinutes = $overtimeStart->diffInMinutes($dayEnd);
        $totalOvertimeMinutes = $overtimeStart->diffInMinutes($overtimeEnd);

        if ($totalOvertimeMinutes <= 0) {
            return [$extraHours, 0];
        }

        $diurnoRatio = $diurnoMinutes / $totalOvertimeMinutes;
        $diurnas = round($extraHours * $diurnoRatio, 2);
        $nocturnas = round($extraHours - $diurnas, 2);

        return [$diurnas, $nocturnas];
    }

    /**
     * Resuelve el turno esperado para la fecha del día dado.
     *
     * @return array{check_in: string|null, check_out: string|null, break_minutes: int|null}
     */
    private static function resolveExpectedShiftData(AttendanceDay $day): array
    {
        return self::resolveShiftDataFor($day->employee, Carbon::parse($day->date));
    }

    /**
     * Resuelve el turno/horario efectivo de un empleado para una fecha dada, sin
     * depender de que exista un AttendanceDay — usado tanto por
     * resolveExpectedShiftData() (cálculo de horas) como por hasScheduledBreak()
     * (decidir si ofrecer "Inicio de descanso" al marcar asistencia).
     *
     * Jerarquía:
     *   1. Rotación activa (ShiftTemplate vía RotationService — incluye overrides)
     *   2. Horario fijo por día de semana (sistema anterior)
     *
     * @return array{check_in: string|null, check_out: string|null, break_minutes: int|null}
     */
    public static function resolveShiftDataFor(Employee $employee, \Carbon\Carbon $date): array
    {
        // 1. Sistema de rotación (override > patrón)
        $shift = RotationService::getShiftForDate($employee, $date);

        if ($shift !== null) {
            return [
                'check_in' => $shift->is_day_off ? null : $shift->start_time,
                'check_out' => $shift->is_day_off ? null : $shift->end_time,
                'break_minutes' => $shift->is_day_off ? 0 : $shift->break_minutes,
            ];
        }

        // 2. Horario fijo por día de semana
        $schedule = $employee->getScheduleForDate($date);

        if ($schedule) {
            $dayOfWeek = $date->dayOfWeekIso; // 1=Lunes … 7=Domingo
            $scheduleDay = $schedule->days()->where('day_of_week', $dayOfWeek)->first();

            if ($scheduleDay && $scheduleDay->is_active) {
                return [
                    'check_in' => $scheduleDay->start_time,
                    'check_out' => $scheduleDay->end_time,
                    'break_minutes' => $scheduleDay->total_break_minutes ?? 0,
                ];
            }
        }

        return ['check_in' => null, 'check_out' => null, 'break_minutes' => null];
    }

    /**
     * True si el turno/horario efectivo del empleado para la fecha tiene un
     * descanso configurado (break_minutes > 0). Sin horario/rotación asignado,
     * o con break_minutes = 0/null, no hay descanso — usado para decidir si
     * ofrecer "Inicio de descanso" al marcar asistencia (ver
     * AttendanceDay::resolveForEvent() / currentStateFor()).
     */
    public static function hasScheduledBreak(Employee $employee, \Carbon\Carbon $date): bool
    {
        return (int) (self::resolveShiftDataFor($employee, $date)['break_minutes'] ?? 0) > 0;
    }

    /**
     * Versión batch de hasScheduledBreak() para varios empleados en la misma
     * fecha — reemplaza las 2-4 queries por empleado que hace
     * resolveShiftDataFor() (vía RotationService::getShiftForDate() +
     * Employee::getScheduleForDate()) por un puñado de queries con whereIn.
     * Usado por EmployeeDescriptorSyncService::breakFlagsForBranch(), que
     * recalcula este flag para toda la sucursal en cada sync del terminal
     * (~cada 5 min) — con muchos empleados eso era N+1 real.
     *
     * Misma jerarquía y mismo resultado que llamar hasScheduledBreak()
     * empleado por empleado: 1) override puntual, 2) rotación vigente,
     * 3) horario fijo por asignación vigente, 4) horario legacy
     * (employees.schedule_id).
     *
     * @param  Collection<int, Employee>  $employees  Deben traer cargado `schedule_id` (columna por defecto de `Employee::query()->get()`).
     * @return array<int, bool> employee_id => tiene descanso hoy
     */
    public static function hasScheduledBreakBatch(Collection $employees, \Carbon\Carbon $date): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $employeeIds = $employees->pluck('id')->all();
        $dateString = $date->toDateString();

        $overrides = ShiftOverride::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('override_date', $dateString)
            ->with('shift')
            ->get()
            ->keyBy('employee_id');

        $rotationAssignments = RotationAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
            ->with('pattern')
            ->orderByDesc('valid_from')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($group) => $group->first());

        $shiftTemplates = ShiftTemplate::query()->get()->keyBy('id');

        // Empleados que no resolvieron por override ni por un ShiftTemplate real
        // de rotación (assignment sin shift válido para la fecha cuenta como
        // "sin rotación", igual que RotationService::getShiftForDate() devolviendo null).
        $needsFixedSchedule = $employees->reject(function (Employee $employee) use ($overrides, $rotationAssignments, $shiftTemplates, $date) {
            if ($overrides->has($employee->id)) {
                return true;
            }
            $assignment = $rotationAssignments->get($employee->id);
            if (! $assignment) {
                return false;
            }
            $shiftId = $assignment->shiftIdForDate($date);

            return $shiftId && $shiftTemplates->has($shiftId);
        });

        $scheduleAssignments = $needsFixedSchedule->isEmpty() ? collect() : EmployeeScheduleAssignment::query()
            ->whereIn('employee_id', $needsFixedSchedule->pluck('id')->all())
            ->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
            ->with('schedule.days')
            ->orderByDesc('valid_from')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($group) => $group->first());

        $legacyScheduleIds = $needsFixedSchedule
            ->reject(fn (Employee $employee) => $scheduleAssignments->has($employee->id))
            ->pluck('schedule_id')
            ->filter()
            ->unique()
            ->all();

        $legacySchedules = empty($legacyScheduleIds) ? collect() : Schedule::query()
            ->whereIn('id', $legacyScheduleIds)
            ->with('days')
            ->get()
            ->keyBy('id');

        $dayOfWeek = $date->dayOfWeekIso;
        $result = [];

        foreach ($employees as $employee) {
            $override = $overrides->get($employee->id);
            if ($override) {
                $shift = $override->shift;
                $result[$employee->id] = $shift ? (! $shift->is_day_off && (int) $shift->break_minutes > 0) : false;

                continue;
            }

            $assignment = $rotationAssignments->get($employee->id);
            if ($assignment) {
                $shiftId = $assignment->shiftIdForDate($date);
                $shift = $shiftId ? $shiftTemplates->get($shiftId) : null;
                if ($shift) {
                    $result[$employee->id] = ! $shift->is_day_off && (int) $shift->break_minutes > 0;

                    continue;
                }
            }

            $schedule = $scheduleAssignments->get($employee->id)?->schedule
                ?? $legacySchedules->get($employee->schedule_id);

            if (! $schedule) {
                $result[$employee->id] = false;

                continue;
            }

            $scheduleDay = $schedule->days->firstWhere('day_of_week', $dayOfWeek);
            $result[$employee->id] = $scheduleDay && $scheduleDay->is_active
                ? (int) ($scheduleDay->total_break_minutes ?? 0) > 0
                : false;
        }

        return $result;
    }

    /**
     * Retorna true si la rotación o el horario fijo indican que este día es descanso planificado.
     * Usado para detectar trabajo extraordinario en días de franco rotativo.
     */
    private static function isScheduledDayOff(AttendanceDay $day): bool
    {
        $date = Carbon::parse($day->date);
        $employee = $day->employee;

        // 1. Rotación: Franco explícito (is_day_off = true)
        $shift = RotationService::getShiftForDate($employee, $date);

        if ($shift !== null) {
            return $shift->is_day_off;
        }

        // 2. Horario fijo: día de semana inactivo en el Schedule asignado
        $schedule = $employee->getScheduleForDate($date);

        if ($schedule) {
            return $schedule->isDayOff($date->dayOfWeekIso);
        }

        return false;
    }

    /**
     * Verifica si el empleado está de vacaciones y actualiza la bandera on_vacation.
     */
    public static function checkVacationStatus(AttendanceDay $day): void
    {
        $isOnVacation = $day->employee->vacations()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day->date)
            ->whereDate('end_date', '>=', $day->date)
            ->exists();

        $day->on_vacation = $isOnVacation;
    }

    /**
     * Verifica si el empleado tiene permiso aprobado y actualiza la bandera justified_absence.
     */
    public static function checkLeaveStatus(AttendanceDay $day): void
    {
        $hasJustifiedLeave = $day->employee->leaves()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day->date)
            ->whereDate('end_date', '>=', $day->date)
            ->exists();

        $day->justified_absence = $hasJustifiedLeave;
    }
}
