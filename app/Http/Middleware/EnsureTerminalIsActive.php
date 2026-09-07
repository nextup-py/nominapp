<?php

namespace App\Http\Middleware;

use App\Models\Terminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso a la API de sincronización del terminal cuando su
 * registro está `inactive`. Defensa en profundidad: `TerminalObserver` ya
 * revoca los tokens Sanctum al desactivar un terminal desde el panel, pero
 * este chequeo cubre cualquier caso no contemplado por esa vía (ej. un
 * token que sobreviva a un flujo de reactivación futuro).
 */
class EnsureTerminalIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $terminal = $request->user();

        if ($terminal instanceof Terminal && $terminal->isInactive()) {
            abort(403, 'Este terminal fue desactivado.');
        }

        return $next($request);
    }
}
