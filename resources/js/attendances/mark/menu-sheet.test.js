import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createMenuSheet } from './menu-sheet.js';

function fakeEl() {
    const classes = new Set();
    const attrs = {};
    return {
        classList: {
            add: (c) => classes.add(c),
            remove: (c) => classes.delete(c),
            contains: (c) => classes.has(c),
        },
        setAttribute: (k, v) => { attrs[k] = v; },
        getAttribute: (k) => attrs[k] ?? null,
        addEventListener: vi.fn(),
        focus: vi.fn(),
        _classes: classes,
        _attrs: attrs,
    };
}

function fakeFocusable(id) {
    const el = fakeEl();
    el.id = id;
    el.disabled = false;
    return el;
}

function fakeDoc(focusableEls) {
    let activeElement = fakeEl();
    const listeners = {};
    return {
        get activeElement() { return activeElement; },
        addEventListener: vi.fn((type, cb) => { listeners[type] = cb; }),
        removeEventListener: vi.fn((type) => { delete listeners[type]; }),
        _setActiveElement: (el) => { activeElement = el; },
        _fireKeydown: (event) => listeners.keydown?.(event),
    };
}

function makeEls() {
    const first = fakeFocusable('first');
    const last = fakeFocusable('last');
    const panel = {
        ...fakeEl(),
        querySelectorAll: vi.fn(() => [first, last]),
    };
    return {
        sheet: fakeEl(),
        backdrop: fakeEl(),
        panel,
        trigger: fakeEl(),
        first,
        last,
    };
}

describe('createMenuSheet', () => {
    it('open() agrega la clase is-open, marca aria-hidden=false y enfoca el primer elemento focuseable', () => {
        const els = makeEls();
        const doc = fakeDoc();
        const sheet = createMenuSheet(els, doc);

        sheet.open();

        expect(els.sheet._classes.has('is-open')).toBe(true);
        expect(els.sheet._attrs['aria-hidden']).toBe('false');
        expect(els.trigger._attrs['aria-expanded']).toBe('true');
        expect(els.first.focus).toHaveBeenCalledTimes(1);
        expect(sheet.isOpen()).toBe(true);
    });

    it('close() quita is-open, marca aria-hidden=true y devuelve el foco a quien lo tenía antes de abrir', () => {
        const els = makeEls();
        const previouslyFocused = fakeFocusable('previously-focused');
        const doc = fakeDoc();
        doc._setActiveElement(previouslyFocused);
        const sheet = createMenuSheet(els, doc);

        sheet.open();
        sheet.close();

        expect(els.sheet._classes.has('is-open')).toBe(false);
        expect(els.sheet._attrs['aria-hidden']).toBe('true');
        expect(els.trigger._attrs['aria-expanded']).toBe('false');
        expect(previouslyFocused.focus).toHaveBeenCalledTimes(1);
        expect(sheet.isOpen()).toBe(false);
    });

    it('Escape cierra el sheet cuando está abierto', () => {
        const els = makeEls();
        const doc = fakeDoc();
        const sheet = createMenuSheet(els, doc);

        sheet.open();
        doc._fireKeydown({ key: 'Escape', preventDefault: vi.fn() });

        expect(sheet.isOpen()).toBe(false);
    });

    it('Tab en el último elemento focuseable vuelve al primero (focus trap)', () => {
        const els = makeEls();
        const doc = fakeDoc();
        doc._setActiveElement(els.last);
        const sheet = createMenuSheet(els, doc);
        sheet.open();

        const event = { key: 'Tab', shiftKey: false, preventDefault: vi.fn() };
        doc._fireKeydown(event);

        expect(event.preventDefault).toHaveBeenCalledTimes(1);
        expect(els.first.focus).toHaveBeenCalledTimes(2); // 1 al abrir + 1 por el trap
    });

    it('click en el trigger alterna abierto/cerrado', () => {
        const els = makeEls();
        const doc = fakeDoc();
        createMenuSheet(els, doc);

        const triggerClickHandler = els.trigger.addEventListener.mock.calls
            .find(([type]) => type === 'click')[1];

        expect(els.sheet._classes.has('is-open')).toBe(false);
        triggerClickHandler();
        expect(els.sheet._classes.has('is-open')).toBe(true);
        triggerClickHandler();
        expect(els.sheet._classes.has('is-open')).toBe(false);
    });
});
