/**
 * =============================================================================
 * SELECTION-GUARD.JS — CONFIRMACIÓN Y TIEMPO DE ESPERA DE LA MARCACIÓN
 * =============================================================================
 *
 * @fileoverview Evita las marcaciones involuntarias del terminal compartido:
 *  - Decide qué eventos piden una confirmación explícita (salida y descansos).
 *    La entrada sigue registrándose sola: es lo que la persona hace al llegar.
 *  - Temporizador con cuenta regresiva para la pantalla de selección/confirmación:
 *    si nadie toca el botón, vuelve a la cámara sin registrar nada.
 *  - Registro de empleados «descartados» (cancelaron o dejaron vencer la pantalla):
 *    mientras siguen frente a la cámara no se vuelve a abrir su pantalla en bucle.
 *
 * Sin dependencias del DOM ni de la cámara, para probarlo de forma aislada.
 */

/** Segundos que espera la pantalla de selección/confirmación antes de volver a la cámara. */
export const DEFAULT_SELECTION_TIMEOUT_SECONDS = 15;

/** Cuánto se ignora a quien dejó vencer la pantalla (se fue o pasó de largo). */
export const DISMISS_AFTER_TIMEOUT_MS = 30_000;

/** Cuánto se ignora a quien tocó «Cancelar» a propósito (evita reabrir al instante). */
export const DISMISS_AFTER_CANCEL_MS = 5_000;

/** Eventos que, si son la única opción, piden confirmación en vez de registrarse solos. */
const EVENTS_REQUIRING_CONFIRMATION = ['check_out', 'break_start', 'break_end'];

/**
 * Indica si un evento que sería la única opción debe confirmarse con un toque.
 * @param {string} eventType - Tipo de evento permitido (`check_in`, `check_out`, `break_start`, `break_end`).
 * @param {boolean|null|undefined} enabled - Configuración del servidor; sin dato se asume activada.
 * @returns {boolean}
 */
export function shouldConfirmEvent(eventType, enabled) {
    if (enabled === false) return false;
    return EVENTS_REQUIRING_CONFIRMATION.includes(eventType);
}

/**
 * Normaliza los segundos de espera recibidos del servidor.
 * @param {*} stored - Valor guardado en la metadata del terminal (puede ser null o texto).
 * @returns {number} Segundos de espera; 0 significa sin tiempo límite.
 */
export function normalizeSelectionTimeout(stored) {
    const value = Number(stored);
    if (stored === null || stored === undefined || stored === '' || !Number.isFinite(value) || value < 0) {
        return DEFAULT_SELECTION_TIMEOUT_SECONDS;
    }
    return Math.floor(value);
}

/** @type {Map<number|string, number>} Empleado → instante (ms) hasta el que se ignora. */
const dismissed = new Map();

/**
 * Ignora a un empleado durante un tiempo para que su pantalla no se reabra en bucle.
 * @param {number|string} employeeId
 * @param {number} ms - Duración del descarte.
 * @param {number} [now] - Instante actual (inyectable para pruebas).
 */
export function dismissEmployee(employeeId, ms, now = Date.now()) {
    dismissed.set(employeeId, now + ms);
}

/**
 * Indica si el empleado fue descartado y todavía no pasó el tiempo.
 * @param {number|string} employeeId
 * @param {number} [now] - Instante actual (inyectable para pruebas).
 * @returns {boolean}
 */
export function isEmployeeDismissed(employeeId, now = Date.now()) {
    const until = dismissed.get(employeeId);
    if (until === undefined) return false;
    if (now >= until) {
        dismissed.delete(employeeId);
        return false;
    }
    return true;
}

/** Olvida todos los descartes (al reiniciar el terminal). */
export function clearDismissed() {
    dismissed.clear();
}

/**
 * Crea el temporizador con cuenta regresiva de la pantalla de selección.
 * @param {object} options
 * @param {number} options.seconds - Segundos de espera; 0 o menos = no hay temporizador.
 * @param {(remaining: number) => void} [options.onTick] - Se llama al iniciar y cada segundo con los segundos restantes.
 * @param {() => void} options.onExpire - Se llama una sola vez cuando llega a cero.
 * @returns {{start: () => void, stop: () => void, isRunning: () => boolean}}
 */
export function createSelectionTimer({ seconds, onTick = () => {}, onExpire }) {
    let handle = null;
    let remaining = 0;

    function stop() {
        if (handle !== null) {
            clearInterval(handle);
            handle = null;
        }
    }

    function start() {
        stop();
        if (!(seconds > 0)) return;
        remaining = seconds;
        onTick(remaining);
        handle = setInterval(() => {
            remaining -= 1;
            if (remaining > 0) {
                onTick(remaining);
                return;
            }
            stop();
            onExpire();
        }, 1000);
    }

    return { start, stop, isRunning: () => handle !== null };
}
