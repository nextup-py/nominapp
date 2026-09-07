/**
 * =============================================================================
 * MARK.JS — MODAL "MIS MARCACIONES DE HOY"
 * =============================================================================
 *
 * @fileoverview Abre y cierra el modal de eventos del día del propio
 * empleado. Extraído de mark.js como parte de su descomposición en módulos
 * más chicos — mismo comportamiento que el código original: consulta
 * getOwnStatus() (mismo camino que la identificación, con su misma
 * resiliencia offline) — a diferencia del resto de esa respuesta,
 * `today_events` no tiene resolución local equivalente: sin red, se avisa en
 * vez de mostrar una lista parcial/desactualizada.
 */

import { getOwnStatus } from '../mobile-offline/queue.js';
import { MobileAuthError } from '../mobile-offline/sync.js';
import { translateEventType } from './text-helpers.js';

/**
 * Abre el modal "Mis marcaciones de hoy" y puebla la lista de eventos.
 * @returns {Promise<void>}
 */
export async function openMyEventsModal() {
    const modal      = document.getElementById("myEventsModal");
    const listEl      = document.getElementById("myEventsList");
    const emptyEl     = document.getElementById("myEventsEmpty");
    const offlineEl   = document.getElementById("myEventsOffline");
    const loadingEl   = document.getElementById("myEventsLoading");
    const closeBtn    = document.getElementById("closeMyEventsModal");
    if (!modal || !listEl) return;

    listEl.innerHTML = "";
    emptyEl?.classList.add("hidden");
    offlineEl?.classList.add("hidden");
    loadingEl?.classList.remove("hidden");

    modal.setAttribute("aria-hidden", "false");
    modal.classList.remove("hidden");
    void modal.offsetWidth;
    requestAnimationFrame(() => { modal.classList.add("show"); });
    document.body.classList.add("modal-open");
    setTimeout(() => { closeBtn?.focus(); }, 100);

    try {
        const status = await getOwnStatus();
        loadingEl?.classList.add("hidden");

        const events = status.today_events;
        if (!Array.isArray(events)) {
            // getOwnStatus() cayó al fallback offline — ese fallback no reconstruye
            // la lista completa, solo el último evento (ver resolveOwnStatus()).
            offlineEl?.classList.remove("hidden");
            return;
        }
        if (events.length === 0) {
            emptyEl?.classList.remove("hidden");
            return;
        }
        for (const event of events) {
            const li = document.createElement("li");
            li.className = "my-events-item";

            const typeSpan = document.createElement("span");
            typeSpan.className = "my-events-item-type";
            typeSpan.textContent = event.event_type_label || translateEventType(event.event_type);

            const timeSpan = document.createElement("span");
            timeSpan.className = "my-events-item-time";
            timeSpan.textContent = event.time || "";

            li.append(typeSpan, timeSpan);
            listEl.appendChild(li);
        }
    } catch (error) {
        loadingEl?.classList.add("hidden");
        if (error instanceof MobileAuthError) {
            closeMyEventsModal();
            window.location.href = "/vincular-dispositivo";
            return;
        }
        console.warn("[Warn]", "No se pudo consultar mis marcaciones:", error.message);
        offlineEl?.classList.remove("hidden");
    }
}

/** Cierra el modal "Mis marcaciones de hoy" con animación. */
export function closeMyEventsModal() {
    const modal = document.getElementById("myEventsModal");
    if (!modal) return;

    modal.setAttribute("aria-hidden", "true");
    modal.classList.remove("show");
    setTimeout(() => {
        modal.classList.add("hidden");
        document.body.classList.remove("modal-open");
    }, 250);
}
