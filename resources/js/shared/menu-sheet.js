/**
 * =============================================================================
 * MENÚ DE ACCIONES SECUNDARIAS (HOJA INFERIOR) — compartido mark.js/terminal.js
 * =============================================================================
 *
 * @fileoverview Abrir/cerrar un menú `⋮` en hoja inferior con foco atrapado
 * dentro mientras está abierto (accesibilidad). Usado por mark.js (menú de
 * acciones del empleado) y terminal.js (estado del terminal + tema). Elementos
 * DOM inyectados como parámetros, no `document.getElementById` interno —
 * mismo patrón de `resources/js/shared/theme-toggle.js` (doc = document
 * inyectable) y `resources/js/attendances/terminal/screen-state.js`
 * (elementos por parámetro), para poder testear con objetos falsos sin jsdom.
 */

/**
 * @param {{ sheet: HTMLElement, backdrop: HTMLElement, panel: HTMLElement, trigger: HTMLElement, onOpen?: () => void }} els
 *   `onOpen` (opcional) se dispara al abrir el sheet — usado por mark.js para
 *   cancelar un dwell de auto-identificación en curso (dots ya llenándose)
 *   que de otro modo seguiría corriendo por su propio setInterval, ajeno a
 *   los ticks del drawLoop.
 * @param {Document} [doc] - inyectable para tests; document en producción
 * @returns {{ open: () => void, close: () => void, isOpen: () => boolean }}
 */
export function createMenuSheet(els, doc = document) {
    let open = false;
    let lastFocused = null;

    function getFocusable() {
        return Array.from(els.panel.querySelectorAll('button:not([disabled])'))
            .filter((el) => !el.classList.contains('hidden'));
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            closeSheet();
            return;
        }
        if (event.key !== 'Tab') return;

        const focusable = getFocusable();
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && doc.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && doc.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function openSheet() {
        if (open) return;
        open = true;
        els.onOpen?.();
        lastFocused = doc.activeElement;
        els.sheet.classList.add('is-open');
        els.sheet.setAttribute('aria-hidden', 'false');
        els.trigger.setAttribute('aria-expanded', 'true');
        doc.addEventListener('keydown', onKeydown);
        getFocusable()[0]?.focus();
    }

    function closeSheet() {
        if (!open) return;
        open = false;
        els.sheet.classList.remove('is-open');
        els.sheet.setAttribute('aria-hidden', 'true');
        els.trigger.setAttribute('aria-expanded', 'false');
        doc.removeEventListener('keydown', onKeydown);
        lastFocused?.focus?.();
    }

    els.backdrop.addEventListener('click', closeSheet);
    els.trigger.addEventListener('click', () => (open ? closeSheet() : openSheet()));

    return { open: openSheet, close: closeSheet, isOpen: () => open };
}
