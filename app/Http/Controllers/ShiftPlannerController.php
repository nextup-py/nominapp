<?php

namespace App\Http\Controllers;

use App\Exports\ShiftPlanExport;
use App\Models\Employee;
use App\Models\ShiftTemplate;
use App\Services\RotationService;
use App\Services\ShiftPlanService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Controlador para la API del planificador visual de turnos rotativos.
 * Todos los endpoints requieren autenticación (middleware auth); los que modifican exigen `update_schedule`.
 */
class ShiftPlannerController extends Controller
{
    public function __construct(private ShiftPlanService $plans) {}

    /**
     * Reglas del filtro compartidas por la grilla, la exportación y «copiar semana».
     *
     * @return array<string, string>
     */
    private function filterRules(): array
    {
        return [
            'company_id' => 'nullable|integer|exists:companies,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'position_id' => 'nullable|integer|exists:positions,id',
            'pattern_id' => 'nullable|integer|exists:rotation_patterns,id',
            'scope' => 'nullable|in:all,with_pattern,without_pattern',
            'employee_id' => 'nullable|integer|exists:employees,id',
        ];
    }

    /**
     * Filtros del request normalizados (cero y vacío = sin filtro).
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'company_id' => $request->integer('company_id') ?: null,
            'branch_id' => $request->integer('branch_id') ?: null,
            'position_id' => $request->integer('position_id') ?: null,
            'pattern_id' => $request->integer('pattern_id') ?: null,
            'scope' => $request->input('scope') ?: ShiftPlanService::SCOPE_ALL,
            'employee_id' => $request->integer('employee_id') ?: null,
        ];
    }

    /**
     * Retorna los turnos de los empleados del filtro para un rango de fechas: jerarquía turno puntual → rotación,
     * conflictos con vacaciones y licencias aprobadas, y cobertura por día.
     *
     * @param  Request  $request  Params: start, end (Y-m-d) y los filtros de {@see self::filterRules()}.
     */
    public function data(Request $request): JsonResponse
    {
        $request->validate(['start' => 'required|date', 'end' => 'required|date|after_or_equal:start'] + $this->filterRules());

        $plan = $this->plans->plan($this->filters($request), Carbon::parse($request->start)->startOfDay(), Carbon::parse($request->end)->startOfDay());

        return response()->json([
            'employees' => $plan['employees']->map(fn (Employee $emp) => [
                'id' => $emp->id,
                'name' => $emp->first_name.' '.$emp->last_name,
                'branch' => $emp->branch?->name ?? '—',
                'company' => $emp->branch?->company?->name ?? '—',
                'position' => $emp->activeContract?->position?->name,
                'pattern' => $plan['patterns'][$emp->id] ?? null,
                'photo' => $emp->photo ? asset('storage/'.$emp->photo) : null,
                'initials' => mb_strtoupper(mb_substr($emp->first_name, 0, 1, 'UTF-8').mb_substr($emp->last_name, 0, 1, 'UTF-8'), 'UTF-8'),
            ]),
            'shifts' => $plan['shifts'],
            'conflicts' => $plan['conflicts'],
            'coverage' => $plan['coverage'],
        ]);
    }

    /**
     * Descarga la grilla del rango como Excel: una fila por empleado, una columna por día y las horas netas.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $request->validate(['start' => 'required|date', 'end' => 'required|date|after_or_equal:start'] + $this->filterRules());

        $start = Carbon::parse($request->start)->startOfDay();
        $end = Carbon::parse($request->end)->startOfDay();

        abort_if($start->diffInDays($end) > 62, 422, 'El rango máximo es de 62 días.');

        return Excel::download(
            new ShiftPlanExport($this->plans->plan($this->filters($request), $start, $end), $start, $end),
            'planificador_'.$start->format('Y_m_d').'_'.$end->format('Y_m_d').'.xlsx'
        );
    }

    /**
     * Copia los turnos puntuales de una semana a otra para los empleados del filtro (o uno solo). Con `preview` solo
     * informa lo que haría.
     */
    public function copyWeek(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_start' => 'required|date',
            'to_start' => 'required|date',
            'preview' => 'nullable|boolean',
        ] + $this->filterRules());

        $from = Carbon::parse($data['from_start'])->startOfDay();
        $to = Carbon::parse($data['to_start'])->startOfDay();

        if ($from->equalTo($to)) {
            return response()->json(['message' => 'La semana destino debe ser distinta de la de origen.'], 422);
        }

        return response()->json($this->plans->copyWeek($this->filters($request), $from, $to, (bool) ($data['preview'] ?? false)));
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

        return response()->json($this->plans->payload($override->shift, true, $override->reason_type) + [
            'notes' => $override->notes,
            'created_by' => $override->createdBy?->name,
            'updated_at' => $override->updated_at?->format('d/m/Y H:i'),
        ]);
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
