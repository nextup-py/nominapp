/**
 * @fileoverview Tests de caracterización de terminal-offline/db.js contra su
 * implementación ACTUAL, sin modificar — red de seguridad para la extracción
 * de generic-db.js (sub-proyecto C). Usa fake-indexeddb para ejercitar el
 * schema y las operaciones reales de IndexedDB, no mocks.
 *
 * `getDb()` cachea la conexión en una variable de módulo (`dbPromise`) — para
 * que cada test arranque con una base limpia, se resetea el registro de
 * módulos de Vitest (`vi.resetModules()`) y se reimporta el módulo en cada
 * `beforeEach`, y se borra la base física en `afterEach`.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import 'fake-indexeddb/auto';

let db;

beforeEach(async () => {
    vi.resetModules();
    db = await import('./db.js');
});

afterEach(async () => {
    try {
        const openDb = await db.getDb();
        openDb.close();
    } catch (e) {
        // Ignore if already closed or on first test
    }

    await new Promise((resolve) => {
        const req = indexedDB.deleteDatabase('nominapp-terminal');
        req.onsuccess = resolve;
        req.onerror = resolve;
        req.onblocked = resolve;
    });
});

describe('getDb', () => {
    it('crea las 5 stores con los keyPath correctos', async () => {
        const database = await db.getDb();
        expect(Array.from(database.objectStoreNames).sort()).toEqual(
            ['employee_status_cache', 'employees_cache', 'outbound_events', 'sync_log', 'terminal_meta'].sort(),
        );
        expect(database.transaction('employees_cache').objectStore('employees_cache').keyPath).toBe('id');
        expect(database.transaction('outbound_events').objectStore('outbound_events').keyPath).toBe('client_event_id');
        expect(database.transaction('employee_status_cache').objectStore('employee_status_cache').keyPath).toBe('employee_id');
    });
});

describe('getMeta / setMeta', () => {
    it('hace round-trip de un valor', async () => {
        await db.setMeta('api_token', 'tok-123');
        expect(await db.getMeta('api_token')).toBe('tok-123');
    });

    it('retorna undefined para una key inexistente', async () => {
        expect(await db.getMeta('no_existe')).toBeUndefined();
    });
});

describe('migrateTokenFromLocalStorage', () => {
    beforeEach(() => {
        // Stub localStorage since Vitest runs in Node without jsdom
        const store = {};
        globalThis.localStorage = {
            getItem: (key) => store[key] ?? null,
            setItem: (key, value) => { store[key] = String(value); },
            removeItem: (key) => { delete store[key]; },
            clear: () => { Object.keys(store).forEach(key => delete store[key]); },
        };
    });

    afterEach(() => {
        localStorage.clear();
        vi.unstubAllGlobals();
    });

    it('migra token/id/code desde localStorage y los borra', async () => {
        localStorage.setItem('nominapp_terminal_token', 'legacy-tok');
        localStorage.setItem('nominapp_terminal_id', '42');
        localStorage.setItem('nominapp_terminal_code', 'ABC123');

        await db.migrateTokenFromLocalStorage();

        expect(await db.getMeta('api_token')).toBe('legacy-tok');
        expect(await db.getMeta('terminal_id')).toBe(42);
        expect(await db.getMeta('terminal_code')).toBe('ABC123');
        expect(localStorage.getItem('nominapp_terminal_token')).toBeNull();
        expect(localStorage.getItem('nominapp_terminal_id')).toBeNull();
        expect(localStorage.getItem('nominapp_terminal_code')).toBeNull();
    });

    it('es no-op si no hay token legacy en localStorage', async () => {
        await db.migrateTokenFromLocalStorage();
        expect(await db.getMeta('api_token')).toBeUndefined();
    });
});

describe('clearTerminalState', () => {
    it('vacía terminal_meta, employees_cache, outbound_events y employee_status_cache', async () => {
        await db.setMeta('api_token', 'tok');
        await db.applyEmployeesDelta([{ id: 1, first_name: 'Juan', face_descriptor: Array(128).fill(0) }], []);
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.setEmployeeStatusCache(1, { last_event: 'check_in', last_event_time: '10:00', allowed_events: [] });

        await db.clearTerminalState();

        expect(await db.getMeta('api_token')).toBeUndefined();
        expect(await db.getCachedEmployees()).toEqual([]);
        expect(await db.getPendingEvents()).toEqual([]);
        expect(await db.getEmployeeStatusCache(1)).toBeUndefined();
    });
});

describe('logSync', () => {
    it('agrega una entrada con type/ok/detail/at', async () => {
        await db.logSync('heartbeat', true, null);
        const database = await db.getDb();
        const entries = await database.getAll('sync_log');
        expect(entries).toHaveLength(1);
        expect(entries[0]).toMatchObject({ type: 'heartbeat', ok: true, detail: null });
        expect(typeof entries[0].at).toBe('number');
    });

    it('recorta el historial a las últimas 50 entradas', async () => {
        for (let i = 0; i < 55; i++) {
            await db.logSync('heartbeat', true, `intento ${i}`);
        }
        const database = await db.getDb();
        const entries = await database.getAll('sync_log');
        expect(entries).toHaveLength(50);
        expect(entries[0].detail).toBe('intento 5'); // las primeras 5 se recortaron
        expect(entries[49].detail).toBe('intento 54');
    });
});

describe('caché de empleados', () => {
    it('applyEmployeesDelta hace upsert de los modificados y borra los tombstones', async () => {
        await db.applyEmployeesDelta([
            { id: 1, first_name: 'Juan', face_descriptor: Array(128).fill(0) },
            { id: 2, first_name: 'Ana', face_descriptor: Array(128).fill(0) },
        ], []);
        expect(await db.countCachedEmployees()).toBe(2);

        await db.applyEmployeesDelta([{ id: 1, first_name: 'Juan Actualizado', face_descriptor: Array(128).fill(0) }], [2]);

        const employees = await db.getCachedEmployees();
        expect(employees).toHaveLength(1);
        expect(employees[0].first_name).toBe('Juan Actualizado');
        expect(await db.getCachedEmployee(2)).toBeUndefined();
    });

    it('applyBreakFlags actualiza has_scheduled_break en empleados ya cacheados e ignora ids ausentes', async () => {
        await db.applyEmployeesDelta([{ id: 1, first_name: 'Juan', face_descriptor: Array(128).fill(0) }], []);

        await db.applyBreakFlags({ 1: false, 999: true }); // 999 no está cacheado, se ignora

        const employee = await db.getCachedEmployee(1);
        expect(employee.has_scheduled_break).toBe(false);
        expect(await db.getCachedEmployee(999)).toBeUndefined();
    });
});

describe('cola de eventos (outbound_events)', () => {
    it('queueEvent guarda el evento con status pending, attempts 0 y created_at', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        const [event] = await db.getPendingEvents();
        expect(event).toMatchObject({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', status: 'pending', attempts: 0 });
        expect(typeof event.created_at).toBe('number');
    });

    it('getPendingEvents excluye los marcados conflict', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e1', 'rechazado');
        expect(await db.getPendingEvents()).toEqual([]);
    });

    it('getEventsForEmployeeOnDate filtra por empleado y fecha', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e2', employee_id: 2, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e3', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-02T10:00:00Z', date: '2026-01-02' });

        const result = await db.getEventsForEmployeeOnDate(1, '2026-01-01');
        expect(result.map((e) => e.client_event_id)).toEqual(['e1']);
    });

    it('removeQueuedEvent elimina el evento del store', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.removeQueuedEvent('e1');
        expect(await db.getPendingEvents()).toEqual([]);
    });

    it('markQueuedEventConflict marca status conflict con el mensaje del servidor', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e1', 'Secuencia inválida');
        expect(await db.countConflictEvents()).toBe(1);
    });

    it('incrementQueuedEventAttempts suma 1 al contador existente', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.incrementQueuedEventAttempts('e1');
        await db.incrementQueuedEventAttempts('e1');
        const [event] = await db.getPendingEvents();
        expect(event.attempts).toBe(2);
    });

    it('countPendingEvents y countConflictEvents cuentan correctamente', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e2', employee_id: 1, event_type: 'check_out', recorded_at: '2026-01-01T18:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e2', 'rechazado');

        expect(await db.countPendingEvents()).toBe(1);
        expect(await db.countConflictEvents()).toBe(1);
    });
});

describe('employee_status_cache', () => {
    it('setEmployeeStatusCache / getEmployeeStatusCache hacen round-trip', async () => {
        await db.setEmployeeStatusCache(1, { last_event: 'check_in', last_event_time: '08:00', allowed_events: ['break_start', 'check_out'] });
        const cached = await db.getEmployeeStatusCache(1);
        expect(cached).toMatchObject({ employee_id: 1, last_event: 'check_in', last_event_time: '08:00', allowed_events: ['break_start', 'check_out'] });
        expect(typeof cached.cached_at).toBe('number');
    });
});
