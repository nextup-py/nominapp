<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Arma la ficha mensual de asistencia de un empleado: una celda por día del mes y los totales.
 * Una sola consulta (con el último evento de cada jornada para detectar las sin salida) y sin N+1.
 */
class EmployeeMonthSheetService
{
    /**
     * Ficha del mes que contiene `$month`.
     *
     * @return array{
     *     month: Carbon,
     *     leading_blanks: int,
     *     days: array<int, array{date: Carbon, state: string, label: string, color: string, record_id: int|null, in: string|null, out: string|null, hours: float|null, late_minutes: int, extra_hours: float, alerts: array<int, string>, is_today: bool}>,
     *     totals: array<string, int|float>
     * }
     */
    public static function build(Employee $employee, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $today = Carbon::today();

        $records = AttendanceDay::query()
            ->select('attendance_days.*')
            ->selectRaw(AttendanceDay::lastEventTypeSql().' as last_event_type')
            ->where('attendance_days.employee_id', $employee->id)
            ->whereBetween('attendance_days.date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceDay $day) => $day->date->toDateString());

        $labels = AttendanceDay::getSheetStateLabels();
        $colors = AttendanceDay::getSheetStateColors();
        $days = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            /** @var AttendanceDay|null $record */
            $record = $records->get($date->toDateString());
            $state = $record?->sheetState() ?? ($date->lt($today) ? 'no_record' : 'upcoming');

            $days[] = [
                'date' => $date->copy(),
                'state' => $state,
                'label' => $labels[$state] ?? '',
                'color' => $colors[$state] ?? 'gray',
                'record_id' => $record?->id,
                'in' => $record?->check_in_time ? Carbon::parse($record->check_in_time)->format('H:i') : null,
                'out' => $record?->check_out_time ? Carbon::parse($record->check_out_time)->format('H:i') : null,
                'hours' => $record && $record->total_hours !== null ? (float) $record->total_hours : null,
                'late_minutes' => (int) ($record?->late_minutes ?? 0),
                'extra_hours' => (float) ($record?->extra_hours ?? 0),
                'alerts' => $record?->attentionReasons() ?? [],
                'is_today' => $date->isSameDay($today),
            ];
        }

        return [
            'month' => $start,
            'leading_blanks' => $start->dayOfWeekIso - 1,
            'days' => $days,
            'totals' => self::totals($days, $records->values()),
        ];
    }

    /**
     * Totales del mes a partir de las celdas y las jornadas registradas.
     *
     * @param  array<int, array<string, mixed>>  $days
     * @param  Collection<int, AttendanceDay>  $records
     * @return array<string, int|float>
     */
    private static function totals(array $days, $records): array
    {
        $count = fn (string $state): int => count(array_filter($days, fn (array $day) => $day['state'] === $state));
        $alerts = fn (string $reason): int => count(array_filter($days, fn (array $day) => in_array($reason, $day['alerts'], true)));

        $late = $records->filter(fn (AttendanceDay $day) => (int) $day->late_minutes > 0);
        $lateApproved = $late->filter(fn (AttendanceDay $day) => $day->tardiness_deduction_approved);
        $extra = $records->filter(fn (AttendanceDay $day) => (float) $day->extra_hours > 0);

        return [
            'present_days' => $count('present'),
            'absent_days' => $count('absent'),
            'absent_justified_days' => $count('absent_justified'),
            'leave_days' => $count('leave'),
            'vacation_days' => $count('vacation'),
            'holiday_days' => $count('holiday'),
            'day_off_days' => $count('day_off'),
            'no_record_days' => $count('no_record'),
            'hours_worked' => round((float) $records->sum(fn (AttendanceDay $day) => (float) $day->total_hours), 2),
            'hours_expected' => round((float) $records->whereIn('status', ['present', 'absent'])->sum(fn (AttendanceDay $day) => (float) $day->expected_hours), 2),
            'extra_hours' => round((float) $extra->sum(fn (AttendanceDay $day) => (float) $day->extra_hours), 2),
            'extra_hours_approved' => round((float) $extra->filter(fn (AttendanceDay $day) => $day->overtime_approved)->sum(fn (AttendanceDay $day) => (float) $day->extra_hours), 2),
            'late_days' => $late->count(),
            'late_minutes' => (int) $late->sum('late_minutes'),
            'late_minutes_approved' => (int) $lateApproved->sum('late_minutes'),
            'pending_missing_check_out' => $alerts('Sin salida'),
            'pending_tardiness' => $alerts('Tardanza por aprobar'),
            'pending_overtime' => $alerts('Extras por aprobar'),
        ];
    }
}
