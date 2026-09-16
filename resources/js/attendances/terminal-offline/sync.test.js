// resources/js/attendances/terminal-offline/sync.test.js
/**
 * @fileoverview Tests de caracterización de terminal-offline/sync.js contra
 * su implementación ACTUAL, sin modificar. sync.js NO se extrae en el
 * sub-proyecto C (diferencias genuinas entre terminal/mobile) — estos tests
 * documentan y protegen su comportamiento actual igual, como parte de la
 * cobertura general acordada en la spec.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('./db.js', () => ({
    getMeta: vi.fn(),
    setMeta: vi.fn(),
    applyEmployeesDelta: vi.fn(),
    applyBreakFlags: vi.fn(),
    logSync: vi.fn(),
    countPendingEvents: vi.fn().mockResolvedValue(0),
    countConflictEvents: vi.fn().mockResolvedValue(0),
}));

import { getMeta, setMeta, applyEmployeesDelta, applyBreakFlags, logSync } from './db.js';
import { apiFetch, syncEmployees, heartbeat, getFaceConfig, fetchEmployeeStatus, submitEvents, TerminalAuthError } from './sync.js';

beforeEach(() => {
    vi.clearAllMocks();
    globalThis.fetch = vi.fn();
});

describe('apiFetch', () => {
    it('lanza TerminalAuthError si no hay token guardado', async () => {
        getMeta.mockResolvedValue(undefined);
        await expect(apiFetch('/heartbeat')).rejects.toThrow(TerminalAuthError);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('llama a fetch con el header Authorization Bearer', async () => {
        getMeta.mockResolvedValue('tok-123');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true }) });

        await apiFetch('/heartbeat');

        expect(fetch).toHaveBeenCalledWith('/api/v1/terminal/heartbeat', expect.objectContaining({
            headers: expect.objectContaining({ Authorization: 'Bearer tok-123' }),
        }));
    });

    it('lanza TerminalAuthError en 401/403', async () => {
        getMeta.mockResolvedValue('tok-123');
        fetch.mockResolvedValue({ status: 401, json: () => Promise.resolve({}) });

        await expect(apiFetch('/heartbeat')).rejects.toThrow(TerminalAuthError);
    });
});

describe('syncEmployees', () => {
    it('aplica el delta y actualiza los cursores de sync', async () => {
        getMeta.mockImplementation((key) => Promise.resolve(key === 'api_token' ? 'tok' : undefined));
        fetch.mockResolvedValue({
            status: 200,
            json: () => Promise.resolve({ ok: true, employees: [{ id: 1 }], tombstones: [2], break_flags: { 1: true }, sync_version: 'v2' }),
        });

        const result = await syncEmployees();

        expect(applyEmployeesDelta).toHaveBeenCalledWith([{ id: 1 }], [2]);
        expect(applyBreakFlags).toHaveBeenCalledWith({ 1: true });
        expect(setMeta).toHaveBeenCalledWith('last_employee_sync_version', 'v2');
        expect(result).toEqual({ employees: 1, tombstones: 1 });
        expect(logSync).toHaveBeenCalledWith('employees_sync', true, expect.any(String));
    });

    it('registra el fallo en logSync y relanza el error', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: false, message: 'Error de sync' }) });

        await expect(syncEmployees()).rejects.toThrow('Error de sync');
        expect(logSync).toHaveBeenCalledWith('employees_sync', false, 'Error de sync');
    });
});

describe('heartbeat', () => {
    it('envía pending_events/conflict_events y guarda config/offset', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({
            status: 200,
            json: () => Promise.resolve({ ok: true, config: { face_threshold: 0.5, face_min_confidence_gap: 0.1 }, server_time: '2026-01-01T00:00:00Z' }),
        });

        await heartbeat();

        expect(setMeta).toHaveBeenCalledWith('face_threshold', 0.5);
        expect(setMeta).toHaveBeenCalledWith('face_min_confidence_gap', 0.1);
        expect(setMeta).toHaveBeenCalledWith('last_heartbeat_at', expect.any(Number));
        expect(logSync).toHaveBeenCalledWith('heartbeat', true);
    });
});

describe('getFaceConfig', () => {
    it('retorna null/null si nunca hubo heartbeat exitoso', async () => {
        getMeta.mockResolvedValue(undefined);
        expect(await getFaceConfig()).toEqual({ threshold: null, minGap: null });
    });

    it('retorna los valores cacheados', async () => {
        getMeta.mockImplementation((key) => Promise.resolve(key === 'face_threshold' ? 0.5 : key === 'face_min_confidence_gap' ? 0.1 : undefined));
        expect(await getFaceConfig()).toEqual({ threshold: 0.5, minGap: 0.1 });
    });
});

describe('fetchEmployeeStatus', () => {
    it('consulta /employees/{id}/status', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true, last_event: 'check_in', allowed_events: ['check_out'] }) });

        const result = await fetchEmployeeStatus(7);

        expect(fetch).toHaveBeenCalledWith('/api/v1/terminal/employees/7/status', expect.anything());
        expect(result.last_event).toBe('check_in');
    });
});

describe('submitEvents', () => {
    it('envía los eventos y retorna data.results', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true, results: [{ client_event_id: 'e1', status: 'synced' }] }) });

        const result = await submitEvents([{ client_event_id: 'e1', employee_id: 1, event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' }]);

        expect(result).toEqual([{ client_event_id: 'e1', status: 'synced' }]);
    });
});
