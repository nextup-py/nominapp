import { describe, it, expect, vi } from 'vitest';
import { createLocationReader, describeLocationError, LOCATION_INTERVAL_MS, RETRY_AFTER_ERROR_MS } from './location-reader.js';

/** Geolocation falsa que responde con lo que se le indique. */
function fakeGeolocation(outcomes) {
    const queue = [...outcomes];

    return { getCurrentPosition: vi.fn((ok, fail) => { const next = queue.shift(); next.error ? fail(next.error) : ok(next.position); }) };
}

const position = (lat, lng, accuracy = 12.4) => ({ position: { coords: { latitude: lat, longitude: lng, accuracy } } });

describe('describeLocationError', () => {
    it.each([[1, 'denied'], [2, 'unavailable'], [3, 'timeout'], [undefined, 'unavailable']])('código %s → %s', (code, expected) => {
        expect(describeLocationError({ code })).toBe(expected);
    });
});

describe('createLocationReader', () => {
    it('lee una vez, redondea la precisión y no vuelve a leer hasta pasados 10 minutos', () => {
        let time = 1_000_000;
        const geolocation = fakeGeolocation([position(-25.5, -54.6), position(-25.6, -54.7)]);
        const reader = createLocationReader({ geolocation, now: () => time });

        reader.refreshIfStale();
        expect(reader.snapshot()).toMatchObject({ lat: -25.5, lng: -54.6, accuracy_m: 12 });

        time += LOCATION_INTERVAL_MS - 1000;
        reader.refreshIfStale();
        expect(geolocation.getCurrentPosition).toHaveBeenCalledTimes(1);

        time += 2000;
        reader.refreshIfStale();
        expect(geolocation.getCurrentPosition).toHaveBeenCalledTimes(2);
        expect(reader.snapshot().lat).toBe(-25.6);
    });

    it('sin permiso informa «denied» y no reintenta hasta el próximo intervalo', () => {
        let time = 0;
        const geolocation = fakeGeolocation([{ error: { code: 1 } }, position(1, 2)]);
        const reader = createLocationReader({ geolocation, now: () => time });

        reader.refreshIfStale();
        expect(reader.snapshot()).toEqual({ error: 'denied' });

        time += RETRY_AFTER_ERROR_MS + 1;
        reader.refreshIfStale();
        expect(geolocation.getCurrentPosition).toHaveBeenCalledTimes(1);
    });

    it('un fallo transitorio se reintenta al minuto', () => {
        let time = 0;
        const geolocation = fakeGeolocation([{ error: { code: 3 } }, position(1, 2)]);
        const reader = createLocationReader({ geolocation, now: () => time });

        reader.refreshIfStale();
        expect(reader.snapshot()).toEqual({ error: 'timeout' });

        time += RETRY_AFTER_ERROR_MS + 1;
        reader.refreshIfStale();
        expect(reader.snapshot()).toMatchObject({ lat: 1, lng: 2 });
    });

    it('sin Geolocation en el navegador informa «unavailable» y no lanza', () => {
        const reader = createLocationReader({ geolocation: undefined });

        expect(() => reader.refreshIfStale()).not.toThrow();
        expect(reader.snapshot()).toEqual({ error: 'unavailable' });
    });

    it('no dispara dos lecturas a la vez', () => {
        const geolocation = { getCurrentPosition: vi.fn() }; // nunca responde
        const reader = createLocationReader({ geolocation });

        reader.refreshIfStale();
        reader.refreshIfStale();

        expect(geolocation.getCurrentPosition).toHaveBeenCalledTimes(1);
        expect(reader.snapshot()).toBeNull();
    });
});
