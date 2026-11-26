<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\RotationAssignment;
use App\Models\ShiftOverride;
use App\Models\ShiftTemplate;
use App\Services\RotationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Controlador para la API del planificador visual de turnos rotativos.
 * Todos los endpoints requieren autenticación (middleware auth).
 */
class ShiftPlannerController extends Controller
{
    /**
     * Retorna los turnos de todos los empleados para un rango de fechas.
     * Resuelve la jerarquía override → rotación para cada empleado × fecha.
     *
     * @param  Request  $request  Params: start (Y-m-d), end (Y-m-d), company_id?, branch_id?
     */
    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'company_id' => 'nullable|integer|exists:companies,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'employee_id' => 'nullable|integer|exists:employees,id',
        ]);

        $start = Carbon::parse($request->start)->startOfDay();
        $end = Carbon::parse($request->end)->startOfDay();
        $companyId = $request->integer('company_id') ?: null;
        $branchId = $request->integer('branch_id') ?: null;

        // ── Empleados filtrados ─────────────────────────────────────────────
        $employees = Employee::with('branch.company')
            ->where('status', 'active')
            ->when($request->integer('employee_id'), fn ($q, $id) => $q->whereKey($id))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($companyId && ! $branchId, fn ($q) => $q->whereHas('branch', fn ($b) => $b->where('company_id', $companyId)))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $employeeIds = $employees->pluck('id');

        // ── Rotation assignments activos en el rango ────────────────────────
        $assignments = RotationAssignment::with('pattern')
            ->whereIn('employee_id', $employeeIds)
            ->where('valid_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $start))
            ->get()
            ->groupBy('employee_id');

        // ── Overrides en el rango ───────────────────────────────────────────
        $overrides = ShiftOverride::with('shift')
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('override_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($items) => $items->keyBy(fn ($item) => $item->override_date->format('Y-m-d')));

        // ── ShiftTemplates referenciados en patrones (carga en memoria) ─────
        $allShiftIds = $assignments->flatten()
            ->flatMap(fn ($a) => $a->pattern?->sequence ?? [])
            ->unique()
            ->values();

        $shiftMap = ShiftTemplate::whereIn('id', $allShiftIds)
            ->get()
            ->keyBy('id');

        // ── Construir respuesta ─────────────────────────────────────────────
        $employeesData = $employees->map(fn ($emp) => [
            'id' => $emp->id,
            'name' => $emp->first_name.' '.$emp->last_name,
            'branch' => $emp->branch?->name ?? '—',
            'company' => $emp->branch?->company?->name ?? '—',
            'photo' => $emp->photo ? asset('storage/'.$emp->photo) : null,
            'initials' => mb_strtoupper(
                mb_substr($emp->first_name, 0, 1, 'UTF-8').
                mb_substr($emp->last_name, 0, 1, 'UTF-8'),
                'UTF-8'
            ),
        ]);

        $shiftsData = [];

        foreach ($employees as $emp) {
            $empAssignments = $assignments->get($emp->id, collect());
            $empOverrides = $overrides->get($emp->id, collect());
            $shiftsData[$emp->id] = [];

            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $dateStr = $d->toDateString();

                // 1. Override puntual
                if ($override = $empOverrides->get($dateStr)) {
                    $shiftsData[$emp->id][$dateStr] = $this->shiftPayload($override->shift, true, $override->reason_type);

                    continue;
                }

                // 2. Patrón rotativo vigente
                $activeAssignment = $empAssignments->first(fn ($a) => $a->valid_from->lte($d) &&
                    ($a->valid_until === null || $a->valid_until->gte($d))
                );

                if ($activeAssignment && $activeAssignment->pattern) {
                    $shiftId = $activeAssignment->shiftIdForDate($d);

                    if ($shiftId && $s = $shiftMap->get($shiftId)) {
                        $shiftsData[$emp->id][$dateStr] = $this->shiftPayload($s, false, null);
                    }
                }
                // Sin asignación de rotación → la celda queda vacía
            }
        }

        return response()->json([
            'employees' => $employeesData,
            'shifts' => $shiftsData,
        ]);
    }

    /**
     * Datos de un turno para la grilla: identificación, horario, minutos netos (para las horas por empleado) y
     * si el turno está inactivo (sigue mostrándose donde ya estaba asignado).
     *
     * @return array<string, mixed>
     */
    private function shiftPayload(ShiftTemplate $s, bool $isOverride, ?string $reasonType): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'color' => $s->color,
            'start' => $s->start_time,
            'end' => $s->end_time,
            'is_day_off' => $s->is_day_off,
            'net_minutes' => $s->netMinutes(),
            'is_active' => $s->is_active,
            'is_override' => $isOverride,
            'reason_type' => $reasonType,
        ];
    }

    /**
     * Crea o actualiza un override puntual de turno para un empleado en una fecha.
     */
    public function storeOverride(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'date' => 'required|date',
            'shift_id' => 'required|integer|exists:shift_templates,id',
            'reason_type' => 'required|in:cambio_turno,guardia_extra,permiso,reposo,otro',
            'notes' => 'nullable|string|max:150',
        ]);

        $employee = Employee::with('branch')->findOrFail($data['employee_id']);
        $shift = ShiftTemplate::findOrFail($data['shift_id']);

        if ($employee->status !== 'active') {
            return response()->json(['message' => 'El empleado está inactivo: no se le pueden asignar turnos.'], 422);
        }

        if (! $shift->is_active) {
            return response()->json(['message' => 'El turno está inactivo: reactivarlo en Turnos o elegir otro.'], 422);
        }

        if ($employee->branch?->company_id !== $shift->company_id) {
            return response()->json(['message' => 'El turno pertenece a otra empresa que el empleado.'], 422);
        }

        $override = RotationService::override(
            employee: $employee,
            date: Carbon::parse($data['date']),
            shift: $shift,
            reasonType: $data['reason_type'],
            notes: $data['notes'] ?? null,
        );

        return response()->json($this->shiftPayload($override->shift, true, $override->reason_type));
    }

    /**
     * Elimina el override de un empleado para una fecha, restaurando el turno del ciclo.
     */
    public function destroyOverride(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'date' => 'required|date',
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $removed = RotationService::removeOverride($employee, Carbon::parse($data['date']));

        return response()->json(['removed' => $removed]);
    }

    /**
     * Retorna los turnos disponibles para una empresa, para poblar el modal de asignación.
     */
    public function shifts(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        $shifts = ShiftTemplate::query()
            ->when($request->company_id, fn ($q) => $q->where('company_id', $request->company_id))
            ->where('is_active', true)
            ->orderByRaw('is_day_off ASC, name ASC')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'company_id' => $s->company_id,
                'name' => $s->name,
                'color' => $s->color,
                'start' => $s->start_time,
                'end' => $s->end_time,
                'is_day_off' => $s->is_day_off,
            ]);

        return response()->json($shifts);
    }
}
