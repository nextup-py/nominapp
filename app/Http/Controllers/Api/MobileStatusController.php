<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Services\AttendanceCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado de marcación del día en curso para el propio empleado autenticado
 * (a diferencia de `TerminalEmployeeSyncController::status()`, que recibe el
 * `employee_id` por ruta porque un terminal consulta por cualquiera de sus
 * empleados — acá el empleado es implícito, siempre `$request->user()`).
 * El dispositivo prefiere esta consulta en línea; si falla, cae a una
 * resolución local equivalente (ver mobile-offline/queue.js, igual que
 * hace el terminal desde la Fase 4).
 *
 * `today_events` (lista completa del día, no solo el último) alimenta la
 * pantalla "Mis marcaciones" en /marcar — a diferencia del resto de esta
 * respuesta, no tiene resolución local offline equivalente. Si todavía no
 * hay eventos hoy pero la jornada de ayer sigue abierta (turno nocturno),
 * muestra los eventos de esa jornada en su lugar — mismo criterio que
 * AttendanceDay::currentStateFor().
 */
class MobileStatusController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        $now = Carbon::now(config('app.timezone'));

        $today = AttendanceDay::where('employee_id', $employee->id)->where('date', $now->toDateString())->first();
        $todayEvents = $today
            ? AttendanceEvent::where('attendance_day_id', $today->id)->orderBy('recorded_at')->get(['event_type', 'recorded_at'])
            : collect();

        $state = AttendanceDay::currentStateFor($employee, $now);

        $displayEvents = $todayEvents;
        if ($todayEvents->isEmpty() && $state['last'] !== null) {
            $displayEvents = AttendanceEvent::where('attendance_day_id', $state['last']->attendance_day_id)
                ->orderBy('recorded_at')
                ->get(['event_type', 'recorded_at']);
        }

        return response()->json([
            'ok' => true,
            'last_event' => $state['last']?->event_type,
            'last_event_time' => $state['last']?->recorded_at?->format('H:i'),
            'allowed_events' => $state['allowed'],
            // Hora de salida del horario de hoy ("HH:MM"): el celular deja de ofrecer «Inicio descanso» cerca de ella.
            'expected_check_out' => $this->expectedCheckOut($employee, $now),
            'today_events' => $displayEvents->map(fn (AttendanceEvent $event): array => [
                'event_type' => $event->event_type,
                'event_type_label' => AttendanceEvent::getEventTypeLabel($event->event_type),
                'time' => $event->recorded_at->format('H:i'),
            ])->values(),
        ]);
    }

    /** Hora de salida esperada de hoy ("HH:MM"), o null si el empleado no tiene turno ese día. */
    private function expectedCheckOut(Employee $employee, Carbon $now): ?string
    {
        $time = AttendanceCalculator::resolveShiftDataFor($employee, $now->copy()->startOfDay())['check_out'] ?? null;

        return $time ? substr((string) $time, 0, 5) : null;
    }
}
