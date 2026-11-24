/**
 * @fileoverview Tests del respaldo del vínculo del terminal en localStorage (funciones puras con un storage inyectado).
 */
import { describe, it, expect } from 'vitest';
import { MIRROR_KEY, mirrorSet, mirrorSnapshot, mirrorClear } from './session-mirror.js';

/** @returns {Storage} Un localStorage mínimo en memoria. */
function fakeStorage() {
    const store = {};

    return {
        getItem: (key) => store[key] ?? null,
        setItem: (key, value) => { store[key] = String(value); },
        removeItem: (key) => { delete store[key]; },
        raw: store,
    };
}

describe('mirrorSet / mirrorSnapshot', () => {
    it('respalda solo las claves del vínculo y devuelve el respaldo si hay token', () => {
        const storage = fakeStorage();

        mirrorSet('api_token', 'tok-1', storage);
        mirrorSet('terminal_id', 7, storage);
        mirrorSet('terminal_code', 'luque', storage);
        mirrorSet('employees_synced_at', 123, storage);

        expect(mirrorSnapshot(storage)).toEqual({ api_token: 'tok-1', terminal_id: 7, terminal_code: 'luque' });
        expect(JSON.parse(storage.raw[MIRROR_KEY])).not.toHaveProperty('employees_synced_at');
    });

    it('no devuelve nada si el respaldo no tiene token', () => {
        const storage = fakeStorage();

        mirrorSet('terminal_id', 7, storage);

        expect(mirrorSnapshot(storage)).toBeNull();
    });

    it('un valor nulo borra la clave', () => {
        const storage = fakeStorage();

        mirrorSet('api_token', 'tok-1', storage);
        mirrorSet('api_token', null, storage);

        expect(mirrorSnapshot(storage)).toBeNull();
    });

    it('ignora un respaldo dañado', () => {
        const storage = fakeStorage();
        storage.setItem(MIRROR_KEY, '{no es json');

        expect(mirrorSnapshot(storage)).toBeNull();

        mirrorSet('api_token', 'tok-2', storage);
        expect(mirrorSnapshot(storage)).toEqual({ api_token: 'tok-2' });
    });
});

describe('sin localStorage', () => {
    it('nunca lanza', () => {
        expect(() => mirrorSet('api_token', 'tok', undefined)).not.toThrow();
        expect(mirrorSnapshot(undefined)).toBeNull();
        expect(() => mirrorClear(undefined)).not.toThrow();
    });

    it('no lanza si la cuota está llena', () => {
        const storage = { getItem: () => null, setItem: () => { throw new Error('QuotaExceededError'); }, removeItem: () => {} };

        expect(() => mirrorSet('api_token', 'tok', storage)).not.toThrow();
    });
});

describe('mirrorClear', () => {
    it('borra el respaldo', () => {
        const storage = fakeStorage();
        mirrorSet('api_token', 'tok-1', storage);

        mirrorClear(storage);

        expect(mirrorSnapshot(storage)).toBeNull();
    });
});
