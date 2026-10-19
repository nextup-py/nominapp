// resources/js/attendances/terminal/install-notice.js
/**
 * @fileoverview Aviso discreto en la pantalla de reposo cuando el terminal corre en una pestaña del navegador, y
 * botón «Instalar app» del menú ⋮. Una pestaña puede quedar oculta detrás de otras y entonces el navegador suelta el
 * bloqueo de pantalla y el equipo se suspende; instalada como app (o en pantalla completa) tiene su propia ventana.
 *
 * Nunca impide marcar: solo informa. El descarte dura la sesión (`sessionStorage`), no para siempre, porque quien lo
 * toca suele ser un empleado y el aviso es para quien administra el equipo.
 */

/** Textos del aviso según el navegador pueda o no ofrecer la instalación. */
export const NOTICE_TEXTS = {
    installable: 'Este terminal está abierto en una pestaña del navegador. Instálelo como app para que el equipo no se suspenda.',
    manual: 'Este terminal está abierto en una pestaña del navegador. Ábralo como app o en modo kiosko para que el equipo no se suspenda (ver la guía de terminales).',
};

/**
 * Qué mostrar, si corresponde. Solo avisa en una pestaña (`browser`): una app instalada, la pantalla completa o un
 * modo desconocido (`null`) no necesitan el aviso.
 * @param {object} state
 * @param {string|null} state.displayMode - `browser`, `standalone`, `fullscreen`... o null si no se sabe.
 * @param {boolean} state.canInstall - el navegador ofreció instalar (`beforeinstallprompt`).
 * @param {boolean} state.dismissed - ya se ocultó en esta sesión.
 * @returns {{text: string, showInstall: boolean}|null}
 */
export function describeInstallNotice({ displayMode, canInstall, dismissed }) {
    if (displayMode !== 'browser' || dismissed) return null;

    return { text: canInstall ? NOTICE_TEXTS.installable : NOTICE_TEXTS.manual, showInstall: canInstall };
}

/**
 * Conecta el aviso y los botones de instalación con la pantalla. Las dependencias llegan inyectadas.
 * @param {object} deps
 * @param {() => string|null} deps.getDisplayMode
 * @param {() => boolean} deps.isDismissed
 * @param {() => void} deps.dismiss
 * @param {() => Promise<'accepted'|'dismissed'|'unavailable'>} deps.triggerInstall
 * @param {{notice: HTMLElement|null, text: HTMLElement|null, installButtons: Array<HTMLElement|null>, dismissButton: HTMLElement|null}} deps.elements
 * @returns {{setCanInstall: (value: boolean) => void, render: () => void, install: () => Promise<string>, dismissNotice: () => void}}
 */
export function createInstallNotice({ getDisplayMode, isDismissed, dismiss, triggerInstall, elements }) {
    let canInstall = false;

    /** Repinta el aviso y muestra u oculta los botones de instalar. */
    function render() {
        const displayMode = getDisplayMode();
        const notice = describeInstallNotice({ displayMode, canInstall, dismissed: isDismissed() });

        elements.notice?.classList.toggle('hidden', !notice);
        if (notice && elements.text) elements.text.textContent = notice.text;

        const offerInstall = canInstall && displayMode === 'browser';
        elements.installButtons.forEach((button) => button?.classList.toggle('hidden', !offerInstall || (button.dataset?.scope === 'notice' && !notice)));
    }

    /** Pide al navegador instalar la app; si se aceptó, el botón y el aviso ya no hacen falta. */
    async function install() {
        const outcome = await triggerInstall();

        if (outcome !== 'dismissed') canInstall = false;
        render();

        return outcome;
    }

    function dismissNotice() {
        dismiss();
        render();
    }

    elements.installButtons.forEach((button) => button?.addEventListener('click', (event) => {
        event.stopPropagation();
        install();
    }));
    elements.dismissButton?.addEventListener('click', (event) => {
        event.stopPropagation();
        dismissNotice();
    });

    return {
        setCanInstall(value) {
            canInstall = Boolean(value);
            render();
        },
        render,
        install,
        dismissNotice,
    };
}
