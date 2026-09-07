/**
 * =============================================================================
 * INDEXEDDB — CACHÉ LOCAL DEL TERMINAL
 * =============================================================================
 *
 * @fileoverview Wrapper delgado sobre `idb` para la base local del terminal.
 *
 * Stores:
 * - terminal_meta          — key/value: api_token, terminal_id/code/branch_id,
 *                             cursores de sync, config de reconocimiento facial.
 * - employees_cache        — keyPath 'id': empleados activos con descriptor
 *                             facial, scopeados a la sucursal del terminal.
 *                             Incluye `has_scheduled_break` (ver
 *                             applyBreakFlags()), usado para filtrar "Inicio
 *                             de descanso" en la resolución local sin red.
 * - outbound_events        — keyPath 'client_event_id': cola de marcaciones
 *                             capturadas localmente. `status`: 'pending'
 *                             (por sincronizar) | 'conflict' (el servidor la
 *                             rechazó — no se reintenta, queda para revisión).
 *                             Las sincronizadas con éxito se eliminan del store.
 * - employee_status_cache  — keyPath 'employee_id': último estado de marcación
 *                             conocido del servidor (last_event/allowed_events)
 *                             por empleado, para poder resolver localmente qué
 *                             botones mostrar cuando no hay red (ver queue.js).
 * - sync_log               — autoIncrement: historial breve de intentos de
 *                             sync, para diagnóstico en pantalla.
 */

import { openDB } from 'idb';

const DB_NAME = 'nominapp-terminal';
const DB_VERSION = 2;

/** @type {Promise<import('idb').IDBPDatabase>|null} */
let dbPromise = null;

/** @returns {Promise<import('idb').IDBPDatabase>} */
export function getDb() {
    if (!dbPromise) {
        dbPromise = openDB(DB_NAME, DB_VERSION, {
            upgrade(db) {
                if (!db.objectStoreNames.contains('terminal_meta')) {
                    db.createObjectStore('terminal_meta');
                }
                if (!db.objectStoreNames.contains('employees_cache')) {
                    db.createObjectStore('employees_cache', { keyPath: 'id' });
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

/**
 * Lee un valor de `terminal_meta`.
 * @param {string} key
 * @returns {Promise<any>}
 */
export async function getMeta(key) {
    const db = await getDb();
    return db.get('terminal_meta', key);
}

/**
 * Escribe un valor en `terminal_meta`.
 * @param {string} key
 * @param {any} value
 */
export async function setMeta(key, value) {
    const db = await getDb();
    return db.put('terminal_meta', value, key);
}

/**
 * Migración única del token guardado en localStorage por la pantalla de
 * configuración (terminal-setup.blade.php) hacia IndexedDB. Se puede llamar
 * en cada carga: es un no-op si ya no queda nada en localStorage.
 * @returns {Promise<void>}
 */
export async function migrateTokenFromLocalStorage() {
    const legacyToken = localStorage.getItem('nominapp_terminal_token');
    if (!legacyToken) return;

    const legacyId = localStorage.getItem('nominapp_terminal_id');
    const legacyCode = localStorage.getItem('nominapp_terminal_code');
    await setMeta('api_token', legacyToken);
    if (legacyId) await setMeta('terminal_id', Number(legacyId));
    if (legacyCode) await setMeta('terminal_code', legacyCode);

    localStorage.removeItem('nominapp_terminal_token');
    localStorage.removeItem('nominapp_terminal_id');
    localStorage.removeItem('nominapp_terminal_code');
}

/**
 * Limpia todo el estado local ligado a la identidad de un terminal (token,
 * empleados cacheados, cola de eventos, estado por empleado) — se usa cuando
 * `initializeOfflineSync()` detecta que el `terminal_id`/`terminal_code`
 * guardado no coincide con el de la página actual (`window.terminalData`):
 * significa que este navegador ya reclamó un token de sincronización para
 * OTRO terminal en algún momento (esta base de IndexedDB, `nominapp-terminal`,
 * es única por navegador, no está separada por terminal), y sin esta
 * limpieza seguiría autenticando/sincronizando en silencio como el terminal
 * viejo en cualquier `/terminal/{code}` que se abra desde este dispositivo.
 * @returns {Promise<void>}
 */
export async function clearTerminalState() {
    const db = await getDb();
    await Promise.all([
        db.clear('terminal_meta'),
        db.clear('employees_cache'),
        db.clear('outbound_events'),
        db.clear('employee_status_cache'),
    ]);
}

/**
 * Registra una entrada en `sync_log`, recortando el historial a las últimas 50.
 * @param {string} type
 * @param {boolean} ok
 * @param {string|null} [detail]
 */
export async function logSync(type, ok, detail = null) {
    const db = await getDb();
    await db.add('sync_log', { type, ok, detail, at: Date.now() });

    const allKeys = await db.getAllKeys('sync_log');
    if (allKeys.length > 50) {
        const tx = db.transaction('sync_log', 'readwrite');
        for (const key of allKeys.slice(0, allKeys.length - 50)) {
            await tx.store.delete(key);
        }
        await tx.done;
    }
}

/** @returns {Promise<Array<object>>} */
export async function getCachedEmployees() {
    const db = await getDb();
    return db.getAll('employees_cache');
}

/** @returns {Promise<number>} Cantidad de empleados cacheados — 0 indica que el terminal nunca sincronizó (o quedó sin candidatos). */
export async function countCachedEmployees() {
    return (await getCachedEmployees()).length;
}

/** @param {number} employeeId @returns {Promise<object|undefined>} */
export async function getCachedEmployee(employeeId) {
    const db = await getDb();
    return db.get('employees_cache', employeeId);
}

/**
 * Aplica un delta de sincronización de empleados: upsert de los modificados,
 * borrado de los tombstones.
 * @param {Array<object>} employees
 * @param {Array<number>} tombstones
 */
export async function applyEmployeesDelta(employees, tombstones) {
    const db = await getDb();
    const tx = db.transaction('employees_cache', 'readwrite');
    for (const employee of employees) {
        await tx.store.put(employee);
    }
    for (const id of tombstones) {
        await tx.store.delete(id);
    }
    await tx.done;
}

/**
 * Aplica el mapa `has_scheduled_break` (id → bool) a los empleados YA
 * cacheados — a diferencia de `applyEmployeesDelta()`, esto se recalcula
 * completo en cada sync (ver EmployeeDescriptorSyncService::breakFlagsForBranch()),
 * no solo para los que cambiaron, porque el valor depende del día calendario
 * y de asignaciones de horario que no tocan el registro del empleado. Un id
 * que todavía no está en la caché (recién sincronizado en el mismo lote) se
 * ignora silenciosamente — ya llegó con el campo puesto vía `applyEmployeesDelta()`.
 * @param {Record<number, boolean>} breakFlags
 */
export async function applyBreakFlags(breakFlags) {
    const db = await getDb();
    const tx = db.transaction('employees_cache', 'readwrite');
    for (const [idStr, hasScheduledBreak] of Object.entries(breakFlags || {})) {
        const id = Number(idStr);
        const employee = await tx.store.get(id);
        if (employee) {
            await tx.store.put({ ...employee, has_scheduled_break: hasScheduledBreak });
        }
    }
    await tx.done;
}

// =========================================================================
// COLA DE EVENTOS OFFLINE (outbound_events)
// =========================================================================

/**
 * Encola una marcación capturada localmente. `date` es la fecha local del
 * dispositivo (YYYY-MM-DD) al momento de la captura — se usa solo para
 * filtrar "eventos de hoy de este empleado" del lado del cliente; la fecha
 * real de negocio (con el timezone de la app) la decide el servidor al sincronizar.
 * @param {{client_event_id: string, employee_id: number, event_type: string, recorded_at: string, date: string}} event
 */
export async function queueEvent(event) {
    const db = await getDb();
    await db.put('outbound_events', { ...event, status: 'pending', attempts: 0, created_at: Date.now() });
}

/** @returns {Promise<Array<object>>} Eventos pendientes de sincronizar (no incluye los marcados 'conflict'). */
export async function getPendingEvents() {
    const db = await getDb();
    const all = await db.getAll('outbound_events');
    return all.filter((event) => event.status === 'pending');
}

/**
 * Eventos (pendientes o en conflicto) de un empleado para la fecha local
 * indicada — usado para resolver localmente el último evento del día
 * cuando no hay red para consultar al servidor (ver queue.js).
 * @param {number} employeeId
 * @param {string} date - YYYY-MM-DD local.
 */
export async function getEventsForEmployeeOnDate(employeeId, date) {
    const db = await getDb();
    const all = await db.getAll('outbound_events');
    return all.filter((event) => event.employee_id === employeeId && event.date === date);
}

/** Marca un evento encolado como sincronizado — se elimina del store (ya vive en el servidor). */
export async function removeQueuedEvent(clientEventId) {
    const db = await getDb();
    await db.delete('outbound_events', clientEventId);
}

/** Marca un evento encolado como rechazado por el servidor — no se reintenta más, queda para revisión manual. */
export async function markQueuedEventConflict(clientEventId, message) {
    const db = await getDb();
    const event = await db.get('outbound_events', clientEventId);
    if (!event) return;
    await db.put('outbound_events', { ...event, status: 'conflict', server_message: message ?? null });
}

/** Incrementa el contador de intentos de un evento encolado (diagnóstico, no afecta el reintento en sí). */
export async function incrementQueuedEventAttempts(clientEventId) {
    const db = await getDb();
    const event = await db.get('outbound_events', clientEventId);
    if (!event) return;
    await db.put('outbound_events', { ...event, attempts: (event.attempts ?? 0) + 1 });
}

/** @returns {Promise<number>} Cantidad de eventos pendientes de sincronizar. */
export async function countPendingEvents() {
    return (await getPendingEvents()).length;
}

/** @returns {Promise<number>} Cantidad de eventos en conflicto (requieren revisión manual en Filament). */
export async function countConflictEvents() {
    const db = await getDb();
    const all = await db.getAll('outbound_events');
    return all.filter((event) => event.status === 'conflict').length;
}

// =========================================================================
// CACHÉ DE ESTADO POR EMPLEADO (employee_status_cache)
// =========================================================================

/**
 * Guarda el último estado de marcación conocido del servidor para un
 * empleado (se actualiza cada vez que fetchEmployeeStatus() tiene éxito).
 * @param {number} employeeId
 * @param {{last_event: string|null, last_event_time: string|null, allowed_events: string[]}} status
 */
export async function setEmployeeStatusCache(employeeId, status) {
    const db = await getDb();
    await db.put('employee_status_cache', { employee_id: employeeId, ...status, cached_at: Date.now() });
}

/** @param {number} employeeId @returns {Promise<object|undefined>} */
export async function getEmployeeStatusCache(employeeId) {
    const db = await getDb();
    return db.get('employee_status_cache', employeeId);
}
