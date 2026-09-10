<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\MobileLinkThrottledNotification;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Vinculación del dispositivo personal del empleado para la marcación offline
 * vía PWA. A diferencia de `TerminalSetupController` (enlace de un solo uso
 * generado por un admin), acá el propio empleado se identifica con CI +
 * fecha de nacimiento — ese par de datos funciona como credencial para
 * "reclamar" el dispositivo, no como control de acceso completo: la
 * marcación en sí sigue requiriendo un match facial exitoso contra el
 * descriptor cacheado (segundo factor real).
 *
 * Vincular un dispositivo nuevo revoca automáticamente el anterior — un solo
 * dispositivo vinculado a la vez por empleado (`Employee::claimMobileToken()`).
 */
class MobileLinkController extends Controller
{
    /** Muestra el formulario de vinculación (CI + fecha de nacimiento). */
    public function show(): View
    {
        return view('attendances.device-link');
    }

    /**
     * Valida CI + fecha de nacimiento y emite el token Sanctum del empleado.
     * El mensaje de error es deliberadamente genérico (no distingue "CI no
     * existe" de "fecha incorrecta") para no facilitar enumeración de CIs
     * válidos — la ruta ya está limitada por `throttle:5,1` + `throttle:15,1440`
     * (más estricto que otros enlaces públicos del proyecto: CI+fecha de
     * nacimiento es una credencial de baja entropía).
     */
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ci' => ['required', 'string', 'max:20'],
            'birth_date' => ['required', 'date'],
            'device_model_hint' => ['nullable', 'string', 'max:100'],
        ]);

        $employee = Employee::query()
            ->where('ci', $data['ci'])
            ->whereDate('birth_date', $data['birth_date'])
            ->where('status', 'active')
            ->whereNotNull('face_descriptor')
            ->first();

        if (! $employee) {
            // Se loguea el CI intentado (no la fecha) para poder correlacionar en logs
            // si un mismo CI está siendo atacado desde distintas IPs, o viceversa.
            Log::warning('Intento de vinculación de dispositivo con datos inválidos', [
                'ip' => $request->ip(),
                'ci' => $data['ci'],
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'CI o fecha de nacimiento incorrectos, o el empleado no está habilitado para marcar desde su dispositivo.',
            ], 422);
        }

        $plainTextToken = $employee->claimMobileToken($request->userAgent(), $data['device_model_hint'] ?? null);

        Log::info("Dispositivo vinculado para el empleado #{$employee->id} ({$employee->first_name} {$employee->last_name})", [
            'employee_id' => $employee->id,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'ok' => true,
            'token' => $plainTextToken,
            'employee' => [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'ci' => $employee->ci,
                'face_descriptor' => $employee->face_descriptor,
                'company_name' => $employee->branch?->company?->name,
                'company_logo' => $employee->branch?->company?->logo_thumbnail,
            ],
        ]);
    }

    /**
     * Traduce el 429 genérico de Laravel ("Too Many Attempts.") a un mensaje
     * en español con el tiempo de espera real, para la ruta de vinculación
     * de dispositivo (`throttle:5,1,device-link-minute` +
     * `throttle:15,1440,device-link-day` en routes/web.php). Registrado
     * desde `bootstrap/app.php`, scopeado a esta única ruta — el resto de
     * los enlaces públicos del proyecto (registro-facial, terminal/setup)
     * quedan con el comportamiento por defecto, fuera de alcance acá.
     *
     * El header `X-RateLimit-Limit` distingue qué throttle disparó la
     * excepción sin necesitar adivinar la clave interna del rate limiter:
     * 5 = límite por minuto (probablemente un usuario tipeando mal),
     * 15 = límite diario (señal más fuerte de empleado trabado o fuerza
     * bruta — amerita avisar a los admins).
     */
    public static function throttledResponse(ThrottleRequestsException $exception, Request $request): JsonResponse
    {
        $headers = $exception->getHeaders();
        $retryAfterSeconds = (int) ($headers['Retry-After'] ?? 60);
        $limit = (int) ($headers['X-RateLimit-Limit'] ?? 0);

        if ($limit === 15) {
            static::notifyAdminsOfDailyLimitOnce($request->ip(), $request->input('ci'));

            return response()->json([
                'ok' => false,
                'message' => 'Alcanzó el límite de intentos por hoy. Contacte a RRHH para vincular su dispositivo.',
            ], 429, $headers);
        }

        $minutes = max(1, (int) ceil($retryAfterSeconds / 60));

        return response()->json([
            'ok' => false,
            'message' => "Demasiados intentos. Espere {$minutes} minuto".($minutes === 1 ? '' : 's').' e intente de nuevo.',
        ], 429, $headers);
    }

    /**
     * Notifica a los admins como máximo una vez por IP por día calendario
     * cuando se agota el límite diario — sin este flag, la notificación se
     * dispararía en cada uno de los intentos bloqueados posteriores (el
     * contador del rate limiter queda fijo en el máximo el resto del día).
     */
    private static function notifyAdminsOfDailyLimitOnce(string $ip, ?string $lastCiAttempted): void
    {
        $notifiedKey = 'device-link-day-notified:'.$ip.':'.now()->format('Y-m-d');

        if (Cache::has($notifiedKey)) {
            return;
        }

        Cache::put($notifiedKey, true, now()->endOfDay());

        // Un fallo al notificar (ej. mailer mal configurado) no debe convertirse en un 500
        // genérico para un empleado que ya está siendo bloqueado por el rate limiter — debe
        // seguir viendo el mensaje 429 "límite alcanzado", no un error interno sin relación.
        try {
            User::all()->each(fn (User $user) => $user->notify(new MobileLinkThrottledNotification($ip, $lastCiAttempted)));
        } catch (\Throwable $e) {
            Log::warning("No se pudo notificar el límite diario de vinculación alcanzado (IP {$ip}): {$e->getMessage()}");
        }
    }
}
