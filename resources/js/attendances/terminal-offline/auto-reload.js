/**
 * =============================================================================
 * RECARGA AUTOMÁTICA TRAS UN DEPLOY
 * =============================================================================
 *
 * @fileoverview El servidor informa en cada heartbeat la versión vigente de la
 * app (`Terminal::currentAppVersion()`). Si el terminal sigue corriendo una
 * versión distinta, se recarga solo cuando queda en reposo, sin esperar el
 * comando remoto "Recargar app". La cola offline está en IndexedDB, así que
 * recargar nunca pierde marcaciones.
 *
 * Para no entrar en un bucle de recargas si el navegador sigue sirviendo la
 * versión vieja desde su caché, solo se intenta una vez por versión y pestaña
 * (se recuerda en `sessionStorage`).
 */

/** Clave de `sessionStorage` con la última versión para la que ya se recargó. */
export const RELOAD_GUARD_KEY = 'terminal_reloaded_for_version';

/**
 * Recarga el terminal si corre una versión distinta de la vigente.
 * @param {object} options
 * @param {string|null|undefined} options.currentVersion - versión que corre el terminal (`window.terminalData.app_version`).
 * @param {string|null|undefined} options.serverVersion - versión vigente informada por el servidor.
 * @param {() => Promise<boolean>} options.waitForIdle - espera a que nadie esté marcando; false si venció el tiempo.
 * @param {() => void} options.reload - recarga la página.
 * @param {Pick<Storage, 'getItem'|'setItem'>} [options.storage] - inyectable para tests.
 * @returns {Promise<boolean>} true si se recargó.
 */
export async function reloadIfOutdated({ currentVersion, serverVersion, waitForIdle, reload, storage = globalThis.sessionStorage }) {
    // 'dev' = servidor sin build de Vite (desarrollo): no hay una versión real contra la cual comparar.
    if (!currentVersion || !serverVersion || serverVersion === 'dev' || currentVersion === 'dev' || currentVersion === serverVersion) {
        return false;
    }

    try {
        if (storage?.getItem(RELOAD_GUARD_KEY) === serverVersion) return false;
    } catch {
        // sin sessionStorage: se sigue, el riesgo de bucle lo acota el reposo previo
    }

    if (!(await waitForIdle())) return false;

    try {
        storage?.setItem(RELOAD_GUARD_KEY, serverVersion);
    } catch {
        // idem
    }

    reload();
    return true;
}
