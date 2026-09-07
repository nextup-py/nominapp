/**
 * =============================================================================
 * MARK.JS — FEEDBACK VISUAL (reloj, estado del video/status bar, dots de captura)
 * =============================================================================
 *
 * @fileoverview Funciones de UI puramente visuales sin estado compartido con
 * el resto de mark.js — reloj del header, clases de estado del video/barra de
 * estado, y los dots de progreso de captura facial. Extraído de mark.js como
 * parte de su descomposición en módulos más chicos — mismo comportamiento
 * que el código original.
 */

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Actualiza el reloj del header con la fecha y hora actual. Se llama una vez y luego cada 1s. */
export function updateClock() {
    const headerClock = document.getElementById("headerClock");
    if (headerClock) {
        const now = new Date();
        const day   = String(now.getDate()).padStart(2, "0");
        const month = String(now.getMonth() + 1).padStart(2, "0");
        const year  = now.getFullYear();
        const dateStr = `${day}/${month}/${year}`;
        const timeStr = now.toLocaleTimeString("es-BO", {
            hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false,
        });
        headerClock.innerHTML =
            `<span class="clock-date">${dateStr}</span><span class="clock-time">${timeStr}</span>`;
    }
}

/** @param {string|null} stateClass - "detecting"|"face-found"|"success"|"error"|null */
export function setVideoState(stateClass) {
    const videoWrap = document.getElementById("videoWrap");
    if (!videoWrap) return;
    videoWrap.classList.remove(
        "video-wrap--detecting", "video-wrap--face-found",
        "video-wrap--success", "video-wrap--error"
    );
    if (stateClass) videoWrap.classList.add(`video-wrap--${stateClass}`);
}

/**
 * @param {string} text
 * @param {string|null} dotClass - "detecting"|"found"|"error"|null
 */
export function setStatusBar(text, dotClass) {
    const statusText = document.getElementById("statusText");
    const statusBar = document.getElementById("statusBar");
    const statusDot = document.getElementById("statusDot");

    if (statusText) {
        statusText.classList.remove("status-text--new");
        void statusText.offsetWidth; // reiniciar animación
        statusText.textContent = text;
        statusText.classList.add("status-text--new");
    }
    if (statusBar) {
        statusBar.classList.remove("status-bar--detecting", "status-bar--found", "status-bar--error");
        if (dotClass) statusBar.classList.add(`status-bar--${dotClass}`);
    }
    if (statusDot) {
        statusDot.classList.remove(
            "status-dot--active", "status-dot--found",
            "status-dot--success", "status-dot--error"
        );
        const cssMap = { detecting: "active", found: "found", success: "success", error: "error" };
        const cssClass = cssMap[dotClass] ?? dotClass;
        if (cssClass) statusDot.classList.add(`status-dot--${cssClass}`);
    }
}

// Memoizados en el primer uso (no al cargar el módulo — evita depender del
// timing exacto de evaluación de módulos vs. parseo del DOM). Los dots son
// estáticos en el DOM, no se recrean, así que una sola consulta alcanza.
let captureProgress;
let captureDots;

function getCaptureDots() {
    if (captureDots === undefined) {
        captureProgress = document.getElementById("captureProgress");
        captureDots = captureProgress ? Array.from(captureProgress.querySelectorAll(".capture-dot")) : [];
    }
    return { captureProgress, captureDots };
}

/** @returns {number} Cantidad total de dots de progreso de captura. */
export function getCaptureDotCount() {
    const { captureDots } = getCaptureDots();
    return captureDots.length;
}

/** Muestra los dots de progreso de captura, todos vacíos. */
export function showCaptureProgress() {
    const { captureProgress, captureDots } = getCaptureDots();
    if (!captureProgress) return;
    captureDots.forEach(dot => dot.classList.remove("capture-dot--filled"));
    captureProgress.classList.remove("hidden");
}

/** @param {number} count - Cantidad de dots a llenar */
export function updateCaptureProgress(count) {
    const { captureDots } = getCaptureDots();
    captureDots.forEach((dot, i) => {
        dot.classList.toggle("capture-dot--filled", i < count);
    });
}

/** Oculta los dots de progreso de captura y limpia sus clases de resultado. */
export function hideCaptureProgress() {
    const { captureProgress, captureDots } = getCaptureDots();
    if (!captureProgress) return;
    captureProgress.classList.add("hidden");
    captureDots.forEach(dot => dot.classList.remove("capture-dot--filled", "capture-dot--success", "capture-dot--error"));
}

/**
 * Finaliza la animación de captura mostrando todos los dots en el color del resultado
 * (éxito = verde, error = rojo) durante 400ms antes de ocultarlos.
 * @param {"success"|"error"} outcome
 */
export async function finishCaptureProgress(outcome) {
    const { captureProgress, captureDots } = getCaptureDots();
    if (!captureProgress) return;
    captureDots.forEach(dot => {
        dot.classList.remove("capture-dot--filled", "capture-dot--success", "capture-dot--error");
        dot.classList.add(`capture-dot--${outcome}`);
    });
    captureProgress.classList.remove("hidden");
    await sleep(400);
    hideCaptureProgress();
}
