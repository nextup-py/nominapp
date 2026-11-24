import { describe, it, expect, vi } from 'vitest';
import { buildChecklist, createDeviceChecklist, DISMISS_MINUTES, PENDING_THRESHOLD, TEXTS, OK_LABELS } from './device-checklist.js';

const healthy = {
    online: true,
    displayMode: 'fullscreen',
    installText: null,
    chargerText: null,
    charging: true,
    wakeLock: { active: true, error: null },
    cameraPermission: 'granted',
    storageText: null,
    pending: 0,
    outdated: false,
};

const failing = (rows) => rows.filter((row) => !row.ok).map((row) => row.key);

describe('buildChecklist', () => {
    it('con todo en orden marca cada fila como lograda y no pide atención', () => {
        const { rows, needsAttention } = buildChecklist(healthy);

        expect(needsAttention).toBe(false);
        expect(rows.map((row) => row.key)).toEqual(['connection', 'app', 'power', 'screen', 'camera']);
        expect(rows.every((row) => row.ok)).toBe(true);
        expect(rows.find((row) => row.key === 'app').text).toBe(OK_LABELS.app);
    });

    it('sin internet avisa que las marcaciones se guardan y se envían luego', () => {
        const { rows, needsAttention } = buildChecklist({ ...healthy, online: false });

        expect(needsAttention).toBe(true);
        expect(rows.find((row) => row.key === 'connection')).toMatchObject({ ok: false, text: TEXTS.offline });
    });

    it('usa los textos de instalación y de cargador que le pasan los otros módulos', () => {
        const { rows } = buildChecklist({ ...healthy, displayMode: 'browser', installText: 'Instalar la app', chargerText: 'Enchufar el equipo', charging: false });

        expect(rows.find((row) => row.key === 'app')).toMatchObject({ ok: false, text: 'Instalar la app' });
        expect(rows.find((row) => row.key === 'power')).toMatchObject({ ok: false, text: 'Enchufar el equipo' });
    });

    it('una pestaña sin aviso de instalación (ocultado) no cuenta ni como falla ni como lograda', () => {
        const { rows } = buildChecklist({ ...healthy, displayMode: 'browser' });

        expect(rows.find((row) => row.key === 'app')).toBeUndefined();
    });

    it.each([
        ['pantalla que se apaga', { wakeLock: { active: false, error: 'NotAllowedError' } }, 'screen'],
        ['cámara sin permiso', { cameraPermission: 'denied' }, 'camera'],
        ['navegador que no guarda datos', { storageText: 'No conserva datos' }, 'storage'],
        ['cola larga', { pending: PENDING_THRESHOLD }, 'pending'],
        ['app desactualizada', { outdated: true }, 'outdated'],
    ])('avisa: %s', (_name, override, key) => {
        const { rows, needsAttention } = buildChecklist({ ...healthy, ...override });

        expect(needsAttention).toBe(true);
        expect(failing(rows)).toEqual([key]);
    });

    it('una pestaña oculta suelta el bloqueo de pantalla sin ser una falla', () => {
        const { rows } = buildChecklist({ ...healthy, wakeLock: { active: false, error: 'hidden' } });

        expect(rows.find((row) => row.key === 'screen')).toBeUndefined();
        expect(failing(rows)).toEqual([]);
    });

    it('lo que el navegador no informa no aparece', () => {
        const { rows, needsAttention } = buildChecklist({ ...healthy, online: null, displayMode: null, charging: null, wakeLock: { active: null, error: null }, cameraPermission: 'prompt' });

        expect(rows).toEqual([]);
        expect(needsAttention).toBe(false);
    });

    it('con pocas marcaciones pendientes no avisa', () => {
        expect(failing(buildChecklist({ ...healthy, pending: PENDING_THRESHOLD - 1 }).rows)).toEqual([]);
    });
});

describe('createDeviceChecklist', () => {
    /** Elemento mínimo del DOM: guarda clases, atributos y hijos. */
    function fakeElement(tag = 'div') {
        const classes = new Set();

        return {
            tag,
            className: '',
            dataset: {},
            textContent: '',
            children: [],
            attributes: {},
            classList: { toggle: (name, on) => (on ? classes.add(name) : classes.delete(name)), contains: (name) => classes.has(name) },
            setAttribute(name, value) { this.attributes[name] = value; },
            append(...nodes) { this.children.push(...nodes); },
            replaceChildren(...nodes) { this.children = nodes; },
            addEventListener(event, handler) { this.handler = handler; },
        };
    }

    const doc = { createElement: (tag) => fakeElement(tag) };

    function setup(state, now = () => 1_000_000) {
        const card = fakeElement();
        card.classList.toggle('hidden', true);
        const list = fakeElement('ul');
        const dismissButton = fakeElement('button');
        const collect = vi.fn().mockResolvedValue(state);
        const checklist = createDeviceChecklist({ collect, now, doc, elements: { card, list, dismissButton } });
        const hidden = () => card.classList.contains('hidden');
        const click = () => dismissButton.handler({ stopPropagation() {} });

        return { checklist, list, collect, hidden, click };
    }

    it('con todo en orden mantiene el cartel oculto', async () => {
        const { checklist, hidden } = setup(healthy);
        await checklist.refresh();

        expect(hidden()).toBe(true);
    });

    it('si algo falla muestra el cartel con las filas, sin interpretar los textos como HTML', async () => {
        const { checklist, hidden, list } = setup({ ...healthy, online: false, storageText: '<b>peligro</b>' });
        await checklist.refresh();

        expect(hidden()).toBe(false);
        expect(list.children).toHaveLength(6);
        expect(list.children.filter((item) => item.className.includes('--warn'))).toHaveLength(2);
        const storage = list.children.find((item) => item.dataset.check === 'storage');
        expect(storage.children[1].textContent).toBe('<b>peligro</b>');
        expect(storage.children[0].attributes['aria-hidden']).toBe('true');
    });

    it('«Ocultar» lo esconde por un rato y vuelve a aparecer pasado el tiempo', async () => {
        let now = 1_000_000;
        const { checklist, hidden, click } = setup({ ...healthy, online: false }, () => now);
        await checklist.refresh();

        click();
        expect(hidden()).toBe(true);

        now += (DISMISS_MINUTES - 1) * 60 * 1000;
        await checklist.refresh();
        expect(hidden()).toBe(true);

        now += 2 * 60 * 1000;
        await checklist.refresh();
        expect(hidden()).toBe(false);
    });

    it('desaparece solo cuando se corrige todo', async () => {
        const { checklist, hidden, collect } = setup({ ...healthy, online: false });
        await checklist.refresh();
        expect(hidden()).toBe(false);

        collect.mockResolvedValue(healthy);
        await checklist.refresh();
        expect(hidden()).toBe(true);
    });

    it('si no puede leer el estado deja el cartel como estaba', async () => {
        const { checklist, hidden, collect } = setup({ ...healthy, online: false });
        await checklist.refresh();

        collect.mockRejectedValue(new Error('boom'));
        await checklist.refresh();

        expect(hidden()).toBe(false);
    });

    it('start evalúa de inmediato y periódicamente hasta que se lo detiene', async () => {
        vi.useFakeTimers();
        const { checklist, collect } = setup(healthy);

        const stop = checklist.start();
        expect(collect).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(31_000);
        expect(collect).toHaveBeenCalledTimes(2);

        stop();
        await vi.advanceTimersByTimeAsync(60_000);
        expect(collect).toHaveBeenCalledTimes(2);
        vi.useRealTimers();
    });
});
