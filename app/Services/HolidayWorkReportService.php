<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Contract;
use App\Models\Employee;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Trabajo en feriado y domingo: cada jornada con marcación en un feriado o domingo, con las horas trabajadas, las
 * horas extra ya registradas (las que la nómina recarga, si están aprobadas) y una **estimación** del recargo que
 * correspondería sobre el resto del turno (`horas del turno × valor hora × (multiplicador − 1)`), porque el turno
 * normal en feriado hoy se paga como un día común. Es una herramienta para conversar con el asesor legal y el
 * contador: no cambia ningún pago y la exigencia legal la confirma el asesor.
 */
class HolidayWorkReportService
{
    public const KIND_ALL = 'all';

    public const KIND_HOLIDAY = 'holiday';

    public const KIND_SUNDAY = 'sunday';

    /** Máximo de filas que se devuelven. */
    public const MAX_ROWS = 2000;

    /**
     * Opciones del filtro de tipo de día.
     *
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return [self::KIND_ALL => 'Feriados y domingos', self::KIND_HOLIDAY => 'Solo feriados', self::KIND_SUNDAY => 'Solo domingos'];
    }

    /**
     * Filas del reporte.
     *
     * @return Collection<int, array{date: Carbon, kind: string, employee: string, branch: string, worked: float, expected: ?float, extra: float, extra_approved: bool, normal: float, hourly: ?float, premium: ?float}>
     */
    public static function rows(Carbon $from, Carbon $to, string $kind = self::KIND_ALL, ?int $branchId = null): Collection
    {
        $multiplier = (float) app(PayrollSettings::class)->overtime_multiplier_holiday;
        $defaultMonthlyHours = (int) app(PayrollSettings::class)->monthly_hours;

        $days = AttendanceDay::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('check_in_time')
            ->where(fn ($q) => match ($kind) {
                self::KIND_HOLIDAY => $q->where('is_holiday', true),
                self::KIND_SUNDAY => $q->where('is_weekend', true)->where('is_holiday', false),
                default => $q->where('is_holiday', true)->orWhere('is_weekend', true),
            })
            ->when($branchId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $branchId)))
            ->with(['employee.branch', 'employee.contracts'])
            ->orderBy('date')
            ->limit(self::MAX_ROWS)
            ->get()
            ->filter(fn (AttendanceDay $day): bool => $day->employee !== null);

        $monthlyHours = [];

        return $days->map(function (AttendanceDay $day) use ($multiplier, $defaultMonthlyHours, &$monthlyHours): array {
            $employee = $day->employee;
            $date = Carbon::parse($day->date);
            $contract = self::contractOn($employee, $date);
            $hours = $monthlyHours[$employee->id] ??= (int) ($employee->getScheduleForDate($date)?->getMonthlyHours() ?: $defaultMonthlyHours);
            $hourly = $contract && $contract->salary_type === 'mensual' && $hours > 0 ? (float) $contract->salary / $hours : null;
            $worked = (float) $day->total_hours;
            $extra = (float) $day->extra_hours;
            $normal = max(0.0, $worked - $extra);

            return [
                'date' => $date,
                'kind' => $day->is_holiday ? 'Feriado' : 'Domingo',
                'employee' => $employee->full_name,
                'branch' => $employee->branch?->name ?? 'Sin sucursal',
                'worked' => $worked,
                'expected' => $day->expected_hours !== null ? (float) $day->expected_hours : null,
                'extra' => $extra,
                'extra_approved' => (bool) $day->overtime_approved,
                'normal' => $normal,
                'hourly' => $hourly,
                'premium' => $hourly !== null ? round($normal * $hourly * max(0.0, $multiplier - 1), 0) : null,
            ];
        })->values();
    }

    /**
     * Totales del conjunto de filas.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{days: int, employees: int, worked: float, extra: float, normal: float, premium: float, without_rate: int}
     */
    public static function totals(Collection $rows): array
    {
        return [
            'days' => $rows->count(),
            'employees' => $rows->pluck('employee')->unique()->count(),
            'worked' => round($rows->sum('worked'), 1),
            'extra' => round($rows->sum('extra'), 1),
            'normal' => round($rows->sum('normal'), 1),
            'premium' => (float) $rows->sum('premium'),
            'without_rate' => $rows->whereNull('hourly')->count(),
        ];
    }

    /** Contrato que regía para el empleado en esa fecha (el más reciente que ya había empezado y no estaba terminado). */
    private static function contractOn(Employee $employee, Carbon $date): ?Contract
    {
        return $employee->contracts
            ->filter(fn (Contract $c): bool => $c->status !== 'draft'
                && $c->start_date->lte($date)
                && ($c->terminated_at === null || $c->terminated_at->gte($date->copy()->startOfDay()))
                && ($c->status !== 'expired' || $c->end_date === null || $c->end_date->gte($date)))
            ->sortByDesc('start_date')
            ->first();
    }
}
