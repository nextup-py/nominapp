import { describe, it, expect, vi } from 'vitest';
import { describeLowBattery, createLowBatteryAlert, DISMISS_MINUTES, CRITICAL_PERCENT } from './low-battery-alert.js';

const MIN = 60 * 1000;
const base = { charging: false, level: 18, lowPercent: 20, now: 0, dismissedUntil: 0 };

describe('describeLowBattery', () => {
    it('avisa con la batería por debajo del nivel y pide avisar a la encargada', () => {
        const alert = describeLowBattery(base);

        expect(alert.critical).toBe(false);
        expect(alert.text).toContain('Batería baja (18%)');
        expect(alert.text).toContain('encargada');
    });

    it('con la batería crítica el texto es más fuerte', () => {
        const alert = describeLowBattery({ ...base, level: CRITICAL_PERCENT });

        expect(alert.critical).toBe(true);
        expect(alert.text).toContain('casi agotada (10%)');
    });

    it.each([
        ['enchufado', { charging: true }],
        ['el navegador no informa la carga', { charging: null }],
        ['sin nivel de batería', { level: null }],
        ['nivel igual al configurado', { level: 20 }],
        ['nivel por encima del configurado', { level: 60 }],
        ['aviso apagado (0 %)', { lowPercent: 0 }],
        ['oculto por el usuario', { dismissedUntil: 5 * MIN, now: MIN }],
    ])('no avisa: %s', (_name, override) => {
        expect(describeLowBattery({ ...base, ...override })).toBeNull();
    });

    it('un cartel crítico ignora el «Entendido»', () => {
        expect(describeLowBattery({ ...base, level: 5, dismissedUntil: 60 * MIN, now: MIN })).not.toBeNull();
    });
});

describe('createLowBatteryAlert', () => {
    /** Elementos mínimos del DOM. */
    function dom() {
        const banner = new Set(['hidden']);
        const button = new Set();

        return {
            banner: { classList: { toggle: (name, on) => (on ? banner.add(name) : banner.delete(name)) } },
            text: { textContent: '' },
            dismissButton: { classList: { toggle: (name, on) => (on ? button.add(name) : button.delete(name)) }, addEventListener: vi.fn() },
            bannerHas: (name) => banner.has(name),
            buttonHas: (name) => button.has(name),
        };
    }

    it('muestra el cartel con batería baja y lo oculta al enchufar', async () => {
        let charging = false;
        const elements = dom();
        const alert = createLowBatteryAlert({ readBattery: async () => ({ charging, level: 18 }), getLowPercent: async () => 20, elements });

        await alert.check();
        expect(elements.bannerHas('hidden')).toBe(false);
        expect(elements.text.textContent).toContain('Batería baja (18%)');
        expect(alert.isShowing()).toBe(true);

        charging = true;
        await alert.check();
        expect(elements.bannerHas('hidden')).toBe(true);
        expect(alert.isShowing()).toBe(false);
    });

    it('«Entendido» lo oculta por unos minutos y reaparece', async () => {
        let time = 0;
        const elements = dom();
        const alert = createLowBatteryAlert({ readBattery: async () => ({ charging: false, level: 18 }), getLowPercent: async () => 20, now: () => time, elements });

        await alert.check();
        alert.dismissAlert();
        expect(elements.bannerHas('hidden')).toBe(true);

        time += (DISMISS_MINUTES + 1) * MIN;
        await alert.check();
        expect(elements.bannerHas('hidden')).toBe(false);
    });

    it('con la batería crítica queda en rojo y sin botón para ocultarlo', async () => {
        const elements = dom();
        const alert = createLowBatteryAlert({ readBattery: async () => ({ charging: false, level: 6 }), getLowPercent: async () => 20, elements });

        await alert.check();
        expect(elements.bannerHas('is-critical')).toBe(true);
        expect(elements.buttonHas('hidden')).toBe(true);

        alert.dismissAlert();
        expect(elements.bannerHas('hidden')).toBe(false);
    });

    it('un navegador sin Battery API (la lectura falla) no muestra nada ni lanza', async () => {
        const elements = dom();
        const alert = createLowBatteryAlert({ readBattery: async () => { throw new Error('sin API'); }, getLowPercent: async () => 20, elements });

        await expect(alert.check()).resolves.toBeUndefined();
        expect(elements.bannerHas('hidden')).toBe(true);
    });
});
