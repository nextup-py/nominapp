/**
 * =============================================================================
 * TERMINAL.JS — FEEDBACK VISUAL (relojes, estado del video/dot de ID, dots de captura)
 * =============================================================================
 *
 * @fileoverview Funciones de UI puramente visuales sin estado compartido con
 * el resto de terminal.js — reloj del header y del splash idle, fecha del
 * splash idle, clases de estado del video/dot de identificación, y los dots
 * de progreso de captura facial. Extraído de terminal.js siguiendo el mismo
 * patrón ya usado para descomponer mark.js (ver mark/ui-feedback.js) — mismo
 * comportamiento que el código original.
 *
 * No es el mismo módulo que mark/ui-feedback.js: los IDs del DOM son
 * distintos (el terminal tiene reloj propio del splash idle, `idleClock`/
 * `idleDate`, que mark.js no tiene) y las clases CSS de estado del dot de
 * identificación (`id-status-dot--*`) no existen en mark.js.
 */

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Actualiza el reloj del header y del splash idle. Se llama una vez y luego cada 1s. */
export function updateClock() {
    const terminalHeaderClock = document.getElementById("terminalHeaderClock");
    const idleClock = document.getElementById("idleClock");

    const now = new Date();
    const day   = String(now.getDate()).padStart(2, "0");
    const month = String(now.getMonth() + 1).padStart(2, "0");
    const year  = now.getFullYear();
    const dateStr = `${day}/${month}/${year}`;
    const timeStr = now.toLocaleTimeString("es-BO", {
        hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false,
    });
    if (terminalHeaderClock) {
        terminalHeaderClock.innerHTML =
            `<span class="clock-date">${dateStr}</span><span class="clock-time">${timeStr}</span>`;
    }
    if (idleClock) idleClock.textContent = timeStr;
}

/** Actualiza la fecha del splash idle (se llama al entrar a idle, no en un interval). */
export function updateIdleDate() {
    const idleDate = document.getElementById("idleDate");
    if (!idleDate) return;
    const now = new Date();
    const day   = String(now.getDate()).padStart(2, "0");
    const month = String(now.getMonth() + 1).padStart(2, "0");
    const year  = now.getFullYear();
    const weekday = now.toLocaleDateString("es-BO", { weekday: "long" });
    idleDate.textContent = `${weekday.charAt(0).toUpperCase() + weekday.slice(1)} ${day}/${month}/${year}`;
}

/** @param {string|null} stateClass - "detecting"|"face-found"|"success"|"error"|null */
export function setTerminalVideoState(stateClass) {
    const terminalVideoWrap = document.getElementById("terminalVideoWrap");
    if (!terminalVideoWrap) return;
    terminalVideoWrap.classList.remove(
        "video-wrapper--detecting",
        "video-wrapper--face-found",
        "video-wrapper--success",
        "video-wrapper--error"
    );
    if (stateClass) terminalVideoWrap.classList.add(`video-wrapper--${stateClass}`);
}

/** @param {string|null} dotClass - "detecting"|"face-found"|"success"|"error"|null */
export function setIdStatusDot(dotClass) {
    const idStatusDot = document.getElementById("idStatusDot");
    if (!idStatusDot) return;
    idStatusDot.classList.remove(
        "id-status-dot--searching",
        "id-status-dot--processing",
        "id-status-dot--found",
        "id-status-dot--error"
    );
    // detecting → searching (naranja), face-found → processing (teal), success → found (verde)
    const cssMap = { detecting: "searching", "face-found": "processing", success: "found", error: "error" };
    const cssClass = cssMap[dotClass] || dotClass;
    if (cssClass) idStatusDot.classList.add(`id-status-dot--${cssClass}`);
}

// Memoizados en el primer uso (no al cargar el módulo — evita depender del
// timing exacto de evaluación de módulos vs. parseo del DOM). Los dots son
// estáticos en el DOM, no se recrean, así que una sola consulta alcanza.
let terminalCaptureProgress;
let terminalCaptureDots;

function getCaptureDots() {
    if (terminalCaptureDots === undefined) {
        terminalCaptureProgress = document.getElementById("terminalCaptureProgress");
        terminalCaptureDots = terminalCaptureProgress
            ? Array.from(terminalCaptureProgress.querySelectorAll(".capture-dot"))
            : [];
    }
    return { terminalCaptureProgress, terminalCaptureDots };
}

/** Muestra los dots de progreso de captura, todos vacíos. */
export function showCaptureProgress() {
    const { terminalCaptureProgress, terminalCaptureDots } = getCaptureDots();
    if (!terminalCaptureProgress) return;
    terminalCaptureDots.forEach(dot => dot.classList.remove("capture-dot--filled"));
    terminalCaptureProgress.classList.remove("hidden");
}

/** @param {number} count - Cantidad de dots a llenar */
export function updateCaptureProgress(count) {
    const { terminalCaptureDots } = getCaptureDots();
    terminalCaptureDots.forEach((dot, i) => {
        dot.classList.toggle("capture-dot--filled", i < count);
    });
}

/** Oculta los dots de progreso de captura y limpia sus clases de resultado. */
export function hideCaptureProgress() {
    const { terminalCaptureProgress, terminalCaptureDots } = getCaptureDots();
    if (!terminalCaptureProgress) return;
    terminalCaptureProgress.classList.add("hidden");
    terminalCaptureDots.forEach(dot => {
        dot.classList.remove("capture-dot--filled", "capture-dot--success", "capture-dot--error");
    });
}

/**
 * Finaliza la animación de captura mostrando todos los dots en el color del
 * resultado (éxito = verde, error = rojo) durante 400ms antes de ocultarlos.
 * @param {"success"|"error"} outcome
 */
export async function finishCaptureProgress(outcome) {
    const { terminalCaptureDots } = getCaptureDots();
    // Asegurar que los 5 dots están visibles con el color del resultado
    terminalCaptureDots.forEach(dot => {
        dot.classList.remove("capture-dot--filled", "capture-dot--success", "capture-dot--error");
        dot.classList.add(`capture-dot--${outcome}`);
    });
    await sleep(400);
    hideCaptureProgress();
}
