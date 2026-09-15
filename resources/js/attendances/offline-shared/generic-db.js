// resources/js/attendances/offline-shared/generic-db.js
/**
 * =============================================================================
 * INDEXEDDB — CRUD GENÉRICO COMPARTIDO (compartido terminal/mobile)
 * =============================================================================
 *
 * @fileoverview Operaciones de IndexedDB mecánicamente idénticas entre
 * terminal-offline/db.js y mobile-offline/db.js: metadata key/value, cola de
 * eventos (`outbound_events`), caché de estado por empleado
 * (`employee_status_cache`), y log de sincronización (`sync_log`). No abre su
 * propia conexión — recibe el handle `db` ya abierto como primer parámetro,
 * así cada `db.js` mantiene su propio `getDb()` con su propio nombre/versión/
 * schema (incluido lo específico de cada uno: `employees_cache` en terminal,
 * `own_employee` en `mobile_meta` en mobile — eso NO se unifica, ver spec del
 * sub-proyecto C).
 *
 * Los nombres de store `outbound_events`, `employee_status_cache` y
 * `sync_log` son literales fijos porque ambas bases usan los mismos nombres
 * — el store de metadata SÍ varía (`terminal_meta` vs `mobile_meta`), así que
 * `getMeta`/`setMeta` reciben el nombre de store como parámetro.
 */

/**
 * @param {import('idb').IDBPDatabase} db
 * @param {string} storeName
 * @param {string} key
 * @returns {Promise<any>}
 */
export async function getMeta(db, storeName, key) {
    return db.get(storeName, key);
}

/**
 * @param {import('idb').IDBPDatabase} db
 * @param {string} storeName
 * @param {string} key
 * @param {any} value
 */
export async function setMeta(db, storeName, key, value) {
    return db.put(storeName, value, key);
}

/**
 * Registra una entrada en `sync_log`, recortando el historial a las últimas 50.
 * @param {import('idb').IDBPDatabase} db
 * @param {string} type
 * @param {boolean} ok
 * @param {string|null} [detail]
 */
export async function logSync(db, type, ok, detail = null) {
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

/**
 * Encola una marcación capturada localmente.
 * @param {import('idb').IDBPDatabase} db
 * @param {object} event
 */
export async function queueEvent(db, event) {
    await db.put('outbound_events', { ...event, status: 'pending', attempts: 0, created_at: Date.now() });
}

/** @param {import('idb').IDBPDatabase} db @returns {Promise<Array<object>>} Eventos pendientes de sincronizar. */
export async function getPendingEvents(db) {
    const all = await db.getAll('outbound_events');
    return all.filter((event) => event.status === 'pending');
}

/** @param {import('idb').IDBPDatabase} db @param {string} clientEventId */
export async function removeQueuedEvent(db, clientEventId) {
    await db.delete('outbound_events', clientEventId);
}

/** @param {import('idb').IDBPDatabase} db @param {string} clientEventId @param {string} [message] */
export async function markQueuedEventConflict(db, clientEventId, message) {
    const event = await db.get('outbound_events', clientEventId);
    if (!event) return;
    await db.put('outbound_events', { ...event, status: 'conflict', server_message: message ?? null });
}

/** @param {import('idb').IDBPDatabase} db @param {string} clientEventId */
export async function incrementQueuedEventAttempts(db, clientEventId) {
    const event = await db.get('outbound_events', clientEventId);
    if (!event) return;
    await db.put('outbound_events', { ...event, attempts: (event.attempts ?? 0) + 1 });
}

/** @param {import('idb').IDBPDatabase} db @returns {Promise<number>} */
export async function countPendingEvents(db) {
    return (await getPendingEvents(db)).length;
}

/** @param {import('idb').IDBPDatabase} db @returns {Promise<number>} */
export async function countConflictEvents(db) {
    const all = await db.getAll('outbound_events');
    return all.filter((event) => event.status === 'conflict').length;
}

/** @param {import('idb').IDBPDatabase} db Elimina del store los eventos en conflicto. */
export async function dismissConflictEvents(db) {
    const all = await db.getAll('outbound_events');
    const tx = db.transaction('outbound_events', 'readwrite');
    for (const event of all) {
        if (event.status === 'conflict') await tx.store.delete(event.client_event_id);
    }
    await tx.done;
}

/**
 * @param {import('idb').IDBPDatabase} db
 * @param {number} employeeId
 * @param {{last_event: string|null, last_event_time: string|null, allowed_events: string[]}} status
 */
export async function setEmployeeStatusCache(db, employeeId, status) {
    await db.put('employee_status_cache', { employee_id: employeeId, ...status, cached_at: Date.now() });
}

/** @param {import('idb').IDBPDatabase} db @param {number} employeeId @returns {Promise<object|undefined>} */
export async function getEmployeeStatusCache(db, employeeId) {
    return db.get('employee_status_cache', employeeId);
}
