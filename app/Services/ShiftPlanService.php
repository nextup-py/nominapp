<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\RotationAssignment;
use App\Models\ShiftOverride;
use App\Models\ShiftTemplate;
use App\Models\Vacation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Arma el plan de turnos de un rango de fechas para el Planificador: turno de cada empleado y día (turno puntual →
 * patrón de rotación), conflictos con vacaciones y licencias aprobadas y cobertura por día. Lo comparten la grilla,
 * la exportación a Excel y «copiar semana».
 */
class ShiftPlanService
{
    /** Alcances del filtro por patrón de rotación. */
    public const SCOPE_ALL = 'all';

    public const SCOPE_WITH_PATTERN = 'with_pattern';

    public const SCOPE_WITHOUT_PATTERN = 'without_pattern';

    /**
     * Empleados activos del filtro (empresa, sucursal, cargo, patrón o alcance, y empleado puntual).
     *
     * @param  array{company_id?: int|null, branch_id?: int|null, position_id?: int|null, pattern_id?: int|null, scope?: string|null, employee_id?: int|null}  $filters
     * @return Collection<int, Employee>
     */
    public function employees(array $filters, Carbon $start, Carbon $end): Collection
    {
        $overlapping = fn (Builder $query) => $query
            ->where('valid_from', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $start->toDateString()));

        return Employee::query()
            ->with(['branch.company', 'activeContract.position'])
            ->where('status', 'active')
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when(($filters['company_id'] ?? null) && ! ($filters['branch_id'] ?? null), fn ($q) => $q->whereHas('branch', fn ($b) => $b->where('company_id', $filters['company_id'])))
            ->when($filters['position_id'] ?? null, fn ($q, $id) => $q->whereHas('activeContract', fn ($c) => $c->where('position_id', $id)))
            ->when($filters['pattern_id'] ?? null, fn ($q, $id) => $q->whereHas('rotationAssignments', fn ($a) => $a->where('pattern_id', $id)->where($overlapping)))
            ->when(($filters['scope'] ?? self::SCOPE_ALL) === self::SCOPE_WITH_PATTERN, fn ($q) => $q->whereHas('rotationAssignments', $overlapping))
            ->when(($filters['scope'] ?? self::SCOPE_ALL) === self::SCOPE_WITHOUT_PATTERN, fn ($q) => $q->whereDoesntHave('rotationAssignments', $overlapping))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * Plan completo del rango.
     *
     * @param  array<string, mixed>  $filters  Ver {@see self::employees()}.
     * @return array{employees: Collection<int, Employee>, patterns: array<int, string|null>, shifts: array<int, array<string, array<string, mixed>>>, conflicts: array<int, array<string, array{type: string, label: string}>>, coverage: array<string, array{working: int, off: int, away: int}>}
     */
    public function plan(array $filters, Carbon $start, Carbon $end): array
    {
        $employees = $this->employees($filters, $start, $end);
        $employeeIds = $employees->pluck('id');

        $assignments = RotationAssignment::with('pattern')
            ->whereIn('employee_id', $employeeIds)
            ->where('valid_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $start))
            ->get()
            ->groupBy('employee_id');

        $overrides = ShiftOverride::with(['shift', 'createdBy'])
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('override_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($items) => $items->keyBy(fn ($item) => $item->override_date->format('Y-m-d')));

        $shiftMap = ShiftTemplate::whereIn('id', $assignments->flatten()->flatMap(fn ($a) => $a->pattern?->sequence ?? [])->unique()->values())->get()->keyBy('id');

        $conflicts = $this->conflicts($employeeIds->all(), $start, $end);

        $shifts = [];
        $patterns = [];
        $coverage = [];

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $coverage[$d->toDateString()] = ['working' => 0, 'off' => 0, 'away' => 0];
        }

        foreach ($employees as $employee) {
            $employeeAssignments = $assignments->get($employee->id, collect());
            $employeeOverrides = $overrides->get($employee->id, collect());
            $shifts[$employee->id] = [];
            $patterns[$employee->id] = $employeeAssignments->sortByDesc('valid_from')->first()?->pattern?->name;

            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $date = $d->toDateString();
                $cell = null;

                if ($override = $employeeOverrides->get($date)) {
                    $cell = $this->payload($override->shift, true, $override->reason_type) + [
                        'notes' => $override->notes,
                        'created_by' => $override->createdBy?->name,
                        'updated_at' => $override->updated_at?->format('d/m/Y H:i'),
                    ];
                } else {
                    $active = $employeeAssignments->first(fn ($a) => $a->valid_from->lte($d) && ($a->valid_until === null || $a->valid_until->gte($d)));

                    if ($active?->pattern && ($shiftId = $active->shiftIdForDate($d)) && ($shift = $shiftMap->get($shiftId))) {
                        $cell = $this->payload($shift, false, null);
                    }
                }

                if ($cell !== null) {
                    $shifts[$employee->id][$date] = $cell;
                }

                if (isset($conflicts[$employee->id][$date])) {
                    $coverage[$date]['away']++;
                } elseif ($cell !== null) {
                    $coverage[$date][$cell['is_day_off'] ? 'off' : 'working']++;
                }
            }
        }

        return compact('employees', 'patterns', 'shifts', 'conflicts', 'coverage');
    }

    /**
     * Vacaciones y licencias aprobadas que cubren cada día del rango, por empleado.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array<string, array{type: string, label: string}>>
     */
    public function conflicts(array $employeeIds, Carbon $start, Carbon $end): array
    {
        $conflicts = [];

        $mark = function (int $employeeId, Carbon $from, Carbon $to, string $type, string $label) use (&$conflicts, $start, $end): void {
            $last = $to->copy()->min($end)->copy();

            // `max()` devuelve una de las dos instancias: se copia para no mutar el rango del llamador.
            for ($d = $from->copy()->max($start)->copy(); $d->lte($last); $d->addDay()) {
                $conflicts[$employeeId][$d->toDateString()] ??= ['type' => $type, 'label' => $label];
            }
        };

        Vacation::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->get()
            ->each(fn (Vacation $vacation) => $mark($vacation->employee_id, $vacation->start_date, $vacation->end_date, 'vacation', 'Vacaciones'));

        EmployeeLeave::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->get()
            ->each(fn (EmployeeLeave $leave) => $mark($leave->employee_id, $leave->start_date, $leave->end_date, 'leave', EmployeeLeave::getTypeOptions()[$leave->type] ?? 'Licencia'));

        return $conflicts;
    }

    /**
     * Copia los turnos puntuales de la semana origen a la semana destino (mismos empleados y mismo día de la semana).
     * Con `$preview` solo cuenta lo que haría; no copia a fechas pasadas, ni turnos inactivos o de otra empresa, ni a
     * empleados inactivos.
     *
     * @param  array<string, mixed>  $filters  Ver {@see self::employees()}.
     * @return array{create: int, overwrite: int, skipped: array<string, int>}
     */
    public function copyWeek(array $filters, Carbon $fromStart, Carbon $toStart, bool $preview): array
    {
        $offset = (int) $fromStart->diffInDays($toStart, false);
        $fromEnd = $fromStart->copy()->addDays(6);
        $result = ['create' => 0, 'overwrite' => 0, 'skipped' => ['past' => 0, 'inactive_shift' => 0, 'other_company' => 0, 'inactive_employee' => 0]];

        $employees = $this->employees($filters, $fromStart, $fromEnd)->keyBy('id');
        $sources = ShiftOverride::with('shift')
            ->whereIn('employee_id', $employees->keys())
            ->whereBetween('override_date', [$fromStart->toDateString(), $fromEnd->toDateString()])
            ->get();

        foreach ($sources as $source) {
            $employee = $employees->get($source->employee_id);
            $target = $source->override_date->copy()->addDays($offset);

            if ($target->lt(Carbon::today())) {
                $result['skipped']['past']++;
            } elseif (! $source->shift->is_active) {
                $result['skipped']['inactive_shift']++;
            } elseif ($employee->branch?->company_id !== $source->shift->company_id) {
                $result['skipped']['other_company']++;
            } else {
                $exists = ShiftOverride::where('employee_id', $employee->id)->where('override_date', $target->toDateString())->exists();
                $result[$exists ? 'overwrite' : 'create']++;

                if (! $preview) {
                    RotationService::override($employee, $target, $source->shift, $source->reason_type, 'Copiado de la semana del '.$fromStart->format('d/m/Y').($source->notes ? ' · '.$source->notes : ''));
                }
            }
        }

        return $result;
    }

    /**
     * Datos de un turno para la grilla: identificación, horario, minutos netos y si está inactivo (sigue
     * mostrándose donde ya estaba asignado).
     *
     * @return array<string, mixed>
     */
    public function payload(ShiftTemplate $shift, bool $isOverride, ?string $reasonType): array
    {
        return [
            'id' => $shift->id,
            'name' => $shift->name,
            'color' => $shift->color,
            'start' => $shift->start_time,
            'end' => $shift->end_time,
            'is_day_off' => $shift->is_day_off,
            'net_minutes' => $shift->netMinutes(),
            'is_active' => $shift->is_active,
            'is_override' => $isOverride,
            'reason_type' => $reasonType,
        ];
    }
}
