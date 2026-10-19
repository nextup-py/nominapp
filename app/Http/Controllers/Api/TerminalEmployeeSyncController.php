<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\Terminal;
use App\Services\AttendanceCalculator;
use App\Services\EmployeeDescriptorSyncService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Sincronización incremental de empleados/descriptores faciales para la
 * caché offline del terminal. Autenticado vía Sanctum (ability `terminal:sync`).
 */
class TerminalEmployeeSyncController extends Controller
{
    public function __construct(private readonly EmployeeDescriptorSyncService $syncService) {}

    /**
     * GET /api/v1/terminal/employees/sync?since=<ISO8601>
     *
     * Sin `since`, retorna la carga completa (primera sincronización del terminal).
     * Con `since`, retorna solo los empleados modificados después de esa fecha,
     * más los `tombstones` (empleados que dejaron de calificar) a eliminar de la caché.
     */
    public function index(Request $request): JsonResponse
    {
        $since = null;

        if ($request->filled('since')) {
            try {
                $since = Carbon::parse($request->query('since'));
            } catch (Throwable) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Parámetro since inválido — debe ser una fecha en formato ISO 8601.',
                ], 422);
            }
        }

        /** @var Terminal $terminal */
        $terminal = $request->user();

        $delta = $this->syncService->deltaSince($terminal, $since);

        $terminal->update(['last_employee_sync_at' => now()]);

        return response()->json(array_merge(['ok' => true], $delta));
    }

    /**
     * GET /api/v1/terminal/employees/{employee}/status
     *
     * Estado de marcación del día en curso para un empleado ya identificado
     * localmente por el terminal (matching client-side, ver terminal-offline/matcher.js).
     * El terminal prefiere esta consulta en línea cuando hay red; si falla,
     * cae a una resolución local equivalente combinando la última respuesta
     * cacheada de este endpoint con sus propios eventos aún no confirmados
     * (ver terminal-offline/queue.js, `resolveEmployeeStatus()`).
     */
    public function status(Request $request, Employee $employee): JsonResponse
    {
        /** @var Terminal $terminal */
        $terminal = $request->user();

        if ($employee->status !== 'active' || $employee->branch_id !== $terminal->branch_id) {
            return response()->json([
                'ok' => false,
                'message' => 'Empleado no disponible para este terminal.',
            ], 404);
        }

        $now = Carbon::now(config('app.timezone'));

        // Mismo criterio que AttendanceFaceMarkController::identify() — considera una
        // posible jornada nocturna del día anterior todavía abierta y filtra
        // 'break_start' si el horario del empleado no contempla descanso.
        $state = AttendanceDay::currentStateFor($employee, $now);

        return response()->json([
            'ok' => true,
            'last_event' => $state['last']?->event_type,
            'last_event_time' => $state['last']?->recorded_at?->format('H:i'),
            'last_event_at' => $state['last']?->recorded_at?->toIso8601String(),
            'expected_check_in' => $this->expectedShiftTime($employee, $now, 'check_in'),
            'expected_check_out' => $this->expectedShiftTime($employee, $now, 'check_out'),
            'allowed_events' => $state['allowed'],
        ]);
    }

    /**
     * Hora de inicio (`check_in`) o fin (`check_out`) del turno de hoy ("HH:MM"); null si no tiene horario ese día.
     * El fin alimenta el recordatorio de salida del terminal y ambas juntas deciden cuándo preguntar «¿Entrada o
     * salida?» a quien llega con el día vacío (ver terminal/missing-entry.js).
     *
     * @param  'check_in'|'check_out'  $key
     */
    private function expectedShiftTime(Employee $employee, Carbon $now, string $key): ?string
    {
        $time = AttendanceCalculator::resolveShiftDataFor($employee, $now->copy()->startOfDay())[$key] ?? null;

        return $time ? substr((string) $time, 0, 5) : null;
    }
}
