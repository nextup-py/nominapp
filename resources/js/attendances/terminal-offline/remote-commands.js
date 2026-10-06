/**
 * =============================================================================
 * COMANDOS REMOTOS DEL TERMINAL (Fase 3)
 * =============================================================================
 *
 * @fileoverview Ejecuta los comandos que el servidor entrega en la respuesta del
 * heartbeat (`force_sync`, `reload`, `clear_cache`, `report`) y confirma cada uno
 * (`done`/`failed`) en el siguiente heartbeat. Las dependencias (sync, recarga,
 * limpieza, confirmación) se inyectan para poder probarlo sin navegador.
 *
 * Reglas de seguridad:
 * - Solo se ejecuta una lista blanca de comandos; cualquier otro se confirma como `failed`.
 * - `reload` y `clear_cache` esperan a que el terminal esté en reposo (nadie marcando).
 * - `clear_cache` NUNCA borra la cola de marcaciones ni el token (ver `clearRebuildableCaches()`).
 */

/** Comandos que esta versión de la app sabe ejecutar. */
export const SUPPORTED_COMMANDS = ['force_sync', 'reload', 'clear_cache', 'report'];

/**
 * Ejecuta una tanda de comandos remotos y encola su confirmación.
 * @param {Array<{id: number, command: string}>} commands
 * @param {{
 *   forceSync: () => Promise<void>,
 *   clearCache: () => Promise<void>,
 *   waitForIdle: () => Promise<boolean>,
 *   ack: (ack: {id: number, status: 'done'|'failed', message?: string}) => void,
 *   flushAcks: () => Promise<void>,
 *   reload: () => void,
 * }} deps
 * @returns {Promise<void>}
 */
export async function runRemoteCommands(commands, deps) {
    let needsReload = false;
    let needsFlush = false;

    for (const { id, command } of commands) {
        try {
            if (!SUPPORTED_COMMANDS.includes(command)) {
                throw new Error('Comando no soportado por esta versión de la app.');
            }

            if (command === 'force_sync') {
                await deps.forceSync();
            } else if (command === 'report') {
                needsFlush = true;
            } else {
                if (!(await deps.waitForIdle())) {
                    throw new Error('El terminal estuvo ocupado (marcación en curso) y no se ejecutó.');
                }
                if (command === 'clear_cache') await deps.clearCache();
                needsReload = true;
            }

            deps.ack({ id, status: 'done' });
        } catch (error) {
            deps.ack({ id, status: 'failed', message: String(error?.message ?? error).slice(0, 255) });
        }
    }

    if (needsFlush || needsReload) {
        // La confirmación tiene que llegar al servidor ANTES de recargar la página.
        try {
            await deps.flushAcks();
        } catch {
            // Si falla, el comando quedará sin confirmar y el servidor lo marcará "sin confirmación".
        }
    }

    if (needsReload) deps.reload();
}
