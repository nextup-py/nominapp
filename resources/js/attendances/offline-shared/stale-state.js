/**
 * @fileoverview Estado de marcación cacheado que ya no vale: una salida de otro día.
 *
 * Sin conexión, el terminal y el celular resuelven el estado del empleado con el último que el servidor
 * confirmó. Una jornada cerrada (`check_out`) solo bloquea la entrada de ese mismo día: al día siguiente el
 * empleado empieza de cero. Sin este descarte, un equipo sin conexión por la mañana mostraba «Hasta mañana»
 * a quien se había ido ayer.
 */

/**
 * Fecha local (YYYY-MM-DD) de un instante ISO, o null si no es una fecha válida.
 * @param {string|null|undefined} iso
 * @returns {string|null}
 */
export function localDateOfInstant(iso) {
    if (!iso) return null;

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return null;

    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * Indica si el estado cacheado es una salida que NO es de hoy y por eso no debe contarse: la fecha sale de la hora
 * de la última marcación (`last_event_at`) y, si el caché es anterior a ese dato, de la fecha de la última consulta
 * en línea (`shift_date`). Sin ninguna fecha se descarta: lo peor es ofrecer una entrada de segundo turno, que el
 * servidor valida igual al sincronizar.
 * @param {{last_event?: string|null, last_event_at?: string|null, shift_date?: string|null}|undefined} cached
 * @param {string} today - Fecha local de hoy (YYYY-MM-DD).
 * @returns {boolean}
 */
export function isClosedStateFromAnotherDay(cached, today) {
    if (cached?.last_event !== 'check_out') return false;

    const eventDate = localDateOfInstant(cached.last_event_at) ?? cached.shift_date ?? null;

    return eventDate === null || eventDate !== today;
}
