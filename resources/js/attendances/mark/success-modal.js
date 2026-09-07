/**
 * =============================================================================
 * MARK.JS — MODAL DE ÉXITO
 * =============================================================================
 *
 * @fileoverview Muestra y cierra el modal de confirmación tras una marcación
 * exitosa. Extraído de mark.js como parte de su descomposición en módulos
 * más chicos — mismo comportamiento que el código original.
 *
 * A diferencia del modal de error, este no comparte estado con el resto del
 * flujo de identificación/cámara — solo necesita que quien lo use le pase
 * `onClose` (en mark.js, `returnToSplash`) para saber qué hacer al cerrarse.
 */

import { translateEventType } from './text-helpers.js';

let modalListenerAdded = false;

/** @type {(() => void)|null} Callback invocado al cerrar el modal (mismo valor en cada llamada — returnToSplash). */
let onCloseCallback = null;

/**
 * Muestra el modal de éxito después de una marcación exitosa.
 * @param {string} [name]
 * @param {string} [eventType]
 * @param {Date} [time]
 * @param {{queued?: boolean}} [opts]
 * @param {() => void} [onClose] - Callback al cerrar el modal (ej. volver al splash).
 * @returns {void}
 */
export function showSuccessModal(name, eventType, time, { queued = false } = {}, onClose) {
    const modal = document.getElementById("successModal");
    const closeModal = document.getElementById("closeModal");

    if (!modal || !closeModal) {
        console.error("[Error]", "Elementos del modal no encontrados");
        return;
    }

    onCloseCallback = onClose;

    // Poblar bloque de detalle si se proveyeron datos
    const metaEl    = document.getElementById("successModalMeta");
    const nameEl    = document.getElementById("successModalMetaName");
    const eventEl   = document.getElementById("successModalMetaEvent");
    const timeEl    = document.getElementById("successModalMetaTime");
    const queuedEl  = document.getElementById("successModalQueuedNotice");

    if (metaEl && name && eventType && time) {
        if (nameEl)  nameEl.textContent  = name;
        if (eventEl) eventEl.textContent = translateEventType(eventType);
        if (timeEl)  timeEl.textContent  = time.toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit" });
        metaEl.classList.remove("hidden");
    } else if (metaEl) {
        metaEl.classList.add("hidden");
    }

    // Marcación guardada en el dispositivo pero todavía no confirmada por el servidor
    // (sin red en el momento) — avisar sin asustar: no se perdió, se sincroniza sola.
    if (queuedEl) queuedEl.style.display = queued ? "" : "none";

    modal.setAttribute("aria-hidden", "false");
    modal.classList.remove("hidden");
    void modal.offsetWidth; // Forzar reflow para animación

    requestAnimationFrame(() => {
        modal.classList.add("show");
    });

    setTimeout(() => {
        closeModal.focus();
    }, 100);

    document.body.classList.add("modal-open");

    if (!modalListenerAdded) {
        closeModal.addEventListener("click", closeSuccessModal);

        modal.addEventListener("click", (e) => {
            if (e.target === modal) {
                closeSuccessModal();
            }
        });

        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && modal.classList.contains("show")) {
                closeSuccessModal();
            }
        });

        modalListenerAdded = true;
    }
}

/**
 * Cierra el modal de éxito con animación y ejecuta el callback de cierre.
 * @returns {void}
 */
export function closeSuccessModal() {
    const modal = document.getElementById("successModal");

    if (!modal) return;

    if (modal.contains(document.activeElement)) {
        document.activeElement.blur();
    }

    modal.setAttribute("aria-hidden", "true");
    modal.classList.remove("show");

    setTimeout(() => {
        modal.classList.add("hidden");
        document.body.classList.remove("modal-open");
        onCloseCallback?.();
    }, 250);
}
