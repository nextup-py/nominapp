/**
 * @fileoverview Cobertura de renderManualSearchResults(): distingue "caché de
 * empleados vacía" (terminal sin sincronizar) de "sin coincidencias" (hay
 * empleados pero ninguno con esa CI). No hay jsdom en el proyecto, así que el
 * DOM se simula con objetos mínimos vía vi.stubGlobal('document', ...).
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../terminal-offline/db.js', () => ({ getCachedEmployees: vi.fn() }));

import { getCachedEmployees } from '../terminal-offline/db.js';
import { renderManualSearchResults } from './manual-search.js';

function fakeElement() {
    const classes = new Set(['hidden']);
    return {
        innerHTML: '',
        textContent: '',
        children: [],
        classList: {
            add: (c) => classes.add(c),
            remove: (c) => classes.delete(c),
            toggle: (c, force) => (force ? classes.add(c) : classes.delete(c)),
            contains: (c) => classes.has(c),
        },
        appendChild(child) { this.children.push(child); },
    };
}

describe('renderManualSearchResults', () => {
    let results;
    let empty;

    beforeEach(() => {
        vi.clearAllMocks();
        results = fakeElement();
        empty = fakeElement();
        empty.textContent = 'No se encontraron empleados con ese CI.';
        vi.stubGlobal('document', {
            getElementById: (id) => ({ manualSearchResults: results, manualSearchEmpty: empty }[id] ?? null),
            createElement: () => ({ ...fakeElement(), setAttribute: vi.fn(), addEventListener: vi.fn(), className: '' }),
        });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('caché vacía: avisa que el terminal no tiene empleados sincronizados, aun sin haber escrito nada', async () => {
        getCachedEmployees.mockResolvedValue([]);

        await renderManualSearchResults('');

        expect(empty.classList.contains('hidden')).toBe(false);
        expect(empty.textContent).toBe('Este terminal todavía no tiene empleados sincronizados.');
    });

    it('caché vacía + CI escrita: sigue mostrando el aviso de sincronización, no "no encontrado"', async () => {
        getCachedEmployees.mockResolvedValue([]);

        await renderManualSearchResults('1234567');

        expect(empty.classList.contains('hidden')).toBe(false);
        expect(empty.textContent).toBe('Este terminal todavía no tiene empleados sincronizados.');
        expect(results.children).toHaveLength(0);
    });

    it('caché con empleados y búsqueda sin coincidencias: mensaje de "no encontrado"', async () => {
        getCachedEmployees.mockResolvedValue([{ id: 1, first_name: 'Ana', last_name: 'Gómez', ci: '1111111' }]);

        await renderManualSearchResults('9999');

        expect(empty.classList.contains('hidden')).toBe(false);
        expect(empty.textContent).toBe('No se encontraron empleados con ese CI.');
        expect(results.children).toHaveLength(0);
    });

    it('vuelve al texto de "no encontrado" tras haber mostrado el de caché vacía', async () => {
        getCachedEmployees.mockResolvedValueOnce([]);
        await renderManualSearchResults('');
        expect(empty.textContent).toBe('Este terminal todavía no tiene empleados sincronizados.');

        getCachedEmployees.mockResolvedValueOnce([{ id: 1, first_name: 'Ana', last_name: 'Gómez', ci: '1111111' }]);
        await renderManualSearchResults('9999');

        expect(empty.textContent).toBe('No se encontraron empleados con ese CI.');
    });

    it('caché con empleados y menos de 2 dígitos: no muestra ningún mensaje', async () => {
        getCachedEmployees.mockResolvedValue([{ id: 1, first_name: 'Ana', last_name: 'Gómez', ci: '1111111' }]);

        await renderManualSearchResults('1');

        expect(empty.classList.contains('hidden')).toBe(true);
    });

    it('con coincidencias: renderiza los resultados y oculta el mensaje vacío', async () => {
        getCachedEmployees.mockResolvedValue([
            { id: 1, first_name: 'Ana', last_name: 'Gómez', ci: '1111111' },
            { id: 2, first_name: 'Luis', last_name: 'Paz', ci: '2222222' },
        ]);

        await renderManualSearchResults('1111');

        expect(results.children).toHaveLength(1);
        expect(empty.classList.contains('hidden')).toBe(true);
    });
});
