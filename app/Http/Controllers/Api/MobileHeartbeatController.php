<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Services\AttendanceCalculator;
use App\Settings\GeneralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Heartbeat del dispositivo personal — a diferencia del heartbeat de terminal
 * (Terminal), acá el propio empleado es el `tokenable`, así que además de
 * la config vigente de reconocimiento facial también devuelve el
 * `face_descriptor` actualizado del empleado: no existe un endpoint
 * separado de "sync de empleados" porque el dispositivo solo necesita cachear
 * un único descriptor, el suyo. Esto además propaga automáticamente una
 * re-inscripción facial (nuevo enrollment) sin que el empleado tenga que
 * re-vincular el dispositivo.
 */
class MobileHeartbeatController extends Controller
{
    public function store(Request $request, GeneralSettings $settings): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        if ($employee->status !== 'active') {
            // El empleado se desvinculó implícitamente (baja, licencia, etc.) — se revoca acá
            // en vez de esperar a que un admin lo haga manualmente desde Filament.
            $employee->revokeMobileToken();

            return response()->json([
                'ok' => false,
                'message' => 'Tu acceso a la marcación por dispositivo fue desactivado. Consultá con RRHH.',
            ], 403);
        }

        $employee->forceFill(['mobile_last_heartbeat_at' => now()])->save();

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'config' => [
                'face_threshold' => (float) $settings->face_threshold,
                'face_min_confidence_gap' => (float) $settings->face_min_confidence_gap,
                // Motivos y tolerancia para pedir el motivo al marcar una salida con horas extra.
                'overtime_reasons' => AttendanceEvent::getOvertimeReasonOptions(),
                'overtime_tolerance_minutes' => (int) $settings->attendance_overtime_tolerance_minutes,
                'overtime_reason_required' => (bool) $settings->attendance_overtime_reason_required,
                'overtime_reason_timeout_seconds' => max(5, (int) $settings->attendance_overtime_reason_timeout_seconds),
            ],
            'employee' => [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'ci' => $employee->ci,
                'face_descriptor' => $employee->face_descriptor,
                'photo_thumbnail' => $employee->photo_thumbnail,
                'branch_name' => $employee->branch?->name,
                'company_name' => $employee->branch?->company?->name,
                'company_logo' => $employee->branch?->company?->logo_thumbnail,
                // Para que mark.js pueda filtrar "Inicio de descanso" localmente sin red —
                // ver AttendanceCalculator::hasScheduledBreak(). Recalculado en cada
                // heartbeat (cada ~90s mientras hay conexión): puede quedar desactualizado
                // si el horario cambia mientras el dispositivo está offline por más tiempo
                // que eso, hasta el próximo heartbeat exitoso.
                'has_scheduled_break' => AttendanceCalculator::hasScheduledBreak($employee, now(config('app.timezone'))),
                // Salida del horario de hoy y la fecha a la que corresponde: el celular la usa sin conexión para no ofrecer
                // «Inicio descanso» cerca de la hora de salida, e ignora el dato si ya no es de hoy.
                'expected_check_out' => $this->expectedCheckOut($employee),
                'expected_shift_date' => now(config('app.timezone'))->toDateString(),
            ],
        ]);
    }

    /** Hora de salida esperada de hoy ("HH:MM"), o null si el empleado no tiene turno ese día. */
    private function expectedCheckOut(Employee $employee): ?string
    {
        $time = AttendanceCalculator::resolveShiftDataFor($employee, now(config('app.timezone'))->startOfDay())['check_out'] ?? null;

        return $time ? substr((string) $time, 0, 5) : null;
    }
}
