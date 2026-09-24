// resources/js/attendances/terminal-offline/queue.test.js
/**
 * @fileoverview Tests de caracterización de terminal-offline/queue.js contra
 * su implementación ACTUAL, sin modificar — red de seguridad para la
 * extracción del chunking de flushQueue() a offline-shared/submit-in-chunks.js
 * (sub-proyecto C). db.js y sync.js van mockeados (vi.mock).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('./db.js', () => ({
    getMeta: vi.fn(),
    queueEvent: vi.fn(),
    getPendingEvents: vi.fn(),
    getEventsForEmployeeOnDate: vi.fn(),
    getCachedEmployee: vi.fn(),
    removeQueuedEvent: vi.fn(),
    markQueuedEventConflict: vi.fn(),
    incrementQueuedEventAttempts: vi.fn(),
    countPendingEvents: vi.fn(),
    countConflictEvents: vi.fn(),
    getEmployeeStatusCache: vi.fn(),
    setEmployeeStatusCache: vi.fn(),
}));
vi.mock('./sync.js', () => {
    class TerminalAuthErrorImpl extends Error {}
    return { submitEvents: vi.fn(), fetchEmployeeStatus: vi.fn(), TerminalAuthError: TerminalAuthErrorImpl };
});

import {
    getMeta, queueEvent, getPendingEvents, getEventsForEmployeeOnDate, getCachedEmployee,
    removeQueuedEvent, markQueuedEventConflict, incrementQueuedEventAttempts,
    countPendingEvents, getEmployeeStatusCache, setEmployeeStatusCache,
} from './db.js';
import { submitEvents, fetchEmployeeStatus, TerminalAuthError } from './sync.js';
import { allowedNextEventTypes, resolveEmployeeStatus, getEmployeeStatus, enqueueMark, flushQueue } from './queue.js';

beforeEach(() => {
    vi.clearAllMocks();
    getMeta.mockResolvedValue(0); // server_clock_offset_ms por defecto
});

describe('allowedNextEventTypes', () => {
    it('sin evento previo, solo permite check_in', () => {
        expect(allowedNextEventTypes(null)).toEqual(['check_in']);
    });
    it('tras check_in, permite break_start y check_out', () => {
        expect(allowedNextEventTypes('check_in')).toEqual(['break_start', 'check_out']);
    });
    it('tras break_start, solo permite break_end', () => {
        expect(allowedNextEventTypes('break_start')).toEqual(['break_end']);
    });
    it('tras check_out, no permite nada más', () => {
        expect(allowedNextEventTypes('check_out')).toEqual([]);
    });
    it('con hasScheduledBreak=false, filtra break_start', () => {
        expect(allowedNextEventTypes('check_in', false)).toEqual(['check_out']);
    });
    it('con hasScheduledBreak=false, break_end sigue permitido (nunca se filtra)', () => {
        expect(allowedNextEventTypes('break_start', false)).toEqual(['break_end']);
    });
});

describe('resolveEmployeeStatus', () => {
    it('usa el caché del servidor si no hay eventos locales de hoy ni de ayer', async () => {
        getEmployeeStatusCache.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00' });
        getEventsForEmployeeOnDate.mockResolvedValue([]);
        getCachedEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await resolveEmployeeStatus(1);

        expect(result.last_event).toBe('check_in');
        expect(result.allowed_events).toEqual(['break_start', 'check_out']);
    });

    it('prioriza los eventos de HOY encolados localmente sobre el caché del servidor', async () => {
        getEmployeeStatusCache.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00' });
        getEventsForEmployeeOnDate.mockImplementation((employeeId, date) => {
            if (date === '1970-01-01') { // "hoy" con Date.now()=0 en el test
                return Promise.resolve([{ status: 'pending', event_type: 'break_start', recorded_at: '1970-01-01T09:00:00Z' }]);
            }
            return Promise.resolve([]);
        });
        getCachedEmployee.mockResolvedValue({ has_scheduled_break: true });
        vi.useFakeTimers().setSystemTime(new Date('1970-01-01T12:00:00Z'));

        const result = await resolveEmployeeStatus(1);

        expect(result.last_event).toBe('break_start');
        vi.useRealTimers();
    });

    it('sin caché ni eventos de hoy, revisa si ayer quedó una jornada abierta (no check_out)', async () => {
        getEmployeeStatusCache.mockResolvedValue(undefined);
        getEventsForEmployeeOnDate.mockImplementation((employeeId, date) => {
            if (date === '1970-01-01') return Promise.resolve([{ status: 'pending', event_type: 'check_in', recorded_at: '1970-01-01T22:00:00Z' }]);
            return Promise.resolve([]); // hoy (1970-01-02): nada
        });
        getCachedEmployee.mockResolvedValue({ has_scheduled_break: true });
        vi.useFakeTimers().setSystemTime(new Date('1970-01-02T01:00:00Z'));

        const result = await resolveEmployeeStatus(1);

        expect(result.last_event).toBe('check_in'); // jornada nocturna de ayer sigue abierta
        vi.useRealTimers();
    });
});

describe('getEmployeeStatus', () => {
    it('consulta en línea, cachea y retorna el resultado', async () => {
        fetchEmployeeStatus.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00', allowed_events: ['check_out'] });

        const result = await getEmployeeStatus(1);

        expect(setEmployeeStatusCache).toHaveBeenCalledWith(1, expect.objectContaining({ last_event: 'check_in' }));
        expect(result.last_event).toBe('check_in');
    });

    it('cae a resolveEmployeeStatus si la consulta en línea falla', async () => {
        fetchEmployeeStatus.mockRejectedValue(new Error('network down'));
        getEmployeeStatusCache.mockResolvedValue(undefined);
        getEventsForEmployeeOnDate.mockResolvedValue([]);
        getCachedEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await getEmployeeStatus(1);

        expect(result.last_event).toBeNull();
        expect(setEmployeeStatusCache).not.toHaveBeenCalled();
    });
});

describe('enqueueMark', () => {
    it('encola el evento y refresca el caché de estado con el nuevo evento', async () => {
        getCachedEmployee.mockResolvedValue({ has_scheduled_break: true });

        const result = await enqueueMark(1, 'check_in');

        expect(queueEvent).toHaveBeenCalledWith(expect.objectContaining({ employee_id: 1, event_type: 'check_in' }));
        expect(setEmployeeStatusCache).toHaveBeenCalledWith(1, expect.objectContaining({ last_event: 'check_in', allowed_events: ['break_start', 'check_out'] }));
        expect(result).toHaveProperty('client_event_id');
        expect(result).toHaveProperty('recorded_at');
    });
});

describe('flushQueue', () => {
    it('sin eventos pendientes, retorna de inmediato sin llamar a submitEvents', async () => {
        getPendingEvents.mockResolvedValue([]);
        const result = await flushQueue();
        expect(result).toEqual({ synced: 0, conflicts: 0, stillPending: 0, results: [] });
        expect(submitEvents).not.toHaveBeenCalled();
    });

    it('sincroniza eventos y los elimina del store cuando el servidor confirma synced/duplicate', async () => {
        getPendingEvents.mockResolvedValue([
            { client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' },
        ]);
        submitEvents.mockResolvedValue([{ client_event_id: 'e1', status: 'synced' }]);
        countPendingEvents.mockResolvedValue(0);

        const result = await flushQueue();

        expect(removeQueuedEvent).toHaveBeenCalledWith('e1');
        expect(result.synced).toBe(1);
    });

    it('marca conflict los eventos que el servidor rechaza', async () => {
        getPendingEvents.mockResolvedValue([
            { client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' },
        ]);
        submitEvents.mockResolvedValue([{ client_event_id: 'e1', status: 'rejected', message: 'Secuencia inválida' }]);
        countPendingEvents.mockResolvedValue(0);

        const result = await flushQueue();

        expect(markQueuedEventConflict).toHaveBeenCalledWith('e1', 'Secuencia inválida');
        expect(result.conflicts).toBe(1);
    });

    it('tras un conflict, refresca el caché de estado del empleado afectado (no queda con el estado optimista rechazado)', async () => {
        getPendingEvents.mockResolvedValue([
            { client_event_id: 'e1', employee_id: 7, event_type: 'break_start', recorded_at: '2026-01-01T10:00:00Z' },
        ]);
        submitEvents.mockResolvedValue([{ client_event_id: 'e1', status: 'conflict', message: 'Secuencia inválida' }]);
        countPendingEvents.mockResolvedValue(0);
        fetchEmployeeStatus.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00', allowed_events: ['break_start', 'check_out'] });

        await flushQueue();

        // El refresh debe consultar el estado REAL del empleado 7 (no el 'break_start'
        // optimista que enqueueMark() habría dejado en caché antes del rechazo).
        expect(fetchEmployeeStatus).toHaveBeenCalledWith(7);
        expect(setEmployeeStatusCache).toHaveBeenCalledWith(7, expect.objectContaining({ last_event: 'check_in' }));
    });

    it('si el refresh de caché tras un conflict falla (sin red), no interrumpe el flush', async () => {
        getPendingEvents.mockResolvedValue([
            { client_event_id: 'e1', employee_id: 7, event_type: 'break_start', recorded_at: '2026-01-01T10:00:00Z' },
        ]);
        submitEvents.mockResolvedValue([{ client_event_id: 'e1', status: 'conflict', message: 'Secuencia inválida' }]);
        countPendingEvents.mockResolvedValue(0);
        fetchEmployeeStatus.mockRejectedValue(new Error('network down'));
        getEmployeeStatusCache.mockResolvedValue(undefined);
        getEventsForEmployeeOnDate.mockResolvedValue([]);

        const result = await flushQueue();

        expect(result.conflicts).toBe(1);
        expect(markQueuedEventConflict).toHaveBeenCalledWith('e1', 'Secuencia inválida');
    });

    it('si un lote falla por red, ese lote y los siguientes quedan pending (incrementa attempts)', async () => {
        getPendingEvents.mockResolvedValue([
            { client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' },
        ]);
        submitEvents.mockRejectedValue(new Error('network down'));
        countPendingEvents.mockResolvedValue(1);

        const result = await flushQueue();

        expect(incrementQueuedEventAttempts).toHaveBeenCalledWith('e1');
        expect(result.stillPending).toBe(1);
        expect(removeQueuedEvent).not.toHaveBeenCalled();
    });

    /**
     * Regresión: antes de este fix, terminal-offline/queue.js no distinguía
     * TerminalAuthError de una falla de red genérica (a diferencia de
     * mobile-offline/queue.js, que ya chequeaba MobileAuthError) — un token
     * revocado incrementaba attempts como si fuera wifi caída, y flushQueue()
     * nunca lanzaba el error, así que runQueueFlush() (bootstrap.js) no
     * llegaba a mostrar "Terminal sin configurar".
     */
    it('propaga TerminalAuthError inmediatamente (no la trata como fallo de red reintentable)', async () => {
        getPendingEvents.mockResolvedValue([
            { client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' },
        ]);
        submitEvents.mockRejectedValue(new TerminalAuthError('revocado'));

        await expect(flushQueue()).rejects.toThrow(TerminalAuthError);
        expect(incrementQueuedEventAttempts).not.toHaveBeenCalled();
    });

    it('parte la cola en lotes de a lo sumo 200 eventos', async () => {
        const pending = Array.from({ length: 250 }, (_, i) => ({ client_event_id: `e${i}`, employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' }));
        getPendingEvents.mockResolvedValue(pending);
        submitEvents.mockImplementation((batch) => Promise.resolve(batch.map((e) => ({ client_event_id: e.client_event_id, status: 'synced' }))));
        countPendingEvents.mockResolvedValue(0);

        const result = await flushQueue();

        expect(submitEvents).toHaveBeenCalledTimes(2); // 200 + 50
        expect(submitEvents.mock.calls[0][0]).toHaveLength(200);
        expect(submitEvents.mock.calls[1][0]).toHaveLength(50);
        expect(result.synced).toBe(250);
    });

    it('un fallo de persistencia (no de red) en onSynced se propaga en vez de devolverse como resultado limpio', async () => {
        // Regresión: antes de este fix, cualquier error capturado en el catch de flushQueue()
        // se trataba como "fallo de lote de red" y se absorbía devolviendo {synced: 0, ...} —
        // aunque el error viniera de una escritura fallida en IndexedDB (removeQueuedEvent)
        // DESPUÉS de que el servidor ya confirmó el envío. El comportamiento original propagaba
        // ese tipo de error fuera de flushQueue().
        getPendingEvents.mockResolvedValue([
            { client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' },
        ]);
        submitEvents.mockResolvedValue([{ client_event_id: 'e1', status: 'synced' }]);
        removeQueuedEvent.mockRejectedValueOnce(new Error('IndexedDB write failed'));

        await expect(flushQueue()).rejects.toThrow('IndexedDB write failed');
    });

    it('no corre dos flush en simultáneo — el segundo retorna de inmediato', async () => {
        let resolveFirst;
        getPendingEvents.mockResolvedValue([{ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' }]);
        submitEvents.mockImplementation(() => new Promise((resolve) => { resolveFirst = resolve; }));
        countPendingEvents.mockResolvedValue(1);

        const firstCall = flushQueue();
        const secondCall = flushQueue(); // arranca mientras el primero sigue en curso

        const secondResult = await secondCall;
        expect(secondResult.results).toEqual([]); // el segundo no reintentó nada, solo devolvió el estado actual

        resolveFirst([{ client_event_id: 'e1', status: 'synced' }]);
        await firstCall;
    });
});
