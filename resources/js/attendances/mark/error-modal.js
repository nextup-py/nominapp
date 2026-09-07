/**
 * =============================================================================
 * MARK.JS — MODAL DE ERROR
 * =============================================================================
 *
 * @fileoverview Muestra y cierra el modal de error genérico usado en todo el
 * flujo de marcación (cámara, identificación, GPS, marcación). Extraído de
 * mark.js como parte de su descomposición en módulos más chicos — mismo
 * comportamiento que el código original.
 *
 * A diferencia del modal de éxito, este comparte estado con el resto del
 * flujo de identificación/cámara de mark.js: `errorModalVisible` pausa el
 * drawLoop y el dwell de auto-identificación mientras el modal está abierto
 * (ver isErrorModalVisible()/setErrorModalVisible()), y el flujo de GPS en
 * segundo plano lo mantiene manualmente en `true` durante el reintento (ver
 * transitionToStep2() en mark.js). Por eso el módulo expone getter/setter en
 * vez de ocultar la variable por completo — mark.js sigue leyéndola/
 * escribiéndola directamente en esos puntos.
 *
 * `initErrorModal()` cablea los listeners globales (botón Reintentar, botón
 * Recargar, teclado) una sola vez y recibe de mark.js los dos callbacks que
 * antes leía del closure: `onShow` (cancelAutoIdentifyDwell, se llama cada
 * vez que se abre el modal) y `onRetryFallback` (el feedback de "reintentando
 * en un momento" cuando showErrorModal() no recibió un onRetry explícito).
 */

let errorModalVisible = false;
let previousActiveElement = null;
let errorModalListenerAdded = false;
let errorKeyListenerAdded = false;

/** @type {(() => void)|null} */
let onShowCallback = null;

/** @type {(() => void)|null} */
let onRetryFallbackCallback = null;

/** @returns {boolean} */
export function isErrorModalVisible() {
    return errorModalVisible;
}

/** @param {boolean} value */
export function setErrorModalVisible(value) {
    errorModalVisible = value;
}

/**
 * Cablea los listeners globales del modal de error (una sola vez).
 * @param {{onShow?: () => void, onRetryFallback?: () => void}} callbacks
 */
export function initErrorModal({ onShow, onRetryFallback } = {}) {
    onShowCallback = onShow ?? null;
    onRetryFallbackCallback = onRetryFallback ?? null;

    const retryErrorBtn = document.getElementById("retryErrorModal");
    if (retryErrorBtn) {
        retryErrorBtn.addEventListener("click", () => {
            // Guardia contra tap-through: ignorar si el click llega < 400ms después de mostrar el modal
            if (Date.now() - (retryErrorBtn._shownAt || 0) < 400) return;

            // Blur antes de ocultar el modal para evitar la advertencia ARIA
            if (document.activeElement === retryErrorBtn) retryErrorBtn.blur();

            // Cerrar modal
            const modal = document.getElementById("errorModal");
            if (modal) {
                modal.setAttribute("aria-hidden", "true");
                modal.classList.remove("show");
                setTimeout(() => {
                    modal.classList.add("hidden");
                    document.body.classList.remove("modal-open");
                    errorModalVisible = false;
                    if (previousActiveElement?.focus && !modal.contains(previousActiveElement)) {
                        previousActiveElement.focus();
                    }
                }, 250);
            }
            // Ejecutar callback de reintento si fue provisto (ej: re-solicitar GPS)
            const onRetry = retryErrorBtn._onRetry;
            retryErrorBtn._onRetry = null;
            if (typeof onRetry === "function") {
                setTimeout(onRetry, 260); // esperar a que el modal termine de ocultarse
                return;
            }

            onRetryFallbackCallback?.();
        });
    }

    // Evento: Botón "Recargar" del modal de error
    const closeErrorBtn = document.getElementById("closeErrorModal");
    if (closeErrorBtn && !errorModalListenerAdded) {
        closeErrorBtn.addEventListener("click", closeErrorModalHandler);
        errorModalListenerAdded = true;
    }

    // Evento: Teclado en modal de error — Escape cierra, Tab queda atrapado dentro del modal
    if (!errorKeyListenerAdded) {
        document.addEventListener("keydown", (e) => {
            if (!errorModalVisible) return;
            const modal = document.getElementById("errorModal");
            if (!modal || modal.classList.contains("hidden")) return;

            if (e.key === "Escape") {
                // Solo cerrar con Escape si el botón "Reintentar" está visible;
                // si está oculto (ej: sesión expirada) no hacer nada para evitar
                // recargar la página accidentalmente.
                const retryBtn = document.getElementById("retryErrorModal");
                if (retryBtn && !retryBtn.classList.contains("hidden")) {
                    retryBtn.click();
                }
                return;
            }

            if (e.key === "Tab") {
                // Focus trap: Tab cicla solo entre los botones visibles del modal
                const retryBtn  = document.getElementById("retryErrorModal");
                const closeBtn  = document.getElementById("closeErrorModal");
                const focusable = [retryBtn, closeBtn].filter(
                    el => el && !el.classList.contains("hidden")
                );
                if (focusable.length <= 1) return;
                const first = focusable[0];
                const last  = focusable[focusable.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });
        errorKeyListenerAdded = true;
    }
}

/**
 * Muestra el modal de error con un mensaje personalizado.
 * @param {string} title - Título del error
 * @param {string} message - Descripción detallada del error
 * @param {(() => void)|false} [onRetry] - Callback de reintento; `false` oculta el botón "Reintentar".
 * @returns {void}
 */
export function showErrorModal(title, message, onRetry) {
    const modal   = document.getElementById("errorModal");
    const titleEl = document.getElementById("errorModalTitle");
    const descEl  = document.getElementById("errorModalDesc");
    const retryBtn = document.getElementById("retryErrorModal");

    if (!modal || !titleEl || !descEl) {
        console.error("[Error]", "Elementos del modal de error no encontrados");
        return;
    }

    previousActiveElement = document.activeElement;

    titleEl.textContent = title || "Error";
    descEl.textContent  = message || "Ha ocurrido un error inesperado.";

    // onRetry === false → ocultar botón Reintentar (ej: sesión expirada, reintentar no ayudaría)
    if (retryBtn) {
        const hideRetry = onRetry === false;
        retryBtn.classList.toggle("hidden", hideRetry);
        retryBtn._onRetry = hideRetry ? null : (onRetry || null);
    }

    errorModalVisible = true;
    onShowCallback?.();

    modal.setAttribute("aria-hidden", "false");
    modal.classList.remove("hidden");
    void modal.offsetWidth;

    requestAnimationFrame(() => { modal.classList.add("show"); });

    if (retryBtn) retryBtn._shownAt = Date.now();
    const focusTarget = (onRetry !== false ? retryBtn : null) ?? document.getElementById("closeErrorModal");
    setTimeout(() => { focusTarget?.focus(); }, 100);

    document.body.classList.add("modal-open");
}

/**
 * Cierra el modal de error con animación.
 * @returns {void}
 */
export function closeErrorModalHandler() {
    errorModalVisible = false;
    setTimeout(() => window.location.reload(), 250);
}
