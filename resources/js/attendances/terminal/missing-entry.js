// resources/js/attendances/terminal/missing-entry.js
/**
 * @fileoverview Decide cuándo el terminal pregunta «¿Entrada o salida?» en vez de registrar sola la entrada.
 *
 * Con el día vacío solo se ofrece «Entrada» y el terminal la registra sola. Si el empleado olvidó marcar al llegar y
 * ahora se va, su salida quedaba como una «entrada» a la hora de salida (y la jornada abierta para siempre). Aquí se
 * detecta ese momento: la hora actual está más cerca del fin del turno que de su inicio. Quien llega tarde de verdad
 * sigue marcando entrada sola (a media jornada hay el mismo margen a ambos lados).
 *
 * Sin horario ese día no hay con qué comparar y se mantiene el comportamiento de siempre. Las horas son las del
 * servidor ("HH:MM") que el terminal guarda con el estado del empleado, así funciona sin conexión.
 */

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
 * Distancia entre dos instantes del día en minutos, tomando el camino corto (un turno que cruza la medianoche
 * queda a un lado u otro de ella).
 * @param {number} a
 * @param {number} b
 * @returns {number}
 */
function circularDistance(a, b) {
    const diff = Math.abs(a - b);

    return Math.min(diff, MINUTES_PER_DAY - diff);
}

/**
 * ¿Hay que preguntar «¿Entrada o salida?»? Solo con el día vacío (lo único permitido es la entrada y no hay una
 * jornada de ayer abierta) y cuando la hora actual está más cerca del fin del turno que de su inicio.
 * @param {object} state
 * @param {string[]} state.allowedEvents - lo que el servidor/estado local permite marcar ahora.
 * @param {string|null} state.lastEvent - último evento considerado (null = día vacío).
 * @param {string|null} state.expectedCheckIn - inicio del turno de hoy ("HH:MM") o null.
 * @param {string|null} state.expectedCheckOut - fin del turno de hoy ("HH:MM") o null.
 * @param {Date} [state.now]
 * @returns {boolean}
 */
export function shouldAskEntryOrExit({ allowedEvents, lastEvent, expectedCheckIn, expectedCheckOut, now = new Date() }) {
    if (lastEvent !== null || allowedEvents.length !== 1 || allowedEvents[0] !== 'check_in') return false;

    const start = toMinutes(expectedCheckIn);
    const end = toMinutes(expectedCheckOut);
    if (start === null || end === null) return false;

    const current = now.getHours() * 60 + now.getMinutes();

    return circularDistance(current, end) < circularDistance(current, start);
}

/** Opciones que se ofrecen cuando se pregunta: la entrada normal o la salida de quien no marcó al llegar. */
export const ENTRY_OR_EXIT_OPTIONS = ['check_in', 'check_out'];
