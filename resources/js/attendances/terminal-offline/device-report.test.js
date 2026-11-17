// resources/js/attendances/terminal-offline/device-report.test.js
/**
 * @fileoverview Tests de collectDeviceReport(): todo dato es best-effort, se
 * omite si el navegador no lo informa y nunca lanza.
 */
import { describe, it, expect } from 'vitest';
import { collectDeviceReport } from './device-report.js';

/** La zona horaria la informa cualquier entorno con Intl: estos tests exactos comparan el resto del reporte. */
const withoutTimezone = ({ timezone, ...rest }) => rest;

/** Ventana falsa en un modo de visualización dado (`browser` = pestaña) y, opcionalmente, con la visibilidad de la página. */
const win = (mode = 'browser', visibility) => ({
    matchMedia: (query) => ({ matches: query === `(display-mode: ${mode})` }),
    ...(visibility ? { document: { visibilityState: visibility } } : {}),
});

describe('collectDeviceReport', () => {
    it('arma el reporte completo cuando el navegador lo informa todo', async () => {
        const nav = {
            getBattery: async () => ({ level: 0.42, charging: true }),
            mediaDevices: { getUserMedia: () => {} },
            permissions: { query: async () => ({ state: 'granted' }) },
            storage: { estimate: async () => ({ usage: 50 * 1024 * 1024, quota: 2048 * 1024 * 1024 }) },
            serviceWorker: { controller: {} },
        };

        const report = await collectDeviceReport({ nav, win: win('standalone'), appVersion: 'abc123', cachedEmployees: 12, clockOffsetMs: -4600 });

        expect(withoutTimezone(report)).toEqual({
            app_version: 'abc123',
            display_mode: 'standalone',
            standalone: true,
            sw_active: true,
            cached_employees: 12,
            clock_skew_seconds: -5,
            battery_level: 42,
            battery_charging: true,
            camera: 'granted',
            storage_used_mb: 50,
            storage_quota_mb: 2048,
        });
    });

    it('omite lo que el navegador no informa (sin Battery API ni storage, como en iOS)', async () => {
        const nav = { mediaDevices: { getUserMedia: () => {} }, standalone: true };

        const report = await collectDeviceReport({ nav, win: win() });

        expect(withoutTimezone(report)).toEqual({ display_mode: 'standalone', standalone: true });
        expect(report).not.toHaveProperty('battery_level');
        expect(report).not.toHaveProperty('storage_used_mb');
        expect(report).not.toHaveProperty('camera');
    });

    it('marca la cámara como no disponible si el navegador no tiene getUserMedia', async () => {
        const report = await collectDeviceReport({ nav: {}, win: {} });

        expect(report.camera).toBe('unavailable');
    });

    it('reporta cámara bloqueada (denied) y service worker sin controlar la página', async () => {
        const nav = {
            mediaDevices: { getUserMedia: () => {} },
            permissions: { query: async () => ({ state: 'denied' }) },
            serviceWorker: { controller: null },
        };

        const report = await collectDeviceReport({ nav, win: win() });

        expect(report.camera).toBe('denied');
        expect(report.sw_active).toBe(false);
    });

    it('nunca lanza aunque fallen las APIs del navegador', async () => {
        const nav = {
            getBattery: async () => { throw new Error('bloqueado'); },
            mediaDevices: { getUserMedia: () => {} },
            permissions: { query: async () => { throw new Error('no soportado'); } },
            storage: { estimate: async () => { throw new Error('error'); } },
        };

        const report = await collectDeviceReport({ nav, win: { matchMedia: () => { throw new Error('x'); } } });

        expect(withoutTimezone(report)).toEqual({});
    });

    it('trunca la versión a 40 caracteres y descarta valores no numéricos', async () => {
        const report = await collectDeviceReport({
            nav: {}, win: {}, appVersion: 'v'.repeat(80), cachedEmployees: NaN, clockOffsetMs: null,
        });

        expect(report.app_version).toHaveLength(40);
        expect(report).not.toHaveProperty('cached_employees');
        expect(report).not.toHaveProperty('clock_skew_seconds');
    });

    it('informa si la pantalla se mantiene encendida (Wake Lock) solo cuando se sabe', async () => {
        expect((await collectDeviceReport({ nav: {}, win: win(), wakeLock: true })).wake_lock).toBe(true);
        expect((await collectDeviceReport({ nav: {}, win: win(), wakeLock: false })).wake_lock).toBe(false);
        expect(await collectDeviceReport({ nav: {}, win: win(), wakeLock: null })).not.toHaveProperty('wake_lock');
    });

    it('informa el motivo del fallo del Wake Lock solo cuando no se logró', async () => {
        const fallo = await collectDeviceReport({ nav: {}, win: win(), wakeLock: false, wakeLockError: 'NotAllowedError' });
        expect(fallo.wake_lock_error).toBe('NotAllowedError');

        const ok = await collectDeviceReport({ nav: {}, win: win(), wakeLock: true, wakeLockError: 'NotAllowedError' });
        expect(ok).not.toHaveProperty('wake_lock_error');

        const sinMotivo = await collectDeviceReport({ nav: {}, win: win(), wakeLock: false });
        expect(sinMotivo).not.toHaveProperty('wake_lock_error');
    });

    describe('modo de visualización y visibilidad', () => {
        it.each([
            ['browser', false],
            ['standalone', true],
            ['fullscreen', true],
            ['minimal-ui', true],
            ['window-controls-overlay', true],
        ])('modo %s: standalone = %s', async (mode, standalone) => {
            const report = await collectDeviceReport({ nav: {}, win: win(mode) });

            expect(report.display_mode).toBe(mode);
            expect(report.standalone).toBe(standalone);
        });

        it('sin matchMedia no informa el modo (no se puede saber)', async () => {
            const report = await collectDeviceReport({ nav: {}, win: {} });

            expect(report).not.toHaveProperty('display_mode');
            expect(report).not.toHaveProperty('standalone');
        });

        it('informa si la página está visible u oculta', async () => {
            expect((await collectDeviceReport({ nav: {}, win: win('browser', 'visible') })).visible).toBe(true);
            expect((await collectDeviceReport({ nav: {}, win: win('browser', 'hidden') })).visible).toBe(false);
            expect(await collectDeviceReport({ nav: {}, win: win() })).not.toHaveProperty('visible');
        });
    });
    it('incluye el tiempo de identificación cuando hay muestras', async () => {
        const report = await collectDeviceReport({ nav: {}, win: {}, identification: { samples: 12, median_ms: 2100.4, p90_ms: 4800 } });

        expect(report).toMatchObject({ identification_median_ms: 2100, identification_p90_ms: 4800, identification_samples: 12 });
    });

    it('incluye los contadores acumulados de identificación cuando existen', async () => {
        const report = await collectDeviceReport({ nav: {}, win: {}, identificationTotals: { attempts: 10, failures: 2, successes: 8, msTotal: 16000.4 } });

        expect(report).toMatchObject({ identification_attempts: 10, identification_failures: 2, identification_successes: 8, identification_ms_total: 16000 });
    });

    it('sin contadores de identificación no manda ninguno', async () => {
        for (const identificationTotals of [null, undefined, { attempts: NaN }]) {
            const report = await collectDeviceReport({ nav: {}, win: {}, identificationTotals });

            expect(report).not.toHaveProperty('identification_attempts');
            expect(report).not.toHaveProperty('identification_ms_total');
        }
    });

    it('omite el tiempo de identificación si todavía no hubo ninguna o llega incompleto', async () => {
        for (const identification of [null, undefined, { samples: 0 }, { samples: 3, median_ms: NaN, p90_ms: 10 }]) {
            const report = await collectDeviceReport({ nav: {}, win: {}, identification });

            expect(report).not.toHaveProperty('identification_median_ms');
            expect(report).not.toHaveProperty('identification_samples');
        }
    });
});

describe('datos del equipo (readDeviceInfo) y device_id', () => {
    it('incluye memoria, núcleos, conexión, pantalla, idioma, zona y plataforma cuando el navegador los informa', async () => {
        const nav = { deviceMemory: 8, hardwareConcurrency: 4, connection: { effectiveType: '4g' }, language: 'es-PY', userAgentData: { platform: 'Windows' } };
        const win = { screen: { width: 1366, height: 768 } };

        const report = await collectDeviceReport({ nav, win, deviceId: '3f2b8c1e-aaaa-bbbb-cccc-123456789012' });

        expect(report).toMatchObject({
            device_id: '3f2b8c1e-aaaa-bbbb-cccc-123456789012',
            device_memory_gb: 8,
            cpu_cores: 4,
            connection_type: '4g',
            screen: '1366x768',
            language: 'es-PY',
            platform: 'Windows',
        });
        expect(typeof report.timezone).toBe('string');
    });

    it('omite lo que el navegador no informa y descarta valores raros', async () => {
        const nav = { connection: { effectiveType: 'wifi' }, language: '<script>', hardwareConcurrency: 0 };
        const win = { screen: { width: 0, height: 0 } };

        const report = await collectDeviceReport({ nav, win });

        expect(report).not.toHaveProperty('device_id');
        expect(report).not.toHaveProperty('device_memory_gb');
        expect(report).not.toHaveProperty('cpu_cores');
        expect(report).not.toHaveProperty('connection_type');
        expect(report).not.toHaveProperty('screen');
        expect(report).not.toHaveProperty('language');
    });
});

describe('collectDeviceReport con ubicación', () => {
    it('incluye la última lectura de un equipo móvil y su error si no hay permiso', async () => {
        const reading = { lat: -25.5, lng: -54.6, accuracy_m: 20, read_at: '2026-11-17T12:00:00.000Z' };

        expect((await collectDeviceReport({ nav: {}, win: {}, location: reading })).location).toEqual(reading);
        expect((await collectDeviceReport({ nav: {}, win: {}, location: { error: 'denied' } })).location_error).toBe('denied');
    });

    it('sin ubicación (equipo fijo o sin lectura todavía) no manda nada', async () => {
        const report = await collectDeviceReport({ nav: {}, win: {}, location: null });

        expect(report).not.toHaveProperty('location');
        expect(report).not.toHaveProperty('location_error');
    });
});
