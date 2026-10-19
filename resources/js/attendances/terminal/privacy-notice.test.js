import { describe, it, expect, vi } from 'vitest';
import { normalizeNotice, createPrivacyNotice } from './privacy-notice.js';

/** Elemento mínimo con classList y eventos, suficiente para probar sin DOM real. */
function fakeEl() {
    const listeners = {};
    const classes = new Set(['hidden']);
    return {
        textContent: '',
        src: '',
        attrs: {},
        focus: vi.fn(),
        classList: {
            add: (c) => classes.add(c),
            remove: (c) => classes.delete(c),
            toggle: (c, force) => { if (force) classes.add(c); else classes.delete(c); },
            contains: (c) => classes.has(c),
        },
        setAttribute(k, v) { this.attrs[k] = v; },
        addEventListener: (t, fn) => { listeners[t] = fn; },
        click() { listeners.click?.({ stopPropagation() {} }); },
    };
}

function setup(notice) {
    const doc = { addEventListener: vi.fn(), removeEventListener: vi.fn() };
    const els = {
        footer: fakeEl(), identLine: fakeEl(), trigger: fakeEl(), modal: fakeEl(),
        body: fakeEl(), qr: fakeEl(), link: fakeEl(), closeButtons: [fakeEl()],
    };
    let current = notice;
    const ctl = createPrivacyNotice({ loadNotice: async () => current, elements: els, doc });
    return { ctl, els, doc, set: (n) => { current = n; } };
}

describe('normalizeNotice', () => {
    it('devuelve null sin texto ni enlace', () => {
        expect(normalizeNotice(null)).toBeNull();
        expect(normalizeNotice({ text: '  ', url: '' })).toBeNull();
    });

    it('conserva texto, enlace, versión y solo un QR de imagen', () => {
        const n = normalizeNotice({ text: ' Hola ', url: 'https://x.py', version: 3, qr: 'javascript:alert(1)' });
        expect(n).toEqual({ text: 'Hola', url: 'https://x.py', version: 3, qr: null });
        expect(normalizeNotice({ url: 'https://x.py', qr: 'data:image/svg+xml;base64,AA==' }).qr).toBe('data:image/svg+xml;base64,AA==');
    });
});

describe('createPrivacyNotice', () => {
    it('oculta todo cuando la empresa no tiene aviso', async () => {
        const { ctl, els } = setup(null);
        await ctl.refresh();
        expect(els.footer.classList.contains('hidden')).toBe(true);
        expect(els.trigger.classList.contains('hidden')).toBe(true);
        ctl.open();
        expect(ctl.isOpen()).toBe(false);
    });

    it('muestra pie, línea e ícono y abre el aviso completo con su QR', async () => {
        const { ctl, els, doc } = setup({ text: 'Texto legal', url: 'https://x.py', version: 2, qr: 'data:image/svg+xml;base64,AA==' });
        await ctl.refresh();
        expect(els.footer.classList.contains('hidden')).toBe(false);
        expect(els.identLine.classList.contains('hidden')).toBe(false);
        expect(els.trigger.classList.contains('hidden')).toBe(false);

        els.trigger.click();
        expect(ctl.isOpen()).toBe(true);
        expect(els.body.textContent).toBe('Texto legal');
        expect(els.qr.src).toBe('data:image/svg+xml;base64,AA==');
        expect(els.link.textContent).toBe('https://x.py');
        expect(doc.addEventListener).toHaveBeenCalledWith('keydown', expect.any(Function));

        els.closeButtons[0].click();
        expect(ctl.isOpen()).toBe(false);
        expect(els.modal.classList.contains('hidden')).toBe(true);
    });

    it('con solo enlace muestra un texto de respaldo y oculta el QR si no llegó', async () => {
        const { ctl, els } = setup({ url: 'https://x.py' });
        await ctl.refresh();
        ctl.open();
        expect(els.body.textContent).toContain('política de privacidad');
        expect(els.qr.classList.contains('hidden')).toBe(true);
    });

    it('se cierra y se oculta si un latido posterior quita el aviso', async () => {
        const { ctl, els, set } = setup({ text: 'T' });
        await ctl.refresh();
        ctl.open();
        set(null);
        await ctl.refresh();
        expect(ctl.isOpen()).toBe(false);
        expect(els.footer.classList.contains('hidden')).toBe(true);
    });
});
