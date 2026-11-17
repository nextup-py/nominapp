// resources/js/attendances/terminal/end-of-shift.js
/**
 * @fileoverview Quita «Inicio descanso» de lo que se ofrece cuando el turno está por terminar o ya terminó.
 *
 * En Macro (nov. 2026) buena parte de las jornadas sin salida terminaban en un «Inicio descanso» marcado a la hora de
 * cierre: quien se iba tocaba el botón equivocado y la salida real nunca quedaba registrada. Cerca del fin del turno
 * un descanso casi nunca tiene sentido, así que solo se ofrece «Salida» (que el terminal confirma con un botón).
 *
 * Las horas son las del servidor ("HH:MM") que el terminal guarda con el estado del empleado, así funciona sin conexión.
 * Sin horario ese día no hay con qué comparar y se ofrece lo mismo que antes.
 */

/** Minutos antes de la hora de salida a partir de los cuales ya no se ofrece iniciar un descanso. */
export const NEAR_SHIFT_END_MINUTES = 60;

/** Minutos después de la hora de salida durante los que se sigue sin ofrecerlo (quien se retrasa al salir). */
export const AFTER_SHIFT_END_MINUTES = 240;

const MINUTES_PER_DAY = 24 * 60;

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
 * Minutos que faltan para la hora de salida (negativo si ya pasó), tomando el camino corto alrededor de la medianoche.
 * @param {number} current - minutos desde la medianoche de la hora actual.
 * @param {number} end - minutos desde la medianoche de la hora de salida.
 * @returns {number}
 */
function minutesUntil(current, end) {
    let diff = (end - current) % MINUTES_PER_DAY;
    if (diff > MINUTES_PER_DAY / 2) diff -= MINUTES_PER_DAY;
    if (diff < -MINUTES_PER_DAY / 2) diff += MINUTES_PER_DAY;

    return diff;
}

/**
 * Devuelve lo permitido sin «Inicio descanso» cuando el turno está por terminar o ya terminó y la salida es una de las
 * opciones (si no lo es, no se toca: no se deja al empleado sin nada que marcar).
 * @param {string[]} allowedEvents
 * @param {string|null|undefined} expectedCheckOut - fin del turno de hoy ("HH:MM") o null.
 * @param {Date} [now]
 * @returns {string[]}
 */
export function withoutBreakStartNearShiftEnd(allowedEvents, expectedCheckOut, now = new Date()) {
    if (!allowedEvents.includes('break_start') || !allowedEvents.includes('check_out')) return allowedEvents;

    const end = toMinutes(expectedCheckOut);
    if (end === null) return allowedEvents;

    const untilEnd = minutesUntil(now.getHours() * 60 + now.getMinutes(), end);
    const nearEnd = untilEnd <= NEAR_SHIFT_END_MINUTES && untilEnd >= -AFTER_SHIFT_END_MINUTES;

    return nearEnd ? allowedEvents.filter((type) => type !== 'break_start') : allowedEvents;
}
