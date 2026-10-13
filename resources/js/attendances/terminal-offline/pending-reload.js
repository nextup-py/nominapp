/**
 * =============================================================================
 * CONFIRMACIÓN DIFERIDA DEL COMANDO "RECARGAR APP"
 * =============================================================================
 *
 * @fileoverview Confirmar "hecho" antes de recargar no dice si el terminal quedó
 * con la versión nueva (un caché puede servir la vieja), y si el envío de la
 * confirmación falla justo antes de recargar se pierde (el comando figura como
 * "no confirmó" aunque se haya ejecutado). Por eso `reload` guarda el comando
 * pendiente en IndexedDB y, en el primer heartbeat tras recargar, se confirma
 * comparando la versión que corre la página con la vigente del servidor.
 */

/** Clave de `terminal_meta` con el comando de recarga pendiente de confirmar. */
export const PENDING_RELOAD_KEY = 'pending_reload_command';

/** Pasado este tiempo el servidor ya dio el comando por vencido (ver `TerminalCommand::expireStale()`): se descarta. */
export const PENDING_RELOAD_MAX_AGE_MS = 30 * 60 * 1000;

/**
 * Guarda el comando de recarga para confirmarlo después de recargar.
 * @param {(key: string, value: any) => Promise<void>} setMeta
 * @param {number} id - id del comando en el servidor.
 * @returns {Promise<void>}
 */
export async function markPendingReload(setMeta, id) {
    await setMeta(PENDING_RELOAD_KEY, { id, at: Date.now() });
}

/**
 * Confirma el comando de recarga pendiente (si lo hay) según la versión que corre el terminal.
 * @param {object} options
 * @param {(key: string) => Promise<any>} options.getMeta
 * @param {(key: string, value: any) => Promise<void>} options.setMeta
 * @param {(ack: {id: number, status: 'done'|'failed', message?: string}) => void} options.ack
 * @param {string|null|undefined} options.currentVersion - versión que corre la página (`window.terminalData.app_version`).
 * @param {string|null|undefined} options.serverVersion - versión vigente informada por el heartbeat.
 * @returns {Promise<'done'|'failed'|null>} null si no había nada pendiente o ya venció.
 */
export async function settlePendingReload({ getMeta, setMeta, ack, currentVersion, serverVersion }) {
    const pending = await getMeta(PENDING_RELOAD_KEY);
    if (!pending?.id) return null;

    await setMeta(PENDING_RELOAD_KEY, null);

    if (Date.now() - (pending.at ?? 0) > PENDING_RELOAD_MAX_AGE_MS) return null;

    // 'dev' = servidor sin build de Vite: no hay versión real contra la cual comparar.
    const comparable = currentVersion && serverVersion && currentVersion !== 'dev' && serverVersion !== 'dev';
    if (comparable && currentVersion !== serverVersion) {
        ack({ id: pending.id, status: 'failed', message: 'Recargó pero sigue con la versión anterior de la app.' });

        return 'failed';
    }

    ack({ id: pending.id, status: 'done' });

    return 'done';
}
