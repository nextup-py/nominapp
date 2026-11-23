import { describe, it, expect, vi } from 'vitest';
import { describeChargerNotice, createChargerNotice, DISMISS_MINUTES } from './charger-notice.js';

const MIN = 60 * 1000;
const base = { charging: false, level: 55, unchargedSince: 0, now: 31 * MIN, minMinutes: 30, dismissedUntil: 0 };

describe('describeChargerNotice', () => {
    it('avisa pasado el tiempo configurado sin cargador y menciona la batería', () => {
        expect(describeChargerNotice(base)).toContain('no está enchufado (batería al 55%)');
    });

    it('no avisa antes del tiempo configurado', () => {
        expect(describeChargerNotice({ ...base, now: 29 * MIN })).toBeNull();
    });

    it.each([
        ['enchufado', { charging: true }],
        ['el navegador no informa la batería', { charging: null, unchargedSince: null }],
        ['aviso apagado (0 minutos)', { minMinutes: 0 }],
        ['oculto por el usuario', { dismissedUntil: 60 * MIN }],
    ])('no avisa: %s', (_name, override) => {
        expect(describeChargerNotice({ ...base, ...override })).toBeNull();
    });

    it('sin nivel de batería no lo menciona', () => {
        expect(describeChargerNotice({ ...base, level: null })).not.toContain('batería al');
    });
});

describe('createChargerNotice', () => {
    /** Elementos mínimos del DOM. */
    function dom() {
        const classes = new Set(['hidden']);

        return {
            notice: { classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)) } },
            text: { textContent: '' },
            dismissButton: { addEventListener: vi.fn() },
            hidden: () => classes.has('hidden'),
        };
    }

    it('muestra el aviso tras el tiempo sin cargador y lo oculta al enchufar', async () => {
        let time = 0;
        let charging = false;
        const elements = dom();
        const notice = createChargerNotice({
            readBattery: async () => ({ charging, level: 40 }),
            getMinutes: async () => 30,
            now: () => time,
            elements,
        });

        await notice.check();
        expect(elements.hidden()).toBe(true); // recién desenchufado

        time = 31 * MIN;
        await notice.check();
        expect(elements.hidden()).toBe(false);
        expect(elements.text.textContent).toContain('batería al 40%');

        charging = true;
        await notice.check();
        expect(elements.hidden()).toBe(true);
    });

    it('«Ocultar» lo esconde por un rato y vuelve después', async () => {
        let time = 0;
        const elements = dom();
        const notice = createChargerNotice({ readBattery: async () => ({ charging: false, level: 40 }), getMinutes: async () => 30, now: () => time, elements });

        await notice.check();
        time = 31 * MIN;
        await notice.check();
        expect(elements.hidden()).toBe(false);

        notice.dismissNotice();
        expect(elements.hidden()).toBe(true);

        time += (DISMISS_MINUTES + 1) * MIN;
        await notice.check();
        expect(elements.hidden()).toBe(false);
    });

    it('no se muestra mientras otro cartel más urgente (batería baja) está a la vista', async () => {
        let time = 0;
        let suppressed = true;
        const elements = dom();
        const notice = createChargerNotice({ readBattery: async () => ({ charging: false, level: 40 }), getMinutes: async () => 30, now: () => time, isSuppressed: () => suppressed, elements });

        await notice.check();
        time = 31 * MIN;
        await notice.check();
        expect(elements.hidden()).toBe(true);

        suppressed = false;
        await notice.check();
        expect(elements.hidden()).toBe(false);
    });

    it('un navegador sin Battery API (la lectura falla) no muestra nada ni lanza', async () => {
        const elements = dom();
        const notice = createChargerNotice({ readBattery: async () => { throw new Error('sin API'); }, getMinutes: async () => 30, elements });

        await expect(notice.check()).resolves.toBeUndefined();
        expect(elements.hidden()).toBe(true);
    });
});
