// resources/js/attendances/offline-shared/submit-in-chunks.test.js
/**
 * @fileoverview Tests unitarios de submitInChunks() en aislamiento total — sin
 * queue.js ni db.js de ninguna carpeta (terminal/mobile). Cubre el contrato de
 * chunking, éxito, conflicto y, en particular, el caso crítico de "lote 1 OK,
 * lote 2 falla" que motivó el fix de flushQueue() (ver terminal-offline/queue.js
 * y mobile-offline/queue.js): el error relanzado por submitInChunks() debe
 * decorarse con `partialResults`/`remainingEvents` — y solo ESE tipo de error
 * debe activar la lógica de "fallo de lote" en el catch de flushQueue().
 */
import { describe, it, expect, vi } from 'vitest';
import { submitInChunks } from './submit-in-chunks.js';

function makeEvents(count) {
    return Array.from({ length: count }, (_, i) => ({ client_event_id: `e${i}` }));
}

describe('submitInChunks', () => {
    it('happy path: todos los eventos sincronizan en un solo lote', async () => {
        const events = makeEvents(3);
        const submitFn = vi.fn().mockResolvedValue([
            { client_event_id: 'e0', status: 'synced' },
            { client_event_id: 'e1', status: 'synced' },
            { client_event_id: 'e2', status: 'synced' },
        ]);
        const onSynced = vi.fn();
        const onConflict = vi.fn();

        const result = await submitInChunks(events, submitFn, { onSynced, onConflict });

        expect(submitFn).toHaveBeenCalledTimes(1);
        expect(onSynced).toHaveBeenCalledTimes(3);
        expect(onConflict).not.toHaveBeenCalled();
        expect(result).toEqual({ synced: 3, conflicts: 0, results: expect.any(Array) });
        expect(result.results).toHaveLength(3);
    });

    it('parte en varios lotes de a lo sumo chunkSize eventos (250 → 200 + 50)', async () => {
        const events = makeEvents(250);
        const submitFn = vi.fn().mockImplementation((batch) =>
            Promise.resolve(batch.map((e) => ({ client_event_id: e.client_event_id, status: 'synced' }))));

        const result = await submitInChunks(events, submitFn, { onSynced: vi.fn(), onConflict: vi.fn() }, 200);

        expect(submitFn).toHaveBeenCalledTimes(2);
        expect(submitFn.mock.calls[0][0]).toHaveLength(200);
        expect(submitFn.mock.calls[1][0]).toHaveLength(50);
        expect(result.synced).toBe(250);
    });

    it('status "duplicate" cuenta como sincronizado (llama a onSynced, no a onConflict)', async () => {
        const events = makeEvents(1);
        const submitFn = vi.fn().mockResolvedValue([{ client_event_id: 'e0', status: 'duplicate' }]);
        const onSynced = vi.fn();
        const onConflict = vi.fn();

        const result = await submitInChunks(events, submitFn, { onSynced, onConflict });

        expect(onSynced).toHaveBeenCalledWith('e0');
        expect(onConflict).not.toHaveBeenCalled();
        expect(result.synced).toBe(1);
        expect(result.conflicts).toBe(0);
    });

    it('resultados en conflicto/rechazados llaman a onConflict e incrementan el contador', async () => {
        const events = makeEvents(1);
        const submitFn = vi.fn().mockResolvedValue([{ client_event_id: 'e0', status: 'rejected', message: 'Secuencia inválida' }]);
        const onSynced = vi.fn();
        const onConflict = vi.fn();

        const result = await submitInChunks(events, submitFn, { onSynced, onConflict });

        expect(onConflict).toHaveBeenCalledWith('e0', 'Secuencia inválida');
        expect(onSynced).not.toHaveBeenCalled();
        expect(result.conflicts).toBe(1);
    });

    it('lote 1 (200 eventos) sincroniza por completo y lote 2 (50) falla: el error queda decorado con partialResults y remainingEvents', async () => {
        const events = makeEvents(250);
        const batch1Results = events.slice(0, 200).map((e) => ({ client_event_id: e.client_event_id, status: 'synced' }));
        const networkError = new Error('network down');
        const submitFn = vi.fn()
            .mockResolvedValueOnce(batch1Results)
            .mockRejectedValueOnce(networkError);
        const onSynced = vi.fn();
        const onConflict = vi.fn();

        expect.assertions(6);
        try {
            await submitInChunks(events, submitFn, { onSynced, onConflict }, 200);
        } catch (error) {
            expect(error).toBe(networkError);
            // El lote 1 se procesó por completo antes de que el lote 2 fallara.
            expect(onSynced).toHaveBeenCalledTimes(200);
            expect(error.partialResults.synced).toBe(200);
            expect(error.partialResults.results).toHaveLength(200);
            expect(error.remainingEvents).toHaveLength(50);
            expect(error.remainingEvents.map((e) => e.client_event_id)).toEqual(events.slice(200).map((e) => e.client_event_id));
        }
    });
});
