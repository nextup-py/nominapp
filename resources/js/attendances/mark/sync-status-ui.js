/**
 * =============================================================================
 * MARK.JS — UI DE ESTADO DE SINCRONIZACIÓN OFFLINE
 * =============================================================================
 *
 * @fileoverview Banner de "sin conexión", fila de estado de sincronización y
 * aviso de conflictos — extraído de mark.js como parte de su descomposición
 * en módulos más chicos. Mismo comportamiento que el código original: paridad
 * con `refreshIdleSyncStatus()` de terminal.js (mismo texto/prioridad —
 * conflictos > pendientes > sincronizado), pero acá además hay un aviso
 * separado (conflictBanner) porque un conflicto en el dispositivo es del
 * propio empleado, no de un tercero — amerita más que una línea de texto discreta.
 */

import { countPendingEvents, countConflictEvents } from '../mobile-offline/queue.js';

/**
 * Muestra u oculta el banner de "sin conexión".
 * @param {boolean} isOffline
 * @param {() => void} [onChange] - Callback tras el cambio (ej. re-evaluar el botón de marcación).
 */
export function setOfflineBanner(isOffline, onChange) {
    const banner = document.getElementById("offlineBanner");
    if (!banner) return;
    banner.classList.toggle("is-visible", isOffline);
    banner.setAttribute("aria-hidden", String(!isOffline));
    onChange?.();
}

/** @param {string} text */
export function updateSyncStatus(text) {
    const syncStatusText = document.getElementById("syncStatusText");
    if (syncStatusText) syncStatusText.textContent = text;
}

/**
 * Refleja en la fila de estado la cola de eventos offline real (no solo
 * el último resultado puntual) — se llama después de cada intento de
 * sincronización (marcación, sync de fondo, botón manual, carga inicial).
 * @returns {Promise<void>}
 */
export async function refreshSyncStatus() {
    const [pending, conflicts] = await Promise.all([countPendingEvents(), countConflictEvents()]);

    if (conflicts > 0) {
        updateSyncStatus(`${conflicts} marcación(es) requieren revisión`);
    } else if (pending > 0) {
        updateSyncStatus(`${pending} marcación(es) pendiente(s) de sincronizar`);
    } else {
        updateSyncStatus(navigator.onLine ? "Sincronizado" : "Sin conexión — usando datos locales");
    }

    const conflictBanner = document.getElementById("conflictBanner");
    if (conflictBanner) {
        conflictBanner.classList.toggle("is-visible", conflicts > 0);
        conflictBanner.setAttribute("aria-hidden", String(conflicts === 0));
    }
}
