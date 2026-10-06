// resources/js/attendances/terminal-offline/device-report.test.js
/**
 * @fileoverview Tests de collectDeviceReport(): todo dato es best-effort, se
 * omite si el navegador no lo informa y nunca lanza.
 */
import { describe, it, expect } from 'vitest';
import { collectDeviceReport } from './device-report.js';

const win = (standalone = false) => ({ matchMedia: () => ({ matches: standalone }) });

describe('collectDeviceReport', () => {
    it('arma el reporte completo cuando el navegador lo informa todo', async () => {
        const nav = {
            getBattery: async () => ({ level: 0.42, charging: true }),
            mediaDevices: { getUserMedia: () => {} },
            permissions: { query: async () => ({ state: 'granted' }) },
            storage: { estimate: async () => ({ usage: 50 * 1024 * 1024, quota: 2048 * 1024 * 1024 }) },
            serviceWorker: { controller: {} },
        };

        const report = await collectDeviceReport({ nav, win: win(true), appVersion: 'abc123', cachedEmployees: 12, clockOffsetMs: -4600 });

        expect(report).toEqual({
            app_version: 'abc123',
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

        const report = await collectDeviceReport({ nav, win: win(false) });

        expect(report).toEqual({ standalone: true });
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

        expect(report).toEqual({});
    });

    it('trunca la versión a 40 caracteres y descarta valores no numéricos', async () => {
        const report = await collectDeviceReport({
            nav: {}, win: {}, appVersion: 'v'.repeat(80), cachedEmployees: NaN, clockOffsetMs: null,
        });

        expect(report.app_version).toHaveLength(40);
        expect(report).not.toHaveProperty('cached_employees');
        expect(report).not.toHaveProperty('clock_skew_seconds');
    });
});
