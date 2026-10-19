// resources/js/attendances/terminal/privacy-notice.js
/**
 * @fileoverview Aviso de privacidad del terminal. El texto y el enlace los carga cada empresa en el panel
 * (Empresas → Aviso de privacidad): el sistema solo los muestra, nunca redacta contenido legal. Se ve en tres
 * lugares: una línea al pie de la pantalla de reposo, una línea mientras se identifica el rostro y el ícono ⓘ del
 * encabezado, que abre el aviso completo con el código QR de la política.
 *
 * Funciona sin conexión: el aviso viaja en `window.terminalData` y el latido lo renueva en IndexedDB
 * (`terminal_meta.privacy_notice`; `null` = la empresa lo quitó). Sin aviso configurado no se muestra nada.
 */

/** Texto fijo de las líneas breves: apunta al aviso completo, no es contenido legal. */
export const SHORT_LINE = 'Su imagen facial se usa solo para registrar asistencia. Toque ⓘ para ver el aviso de privacidad.';

/**
 * Normaliza el aviso recibido del servidor o del caché; null si no hay nada que mostrar.
 * @param {object|null|undefined} raw
 * @returns {{text: string|null, url: string|null, version: number, qr: string|null}|null}
 */
export function normalizeNotice(raw) {
    if (!raw || typeof raw !== 'object') return null;

    const text = typeof raw.text === 'string' && raw.text.trim() !== '' ? raw.text.trim() : null;
    const url = typeof raw.url === 'string' && raw.url.trim() !== '' ? raw.url.trim() : null;
    if (!text && !url) return null;

    return {
        text,
        url,
        version: Number.isFinite(Number(raw.version)) ? Number(raw.version) : 1,
        qr: typeof raw.qr === 'string' && raw.qr.startsWith('data:image/') ? raw.qr : null,
    };
}

/**
 * Conecta el aviso con la pantalla. Las dependencias llegan inyectadas (se prueba sin DOM real).
 * @param {object} deps
 * @param {() => Promise<object|null>} deps.loadNotice - caché del latido, o el dato del servidor al cargar la página.
 * @param {{footer: HTMLElement|null, identLine: HTMLElement|null, trigger: HTMLElement|null, modal: HTMLElement|null, body: HTMLElement|null, qr: HTMLImageElement|null, link: HTMLElement|null, closeButtons: Array<HTMLElement|null>}} deps.elements
 * @param {Document} [deps.doc] - inyectable para tests.
 * @returns {{refresh: () => Promise<void>, open: () => void, close: () => void, isOpen: () => boolean}}
 */
export function createPrivacyNotice({ loadNotice, elements, doc = document }) {
    let notice = null;
    let opened = false;

    /** Muestra u oculta cada pieza según haya aviso. */
    function render() {
        const has = notice !== null;
        elements.footer?.classList.toggle('hidden', !has);
        elements.identLine?.classList.toggle('hidden', !has);
        elements.trigger?.classList.toggle('hidden', !has);
        if (!has && opened) close();
    }

    /** Relee el aviso (al cargar y después de cada latido). */
    async function refresh() {
        notice = normalizeNotice(await loadNotice());
        render();
    }

    function onKeydown(event) {
        if (event.key === 'Escape') close();
    }

    /** Abre el aviso completo. */
    function open() {
        if (!notice || opened) return;
        opened = true;

        if (elements.body) elements.body.textContent = notice.text ?? 'La empresa publicó su política de privacidad en el enlace de abajo.';
        if (elements.qr) {
            elements.qr.src = notice.qr ?? '';
            elements.qr.classList.toggle('hidden', !notice.qr);
        }
        if (elements.link) {
            elements.link.textContent = notice.url ?? '';
            elements.link.classList.toggle('hidden', !notice.url);
        }
        elements.modal?.classList.remove('hidden');
        elements.modal?.setAttribute('aria-hidden', 'false');
        doc.addEventListener('keydown', onKeydown);
        elements.closeButtons[0]?.focus?.();
    }

    /** Cierra el aviso completo. */
    function close() {
        if (!opened) return;
        opened = false;
        elements.modal?.classList.add('hidden');
        elements.modal?.setAttribute('aria-hidden', 'true');
        doc.removeEventListener('keydown', onKeydown);
        elements.trigger?.focus?.();
    }

    elements.trigger?.addEventListener('click', (event) => {
        event.stopPropagation();
        open();
    });
    elements.footer?.addEventListener('click', (event) => {
        event.stopPropagation();
        open();
    });
    elements.closeButtons.forEach((button) => button?.addEventListener('click', (event) => {
        event.stopPropagation();
        close();
    }));

    return { refresh, open, close, isOpen: () => opened };
}
