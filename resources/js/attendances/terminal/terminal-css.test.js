/**
 * @fileoverview Regresión de la vista de cámara del terminal: el `<video>` debe mostrar la imagen completa
 * (`object-fit: contain`). Con `cover` un marco ancho y bajo recortaba la imagen y la persona se alejaba de
 * la cámara para "encajar" su rostro (queja real de bar777, oct. 2026).
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const css = readFileSync(resolve(__dirname, '../../../css/attendances/terminal.css'), 'utf8');

/**
 * Cuerpo de la primera regla que empieza con el selector dado.
 * @param {string} selector
 * @returns {string}
 */
function ruleBody(selector) {
    const match = css.match(new RegExp(`${selector.replace(/[.#]/g, '\\$&')}\\s*\\{([^}]*)\\}`));
    return match ? match[1] : '';
}

describe('terminal.css — vista de cámara', () => {
    it('el video muestra la imagen completa, sin recortar', () => {
        const body = ruleBody('#terminalVideo');

        expect(body).toContain('object-fit: contain');
        expect(body).not.toContain('object-fit: cover');
    });

    it('el marco de la cámara tiene fondo negro para las franjas que deja el modo contain', () => {
        expect(ruleBody('.video-wrapper')).toContain('background: #000');
    });
});
