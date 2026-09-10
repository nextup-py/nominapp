/**
 * Toggle de tema claro/oscuro compartido por las vistas públicas de
 * marcación. Cada vista usa su propia clave de localStorage (no comparten
 * estado entre sí a propósito — un mismo dispositivo podría usarse en más
 * de un modo). Las dependencias del navegador se reciben como parámetros
 * con default al global real, para poder testear sin jsdom — mismo patrón
 * que resources/js/shared/install-prompt.js.
 */

/**
 * Aplica el tema guardado (o prefers-color-scheme si no hay nada guardado)
 * y engancha el click del botón de toggle para alternar y persistir.
 * @param {string} storageKey - clave de localStorage propia de esta vista (ej. 'mark-theme')
 * @param {Document} [doc] - inyectable para tests; document en producción
 * @param {Window} [win] - inyectable para tests; window en producción
 * @param {{getItem: (key: string) => string|null, setItem: (key: string, value: string) => void}} [storage] - inyectable para tests; localStorage en producción
 */
export function initThemeToggle(storageKey, doc = document, win = window, storage = localStorage) {
    const saved = storage.getItem(storageKey);
    const prefersDark = win.matchMedia('(prefers-color-scheme: dark)').matches;
    const isDark = saved === 'dark' || (!saved && prefersDark);
    doc.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');

    const btn = doc.getElementById('btnThemeToggle');
    if (!btn) return;

    btn.addEventListener('click', () => {
        const currentlyDark = doc.documentElement.getAttribute('data-theme') === 'dark';
        const next = currentlyDark ? 'light' : 'dark';
        doc.documentElement.setAttribute('data-theme', next);
        storage.setItem(storageKey, next);
    });
}
