<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Terminal;
use App\Services\TerminalPairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints públicos de vinculación por código de emparejamiento (routes/api.php,
 * `v1/terminal-pairing`). No usan `auth:sanctum`: el dispositivo todavía no
 * tiene token. El secreto de polling viaja en el header `Authorization`
 * (nunca en la URL ni en la query, que quedarían en los access logs) y se
 * guarda solo como hash. Throttle por IP y por terminal/secreto (ver
 * AppServiceProvider::configureRateLimiting()).
 */
class TerminalPairingController extends Controller
{
    public function __construct(private readonly TerminalPairingService $pairing) {}

    /** Crea una solicitud de vinculación y devuelve el código a mostrar y el secreto de polling. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'terminal_code' => ['required', 'string', 'max:12'],
            'device_model_hint' => ['nullable', 'string', 'max:100'],
        ]);

        $terminal = Terminal::where('code', $data['terminal_code'])->first();

        if (! $terminal) {
            return response()->json(['ok' => false, 'code' => 'terminal_not_found', 'message' => 'El terminal no existe.'], 404);
        }

        if ($terminal->isInactive()) {
            return $this->inactiveResponse();
        }

        $result = $this->pairing->request($terminal, $request->ip(), $request->userAgent(), $data['device_model_hint'] ?? null);

        return response()->json([
            'ok' => true,
            'pairing_code' => $result['request']->code,
            'poll_secret' => $result['poll_secret'],
            'expires_at' => $result['request']->expires_at->toIso8601String(),
            'poll_interval_seconds' => 3,
        ], 201);
    }

    /** Estado de la solicitud; si fue aprobada, entrega el token Sanctum una sola vez. */
    public function status(Request $request): JsonResponse
    {
        $secret = $request->bearerToken();

        if (! $secret) {
            return response()->json(['ok' => false, 'status' => 'invalid', 'message' => 'Falta el secreto de la solicitud.'], 422);
        }

        $result = $this->pairing->poll($secret, $request->ip(), $request->userAgent());

        if ($result['status'] === 'terminal_inactive') {
            return $this->inactiveResponse();
        }

        return response()->json(['ok' => $result['status'] !== 'invalid', ...$result], $result['status'] === 'invalid' ? 404 : 200);
    }

    private function inactiveResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'terminal_inactive',
            'message' => 'Este terminal fue desactivado. Comuníquese con el administrador.',
        ], 403);
    }
}
