import { describe, it, expect, vi } from 'vitest';
import { reloadIfOutdated, RELOAD_GUARD_KEY } from './auto-reload.js';

const makeStorage = (initial = {}) => {
    const data = { ...initial };
    return { getItem: (k) => data[k] ?? null, setItem: (k, v) => { data[k] = v; }, data };
};

describe('reloadIfOutdated', () => {
    it('recarga cuando la versión del servidor es distinta y el terminal está en reposo', async () => {
        const reload = vi.fn();
        const storage = makeStorage();

        const result = await reloadIfOutdated({ currentVersion: 'aaa', serverVersion: 'bbb', waitForIdle: async () => true, reload, storage });

        expect(result).toBe(true);
        expect(reload).toHaveBeenCalledOnce();
        expect(storage.data[RELOAD_GUARD_KEY]).toBe('bbb');
    });

    it('no recarga si las versiones coinciden', async () => {
        const reload = vi.fn();

        expect(await reloadIfOutdated({ currentVersion: 'aaa', serverVersion: 'aaa', waitForIdle: async () => true, reload, storage: makeStorage() })).toBe(false);
        expect(reload).not.toHaveBeenCalled();
    });

    it.each([
        ['sin versión del servidor', 'aaa', null],
        ['sin versión local', null, 'bbb'],
        ['servidor en desarrollo', 'aaa', 'dev'],
        ['terminal en desarrollo', 'dev', 'bbb'],
    ])('no recarga: %s', async (_name, currentVersion, serverVersion) => {
        const reload = vi.fn();

        expect(await reloadIfOutdated({ currentVersion, serverVersion, waitForIdle: async () => true, reload, storage: makeStorage() })).toBe(false);
        expect(reload).not.toHaveBeenCalled();
    });

    it('no vuelve a recargar para la misma versión (evita el bucle si el navegador sigue sirviendo la vieja)', async () => {
        const reload = vi.fn();
        const storage = makeStorage({ [RELOAD_GUARD_KEY]: 'bbb' });

        expect(await reloadIfOutdated({ currentVersion: 'aaa', serverVersion: 'bbb', waitForIdle: async () => true, reload, storage })).toBe(false);
        expect(reload).not.toHaveBeenCalled();
    });

    it('si el reposo no llega a tiempo no recarga ni marca la versión', async () => {
        const reload = vi.fn();
        const storage = makeStorage();

        expect(await reloadIfOutdated({ currentVersion: 'aaa', serverVersion: 'bbb', waitForIdle: async () => false, reload, storage })).toBe(false);
        expect(reload).not.toHaveBeenCalled();
        expect(storage.data[RELOAD_GUARD_KEY]).toBeUndefined();
    });
});
