<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\Terminal;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Arma la foto "en vivo" de la asistencia de hoy: el estado de cada empleado activo (en turno, en descanso,
 * terminó, sin marcar, ausente, de permiso...), los contadores, las alertas del momento y las últimas marcaciones.
 *
 * Solo lee: no calcula ni modifica jornadas. El estado sale de las marcaciones de hoy, de la jornada
 * (`attendance_days`), de las ausencias detectadas por `attendance:check-missing` y del horario esperado
 * (`AttendanceCalculator::resolveShiftDataFor()`, con caché corta porque no cambia durante el día).
 */
class LiveAttendanceMonitor
{
    /** Segundos que se recuerda el horario esperado de un empleado para no resolverlo en cada refresco. */
    public const SHIFT_CACHE_SECONDS = 300;

    /** Cantidad máxima de filas de la lista en vivo. */
    public const MAX_ROWS = 300;

    /** Cantidad de marcaciones del feed. */
    public const FEED_SIZE = 15;

    /** Minutos en descanso a partir de los cuales se marca como descanso largo. */
    public const LONG_BREAK_MINUTES = 60;

    /** Minutos después de la hora de salida del horario a partir de los cuales quien sigue «en turno» pasa a «Sin salida». */
    public const NO_EXIT_GRACE_MINUTES = 60;

    /** Tipos de último evento que dejan al empleado dentro de su turno. */
    private const OPEN_EVENTS = ['check_in', 'break_start', 'break_end'];

    /**
     * Estados posibles, en orden de prioridad para la lista (lo que requiere atención primero).
     *
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function states(): array
    {
        return [
            'missing' => ['label' => 'Sin marcar', 'color' => 'danger', 'icon' => 'heroicon-o-exclamation-circle'],
            'absent' => ['label' => 'Ausente', 'color' => 'danger', 'icon' => 'heroicon-o-x-circle'],
            'no_exit' => ['label' => 'Sin salida', 'color' => 'warning', 'icon' => 'heroicon-o-arrow-right-on-rectangle'],
            'on_break' => ['label' => 'En descanso', 'color' => 'info', 'icon' => 'heroicon-o-pause-circle'],
            'working' => ['label' => 'En turno', 'color' => 'success', 'icon' => 'heroicon-o-check-circle'],
            'upcoming' => ['label' => 'Aún no inicia', 'color' => 'gray', 'icon' => 'heroicon-o-clock'],
            'finished' => ['label' => 'Terminó', 'color' => 'gray', 'icon' => 'heroicon-o-flag'],
            'on_leave' => ['label' => 'De permiso', 'color' => 'warning', 'icon' => 'heroicon-o-document-text'],
            'no_shift' => ['label' => 'Sin turno hoy', 'color' => 'gray', 'icon' => 'heroicon-o-minus-circle'],
        ];
    }

    /**
     * Etiquetas de los tipos de marcación para el feed.
     *
     * @return array<string, string>
     */
    public static function eventLabels(): array
    {
        return [
            'check_in' => 'Entrada',
            'check_out' => 'Salida',
            'break_start' => 'Inicio de descanso',
            'break_end' => 'Fin de descanso',
        ];
    }

    /**
     * Foto de asistencia de hoy.
     *
     * @param  array{employee_ids?: array<int, int>, company_id?: int|string|null, branch_id?: int|string|null, department_id?: int|string|null, only_scheduled?: bool, search?: string|null, state?: string|null}  $filters
     * @return array{
     *     now: Carbon,
     *     counts: array<string, int>,
     *     total: int,
     *     rows: array<int, array<string, mixed>>,
     *     truncated: bool,
     *     alerts: array<int, array<string, mixed>>,
     *     feed: array<int, array<string, mixed>>,
     *     offline_terminals: int
     * }
     */
    public static function snapshot(array $filters = [], ?Carbon $now = null): array
    {
        $now ??= now();
        $today = $now->copy()->startOfDay();

        $employees = self::employeesQuery($filters)->get();
        $ids = $employees->pluck('id')->all();

        $days = AttendanceDay::query()
            ->whereIn('employee_id', $ids)
            ->where('date', $today->toDateString())
            ->get()
            ->keyBy('employee_id');

        $lastEvents = self::lastEventsByEmployee($ids, $today, $days);
        $overnight = self::openOvernight($ids, $now, $days);
        $absences = self::absencesToday($ids, $today);

        $rows = [];
        foreach ($employees as $employee) {
            $rows[] = self::buildRow($employee, $days->get($employee->id), $lastEvents[$employee->id] ?? null, $overnight[$employee->id] ?? null, $absences[$employee->id] ?? null, $now);
        }

        $onlyScheduled = (bool) ($filters['only_scheduled'] ?? false);
        if ($onlyScheduled) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['state'] !== 'no_shift'));
        }

        $counts = self::countRows($rows);
        $alerts = [...self::alerts($rows), ...self::locationAlerts($ids, $today)];

        if (filled($filters['state'] ?? null)) {
            $rows = array_values(array_filter($rows, fn (array $row) => self::matchesStateFilter($row, (string) $filters['state'])));
        }

        $priority = array_flip(array_keys(self::states()));
        usort($rows, fn (array $a, array $b) => [$priority[$a['state']], -$a['minutes'], $a['name']] <=> [$priority[$b['state']], -$b['minutes'], $b['name']]);

        $truncated = count($rows) > self::MAX_ROWS;

        return [
            'now' => $now,
            'counts' => $counts,
            'total' => count($employees),
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
            'truncated' => $truncated,
            'alerts' => $alerts,
            'feed' => self::feed($ids, $today),
            'offline_terminals' => self::offlineTerminals(),
        ];
    }

    /**
     * Si la fila entra en el filtro de estado elegido. "late" no es un estado sino una condición
     * (entró con atraso), así que cruza varios estados.
     *
     * @param  array<string, mixed>  $row
     */
    private static function matchesStateFilter(array $row, string $state): bool
    {
        if ($state === 'late') {
            return $row['late_minutes'] > 0;
        }

        if ($state === 'present') {
            return in_array($row['state'], ['working', 'on_break'], true);
        }

        return $row['state'] === $state;
    }

    /**
     * Empleados activos que alcanza el filtro (empresa, sucursal, departamento y búsqueda).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Employee>
     */
    private static function employeesQuery(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return Employee::query()
            ->active()
            ->select(['employees.id', 'employees.first_name', 'employees.last_name', 'employees.ci', 'employees.branch_id'])
            ->with('branch:id,name,company_id')
            ->when(array_key_exists('employee_ids', $filters), fn (Builder $q) => $q->whereIn('employees.id', (array) $filters['employee_ids']))
            ->when(filled($filters['company_id'] ?? null), fn (Builder $q) => $q->whereHas('branch', fn ($b) => $b->where('company_id', (int) $filters['company_id'])))
            ->when(filled($filters['branch_id'] ?? null), fn (Builder $q) => $q->where('employees.branch_id', (int) $filters['branch_id']))
            ->when(filled($filters['department_id'] ?? null), fn (Builder $q) => $q->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('contracts')
                ->join('positions', 'positions.id', '=', 'contracts.position_id')
                ->whereColumn('contracts.employee_id', 'employees.id')
                ->where('contracts.status', 'active')
                ->where('positions.department_id', (int) $filters['department_id'])))
            ->when($search !== '', fn (Builder $q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhereRaw("concat(first_name, ' ', last_name) like ?", ["%{$search}%"])
                ->orWhere('ci', 'like', "%{$search}%")))
            ->orderBy('first_name')
            ->orderBy('last_name');
    }

    /**
     * Último evento de la jornada de hoy de cada empleado, en una sola consulta.
     *
     * @param  array<int, int>  $ids
     * @param  Collection<int, AttendanceDay>  $days
     * @return array<int, AttendanceEvent>
     */
    private static function lastEventsByEmployee(array $ids, Carbon $today, Collection $days): array
    {
        $dayIds = $days->pluck('id')->all();

        if ($dayIds === []) {
            return [];
        }

        $last = [];
        AttendanceEvent::query()
            ->whereIn('attendance_day_id', $dayIds)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['id', 'attendance_day_id', 'employee_id', 'event_type', 'recorded_at'])
            ->each(function (AttendanceEvent $event) use (&$last) {
                $last[$event->employee_id] = $event;
            });

        return $last;
    }

    /**
     * Turnos nocturnos de ayer que siguen abiertos (último evento no es salida y dentro del límite nocturno),
     * solo para empleados sin jornada de hoy con marcaciones.
     *
     * @param  array<int, int>  $ids
     * @param  Collection<int, AttendanceDay>  $todayDays
     * @return array<int, AttendanceEvent>
     */
    private static function openOvernight(array $ids, Carbon $now, Collection $todayDays): array
    {
        $limit = (int) app(GeneralSettings::class)->attendance_overnight_max_hours;
        $yesterday = $now->copy()->subDay()->toDateString();

        $dayIds = AttendanceDay::query()
            ->whereIn('employee_id', $ids)
            ->where('date', $yesterday)
            ->pluck('id');

        if ($dayIds->isEmpty()) {
            return [];
        }

        $last = [];
        AttendanceEvent::query()
            ->whereIn('attendance_day_id', $dayIds)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['id', 'attendance_day_id', 'employee_id', 'event_type', 'recorded_at'])
            ->each(function (AttendanceEvent $event) use (&$last) {
                $last[$event->employee_id] = $event;
            });

        return collect($last)
            ->filter(fn (AttendanceEvent $event, int $employeeId) => ! $todayDays->has($employeeId)
                && in_array($event->event_type, self::OPEN_EVENTS, true)
                && ($limit === 0 || $event->recorded_at->gte($now->copy()->subHours($limit))))
            ->all();
    }

    /**
     * Estado de la ausencia detectada hoy por empleado (`pending`, `unjustified` o `justified`).
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private static function absencesToday(array $ids, Carbon $today): array
    {
        return Absence::query()
            ->join('attendance_days', 'attendance_days.id', '=', 'absences.attendance_day_id')
            ->whereIn('absences.employee_id', $ids)
            ->where('attendance_days.date', $today->toDateString())
            ->pluck('absences.status', 'absences.employee_id')
            ->all();
    }

    /**
     * Horario esperado de hoy del empleado (con caché corta).
     *
     * @return array{check_in: string|null, check_out: string|null, break_minutes: int|null}
     */
    private static function shiftToday(Employee $employee, Carbon $now): array
    {
        return Cache::remember(
            'live-monitor:shift:'.$employee->id.':'.$now->toDateString(),
            self::SHIFT_CACHE_SECONDS,
            fn () => AttendanceCalculator::resolveShiftDataFor($employee, $now->copy()->startOfDay()),
        );
    }

    /**
     * Arma la fila de un empleado.
     *
     * @return array<string, mixed>
     */
    private static function buildRow(Employee $employee, ?AttendanceDay $day, ?AttendanceEvent $last, ?AttendanceEvent $overnight, ?string $absence, Carbon $now): array
    {
        $row = [
            'id' => $employee->id,
            'name' => $employee->full_name,
            'branch' => $employee->branch?->name,
            'state' => 'no_shift',
            'detail' => null,
            'expected_in' => null,
            'expected_out' => null,
            'last_event' => null,
            'last_event_at' => null,
            'late_minutes' => 0,
            'minutes' => 0,
        ];

        $event = $last ?? $overnight;

        if ($event !== null) {
            $row['last_event'] = self::eventLabels()[$event->event_type] ?? $event->event_type;
            $row['last_event_at'] = $event->recorded_at->format('H:i');
            $row['late_minutes'] = $last !== null ? (int) ($day?->late_minutes ?? 0) : 0;
            $row['expected_in'] = self::hhmm($day?->expected_check_in);
            $row['expected_out'] = self::hhmm($day?->expected_check_out);

            $row['state'] = match ($event->event_type) {
                'break_start' => 'on_break',
                'check_out' => 'finished',
                default => 'working',
            };
            $row['minutes'] = (int) $event->recorded_at->diffInMinutes($now);

            if ($row['state'] === 'on_break') {
                $row['detail'] = "{$row['minutes']} min en descanso";
            }

            // Su turno ya terminó hace rato y la última marcación sigue abierta: se fue sin marcar la salida.
            $shiftEnd = $last !== null ? self::shiftEnd($day) : null;

            if ($shiftEnd !== null && in_array($row['state'], ['working', 'on_break'], true)
                && $now->gte($shiftEnd->copy()->addMinutes(self::NO_EXIT_GRACE_MINUTES))) {
                $row['state'] = 'no_exit';
                $row['minutes'] = (int) $shiftEnd->diffInMinutes($now);
                $row['detail'] = "Su turno terminó a las {$row['expected_out']} y no marcó la salida ({$row['last_event']} a las {$row['last_event_at']})";
            }

            return $row;
        }

        if ($absence === 'justified' || $day?->status === 'on_leave' || $day?->on_vacation || $day?->excluded_from_calculation) {
            $row['state'] = 'on_leave';

            return $row;
        }

        if (in_array($absence, ['pending', 'unjustified'], true) || $day?->status === 'absent') {
            $row['state'] = 'absent';

            return $row;
        }

        $shift = self::shiftToday($employee, $now);
        $row['expected_in'] = self::hhmm($shift['check_in']);
        $row['expected_out'] = self::hhmm($shift['check_out']);

        if ($shift['check_in'] === null) {
            return $row;
        }

        $expectedIn = $now->copy()->setTimeFromTimeString($shift['check_in']);

        if ($expectedIn->gt($now)) {
            $row['state'] = 'upcoming';
            $row['minutes'] = (int) $now->diffInMinutes($expectedIn);
            $row['detail'] = "Entra en {$row['minutes']} min";

            return $row;
        }

        $row['state'] = 'missing';
        $row['minutes'] = (int) $expectedIn->diffInMinutes($now);
        $row['late_minutes'] = $row['minutes'];
        $row['detail'] = "{$row['minutes']} min de retraso";

        return $row;
    }

    /**
     * Instante en que termina el turno de la jornada (el del día siguiente si cruza la medianoche), o null sin horario.
     */
    private static function shiftEnd(?AttendanceDay $day): ?Carbon
    {
        if ($day === null || ! $day->expected_check_out) {
            return null;
        }

        $end = $day->date->copy()->startOfDay()->setTimeFromTimeString($day->expected_check_out);

        if ($day->expected_check_in && $day->expected_check_out <= $day->expected_check_in) {
            $end->addDay();
        }

        return $end;
    }

    /** Recorta una hora `HH:MM:SS` a `HH:MM`. */
    private static function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    /**
     * Contadores del día a partir de las filas ya armadas.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private static function countRows(array $rows): array
    {
        $counts = array_fill_keys(array_keys(self::states()), 0);
        $counts['present'] = 0;
        $counts['late'] = 0;
        $counts['long_break'] = 0;

        foreach ($rows as $row) {
            $counts[$row['state']]++;

            if (in_array($row['state'], ['working', 'on_break'], true)) {
                $counts['present']++;
            }

            if ($row['state'] !== 'missing' && $row['late_minutes'] > 0) {
                $counts['late']++;
            }

            if ($row['state'] === 'on_break' && $row['minutes'] >= self::LONG_BREAK_MINUTES) {
                $counts['long_break']++;
            }
        }

        return $counts;
    }

    /**
     * Alertas del momento: quien debería haber entrado y no marcó, quien se fue sin marcar la salida y descansos largos.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private static function alerts(array $rows): array
    {
        $alerts = [];

        foreach ($rows as $row) {
            if ($row['state'] === 'missing') {
                $alerts[] = ['type' => 'missing', 'color' => 'danger', 'employee' => $row['name'], 'branch' => $row['branch'], 'text' => "Debía entrar a las {$row['expected_in']} y no marcó ({$row['detail']})", 'minutes' => $row['minutes']];
            } elseif ($row['state'] === 'no_exit') {
                $alerts[] = ['type' => 'no_exit', 'color' => 'warning', 'employee' => $row['name'], 'branch' => $row['branch'], 'text' => $row['detail'], 'minutes' => $row['minutes']];
            } elseif ($row['state'] === 'on_break' && $row['minutes'] >= self::LONG_BREAK_MINUTES) {
                $alerts[] = ['type' => 'long_break', 'color' => 'warning', 'employee' => $row['name'], 'branch' => $row['branch'], 'text' => "Lleva {$row['minutes']} min en descanso", 'minutes' => $row['minutes']];
            }
        }

        usort($alerts, fn (array $a, array $b) => $b['minutes'] <=> $a['minutes']);

        return array_slice($alerts, 0, 20);
    }

    /**
     * Marcaciones de hoy desde el celular fuera de radio (o sin ubicación) que RR.HH. todavía no revisó.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private static function locationAlerts(array $ids, Carbon $today): array
    {
        return AttendanceEvent::query()
            ->whereIn('employee_id', $ids)
            ->where('recorded_at', '>=', $today)
            ->where('location_review', LocationRadiusService::REVIEW_PENDING)
            ->latest('recorded_at')
            ->limit(10)
            ->get(['id', 'employee_name', 'branch_name', 'location_flag', 'location_distance_m', 'recorded_at'])
            ->map(fn (AttendanceEvent $e) => [
                'type' => 'outside_radius',
                'color' => 'warning',
                'employee' => $e->employee_name,
                'branch' => $e->branch_name,
                'text' => $e->location_flag === LocationRadiusService::FLAG_NO_LOCATION
                    ? 'Marcó a las '.$e->recorded_at->format('H:i').' sin ubicación'
                    : 'Marcó a las '.$e->recorded_at->format('H:i')." a {$e->location_distance_m} m de su punto",
                'minutes' => 0,
            ])
            ->all();
    }

    /**
     * Últimas marcaciones de hoy de los empleados alcanzados por el filtro.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private static function feed(array $ids, Carbon $today): array
    {
        return AttendanceEvent::query()
            ->whereIn('employee_id', $ids)
            ->where('recorded_at', '>=', $today)
            ->latest('recorded_at')
            ->latest('id')
            ->limit(self::FEED_SIZE)
            ->get(['id', 'employee_id', 'employee_name', 'event_type', 'recorded_at', 'branch_name', 'source'])
            ->map(fn (AttendanceEvent $e) => [
                'id' => $e->id,
                'name' => $e->employee_name,
                'branch' => $e->branch_name,
                'type' => self::eventLabels()[$e->event_type] ?? $e->event_type,
                'event_type' => $e->event_type,
                'time' => $e->recorded_at->format('H:i:s'),
            ])
            ->all();
    }

    /** Terminales activos que dejaron de reportar (dentro de su horario de vigilancia). */
    private static function offlineTerminals(): int
    {
        return Terminal::query()
            ->withSyncTokenFlag()
            ->with('branch')
            ->where('status', 'active')
            ->get()
            ->filter(fn (Terminal $terminal) => $terminal->connectivity_status === 'stale')
            ->count();
    }
}
