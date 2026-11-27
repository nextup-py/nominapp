/**
 * @fileoverview Decide cuándo pedir el motivo de las horas extra al marcar una salida (terminal y celular).
 *
 * El motivo se pide solo cuando la salida ya deja horas extra: la hora actual supera la salida del horario más la
 * tolerancia de horas extra de Configuración General. No bloquea la marcación: si la persona no elige a tiempo, la
 * salida se registra igual sin motivo (queda para RR.HH.). Con la lista de motivos y la hora de salida guardadas en el
 * dispositivo funciona sin conexión; sin alguna de ellas no se pregunta.
 */

const MINUTES_PER_DAY = 24 * 60;

/** Pasadas estas horas de la salida del horario ya no se trata como horas extra del mismo turno (otro turno u olvido). */
export const MAX_OVERTIME_WINDOW_MINUTES = 12 * 60;

/** Segundos que espera el selector de motivo antes de registrar la salida sin motivo. */
export const OVERTIME_REASON_TIMEOUT_SECONDS = 20;

/**
 * "HH:MM" a minutos desde la medianoche; null si no es una hora válida.
 * @param {string|null|undefined} value
 * @returns {number|null}
 */
function toMinutes(value) {
    const match = /^(\d{1,2}):(\d{2})/.exec(value ?? '');
    if (!match) return null;

    const hours = Number(match[1]);
    const minutes = Number(match[2]);

    return hours < 24 && minutes < 60 ? hours * 60 + minutes : null;
}

/**
 * Convierte el mapa clave → etiqueta que llega del servidor en una lista para dibujar los botones.
 * @param {Record<string, string>|null|undefined} stored
 * @returns {Array<{key: string, label: string}>}
 */
export function normalizeOvertimeReasons(stored) {
    if (!stored || typeof stored !== 'object') return [];

    return Object.entries(stored)
        .filter(([key, label]) => key && typeof label === 'string' && label.trim() !== '')
        .map(([key, label]) => ({ key, label }));
}

/**
 * Minutos que pasaron desde la hora de salida del horario (negativo si todavía falta), con el turno que cruza la
 * medianoche resuelto por el camino más corto de hasta 12 horas hacia atrás.
 * @param {number} current - minutos desde la medianoche de la hora actual.
 * @param {number} end - minutos desde la medianoche de la hora de salida.
 * @returns {number}
 */
function minutesPastShiftEnd(current, end) {
    let diff = (current - end) % MINUTES_PER_DAY;
    if (diff > MINUTES_PER_DAY / 2) diff -= MINUTES_PER_DAY;
    if (diff < -MINUTES_PER_DAY / 2) diff += MINUTES_PER_DAY;

    return diff;
}

/**
 * Indica si hay que pedir el motivo de las horas extra al marcar esta salida.
 * @param {object} options
 * @param {string} options.eventType - Tipo de evento que se va a marcar.
 * @param {string|null|undefined} options.expectedCheckOut - Salida del horario de hoy ("HH:MM") o null.
 * @param {number|null|undefined} options.toleranceMinutes - Tolerancia de horas extra (minutos); sin dato se asume 0.
 * @param {Array<{key: string, label: string}>} options.reasons - Motivos disponibles.
 * @param {Date} [options.now]
 * @returns {boolean}
 */
export function shouldAskOvertimeReason({ eventType, expectedCheckOut, toleranceMinutes, reasons, now = new Date() }) {
    if (eventType !== 'check_out' || !Array.isArray(reasons) || reasons.length === 0) return false;

    const end = toMinutes(expectedCheckOut);
    if (end === null) return false;

    const tolerance = Number.isFinite(Number(toleranceMinutes)) ? Math.max(0, Number(toleranceMinutes)) : 0;
    const past = minutesPastShiftEnd(now.getHours() * 60 + now.getMinutes(), end);

    return past > tolerance && past <= MAX_OVERTIME_WINDOW_MINUTES;
}
