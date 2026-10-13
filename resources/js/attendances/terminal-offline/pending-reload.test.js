import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { markPendingReload, settlePendingReload, PENDING_RELOAD_KEY, PENDING_RELOAD_MAX_AGE_MS } from './pending-reload.js';

function makeStore(initial = {}) {
    const data = { ...initial };

    return {
        data,
        getMeta: vi.fn(async (key) => data[key] ?? null),
        setMeta: vi.fn(async (key, value) => { data[key] = value; }),
    };
}

describe('pending-reload', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('guarda el comando pendiente con su hora', async () => {
        const store = makeStore();

        await markPendingReload(store.setMeta, 7);

        expect(store.data[PENDING_RELOAD_KEY]).toEqual({ id: 7, at: Date.now() });
    });

    it('confirma hecho cuando la versión que corre coincide con la del servidor y limpia lo pendiente', async () => {
        const store = makeStore({ [PENDING_RELOAD_KEY]: { id: 7, at: Date.now() } });
        const ack = vi.fn();

        const result = await settlePendingReload({ ...store, ack, currentVersion: 'abc', serverVersion: 'abc' });

        expect(result).toBe('done');
        expect(ack).toHaveBeenCalledWith({ id: 7, status: 'done' });
        expect(store.data[PENDING_RELOAD_KEY]).toBeNull();
    });

    it('confirma fallo cuando recargó pero sigue con la versión anterior', async () => {
        const store = makeStore({ [PENDING_RELOAD_KEY]: { id: 7, at: Date.now() } });
        const ack = vi.fn();

        const result = await settlePendingReload({ ...store, ack, currentVersion: 'old', serverVersion: 'new' });

        expect(result).toBe('failed');
        expect(ack).toHaveBeenCalledWith(expect.objectContaining({ id: 7, status: 'failed' }));
    });

    it('sin versión comparable (dev) confirma hecho', async () => {
        const store = makeStore({ [PENDING_RELOAD_KEY]: { id: 7, at: Date.now() } });
        const ack = vi.fn();

        expect(await settlePendingReload({ ...store, ack, currentVersion: 'dev', serverVersion: 'abc' })).toBe('done');
    });

    it('no hace nada si no hay comando pendiente', async () => {
        const store = makeStore();
        const ack = vi.fn();

        expect(await settlePendingReload({ ...store, ack, currentVersion: 'a', serverVersion: 'a' })).toBeNull();
        expect(ack).not.toHaveBeenCalled();
    });

    it('descarta sin confirmar un pendiente demasiado viejo', async () => {
        const store = makeStore({ [PENDING_RELOAD_KEY]: { id: 7, at: Date.now() - PENDING_RELOAD_MAX_AGE_MS - 1000 } });
        const ack = vi.fn();

        expect(await settlePendingReload({ ...store, ack, currentVersion: 'a', serverVersion: 'a' })).toBeNull();
        expect(ack).not.toHaveBeenCalled();
        expect(store.data[PENDING_RELOAD_KEY]).toBeNull();
    });
});
