/**
 * @fileoverview Cobertura del identificador del dispositivo: se genera una vez, se guarda en IndexedDB y en
 * localStorage, se recupera si se pierde uno solo y nunca rompe si no hay dónde guardarlo.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { generateDeviceId, resolveDeviceId, getDeviceId, resetDeviceIdCache } from './device-identity.js';

function memoryMeta(initial = {}) {
    const data = new Map(Object.entries(initial));
    return { getMeta: vi.fn(async (k) => data.get(k) ?? null), setMeta: vi.fn(async (k, v) => { data.set(k, v); }), data };
}

function memoryLocal(initial = {}) {
    const data = new Map(Object.entries(initial));
    return { getItem: (k) => data.get(k) ?? null, setItem: (k, v) => data.set(k, v), data };
}

const KEY = 'terminal_device_id';
const ID = '3f2b8c1e-aaaa-bbbb-cccc-123456789012';

beforeEach(() => resetDeviceIdCache());

describe('generateDeviceId', () => {
    it('genera un UUID aleatorio distinto cada vez', () => {
        const a = generateDeviceId();
        const b = generateDeviceId();

        expect(a).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/);
        expect(a).not.toBe(b);
    });

    it('sin crypto.randomUUID cae a getRandomValues y sin crypto a Math.random', () => {
        const withValues = generateDeviceId({ getRandomValues: (bytes) => bytes.fill(7) });
        const withNothing = generateDeviceId({});

        expect(withValues).toBe('07070707-0707-0707-0707-070707070707');
        expect(withNothing).toMatch(/^[0-9a-f-]{36}$/);
    });
});

describe('resolveDeviceId', () => {
    it('la primera vez lo crea y lo guarda en IndexedDB y en localStorage', async () => {
        const meta = memoryMeta();
        const local = memoryLocal();

        const id = await resolveDeviceId({ ...meta, local, generate: () => ID });

        expect(id).toBe(ID);
        expect(meta.data.get(KEY)).toBe(ID);
        expect(local.data.get(KEY)).toBe(ID);
    });

    it('después de la primera vez devuelve el mismo sin generar otro', async () => {
        const meta = memoryMeta({ [KEY]: ID });
        const local = memoryLocal({ [KEY]: ID });
        const generate = vi.fn(() => 'otro-otro-otro');

        expect(await resolveDeviceId({ ...meta, local, generate })).toBe(ID);
        expect(generate).not.toHaveBeenCalled();
    });

    it('si se perdió uno solo de los dos lugares lo recupera del otro y lo vuelve a guardar', async () => {
        const onlyLocal = memoryLocal({ [KEY]: ID });
        const meta = memoryMeta();
        expect(await resolveDeviceId({ ...meta, local: onlyLocal })).toBe(ID);
        expect(meta.data.get(KEY)).toBe(ID);

        const onlyMeta = memoryMeta({ [KEY]: ID });
        const local = memoryLocal();
        expect(await resolveDeviceId({ ...onlyMeta, local })).toBe(ID);
        expect(local.data.get(KEY)).toBe(ID);
    });

    it('un valor guardado inválido se descarta y se genera uno nuevo', async () => {
        const meta = memoryMeta({ [KEY]: 'a b c' });
        const local = memoryLocal({ [KEY]: '<script>' });

        const id = await resolveDeviceId({ ...meta, local, generate: () => ID });

        expect(id).toBe(ID);
    });

    it('si no hay dónde guardar igual devuelve un identificador y no lanza', async () => {
        const id = await resolveDeviceId({
            getMeta: async () => { throw new Error('sin IndexedDB'); },
            setMeta: async () => { throw new Error('sin IndexedDB'); },
            local: { getItem: () => { throw new Error('bloqueado'); }, setItem: () => { throw new Error('lleno'); } },
            generate: () => ID,
        });

        expect(id).toBe(ID);
    });
});

describe('getDeviceId', () => {
    it('resuelve una sola vez por carga de página', async () => {
        const meta = memoryMeta();
        const local = memoryLocal();

        const first = await getDeviceId({ ...meta, local });
        const second = await getDeviceId({ ...meta, local });

        expect(second).toBe(first);
        expect(meta.getMeta).toHaveBeenCalledTimes(1);
    });
});
