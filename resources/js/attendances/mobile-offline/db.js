// resources/js/attendances/mobile-offline/db.js
/**
 * =============================================================================
 * INDEXEDDB — CACHÉ LOCAL DEL DISPOSITIVO PERSONAL
 * =============================================================================
 *
 * @fileoverview Wrapper delgado sobre `idb` para la base local del
 * dispositivo. El CRUD genérico (metadata, cola de eventos, caché de estado,
 * sync log) vive en `offline-shared/generic-db.js` — acá solo el schema
 * propio y lo específico del empleado único (`own_employee`, sin store
 * `employees_cache` — el dispositivo cachea un solo descriptor).
 *
 * Stores:
 * - mobile_meta            — key/value: api_token, own_employee (id/nombre/CI/
 *                             descriptor/has_scheduled_break), config de
 *                             reconocimiento facial, offset de reloj,
 *                             timestamps de sync.
 * - outbound_events        — keyPath 'client_event_id': cola de marcaciones
 *                             capturadas localmente.
 * - employee_status_cache  — keyPath 'employee_id': último estado de
 *                             marcación conocido del servidor, un único registro.
 * - sync_log               — autoIncrement: historial breve de intentos de
 *                             sync, para diagnóstico en pantalla.
 */

import { openDB } from 'idb';
import * as genericDb from '../offline-shared/generic-db.js';

const DB_NAME = 'nominapp-mobile';
const DB_VERSION = 1;
const META_STORE = 'mobile_meta';

/** @type {Promise<import('idb').IDBPDatabase>|null} */
let dbPromise = null;

/** @returns {Promise<import('idb').IDBPDatabase>} */
export function getDb() {
    if (!dbPromise) {
        dbPromise = openDB(DB_NAME, DB_VERSION, {
            upgrade(db) {
                if (!db.objectStoreNames.contains('mobile_meta')) {
                    db.createObjectStore('mobile_meta');
                }
                if (!db.objectStoreNames.contains('outbound_events')) {
                    db.createObjectStore('outbound_events', { keyPath: 'client_event_id' });
                }
                if (!db.objectStoreNames.contains('employee_status_cache')) {
                    db.createObjectStore('employee_status_cache', { keyPath: 'employee_id' });
                }
                if (!db.objectStoreNames.contains('sync_log')) {
                    db.createObjectStore('sync_log', { keyPath: 'id', autoIncrement: true });
                }
            },
        });
    }
    return dbPromise;
}

/** @param {string} key @returns {Promise<any>} */
export async function getMeta(key) {
    return genericDb.getMeta(await getDb(), META_STORE, key);
}

/** @param {string} key @param {any} value */
export async function setMeta(key, value) {
    return genericDb.setMeta(await getDb(), META_STORE, key, value);
}

/**
 * Migración única del token guardado en localStorage por la pantalla de
 * vinculación (device-link.blade.php) hacia IndexedDB.
 * @returns {Promise<void>}
 */
export async function migrateTokenFromLocalStorage() {
    const legacyToken = localStorage.getItem('nominapp_mobile_token');
    if (!legacyToken) return;

    const legacyEmployeeId = localStorage.getItem('nominapp_mobile_employee_id');
    await setMeta('api_token', legacyToken);
    if (legacyEmployeeId) await setMeta('employee_id', Number(legacyEmployeeId));

    localStorage.removeItem('nominapp_mobile_token');
    localStorage.removeItem('nominapp_mobile_employee_id');
}

/** @param {string} type @param {boolean} ok @param {string|null} [detail] */
export async function logSync(type, ok, detail = null) {
    return genericDb.logSync(await getDb(), type, ok, detail);
}

/**
 * Vacía por completo la caché local del dispositivo — llamado tras una
 * auto-desvinculación exitosa (ver `unlinkDevice()` en `sync.js`). El
 * dispositivo queda como recién instalado: sin token, sin empleado
 * cacheado, sin cola de eventos ni estado. Cualquier marcación pendiente de
 * sincronizar en `outbound_events` se pierde — el caller es responsable de
 * intentar `flushQueue()` y advertir al usuario antes de llamar a esto.
 * @returns {Promise<void>}
 */
export async function resetDb() {
    const db = await getDb();
    await Promise.all(
        ['mobile_meta', 'outbound_events', 'employee_status_cache', 'sync_log'].map((store) => db.clear(store))
    );
}

/**
 * Empleado dueño del dispositivo, con su descriptor facial cacheado — el
 * único "candidato" contra el que el matcher local compara.
 * @returns {Promise<{id: number, first_name: string, last_name: string, ci: string|null, face_descriptor: number[]}|undefined>}
 */
export async function getOwnEmployee() {
    return getMeta('own_employee');
}

/**
 * Actualiza el empleado propio cacheado (llamado tras cada heartbeat exitoso
 * — propaga automáticamente una re-inscripción facial sin que el empleado
 * tenga que re-vincular el dispositivo).
 * @param {{id: number, first_name: string, last_name: string, ci: string|null, face_descriptor: number[]}} employee
 */
export async function setOwnEmployee(employee) {
    await setMeta('own_employee', employee);
    await setMeta('employee_id', employee.id);
}

// =========================================================================
// COLA DE EVENTOS OFFLINE (outbound_events) — delegado a generic-db.js
// =========================================================================

/**
 * Encola una marcación capturada localmente. `date` es la fecha local del
 * dispositivo (YYYY-MM-DD) al momento de la captura — se usa solo para
 * filtrar "eventos de hoy" del lado del cliente; la fecha real de negocio
 * (con el timezone de la app) la decide el servidor al sincronizar.
 * @param {{client_event_id: string, employee_id: number, event_type: string, recorded_at: string, date: string, location?: object|null}} event
 */
export async function queueEvent(event) {
    return genericDb.queueEvent(await getDb(), event);
}

/** @returns {Promise<Array<object>>} Eventos pendientes de sincronizar (no incluye los marcados 'conflict'). */
export async function getPendingEvents() {
    return genericDb.getPendingEvents(await getDb());
}

/**
 * Eventos (pendientes o en conflicto) para la fecha local indicada — sin
 * employeeId, a diferencia del terminal (un único empleado por dispositivo).
 * @param {string} date - YYYY-MM-DD local.
 */
export async function getEventsOnDate(date) {
    const db = await getDb();
    const all = await db.getAll('outbound_events');
    return all.filter((event) => event.date === date);
}

/** Marca un evento encolado como sincronizado — se elimina del store (ya vive en el servidor). @param {string} clientEventId */
export async function removeQueuedEvent(clientEventId) {
    return genericDb.removeQueuedEvent(await getDb(), clientEventId);
}

/** Marca un evento encolado como rechazado por el servidor — no se reintenta más, queda para revisión manual. @param {string} clientEventId @param {string} [message] */
export async function markQueuedEventConflict(clientEventId, message) {
    return genericDb.markQueuedEventConflict(await getDb(), clientEventId, message);
}

/** Incrementa el contador de intentos de un evento encolado (diagnóstico, no afecta el reintento en sí). @param {string} clientEventId */
export async function incrementQueuedEventAttempts(clientEventId) {
    return genericDb.incrementQueuedEventAttempts(await getDb(), clientEventId);
}

/** @returns {Promise<number>} */
export async function countPendingEvents() {
    return genericDb.countPendingEvents(await getDb());
}

/** @returns {Promise<number>} */
export async function countConflictEvents() {
    return genericDb.countConflictEvents(await getDb());
}

/**
 * Elimina del store local los eventos en conflicto — el registro real de lo
 * ocurrido ya vive en el servidor (`AttendanceMarkFailure`, revisado por un
 * admin en Filament); la copia local solo sirve para avisarle una vez al
 * empleado. Sin esto, el aviso de conflicto en /marcar quedaría pegado para
 * siempre (nada más limpia `outbound_events` del lado del cliente).
 */
export async function dismissConflictEvents() {
    return genericDb.dismissConflictEvents(await getDb());
}

// =========================================================================
// CACHÉ DE ESTADO (employee_status_cache) — delegado
// =========================================================================

/**
 * Guarda el último estado de marcación conocido del servidor para el propio
 * empleado (se actualiza cada vez que fetchStatus() tiene éxito).
 * @param {number} employeeId
 * @param {{last_event: string|null, last_event_time: string|null, allowed_events: string[]}} status
 */
export async function setEmployeeStatusCache(employeeId, status) {
    return genericDb.setEmployeeStatusCache(await getDb(), employeeId, status);
}

/** @param {number} employeeId @returns {Promise<object|undefined>} */
export async function getEmployeeStatusCache(employeeId) {
    return genericDb.getEmployeeStatusCache(await getDb(), employeeId);
}
