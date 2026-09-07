<?php

namespace App\Observers;

use App\Models\Terminal;

/**
 * Revoca los tokens Sanctum del terminal al desactivarlo.
 *
 * Sin esto, "desactivar" un terminal desde el panel solo ocultaba la
 * pantalla pública del kiosco (`AttendanceFaceMarkController::terminalByCode()`)
 * — el token Sanctum ya emitido seguía siendo válido para sincronizar
 * empleados, recibir heartbeats y marcar asistencia vía la API, porque
 * ninguna de esas rutas validaba el `status` del terminal.
 */
class TerminalObserver
{
    public function updated(Terminal $terminal): void
    {
        if (! $terminal->isDirty('status')) {
            return;
        }

        if ($terminal->status === 'inactive') {
            $terminal->tokens()->delete();
        }
    }
}
