/**
 * =============================================================================
 * COLA DE EVENTOS OFFLINE (dispositivo personal)
 * =============================================================================
 *
 * @fileoverview Orquesta la resolución de estado y sincronización en segundo
 *               plano de las marcaciones del dispositivo. Mismo diseño que
 *               `terminal-offline/queue.js` — toda marcación se guarda primero
 *               en `outbound_events` (durable) y recién después se intenta
 *               sincronizar — pero acá no hace falta un `employeeId` explícito
 *               en cada función: el dispositivo está vinculado a un único
 *               empleado, resuelto internamente desde `mobile_meta`.
 */

import {
    getMeta,
    queueEvent,
    getPendingEvents,
    getEventsOnDate,
    getOwnEmployee,
    removeQueuedEvent,
    markQueuedEventConflict,
    incrementQueuedEventAttempts,
    countPendingEvents,
    countConflictEvents,
    dismissConflictEvents,
    getEmployeeStatusCache,
    setEmployeeStatusCache,
} from './db.js';
import { submitEvents, fetchStatus, MobileAuthError } from './sync.js';
import { submitInChunks } from '../offline-shared/submit-in-chunks.js';

/**
 * Máquina de estados de marcación diaria — mismo criterio que
 * AttendanceEvent::allowedNextEventTypes() en el backend. Se necesita acá
 * (además de en el servidor) para poder resolver localmente qué botones
 * mostrar cuando no hay red para preguntarle al servidor.
 *
 * `hasScheduledBreak` (ver AttendanceCalculator::hasScheduledBreak() /
 * `own_employee.has_scheduled_break`, cacheado en cada heartbeat) quita
 * 'break_start' cuando el horario del empleado no contempla descanso —
 * 'break_end' nunca se filtra así: si ya está en pausa, siempre debe poder
 * cerrarla.
 * @param {string|null} lastEventType
 * @param {boolean} [hasScheduledBreak] - default true (mismo comportamiento que antes si no se pasa).
 * @returns {string[]}
 */
export function allowedNextEventTypes(lastEventType, hasScheduledBreak = true) {
    const allowed = (() => {
        switch (lastEventType) {
            case null:
            case undefined:
                return ['check_in'];
            case 'check_in':
                return ['break_start', 'check_out'];
            case 'break_start':
                return ['break_end'];
            case 'break_end':
                return ['break_start', 'check_out'];
            case 'check_out':
                return [];
            default:
                return ['check_in'];
        }
    })();

    return hasScheduledBreak ? allowed : allowed.filter((event) => event !== 'break_start');
}

/**
 * Hora actual corregida con el offset de reloj del último heartbeat exitoso
 * (`server_clock_offset_ms`, ver sync.js) — un dispositivo con el reloj desviado
 * (o con la hora del sistema manipulada) arrastraría el error a cada
 * marcación capturada offline. Sin heartbeat previo el offset es 0.
 * @returns {Promise<Date>}
 */
async function correctedNow() {
    const offsetMs = (await getMeta('server_clock_offset_ms')) || 0;
    return new Date(Date.now() + offsetMs);
}

/** @returns {string} Fecha local del dispositivo en formato YYYY-MM-DD. */
function localDateString(date = new Date()) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/** @returns {string} Fecha local del día anterior a `date`, en formato YYYY-MM-DD. */
function previousLocalDateString(date) {
    return localDateString(new Date(date.getTime() - 24 * 60 * 60 * 1000));
}

/**
 * Resuelve el estado de marcación del propio empleado para hoy, combinando:
 * 1) el último estado que el servidor confirmó (cacheado en la última
 *    consulta online exitosa — no está scopeado por fecha, así que ya
 *    refleja un turno nocturno si el check_in se sincronizó en línea antes
 *    de quedar offline), con
 * 2) los eventos que este mismo dispositivo ya encoló hoy pero el servidor
 *    todavía no confirmó — para no repetir ni saltear pasos mientras está
 *    offline.
 * 3) si ninguno de los dos anteriores aporta un evento de HOY, revisa si hay
 *    una jornada de AYER encolada localmente (no sincronizada, capturada
 *    offline de punta a punta) que sigue abierta (su último evento no es
 *    check_out) — mismo criterio que AttendanceDay::resolveForEvent() en el
 *    servidor, para que un check_in nocturno capturado sin red no deje al
 *    empleado sin poder marcar la salida al cruzar la medianoche.
 * @returns {Promise<{last_event: string|null, last_event_time: string|null, allowed_events: string[]}>}
 */
export async function resolveOwnStatus() {
    const employeeId = await getMeta('employee_id');
    const cached = employeeId ? await getEmployeeStatusCache(employeeId) : undefined;
    const now = await correctedNow();
    const today = localDateString(now);

    const todayEvents = (await getEventsOnDate(today))
        .filter((event) => event.status === 'pending') // los en conflicto no cuentan como "ya registrados"
        .sort((a, b) => a.recorded_at.localeCompare(b.recorded_at));

    let lastEvent = cached?.last_event ?? null;
    let lastEventTime = cached?.last_event_time ?? null;

    if (todayEvents.length > 0) {
        for (const event of todayEvents) {
            lastEvent = event.event_type;
            lastEventTime = new Date(event.recorded_at).toTimeString().slice(0, 5);
        }
    } else if (lastEvent === null) {
        const yesterdayEvents = (await getEventsOnDate(previousLocalDateString(now)))
            .filter((event) => event.status === 'pending')
            .sort((a, b) => a.recorded_at.localeCompare(b.recorded_at));

        const lastYesterday = yesterdayEvents[yesterdayEvents.length - 1];
        if (lastYesterday && lastYesterday.event_type !== 'check_out') {
            lastEvent = lastYesterday.event_type;
            lastEventTime = new Date(lastYesterday.recorded_at).toTimeString().slice(0, 5);
        }
    }

    const ownEmployee = await getOwnEmployee();
    const hasScheduledBreak = ownEmployee?.has_scheduled_break ?? true;

    return {
        last_event: lastEvent,
        last_event_time: lastEventTime,
        allowed_events: allowedNextEventTypes(lastEvent, hasScheduledBreak),
    };
}

/**
 * Estado de marcación del propio empleado: intenta la consulta en línea (y
 * cachea el resultado para uso offline futuro); si falla por falta de red,
 * cae a resolveOwnStatus() con lo que el dispositivo ya sabe.
 * @returns {Promise<{last_event: string|null, last_event_time: string|null, allowed_events: string[]}>}
 */
export async function getOwnStatus() {
    try {
        const status = await fetchStatus();
        const employeeId = await getMeta('employee_id');
        if (employeeId) await setEmployeeStatusCache(employeeId, status);
        return status;
    } catch (error) {
        // Token revocado — no es un fallo de red recuperable con el estado local, el
        // caller (mark.js) debe mandar al empleado a re-vincular el dispositivo.
        if (error instanceof MobileAuthError) throw error;
        console.warn('No se pudo consultar el estado en línea del empleado, usando estado local:', error.message);
        return resolveOwnStatus();
    }
}

/**
 * Encola una marcación capturada localmente. Debe llamarse ANTES de intentar
 * sincronizar — así la marcación queda a salvo aunque el envío falle o la
 * pestaña se cierre a mitad de camino.
 *
 * `recorded_at` se corrige con el offset de reloj calculado en el último
 * heartbeat exitoso (`server_clock_offset_ms`, ver sync.js). Sin heartbeat
 * previo el offset es 0 (no hay corrección posible).
 * @param {string} eventType
 * @param {{lat: number, lng: number}|null} [location] - GPS real del dispositivo (a diferencia del terminal, que solo tiene fallback a coordenadas de sucursal).
 * @returns {Promise<{client_event_id: string, recorded_at: string}>}
 */
export async function enqueueMark(eventType, location = null) {
    const employeeId = await getMeta('employee_id');
    const clientEventId = crypto.randomUUID();
    const recordedAt = await correctedNow();

    await queueEvent({
        client_event_id: clientEventId,
        employee_id: employeeId,
        event_type: eventType,
        recorded_at: recordedAt.toISOString(),
        date: localDateString(recordedAt),
        location,
    });

    // Refresca el caché de estado de inmediato — sin esto, una vez que este
    // evento se sincroniza y se borra de outbound_events, resolveOwnStatus()
    // no tiene forma de saber que ya se registró (el caché queda con el
    // último estado confirmado ANTES de esta marcación) y vuelve a ofrecer
    // los mismos eventos permitidos que antes de marcar.
    if (employeeId) {
        const ownEmployee = await getOwnEmployee();
        await setEmployeeStatusCache(employeeId, {
            last_event: eventType,
            last_event_time: recordedAt.toTimeString().slice(0, 5),
            allowed_events: allowedNextEventTypes(eventType, ownEmployee?.has_scheduled_break ?? true),
        });
    }

    return { client_event_id: clientEventId, recorded_at: recordedAt.toISOString() };
}

/**
 * Envuelve markQueuedEventConflict() para además invalidar el caché de
 * estado (employee_status_cache) del propio empleado — sin esto,
 * enqueueMark() ya había escrito ahí un estado optimista asumiendo que el
 * evento iba a ser aceptado, y ese estado queda desactualizado si el
 * servidor lo rechaza (ver resolveOwnStatus(): con la cola offline vacía de
 * eventos pendientes de HOY, cae al valor cacheado). El refresco es
 * best-effort y no bloquea el loop de submitInChunks: si hay red,
 * getOwnStatus() corrige el caché con la verdad del servidor; sin red,
 * resuelve localmente igual (ya excluye el evento recién marcado
 * `conflict`, que no cuenta como "pending"). Se espera (await) para que el
 * caller de flushQueue() vea siempre el caché ya corregido. getOwnStatus()
 * puede lanzar MobileAuthError (token revocado) — se absorbe acá porque no
 * debe interrumpir el resto del flush; el caso de token revocado ya lo
 * maneja por separado el catch de flushQueue() cuando submitEvents() falla.
 * @param {string} clientEventId
 * @param {string} [message]
 */
async function onQueuedEventConflict(clientEventId, message) {
    await markQueuedEventConflict(clientEventId, message);

    try {
        await getOwnStatus();
    } catch {
        // best-effort — un fallo acá no debe interrumpir el resto del flush.
    }
}

/** @type {boolean} Evita que dos flush corran en simultáneo (ej. reintento manual + timer de fondo). */
let flushInProgress = false;

/**
 * Máximo de eventos por request de sincronización — debe coincidir con el
 * límite del servidor (`MobileEventSyncController`: `'events' => [...,
 * 'max:200']`). En la práctica un dispositivo personal difícilmente acumule
 * tantas marcaciones (a diferencia de un terminal con muchos empleados), pero
 * se mantiene el mismo chunking por las dudas y por paridad con el terminal.
 */
const MAX_BATCH_SIZE = 200;

/**
 * Intenta sincronizar todos los eventos pendientes, en lotes de a lo sumo
 * `MAX_BATCH_SIZE`. Los `synced`/`duplicate` se eliminan de la cola; los
 * `conflict`/`rejected` se marcan como tal (no se reintentan más) para
 * revisión manual en Filament. Si un lote falla por red, ese lote y los
 * siguientes quedan `pending` para el próximo intento.
 *
 * @returns {Promise<{synced: number, conflicts: number, stillPending: number, results: Array<object>}>}
 */
export async function flushQueue() {
    if (flushInProgress) return { synced: 0, conflicts: 0, stillPending: await countPendingEvents(), results: [] };
    flushInProgress = true;

    try {
        const pending = await getPendingEvents();
        if (pending.length === 0) return { synced: 0, conflicts: 0, stillPending: 0, results: [] };

        try {
            const { synced, conflicts, results } = await submitInChunks(
                pending,
                (batch) => submitEvents(batch.map(({ client_event_id, event_type, recorded_at, location }) => ({
                    client_event_id,
                    event_type,
                    recorded_at,
                    location: location ?? undefined,
                }))),
                { onSynced: removeQueuedEvent, onConflict: onQueuedEventConflict },
                MAX_BATCH_SIZE,
            );
            return { synced, conflicts, stillPending: await countPendingEvents(), results };
        } catch (error) {
            // Token revocado — no es un fallo de red recuperable, el caller (mark.js) debe
            // mandar al empleado a re-vincular el dispositivo. NO se incrementa attempts acá —
            // no tiene sentido contar como "intento fallido" algo que no es un problema de red,
            // mismo comportamiento que el código original.
            if (error instanceof MobileAuthError) throw error;

            // Solo los fallos de lote (submitInChunks los decora con `remainingEvents`) se
            // absorben acá. Cualquier otro error (ej. una escritura fallida en IndexedDB dentro
            // de onSynced/onConflict) debe propagarse sin tocar — mismo comportamiento que el
            // código original, donde removeQueuedEvent/markQueuedEventConflict corrían fuera del
            // try/catch que envolvía solo al envío por red.
            if (!('remainingEvents' in error)) throw error;

            for (const event of error.remainingEvents ?? []) await incrementQueuedEventAttempts(event.client_event_id);
            console.warn(`flushQueue: no se pudo sincronizar el lote (${(error.remainingEvents ?? []).length} eventos restantes):`, error.message);
            const partial = error.partialResults ?? { synced: 0, conflicts: 0, results: [] };
            return { synced: partial.synced, conflicts: partial.conflicts, stillPending: await countPendingEvents(), results: partial.results };
        }
    } finally {
        flushInProgress = false;
    }
}

export { countPendingEvents, countConflictEvents, dismissConflictEvents };
