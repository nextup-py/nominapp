<?php

namespace App\Http\Controllers;

use App\Models\FaceEnrollment;
use App\Models\PrivacyConsent;
use App\Models\User;
use App\Notifications\FaceEnrollmentPendingApprovalNotification;
use App\Rules\FaceDescriptor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FaceEnrollmentController extends Controller
{
    /**
     * Muestra la vista de auto-registro facial para el empleado.
     */
    public function show(string $token): View
    {
        // Buscar el registro de inscripción facial por token
        $enrollment = FaceEnrollment::where('token', $token)->firstOrFail();

        // Verificar si el registro ha expirado o no está pendiente de captura
        if ($enrollment->isExpired() && $enrollment->isPendingCapture()) {
            return view('enrollments.expired');
        }

        // Si el registro no está pendiente de captura, mostrar una vista indicando que ya se ha enviado una solicitud
        if (! $enrollment->isPendingCapture()) {
            return view('enrollments.already-submitted');
        }

        // Obtener el empleado asociado al registro de inscripción facial para mostrar su información en la vista
        $employee = $enrollment->employee;

        $privacyNotice = $employee->company?->privacyNoticePayload();

        return view('enrollments.capture-face', compact('enrollment', 'employee', 'privacyNotice'));
    }

    /**
     * Almacena el descriptor facial capturado por el empleado.
     */
    public function store(Request $request, string $token): JsonResponse
    {
        try {
            // Validar que el token exista y esté en estado pendiente de captura
            $enrollment = FaceEnrollment::where('token', $token)->first();

            // Verificar si el registro existe, no ha expirado y está pendiente de captura
            if (! $enrollment || $enrollment->isExpired() || ! $enrollment->isPendingCapture()) {
                Log::warning('Intento de captura facial con token inválido o expirado', [
                    'token' => substr($token, 0, 8).'...',
                    'exists' => (bool) $enrollment,
                    'expired' => $enrollment?->isExpired(),
                    'status' => $enrollment?->status,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Enlace inválido o expirado.',
                ], 422);
            }

            // Validar el descriptor facial usando la regla personalizada
            $data = $request->validate([
                'face_descriptor' => ['required', new FaceDescriptor],
                'face_snapshot' => ['nullable', 'string'],
                'samples_count' => ['nullable', 'integer', 'min:1', 'max:255'],
                'face_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            ]);

            // Si la empresa cargó un aviso de privacidad, el empleado debe haber aceptado su versión vigente
            $company = $enrollment->employee?->company;
            $notice = $company?->privacyNoticePayload();
            if ($notice !== null && (int) $request->input('privacy_consent_version') !== $notice['version']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Debe aceptar el aviso de privacidad para continuar.',
                ], 422);
            }

            // El descriptor puede ser un array o una cadena JSON, manejar ambos casos
            $descriptor = is_string($data['face_descriptor'])
                ? json_decode($data['face_descriptor'], true)
                : $data['face_descriptor'];

            // Si el descriptor es una cadena JSON pero no se pudo decodificar, lanzar una excepción de validación
            if ($descriptor === null && is_string($data['face_descriptor'])) {
                throw ValidationException::withMessages([
                    'face_descriptor' => 'El descriptor facial contiene JSON inválido.',
                ]);
            }

            // Guardar snapshot del rostro si fue enviado
            $snapshotPath = null;
            if (! empty($data['face_snapshot'])) {
                $snapshotPath = $this->saveSnapshotFromBase64(
                    $data['face_snapshot'],
                    $enrollment->id
                );
            }

            // Actualizar el registro de inscripción con el descriptor facial y cambiar el estado a "pendiente de aprobación"
            $enrollment->update([
                'face_descriptor' => $descriptor,
                'snapshot_path' => $snapshotPath,
                'samples_count' => $data['samples_count'] ?? null,
                'face_score' => $data['face_score'] ?? null,
                'source' => 'self_enrollment',
                'status' => 'pending_approval',
                'captured_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 255),
            ]);

            if ($notice !== null) {
                PrivacyConsent::record($enrollment->employee, $company, PrivacyConsent::CONTEXT_FACE_ENROLLMENT, $notice, $request);
            }

            // Registrar el evento de captura facial para auditoría
            Log::info("Captura facial recibida — empleado ID {$enrollment->employee_id} (enrollment #{$enrollment->id}, pendiente de aprobación)", [
                'enrollment_id' => $enrollment->id,
                'employee_id' => $enrollment->employee_id,
            ]);

            // La captura ya quedó persistida arriba — un fallo al notificar (ej. mailer
            // mal configurado) no debe convertirse en un 500 que le diga al empleado que
            // falló cuando en realidad su rostro ya se guardó correctamente (mismo gotcha
            // corregido en Terminal::claimSanctumToken() / Employee::claimMobileToken()).
            try {
                User::all()->each(fn (User $user) => $user->notify(new FaceEnrollmentPendingApprovalNotification($enrollment)));
            } catch (\Throwable $e) {
                Log::warning("No se pudo notificar la captura facial pendiente de aprobación (enrollment #{$enrollment->id}): {$e->getMessage()}", [
                    'enrollment_id' => $enrollment->id,
                    'employee_id' => $enrollment->employee_id,
                ]);
            }

            // Retornar una respuesta JSON indicando éxito
            return response()->json([
                'success' => true,
                'message' => 'Rostro capturado correctamente. El administrador revisará su solicitud.',
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Registrar el error para diagnóstico
            Log::error('Error en captura facial (enrollment #{'.substr($token, 0, 8)."...}): {$e->getMessage()}", [
                'token' => substr($token, 0, 8).'...',
                'error' => $e->getMessage(),
            ]);

            // Retornar una respuesta JSON indicando error
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el registro facial. Intente nuevamente.',
            ], 500);
        }
    }

    /**
     * Decodifica un base64 de imagen y lo guarda en storage público.
     * Retorna la ruta relativa o null si falla.
     */
    private function saveSnapshotFromBase64(string $base64, int $enrollmentId): ?string
    {
        try {
            $data = preg_replace('/^data:image\/\w+;base64,/', '', $base64);
            $imageData = base64_decode($data);

            if ($imageData === false) {
                return null;
            }

            $path = sprintf('face-snapshots/%s/%d.jpg', now()->format('Y/m'), $enrollmentId);
            Storage::disk('public')->put($path, $imageData);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('No se pudo guardar el snapshot facial', [
                'enrollment_id' => $enrollmentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
