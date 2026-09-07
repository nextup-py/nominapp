/**
 * =============================================================================
 * FEEDBACK SONORO (Web Audio API) — compartido entre mark.js y terminal.js
 * =============================================================================
 *
 * @fileoverview Beeps de éxito/error para la marcación facial. Extraído
 * originalmente de mark.js (mismo comportamiento que ese código original,
 * incluyendo el guard de interacción del usuario — los navegadores bloquean
 * Web Audio hasta el primer gesto) y luego reutilizado en terminal.js, que
 * tenía una copia byte-por-byte idéntica de esta misma lógica.
 */

/** @type {AudioContext|null} */
let audioCtx = null;

/**
 * @type {boolean} Indica si el usuario ya interactuó con la página — Web
 * Audio API no reproduce sonido hasta el primer gesto del usuario.
 */
let userHasInteracted = false;

/** Marca que el usuario ya interactuó con la página (habilita el audio). */
export function markUserInteracted() {
    userHasInteracted = true;
}

/** @returns {boolean} Si el usuario ya interactuó con la página. */
export function hasUserInteracted() {
    return userHasInteracted;
}

/** @returns {AudioContext} */
function getAudioCtx() {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    return audioCtx;
}

/**
 * @param {number} freq
 * @param {number} duration
 * @param {number} [gain]
 * @param {number} [delay]
 */
function playTone(freq, duration, gain = 0.25, delay = 0) {
    try {
        const ctx = getAudioCtx();
        ctx.resume();
        const osc = ctx.createOscillator();
        const env = ctx.createGain();
        osc.connect(env);
        env.connect(ctx.destination);
        osc.type = "sine";
        osc.frequency.value = freq;
        const t = ctx.currentTime + delay;
        env.gain.setValueAtTime(gain, t);
        env.gain.exponentialRampToValueAtTime(0.001, t + duration);
        osc.start(t);
        osc.stop(t + duration + 0.01);
    } catch (_) { /* audio no disponible */ }
}

/**
 * Reproduce el beep correspondiente a un resultado de marcación.
 * @param {"success"|"error"} type
 */
export function playBeep(type) {
    if (!userHasInteracted) return;
    if (type === "success") {
        playTone(880,  0.08, 0.22, 0.0);
        playTone(1100, 0.12, 0.22, 0.1);
    } else if (type === "error") {
        playTone(440, 0.08, 0.22, 0.0);
        playTone(330, 0.14, 0.22, 0.1);
    }
}
