import { describe, it, expect, vi } from 'vitest';
import { runRemoteCommands } from './remote-commands.js';

function makeDeps(overrides = {}) {
    return {
        forceSync: vi.fn().mockResolvedValue(undefined),
        clearCache: vi.fn().mockResolvedValue(undefined),
        waitForIdle: vi.fn().mockResolvedValue(true),
        ack: vi.fn(),
        flushAcks: vi.fn().mockResolvedValue(undefined),
        reload: vi.fn(),
        ...overrides,
    };
}

describe('runRemoteCommands', () => {
    it('force_sync sincroniza y confirma sin recargar', async () => {
        const deps = makeDeps();

        await runRemoteCommands([{ id: 1, command: 'force_sync' }], deps);

        expect(deps.forceSync).toHaveBeenCalledTimes(1);
        expect(deps.ack).toHaveBeenCalledWith({ id: 1, status: 'done' });
        expect(deps.reload).not.toHaveBeenCalled();
    });

    it('report confirma y fuerza un heartbeat inmediato, sin recargar', async () => {
        const deps = makeDeps();

        await runRemoteCommands([{ id: 2, command: 'report' }], deps);

        expect(deps.ack).toHaveBeenCalledWith({ id: 2, status: 'done' });
        expect(deps.flushAcks).toHaveBeenCalledTimes(1);
        expect(deps.reload).not.toHaveBeenCalled();
    });

    it('reload espera el reposo, confirma ANTES de recargar y recarga', async () => {
        const order = [];
        const deps = makeDeps({
            ack: vi.fn(() => order.push('ack')),
            flushAcks: vi.fn(async () => { order.push('flush'); }),
            reload: vi.fn(() => order.push('reload')),
        });

        await runRemoteCommands([{ id: 3, command: 'reload' }], deps);

        expect(deps.waitForIdle).toHaveBeenCalled();
        expect(order).toEqual(['ack', 'flush', 'reload']);
    });

    it('clear_cache limpia, confirma y recarga', async () => {
        const deps = makeDeps();

        await runRemoteCommands([{ id: 4, command: 'clear_cache' }], deps);

        expect(deps.clearCache).toHaveBeenCalledTimes(1);
        expect(deps.ack).toHaveBeenCalledWith({ id: 4, status: 'done' });
        expect(deps.reload).toHaveBeenCalledTimes(1);
    });

    it('si el terminal no llega al reposo, reload/clear_cache fallan sin ejecutarse ni recargar', async () => {
        const deps = makeDeps({ waitForIdle: vi.fn().mockResolvedValue(false) });

        await runRemoteCommands([{ id: 5, command: 'clear_cache' }, { id: 6, command: 'reload' }], deps);

        expect(deps.clearCache).not.toHaveBeenCalled();
        expect(deps.reload).not.toHaveBeenCalled();
        expect(deps.ack).toHaveBeenCalledWith(expect.objectContaining({ id: 5, status: 'failed' }));
        expect(deps.ack).toHaveBeenCalledWith(expect.objectContaining({ id: 6, status: 'failed' }));
    });

    it('si limpiar la caché falla (sin red), confirma failed y NO recarga', async () => {
        const deps = makeDeps({ clearCache: vi.fn().mockRejectedValue(new Error('sin red')) });

        await runRemoteCommands([{ id: 7, command: 'clear_cache' }], deps);

        expect(deps.ack).toHaveBeenCalledWith({ id: 7, status: 'failed', message: 'sin red' });
        expect(deps.reload).not.toHaveBeenCalled();
    });

    it('un comando desconocido se confirma como failed y no ejecuta nada', async () => {
        const deps = makeDeps();

        await runRemoteCommands([{ id: 8, command: 'wipe_everything' }], deps);

        expect(deps.ack).toHaveBeenCalledWith(expect.objectContaining({ id: 8, status: 'failed' }));
        expect(deps.forceSync).not.toHaveBeenCalled();
        expect(deps.clearCache).not.toHaveBeenCalled();
        expect(deps.reload).not.toHaveBeenCalled();
    });

    it('un fallo no impide ejecutar los comandos siguientes de la tanda', async () => {
        const deps = makeDeps({ forceSync: vi.fn().mockRejectedValue(new Error('boom')) });

        await runRemoteCommands([{ id: 9, command: 'force_sync' }, { id: 10, command: 'report' }], deps);

        expect(deps.ack).toHaveBeenNthCalledWith(1, { id: 9, status: 'failed', message: 'boom' });
        expect(deps.ack).toHaveBeenNthCalledWith(2, { id: 10, status: 'done' });
    });
});
