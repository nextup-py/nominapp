/**
 * =============================================================================
 * TERMINAL.JS — UI DE CONECTIVIDAD Y ESTADO DE SINCRONIZACIÓN OFFLINE
 * =============================================================================
 *
 * @fileoverview Indicador de conectividad del header, banner de "sin
 * conexión", y la fila de estado de sincronización de la pantalla idle
 * (texto + "Últ. sync"). Extraído de terminal.js como parte de su
 * descomposición en módulos más chicos — mismo comportamiento que el código
 * original. Paridad con mark/sync-status-ui.js (mismo texto/prioridad:
 * conflictos > pendientes > sincronizado), pero no es el mismo módulo — acá
 * hay además un chequeo previo de "sin empleados cacheados" (más grave: un
 * terminal sin empleados no puede identificar a nadie) y un indicador de
 * conectividad de header que mark.js no tiene.
 */

import { getMeta, countCachedEmployees } from '../terminal-offline/db.js';
import { countPendingEvents, countConflictEvents } from '../terminal-offline/queue.js';

/**
 * Actualiza el banner de "sin conexión" y el indicador de conectividad del header.
 * A diferencia del banner (que solo aparece mientras el navegador está sin red),
 * el indicador del header queda siempre visible — refleja `navigator.onLine`, no la
 * conectividad real con el servidor (eso lo indica por separado "Últ. sync").
 * @param {boolean} isOffline
 */
export function setOffline(isOffline) {
    const offlineBanner = document.getElementById("offlineBanner");
    const connectivityDot = document.getElementById("connectivityDot");
    const connectivityLabel = document.getElementById("connectivityLabel");

    if (offlineBanner) {
        offlineBanner.classList.toggle("is-visible", isOffline);
        offlineBanner.setAttribute("aria-hidden", String(!isOffline));
    }
    if (connectivityDot)   connectivityDot.classList.toggle("is-offline", isOffline);
    if (connectivityLabel) connectivityLabel.textContent = isOffline ? "Sin conexión" : "En línea";
}

/** @param {string} text */
export function updateIdleSyncStatus(text) {
    const idleSyncStatus = document.getElementById("idleSyncStatus");
    if (idleSyncStatus) idleSyncStatus.textContent = text;
}

/**
 * Refleja en la pantalla idle el estado de la cola de eventos offline —
 * se llama después de cada intento de sincronización (registerMark, sync
 * en segundo plano, botón manual) para que el texto visible siempre
 * refleje la cola real en IndexedDB, no solo el último resultado puntual.
 * @returns {Promise<void>}
 */
export async function refreshIdleSyncStatus() {
    // Prioridad más alta: sin empleados cacheados el terminal no puede identificar
    // a NADIE — más grave que marcaciones pendientes/en conflicto, que sí sabe
    // resolver localmente. Antes esto solo se notaba al fallar un intento real de
    // reconocimiento, con un mensaje genérico que no distinguía la causa.
    const employeeCount = await countCachedEmployees();
    if (employeeCount === 0) {
        updateIdleSyncStatus("⚠ Sin empleados sincronizados — verifique la conexión o contacte al administrador");
        await refreshLastSyncLabel();
        return;
    }

    const [pending, conflicts] = await Promise.all([countPendingEvents(), countConflictEvents()]);
    if (conflicts > 0) {
        updateIdleSyncStatus(`${conflicts} marcación(es) requieren revisión`);
    } else if (pending > 0) {
        updateIdleSyncStatus(`${pending} marcación(es) pendiente(s) de sincronizar`);
    } else {
        updateIdleSyncStatus(navigator.onLine ? "Sincronizado" : "Sin conexión — usando datos locales");
    }
    await refreshLastSyncLabel();
}

/**
 * Última vez que un heartbeat exitoso confirmó contacto real con el servidor
 * (`last_heartbeat_at` en terminal_meta, escrito por heartbeat() en sync.js) — a
 * diferencia del indicador "En línea"/"Sin conexión" (que solo refleja
 * `navigator.onLine`), esto sirve para detectar un terminal con wifi pero sin
 * conectividad real al backend. Se lee de IndexedDB en cada llamada, así que
 * sobrevive a recargas de página sin depender del estado de esta sesión.
 * @returns {Promise<void>}
 */
export async function refreshLastSyncLabel() {
    const terminalHeaderLastSync = document.getElementById("terminalHeaderLastSync");
    if (!terminalHeaderLastSync) return;
    const lastHeartbeatAt = await getMeta("last_heartbeat_at");
    if (!lastHeartbeatAt) {
        terminalHeaderLastSync.classList.add("hidden");
        return;
    }
    const time = new Date(lastHeartbeatAt).toLocaleTimeString("es-BO", {
        hour: "2-digit", minute: "2-digit", hour12: false,
    });
    terminalHeaderLastSync.textContent = `Últ. sync: ${time}`;
    terminalHeaderLastSync.classList.remove("hidden");
}
