import { describe, it, expect, vi } from 'vitest';
import { initThemeToggle } from './theme-toggle.js';

function fakeStorage(initial = {}) {
    const data = { ...initial };
    return {
        getItem: (key) => (key in data ? data[key] : null),
        setItem: (key, value) => { data[key] = value; },
        _data: data,
    };
}

function fakeDoc(hasButton = true) {
    const html = { attrs: {}, setAttribute(name, value) { this.attrs[name] = value; }, getAttribute(name) { return this.attrs[name] ?? null; } };
    const btn = { listeners: {}, addEventListener(evt, fn) { this.listeners[evt] = fn; } };
    return {
        documentElement: html,
        getElementById: (id) => (id === 'btnThemeToggle' && hasButton ? btn : null),
        _btn: btn,
    };
}

function fakeWin(prefersDark = false) {
    return { matchMedia: () => ({ matches: prefersDark }) };
}

describe('initThemeToggle', () => {
    it('aplica data-theme="dark" si hay un valor guardado "dark"', () => {
        const doc = fakeDoc();
        initThemeToggle('mark-theme', doc, fakeWin(false), fakeStorage({ 'mark-theme': 'dark' }));
        expect(doc.documentElement.getAttribute('data-theme')).toBe('dark');
    });

    it('cae a prefers-color-scheme si no hay nada guardado', () => {
        const doc = fakeDoc();
        initThemeToggle('mark-theme', doc, fakeWin(true), fakeStorage());
        expect(doc.documentElement.getAttribute('data-theme')).toBe('dark');
        // segunda verificación con prefersDark=false
        const doc2 = fakeDoc();
        initThemeToggle('mark-theme', doc2, fakeWin(false), fakeStorage());
        expect(doc2.documentElement.getAttribute('data-theme')).toBe('light');
    });

    it('alterna y persiste en storage bajo la clave propia al hacer click', () => {
        const doc = fakeDoc();
        const storage = fakeStorage({ 'mark-theme': 'light' });
        initThemeToggle('mark-theme', doc, fakeWin(false), storage);

        doc._btn.listeners.click();

        expect(doc.documentElement.getAttribute('data-theme')).toBe('dark');
        expect(storage.getItem('mark-theme')).toBe('dark');
    });

    it('no revienta si el botón no existe en el DOM', () => {
        const doc = fakeDoc(false);
        expect(() => initThemeToggle('mark-theme', doc, fakeWin(false), fakeStorage())).not.toThrow();
    });

    it('no comparte estado entre distintas storageKey', () => {
        const storage = fakeStorage({ 'terminal-theme': 'dark' });
        const doc = fakeDoc();
        initThemeToggle('mark-theme', doc, fakeWin(false), storage);
        expect(doc.documentElement.getAttribute('data-theme')).toBe('light');
    });
});
