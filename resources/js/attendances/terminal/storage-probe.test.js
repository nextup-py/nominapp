/**
 * @fileoverview Cobertura de la prueba de almacenamiento del terminal: detecta un navegador que no conserva datos
 * (lectura distinta de lo escrito o error de escritura), informa el estado del almacenamiento sin depender de él y
 * recuerda en localStorage que el navegador estuvo vinculado.
 */
import { describe, it, expect, vi } from 'vitest';
import { probeStorage, describeStorageNotice, rememberLinked, hasLinkMarker, STORAGE_NOTICE_TEXT } from './storage-probe.js';

/** Memoria simple que se comporta como IndexedDB que sí guarda. */
function memoryMeta() {
    const data = new Map();
    return { getMeta: vi.fn(async (k) => data.get(k) ?? null), setMeta: vi.fn(async (k, v) => { data.set(k, v); }) };
}

function memoryLocal() {
    const data = new Map();
    return { getItem: (k) => data.get(k) ?? null, setItem: (k, v) => data.set(k, v) };
}

describe('probeStorage', () => {
    it('un navegador que guarda: available true y estado del almacenamiento', async () => {
        const storage = { persisted: async () => true, estimate: async () => ({ quota: 5 * 1024 * 1024 * 1024 }) };

        const result = await probeStorage({ ...memoryMeta(), storage });

        expect(result).toEqual({ available: true, persisted: true, quota_mb: 5120 });
    });

    it('lo leído no coincide con lo escrito: available false (datos que se descartan)', async () => {
        const result = await probeStorage({ getMeta: async () => null, setMeta: async () => {}, storage: null });

        expect(result.available).toBe(false);
    });

    it('si escribir falla: available false y nunca lanza', async () => {
        const result = await probeStorage({ getMeta: async () => null, setMeta: async () => { throw new Error('QuotaExceeded'); }, storage: null });

        expect(result).toEqual({ available: false, persisted: null, quota_mb: null });
    });

    it('un navegador sin API de almacenamiento no cambia el resultado de la prueba', async () => {
        const result = await probeStorage({ ...memoryMeta(), storage: null });

        expect(result).toEqual({ available: true, persisted: null, quota_mb: null });
    });

    it('si estimate() falla se sigue con lo demás', async () => {
        const storage = { persisted: async () => false, estimate: async () => { throw new Error('no'); } };

        const result = await probeStorage({ ...memoryMeta(), storage });

        expect(result.available).toBe(true);
        expect(result.quota_mb).toBeNull();
    });
});

describe('describeStorageNotice', () => {
    it('solo avisa cuando el navegador no conserva datos', () => {
        expect(describeStorageNotice({ available: false })).toBe(STORAGE_NOTICE_TEXT);
        expect(describeStorageNotice({ available: true })).toBeNull();
        expect(describeStorageNotice(null)).toBeNull();
    });
});

describe('marca de vinculación', () => {
    it('recuerda el terminal con el que se vinculó y distingue otro código', () => {
        const local = memoryLocal();

        rememberLinked('abc12345', local);

        expect(hasLinkMarker('abc12345', local)).toBe(true);
        expect(hasLinkMarker('otro', local)).toBe(false);
    });

    it('sin marca, sin código o con localStorage roto devuelve false sin lanzar', () => {
        expect(hasLinkMarker('abc', memoryLocal())).toBe(false);
        expect(hasLinkMarker(null, memoryLocal())).toBe(false);
        expect(hasLinkMarker('abc', { getItem: () => { throw new Error('bloqueado'); } })).toBe(false);
        expect(() => rememberLinked('abc', { setItem: () => { throw new Error('lleno'); } })).not.toThrow();
    });
});
