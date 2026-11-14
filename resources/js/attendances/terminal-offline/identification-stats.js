/**
 * =============================================================================
 * TIEMPO DE IDENTIFICACIÓN DEL TERMINAL
 * =============================================================================
 *
 * @fileoverview Mide cuánto tarda este equipo en reconocer a una persona (desde que se detecta el rostro hasta que
 * el matching local devuelve un empleado) y guarda las últimas muestras en memoria para informarlas en el heartbeat.
 * Es lo único que el servidor no puede ver: el reconocimiento pasa por completo en el navegador, y un equipo sin
 * aceleración gráfica tarda segundos de más sin dejar ninguna huella. Solo cuentan las identificaciones exitosas
 * (un rostro que no se reconoce no es una muestra representativa del equipo) y las muestras no persisten: si el
 * navegador se recarga, empiezan de cero.
 */

/** Cantidad de identificaciones recientes que se conservan. */
export const MAX_SAMPLES = 20;

/** Un valor por encima de esto es un error de medición (suspensión del equipo, pestaña congelada) y se descarta. */
export const MAX_PLAUSIBLE_MS = 120_000;

/** @type {number[]} */
let samples = [];

/**
 * Valor en la posición `fraction` (0–1) de una lista ya ordenada, por rango más cercano.
 * @param {number[]} sorted
 * @param {number} fraction
 * @returns {number}
 */
function percentile(sorted, fraction) {
    const index = Math.min(sorted.length - 1, Math.max(0, Math.ceil(sorted.length * fraction) - 1));

    return sorted[index];
}

/**
 * Registra cuánto tardó una identificación exitosa. Ignora valores no numéricos, negativos o irreales.
 * @param {number} durationMs
 * @returns {void}
 */
export function recordIdentification(durationMs) {
    if (!Number.isFinite(durationMs) || durationMs < 0 || durationMs > MAX_PLAUSIBLE_MS) return;

    samples.push(Math.round(durationMs));
    if (samples.length > MAX_SAMPLES) samples = samples.slice(-MAX_SAMPLES);
}

/**
 * Resumen de las muestras recientes, o null si todavía no hay ninguna.
 * @returns {{samples: number, median_ms: number, p90_ms: number}|null}
 */
export function getIdentificationStats() {
    if (samples.length === 0) return null;

    const sorted = [...samples].sort((a, b) => a - b);

    return {
        samples: sorted.length,
        median_ms: percentile(sorted, 0.5),
        p90_ms: percentile(sorted, 0.9),
    };
}

/**
 * Borra las muestras (solo para tests).
 * @returns {void}
 */
export function resetIdentificationStats() {
    samples = [];
}
