<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta la API del celular personal cuando el empleado ya no puede marcar desde él (RR.HH. cambió el
 * permiso en el empleado, su sucursal, su empresa o la configuración general). Revoca el vínculo en el
 * momento; el cliente trata el 403 como token revocado y deja de intentar.
 */
class EnsureMobileMarkingAllowed
{
    /** @param  Closure(Request): (Response)  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->user();

        if ($employee instanceof Employee && ! $employee->canMarkFromMobile()) {
            $employee->revokeMobileToken();

            return response()->json([
                'ok' => false,
                'code' => 'mobile_not_allowed',
                'message' => 'Tu usuario ya no está habilitado para marcar desde el celular. Marcá desde el terminal o consultá con RRHH.',
            ], 403);
        }

        return $next($request);
    }
}
