/**
 * @fileoverview Tests de caracterización de mobile-offline/db.js contra su
 * implementación ACTUAL, sin modificar — mismo propósito y patrón de setup
 * que terminal-offline/db.test.js (sub-proyecto C).
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
        // ignore if already closed
    }
    await new Promise((resolve) => {
        const req = indexedDB.deleteDatabase('nominapp-mobile');
        req.onsuccess = resolve;
        req.onerror = resolve;
        req.onblocked = resolve;
    });
});

describe('getDb', () => {
    it('crea las 4 stores con los keyPath correctos', async () => {
        const database = await db.getDb();
        expect(Array.from(database.objectStoreNames).sort()).toEqual(
            ['employee_status_cache', 'mobile_meta', 'outbound_events', 'sync_log'].sort(),
        );
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
        const storage = {};
        vi.stubGlobal('localStorage', {
            getItem: vi.fn((key) => storage[key] || null),
            setItem: vi.fn((key, value) => { storage[key] = value; }),
            removeItem: vi.fn((key) => { delete storage[key]; }),
            clear: vi.fn(() => { Object.keys(storage).forEach(key => delete storage[key]); }),
            length: 0,
            key: vi.fn(),
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('migra token/employee_id desde localStorage y los borra', async () => {
        localStorage.setItem('nominapp_mobile_token', 'legacy-tok');
        localStorage.setItem('nominapp_mobile_employee_id', '7');

        await db.migrateTokenFromLocalStorage();

        expect(await db.getMeta('api_token')).toBe('legacy-tok');
        expect(await db.getMeta('employee_id')).toBe(7);
        expect(localStorage.getItem('nominapp_mobile_token')).toBeNull();
        expect(localStorage.getItem('nominapp_mobile_employee_id')).toBeNull();
    });

    it('es no-op si no hay token legacy en localStorage', async () => {
        await db.migrateTokenFromLocalStorage();
        expect(await db.getMeta('api_token')).toBeUndefined();
    });
});

describe('resetDb', () => {
    it('vacía mobile_meta, outbound_events, employee_status_cache y sync_log', async () => {
        await db.setMeta('api_token', 'tok');
        await db.queueEvent({ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.setEmployeeStatusCache(1, { last_event: 'check_in', last_event_time: '10:00', allowed_events: [] });
        await db.logSync('heartbeat', true);

        await db.resetDb();

        expect(await db.getMeta('api_token')).toBeUndefined();
        expect(await db.getPendingEvents()).toEqual([]);
        expect(await db.getEmployeeStatusCache(1)).toBeUndefined();
        const database = await db.getDb();
        expect(await database.getAll('sync_log')).toEqual([]);
    });
});

describe('logSync', () => {
    it('agrega una entrada con type/ok/detail/at', async () => {
        await db.logSync('heartbeat', true, null);
        const database = await db.getDb();
        const entries = await database.getAll('sync_log');
        expect(entries).toHaveLength(1);
        expect(entries[0]).toMatchObject({ type: 'heartbeat', ok: true, detail: null });
    });

    it('recorta el historial a las últimas 50 entradas', async () => {
        for (let i = 0; i < 55; i++) {
            await db.logSync('heartbeat', true, `intento ${i}`);
        }
        const database = await db.getDb();
        const entries = await database.getAll('sync_log');
        expect(entries).toHaveLength(50);
        expect(entries[0].detail).toBe('intento 5');
    });
});

describe('own_employee', () => {
    it('setOwnEmployee guarda el empleado y también employee_id', async () => {
        await db.setOwnEmployee({ id: 5, first_name: 'Juan', last_name: 'Pérez', ci: '1234567', face_descriptor: Array(128).fill(0) });

        const own = await db.getOwnEmployee();
        expect(own).toMatchObject({ id: 5, first_name: 'Juan' });
        expect(await db.getMeta('employee_id')).toBe(5);
    });

    it('getOwnEmployee retorna undefined si nunca se seteó', async () => {
        expect(await db.getOwnEmployee()).toBeUndefined();
    });
});

describe('cola de eventos (outbound_events)', () => {
    it('queueEvent guarda el evento con status pending, attempts 0, created_at y location', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01', location: { lat: -25.3, lng: -57.6 } });
        const [event] = await db.getPendingEvents();
        expect(event).toMatchObject({ client_event_id: 'e1', status: 'pending', attempts: 0, location: { lat: -25.3, lng: -57.6 } });
    });

    it('getPendingEvents excluye los marcados conflict', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e1', 'rechazado');
        expect(await db.getPendingEvents()).toEqual([]);
    });

    it('getEventsOnDate filtra solo por fecha (sin employeeId — un único empleado por dispositivo)', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e2', employee_id: 5, event_type: 'check_out', recorded_at: '2026-01-02T18:00:00Z', date: '2026-01-02' });

        const result = await db.getEventsOnDate('2026-01-01');
        expect(result.map((e) => e.client_event_id)).toEqual(['e1']);
    });

    it('removeQueuedEvent elimina el evento del store', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.removeQueuedEvent('e1');
        expect(await db.getPendingEvents()).toEqual([]);
    });

    it('markQueuedEventConflict marca status conflict con el mensaje del servidor', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e1', 'Secuencia inválida');
        expect(await db.countConflictEvents()).toBe(1);
    });

    it('incrementQueuedEventAttempts suma 1 al contador existente', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.incrementQueuedEventAttempts('e1');
        const [event] = await db.getPendingEvents();
        expect(event.attempts).toBe(1);
    });

    it('countPendingEvents y countConflictEvents cuentan correctamente', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e2', employee_id: 5, event_type: 'check_out', recorded_at: '2026-01-01T18:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e2', 'rechazado');

        expect(await db.countPendingEvents()).toBe(1);
        expect(await db.countConflictEvents()).toBe(1);
    });

    it('dismissConflictEvents elimina solo los eventos en conflicto', async () => {
        await db.queueEvent({ client_event_id: 'e1', employee_id: 5, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', date: '2026-01-01' });
        await db.queueEvent({ client_event_id: 'e2', employee_id: 5, event_type: 'check_out', recorded_at: '2026-01-01T18:00:00Z', date: '2026-01-01' });
        await db.markQueuedEventConflict('e2', 'rechazado');

        await db.dismissConflictEvents();

        expect(await db.countConflictEvents()).toBe(0);
        expect(await db.countPendingEvents()).toBe(1);
    });
});

describe('employee_status_cache', () => {
    it('setEmployeeStatusCache / getEmployeeStatusCache hacen round-trip', async () => {
        await db.setEmployeeStatusCache(5, { last_event: 'check_in', last_event_time: '08:00', allowed_events: ['break_start', 'check_out'] });
        const cached = await db.getEmployeeStatusCache(5);
        expect(cached).toMatchObject({ employee_id: 5, last_event: 'check_in', last_event_time: '08:00' });
    });
});
