<?php

namespace App\Http\Controllers;

use App\Models\Terminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Provisión de terminales para la marcación offline vía PWA. El admin genera
 * un enlace de configuración de un solo uso desde TerminalResource; el
 * terminal lo visita una vez, online, durante la instalación física, y recibe
 * a cambio un token Sanctum (ability `terminal:sync`) que usará contra
 * routes/api.php de ahí en adelante — incluso tras largos períodos offline,
 * ya que no depende de la sesión de Laravel (que expira a los 120 minutos).
 */
class TerminalSetupController extends Controller
{
    /**
     * Muestra la pantalla de vinculación del terminal. El token del enlace viaja
     * en el fragmento (`#`) de la URL — el navegador nunca lo envía al servidor
     * en este GET, así que no queda en access logs ni en el header Referer — y
     * el JS lo manda por POST al reclamar. Por eso esta pantalla no valida nada:
     * un enlace inválido o vencido se descubre al reclamar (ver `claim()`).
     */
    public function show(string $code): View
    {
        $terminal = Terminal::where('code', $code)->first();

        if (! $terminal) {
            return view('attendances.terminal-setup-invalid', ['reason' => 'invalid']);
        }

        return view('attendances.terminal-setup', compact('terminal'));
    }

    /**
     * Enlaces del formato anterior (token en la ruta): ya no se aceptan — el
     * token quedaría en los access logs. Se explica en vez de dar un 404 mudo.
     */
    public function legacy(string $code, string $setupToken): View
    {
        return view('attendances.terminal-setup-invalid', ['reason' => 'legacy']);
    }

    /**
     * Reclama el enlace y emite el token Sanctum del terminal. Un solo uso.
     *
     * El check-y-consumo del setup_token corre bajo un `lockForUpdate()` en
     * una transacción propia — sin esto, dos reclamos casi simultáneos del
     * mismo enlace (ej. el QR escaneado dos veces por error, o un doble tap)
     * podían pasar la validación ambos antes de que ninguno hubiera marcado
     * el consumo, emitiendo dos tokens Sanctum válidos por un instante. El
     * lock serializa: el segundo reclamo espera a que el primero confirme y
     * entonces ve el enlace como `consumed`.
     *
     * La respuesta de error incluye `reason` (`expired`, `consumed`, `invalid`)
     * para que la pantalla diga qué pasó; el estado se informa solo si el token
     * coincide con el enlace del terminal (no revela nada a quien no lo tiene).
     */
    public function claim(Request $request, string $code): JsonResponse
    {
        $input = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'device_model_hint' => ['nullable', 'string', 'max:100'],
        ]);

        $state = 'invalid';

        $terminal = DB::transaction(function () use ($code, $input, &$state) {
            $terminal = Terminal::where('code', $code)->lockForUpdate()->first();

            if (! $terminal) {
                return null;
            }

            $state = $terminal->setupTokenState($input['token']);

            if ($state !== 'valid') {
                return null;
            }

            $terminal->forceFill(['setup_token_consumed_at' => now()])->save();

            return $terminal;
        });

        if (! $terminal) {
            Log::warning("Intento de reclamo de setup token no válido ({$state}) para terminal '{$code}'", [
                'code' => $code,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'ok' => false,
                'reason' => $state,
                'message' => static::failureMessage($state),
            ], 422);
        }

        $plainTextToken = $terminal->claimSanctumToken($request->userAgent(), $input['device_model_hint'] ?? null, $request->ip());

        Log::info("Terminal '{$terminal->code}' ({$terminal->name}) provisionado para sincronización offline", [
            'terminal_id' => $terminal->id,
            'branch_id' => $terminal->branch_id,
        ]);

        return response()->json([
            'ok' => true,
            'token' => $plainTextToken,
            'terminal' => [
                'id' => $terminal->id,
                'code' => $terminal->code,
                'name' => $terminal->name,
                'branch_id' => $terminal->branch_id,
            ],
        ]);
    }

    /** Mensaje para el usuario según el motivo por el que no se pudo reclamar el enlace. */
    public static function failureMessage(string $reason): string
    {
        return match ($reason) {
            'expired' => 'Este enlace de configuración venció. Pedí uno nuevo al administrador.',
            'consumed' => 'Este enlace de configuración ya se usó. Si necesitás vincular otro dispositivo, pedí un enlace nuevo al administrador.',
            default => 'Enlace de configuración inválido. Pedí uno nuevo al administrador.',
        };
    }
}
