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
    /** Muestra la pantalla de vinculación del terminal. */
    public function show(string $code, string $setupToken): View
    {
        $terminal = Terminal::where('code', $code)->first();

        if (! $terminal || ! $terminal->isSetupTokenValid($setupToken)) {
            return view('attendances.terminal-setup-invalid');
        }

        return view('attendances.terminal-setup', compact('terminal', 'setupToken'));
    }

    /**
     * Reclama el enlace y emite el token Sanctum del terminal. Un solo uso.
     *
     * El check-y-consumo del setup_token corre bajo un `lockForUpdate()` en
     * una transacción propia — sin esto, dos reclamos casi simultáneos del
     * mismo enlace (ej. el QR escaneado dos veces por error, o un doble tap)
     * podían pasar `isSetupTokenValid()` ambos antes de que ninguno hubiera
     * guardado el null-out todavía, dejando dos tokens Sanctum válidos por un
     * instante para el mismo terminal sin que ningún cliente supiera cuál
     * quedó realmente vivo (el segundo `claimSanctumToken()` revoca al
     * primero al crear el suyo). El lock serializa: el segundo reclamo espera
     * a que el primero confirme el null-out y entonces `isSetupTokenValid()`
     * ya lo rechaza correctamente con el 422 de siempre.
     */
    public function claim(Request $request, string $code, string $setupToken): JsonResponse
    {
        $terminal = DB::transaction(function () use ($code, $setupToken) {
            $terminal = Terminal::where('code', $code)->lockForUpdate()->first();

            if (! $terminal || ! $terminal->isSetupTokenValid($setupToken)) {
                return null;
            }

            // Consume el setup_token ya dentro del lock — claimSanctumToken() más
            // abajo (fuera del lock) vuelve a guardar setup_token=null (ya lo está,
            // no-op) junto con el resto de su trabajo. No hace falta duplicar esa
            // lógica acá, solo cerrar la ventana de la carrera.
            $terminal->forceFill(['setup_token' => null, 'setup_token_expires_at' => null])->save();

            return $terminal;
        });

        if (! $terminal) {
            Log::warning("Intento de reclamo de setup token inválido o expirado para terminal '{$code}'", [
                'code' => $code,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Enlace de configuración inválido o expirado. Solicite uno nuevo desde el panel de administración.',
            ], 422);
        }

        $clientHintModel = $request->validate([
            'device_model_hint' => ['nullable', 'string', 'max:100'],
        ])['device_model_hint'] ?? null;

        $plainTextToken = $terminal->claimSanctumToken($request->userAgent(), $clientHintModel, $request->ip());

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
}
