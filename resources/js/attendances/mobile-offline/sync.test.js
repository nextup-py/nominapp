// resources/js/attendances/mobile-offline/sync.test.js
/**
 * @fileoverview Tests de caracterización de mobile-offline/sync.js contra su
 * implementación ACTUAL, sin modificar — mismo propósito que
 * terminal-offline/sync.test.js (sub-proyecto C).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('./db.js', () => ({
    getMeta: vi.fn(),
    setMeta: vi.fn(),
    setOwnEmployee: vi.fn(),
    logSync: vi.fn(),
}));

import { getMeta, setMeta, setOwnEmployee, logSync } from './db.js';
import { apiFetch, heartbeat, getFaceConfig, fetchStatus, unlinkDevice, submitEvents, MobileAuthError } from './sync.js';

beforeEach(() => {
    vi.clearAllMocks();
    globalThis.fetch = vi.fn();
});

describe('apiFetch', () => {
    it('lanza MobileAuthError si no hay token guardado', async () => {
        getMeta.mockResolvedValue(undefined);
        await expect(apiFetch('/heartbeat')).rejects.toThrow(MobileAuthError);
    });

    it('lanza MobileAuthError en 401/403', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 403, json: () => Promise.resolve({}) });
        await expect(apiFetch('/heartbeat')).rejects.toThrow(MobileAuthError);
    });
});

describe('heartbeat', () => {
    it('guarda el empleado propio, config y offset', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({
            status: 200,
            json: () => Promise.resolve({
                ok: true,
                employee: { id: 5, first_name: 'Juan' },
                config: { face_threshold: 0.5, face_min_confidence_gap: 0.1 },
                server_time: '2026-01-01T00:00:00Z',
            }),
        });

        await heartbeat();

        expect(setOwnEmployee).toHaveBeenCalledWith({ id: 5, first_name: 'Juan' });
        expect(setMeta).toHaveBeenCalledWith('face_threshold', 0.5);
        expect(logSync).toHaveBeenCalledWith('heartbeat', true);
    });

    it('registra el fallo en logSync y relanza', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: false, message: 'Error en heartbeat' }) });

        await expect(heartbeat()).rejects.toThrow('Error en heartbeat');
        expect(logSync).toHaveBeenCalledWith('heartbeat', false, 'Error en heartbeat');
    });
});

describe('getFaceConfig', () => {
    it('retorna los valores cacheados', async () => {
        getMeta.mockImplementation((key) => Promise.resolve(key === 'face_threshold' ? 0.5 : key === 'face_min_confidence_gap' ? 0.1 : undefined));
        expect(await getFaceConfig()).toEqual({ threshold: 0.5, minGap: 0.1 });
    });
});

describe('fetchStatus', () => {
    it('consulta /status sin employeeId (implícito en el token)', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true, last_event: 'check_in', allowed_events: ['check_out'] }) });

        const result = await fetchStatus();

        expect(fetch).toHaveBeenCalledWith('/api/v1/mobile/status', expect.anything());
        expect(result.last_event).toBe('check_in');
    });
});

describe('unlinkDevice', () => {
    it('llama a POST /unlink', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true }) });

        await unlinkDevice();

        expect(fetch).toHaveBeenCalledWith('/api/v1/mobile/unlink', expect.objectContaining({ method: 'POST' }));
    });

    it('lanza error si el servidor responde ok:false', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: false, message: 'No se pudo desvincular' }) });

        await expect(unlinkDevice()).rejects.toThrow('No se pudo desvincular');
    });
});

describe('submitEvents', () => {
    it('envía los eventos (sin employee_id, implícito) y retorna data.results', async () => {
        getMeta.mockResolvedValue('tok');
        fetch.mockResolvedValue({ status: 200, json: () => Promise.resolve({ ok: true, results: [{ client_event_id: 'e1', status: 'synced' }] }) });

        const result = await submitEvents([{ client_event_id: 'e1', event_type: 'check_in', recorded_at: '2026-01-01T10:00:00Z' }]);

        expect(result).toEqual([{ client_event_id: 'e1', status: 'synced' }]);
    });
});
