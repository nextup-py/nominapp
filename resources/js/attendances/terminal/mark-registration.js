/**
 * =============================================================================
 * TERMINAL.JS — SELECCIÓN DE TIPO Y REGISTRO DE MARCACIÓN
 * =============================================================================
 *
 * @fileoverview Pantalla de selección de tipo de evento (cuando el empleado
 * tiene más de un evento válido) y el registro de marcación en sí — cola
 * offline + sync inmediato. Extraído de terminal.js como parte de su
 * descomposición en módulos más chicos — mismo comportamiento que el código
 * original.
 *
 * `getPendingEmployee()`/`clearPendingEmployee()` existen porque el click en
 * un botón de tipo ocurre en un momento posterior y distinto al de
 * showTypeSelectionForEmployee() — terminal.js (composition root) que cablea
 * el listener del botón necesita poder recuperar qué empleado quedó
 * pendiente de selección.
 */

import { showScreen } from './screen-state.js';
import { enqueueMark, flushQueue } from '../terminal-offline/queue.js';
import { refreshIdleSyncStatus } from './sync-status-ui.js';

const eventTypeNames = { check_in: 'Entrada', break_start: 'Inicio descanso', break_end: 'Fin descanso', check_out: 'Salida' };

let pendingEmployee = null;

/**
 * @param {Record<string, HTMLElement|null>} screens
 * @param {{typeButtons: NodeListOf<Element>|Element[], screenTitleEl, screenEyebrowEl, lastMarkEl}} dom
 * @param {{id: number, first_name?: string, last_name?: string}} employee
 * @param {string[]} allowedEvents
 * @param {string|null} lastEvent
 * @param {string|null} lastEventTime
 */
export function showTypeSelectionForEmployee(screens, dom, employee, allowedEvents, lastEvent, lastEventTime) {
    pendingEmployee = employee;

    dom.typeButtons.forEach((btn) => {
        const evtType = btn.getAttribute('data-event-type');
        btn.style.display = allowedEvents.includes(evtType) ? '' : 'none';
        btn.disabled = false;
    });

    const visibleCount = allowedEvents.length;
    const typeGrid = screens.typeSelection?.querySelector('.type-grid');
    if (typeGrid) {
        typeGrid.style.gridTemplateColumns = visibleCount === 1 ? '1fr' : '1fr 1fr';
        typeGrid.style.maxWidth = visibleCount === 1 ? '320px' : '';
    }

    const fullName = `${employee.first_name || ''} ${employee.last_name || ''}`.trim();

    if (dom.screenTitleEl) dom.screenTitleEl.textContent = fullName ? `Hola, ${fullName}` : 'Seleccione marcación';
    if (dom.screenEyebrowEl) dom.screenEyebrowEl.textContent = fullName ? 'Empleado verificado ✓' : 'Seleccione marcación';

    if (dom.lastMarkEl) {
        if (lastEvent) {
            const lastEventName = eventTypeNames[lastEvent] || lastEvent;
            dom.lastMarkEl.textContent = lastEventTime ? `Última marcación: ${lastEventName}, ${lastEventTime}` : `Última marcación: ${lastEventName}`;
            dom.lastMarkEl.classList.remove('hidden');
        } else {
            dom.lastMarkEl.textContent = '';
            dom.lastMarkEl.classList.add('hidden');
        }
    }

    showScreen(screens, 'typeSelection');
}

/** @returns {object|null} el empleado en espera de selección de tipo, o null. */
export function getPendingEmployee() {
    return pendingEmployee;
}

/** Limpia el empleado pendiente (al cancelar o resetear). */
export function clearPendingEmployee() {
    pendingEmployee = null;
}

/**
 * Encola la marcación en IndexedDB (durable) e intenta sincronizarla de
 * inmediato. Si no hay red, o si la sincronización falla, la marcación NO se
 * pierde — queda en la cola y se reintenta en segundo plano; se muestra
 * éxito igual (queued: true) para no asustar al empleado con un error
 * cuando en realidad ya se guardó.
 * @param {{id: number}} employee
 * @param {string} eventType
 * @param {{onStatusUpdate: (text: string) => void, onSuccess: (employee, markData, eventType, opts: {queued: boolean}) => void, onError: (message: string) => void}} callbacks
 * @param {Navigator} [nav] - inyectable para tests; navigator en producción
 */
export async function registerMark(employee, eventType, { onStatusUpdate, onSuccess, onError }, nav = navigator) {
    const eventName = eventTypeNames[eventType] || eventType;
    onStatusUpdate(`Registrando: ${eventName}...`);
    await new Promise((r) => setTimeout(r, 700));

    const { client_event_id: clientEventId, recorded_at: recordedAt } = await enqueueMark(employee.id, eventType);

    if (!nav.onLine) {
        onSuccess(employee, { recorded_at: recordedAt }, eventType, { queued: true });
        await refreshIdleSyncStatus();
        return;
    }

    try {
        const { results } = await flushQueue();
        const ownResult = results.find((r) => r.client_event_id === clientEventId);

        if (ownResult && ownResult.status !== 'synced' && ownResult.status !== 'duplicate') {
            onError(ownResult.message || 'La marcación fue rechazada por el servidor.');
        } else {
            onSuccess(employee, { recorded_at: recordedAt }, eventType, { queued: !ownResult });
        }
    } catch (error) {
        console.error('Error al sincronizar la cola:', error);
        onSuccess(employee, { recorded_at: recordedAt }, eventType, { queued: true });
    }

    await refreshIdleSyncStatus();
}
