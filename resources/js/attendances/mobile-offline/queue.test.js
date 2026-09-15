// resources/js/attendances/mobile-offline/queue.test.js
/**
 * @fileoverview Tests de caracterización de mobile-offline/queue.js contra su
 * implementación ACTUAL, sin modificar — mismo propósito que
 * terminal-offline/queue.test.js (sub-proyecto C).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('./db.js', () => ({
    getMeta: vi.fn(),
    queueEvent: vi.fn(),
    getPendingEvents: vi.fn(),
    getEventsOnDate: vi.fn(),
    getOwnEmployee: vi.fn(),
    removeQueuedEvent: vi.fn(),
    markQueuedEventConflict: vi.fn(),
    incrementQueuedEventAttempts: vi.fn(),
    countPendingEvents: vi.fn(),
    countConflictEvents: vi.fn(),
    dismissConflictEvents: vi.fn(),
    getEmployeeStatusCache: vi.fn(),
    setEmployeeStatusCache: vi.fn(),
}));
vi.mock('./sync.js', () => {
    class MobileAuthErrorImpl extends Error {}
    return { submitEvents: vi.fn(), fetchStatus: vi.fn(), MobileAuthError: MobileAuthErrorImpl };
});

import {
    getMeta, queueEvent, getPendingEvents, getEventsOnDate, getOwnEmployee,
    removeQueuedEvent, markQueuedEventConflict, incrementQueuedEventAttempts,
    countPendingEvents, getEmployeeStatusCache, setEmployeeStatusCache,
} from './db.js';
import { submitEvents, fetchStatus, MobileAuthError } from './sync.js';
import { allowedNextEventTypes, resolveOwnStatus, getOwnStatus, enqueueMark, flushQueue } from './queue.js';

beforeEach(() => {
    vi.clearAllMocks();
    getMeta.mockImplementation((key) => Promise.resolve(key === 'server_clock_offset_ms' ? 0 : key === 'employee_id' ? 5 : undefined));
});

describe('allowedNextEventTypes', () => {
    it('sin evento previo, solo permite check_in', () => {
        expect(allowedNextEventTypes(null)).toEqual(['check_in']);
    });
    it('con hasScheduledBreak=false, filtra break_start', () => {
        expect(allowedNextEventTypes('check_in', false)).toEqual(['check_out']);
    });
});

describe('resolveOwnStatus', () => {
    it('usa el caché del servidor si no hay eventos locales de hoy ni de ayer', async () => {
        getEmployeeStatusCache.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00' });
        getEventsOnDate.mockResolvedValue([]);
        getOwnEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await resolveOwnStatus();

        expect(result.last_event).toBe('check_in');
    });

    it('sin employeeId en meta, no intenta leer el caché de estado (getEmployeeStatusCache no se llama)', async () => {
        getMeta.mockImplementation((key) => Promise.resolve(key === 'employee_id' ? undefined : 0));
        getEventsOnDate.mockResolvedValue([]);
        getOwnEmployee.mockResolvedValue(undefined);

        await resolveOwnStatus();

        expect(getEmployeeStatusCache).not.toHaveBeenCalled();
    });
});

describe('getOwnStatus', () => {
    it('consulta en línea, cachea (si hay employeeId) y retorna el resultado', async () => {
        fetchStatus.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00', allowed_events: ['check_out'] });

        const result = await getOwnStatus();

        expect(setEmployeeStatusCache).toHaveBeenCalledWith(5, expect.objectContaining({ last_event: 'check_in' }));
        expect(result.last_event).toBe('check_in');
    });

    it('propaga MobileAuthError sin caer a resolución local (token revocado)', async () => {
        fetchStatus.mockRejectedValue(new MobileAuthError('revocado'));

        await expect(getOwnStatus()).rejects.toThrow(MobileAuthError);
        expect(getEventsOnDate).not.toHaveBeenCalled();
    });

    it('cae a resolveOwnStatus para cualquier otro error (sin red)', async () => {
        fetchStatus.mockRejectedValue(new Error('network down'));
        getEmployeeStatusCache.mockResolvedValue(undefined);
        getEventsOnDate.mockResolvedValue([]);
        getOwnEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await getOwnStatus();

        expect(result.last_event).toBeNull();
    });
});

describe('enqueueMark', () => {
    it('encola el evento (con employeeId resuelto de meta y location) y refresca el caché de estado', async () => {
        getOwnEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await enqueueMark('check_in', { lat: -25.3, lng: -57.6 });

        expect(queueEvent).toHaveBeenCalledWith(expect.objectContaining({ employee_id: 5, event_type: 'check_in', location: { lat: -25.3, lng: -57.6 } }));
        expect(setEmployeeStatusCache).toHaveBeenCalledWith(5, expect.objectContaining({ last_event: 'check_in' }));
        expect(result).toHaveProperty('client_event_id');
    });

    it('sin employeeId en meta, no intenta refrescar el caché de estado', async () => {
        getMeta.mockImplementation((key) => Promise.resolve(key === 'employee_id' ? undefined : 0));

        await enqueueMark('check_in');

        expect(setEmployeeStatusCache).not.toHaveBeenCalled();
    });
});

describe('flushQueue', () => {
    it('sin eventos pendientes, retorna de inmediato', async () => {
        getPendingEvents.mockResolvedValue([]);
        const result = await flushQueue();
        expect(result).toEqual({ synced: 0, conflicts: 0, stillPending: 0, results: [] });
    });

    it('sincroniza eventos exitosos', async () => {
        getPendingEvents.mockResolvedValue([{ client_event_id: 'e1', event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', location: null }]);
        submitEvents.mockResolvedValue([{ client_event_id: 'e1', status: 'synced' }]);
        countPendingEvents.mockResolvedValue(0);

        const result = await flushQueue();

        expect(removeQueuedEvent).toHaveBeenCalledWith('e1');
        expect(result.synced).toBe(1);
    });

    it('propaga MobileAuthError inmediatamente (no la trata como fallo de red reintentable)', async () => {
        getPendingEvents.mockResolvedValue([{ client_event_id: 'e1', event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', location: null }]);
        submitEvents.mockRejectedValue(new MobileAuthError('revocado'));

        await expect(flushQueue()).rejects.toThrow(MobileAuthError);
        expect(incrementQueuedEventAttempts).not.toHaveBeenCalled();
    });

    it('fallo de red normal: incrementa attempts y deja pending', async () => {
        getPendingEvents.mockResolvedValue([{ client_event_id: 'e1', event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', location: null }]);
        submitEvents.mockRejectedValue(new Error('network down'));
        countPendingEvents.mockResolvedValue(1);

        const result = await flushQueue();

        expect(incrementQueuedEventAttempts).toHaveBeenCalledWith('e1');
        expect(result.stillPending).toBe(1);
    });

    it('parte la cola en lotes de a lo sumo 200 eventos', async () => {
        const pending = Array.from({ length: 250 }, (_, i) => ({ client_event_id: `e${i}`, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z', location: null }));
        getPendingEvents.mockResolvedValue(pending);
        submitEvents.mockImplementation((batch) => Promise.resolve(batch.map((e) => ({ client_event_id: e.client_event_id, status: 'synced' }))));
        countPendingEvents.mockResolvedValue(0);

        const result = await flushQueue();

        expect(submitEvents).toHaveBeenCalledTimes(2);
        expect(submitEvents.mock.calls[0][0]).toHaveLength(200);
        expect(result.synced).toBe(250);
    });
});
