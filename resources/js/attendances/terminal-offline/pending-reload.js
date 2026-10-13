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

/** Identifica esta carga de página: cambia con cada recarga, así el pendiente solo lo resuelve la página nueva. */
export const PAGE_ID = Math.random().toString(36).slice(2) + Date.now().toString(36);

/** Pasado este tiempo el servidor ya dio el comando por vencido (ver `TerminalCommand::expireStale()`): se descarta. */
export const PENDING_RELOAD_MAX_AGE_MS = 30 * 60 * 1000;

/**
 * Guarda el comando de recarga para confirmarlo después de recargar.
 * @param {(key: string, value: any) => Promise<void>} setMeta
 * @param {number} id - id del comando en el servidor.
 * @param {string} [pageId] - identificador de la página que guarda el pendiente.
 * @returns {Promise<void>}
 */
export async function markPendingReload(setMeta, id, pageId = PAGE_ID) {
    await setMeta(PENDING_RELOAD_KEY, { id, at: Date.now(), pageId });
}

/**
 * Confirma el comando de recarga pendiente (si lo hay) según la versión que corre el terminal.
 * @param {object} options
 * @param {(key: string) => Promise<any>} options.getMeta
 * @param {(key: string, value: any) => Promise<void>} options.setMeta
 * @param {(ack: {id: number, status: 'done'|'failed', message?: string}) => void} options.ack
 * @param {string|null|undefined} options.currentVersion - versión que corre la página (`window.terminalData.app_version`).
 * @param {string|null|undefined} options.serverVersion - versión vigente informada por el heartbeat.
 * @param {string} [options.pageId] - identificador de esta carga de página.
 * @returns {Promise<'done'|'failed'|null>} null si no había nada pendiente o ya venció.
 */
export async function settlePendingReload({ getMeta, setMeta, ack, currentVersion, serverVersion, pageId = PAGE_ID }) {
    const pending = await getMeta(PENDING_RELOAD_KEY);
    if (!pending?.id) return null;

    // El latido que se envía justo antes de recargar corre en la misma página que guardó el pendiente: no es la
    // recarga todavía, y consumirlo ahí perdería la confirmación (queda en memoria y la recarga la descarta).
    if (pending.pageId === pageId) return null;

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
