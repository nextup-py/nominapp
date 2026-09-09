/**
 * Lógica de instalación de la PWA — detección de plataforma, captura del
 * evento beforeinstallprompt (Android/Chrome/Edge) y persistencia del
 * descarte del banner. Las funciones reciben sus dependencias del navegador
 * (userAgent, storage, target de eventos) como parámetros en vez de leer
 * los globals directamente, para poder testearlas sin un entorno jsdom —
 * en producción los valores por defecto ya apuntan a los globals reales.
 */

let deferredPrompt = null;

/**
 * Captura el evento beforeinstallprompt antes de que el navegador lo
 * descarte, y bloquea el mini-infobar nativo (preventDefault) para que la
 * UI propia decida cuándo mostrarlo. Debe llamarse una sola vez al cargar
 * la página — no dispara nada en iOS (ese navegador nunca emite el evento).
 * @param {(event: Event) => void} onAvailable - se invoca cuando el prompt queda disponible
 * @param {EventTarget} [target] - inyectable para tests; window en producción
 */
export function captureInstallPrompt(onAvailable, target = window) {
    target.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event;
        onAvailable(event);
    });
}

/**
 * Dispara el prompt nativo de instalación capturado previamente.
 * @returns {Promise<'accepted'|'dismissed'|'unavailable'>}
 */
export async function triggerInstallPrompt() {
    if (!deferredPrompt) {
        return 'unavailable';
    }

    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    deferredPrompt = null;

    return outcome;
}

/**
 * @param {boolean|undefined} navigatorStandalone - navigator.standalone (solo iOS)
 * @param {boolean} displayModeStandalone - window.matchMedia('(display-mode: standalone)').matches
 */
export function isStandalone(navigatorStandalone, displayModeStandalone) {
    return navigatorStandalone === true || displayModeStandalone === true;
}

/**
 * iPadOS moderno se anuncia como "Macintosh" en el user agent (indistinguible
 * de un Mac de escritorio por UA solo) — se distingue por soporte táctil
 * (maxTouchPoints > 1), que ningún Mac de escritorio real tiene.
 * @param {string} userAgent
 * @param {number} [maxTouchPoints]
 */
export function isIOS(userAgent, maxTouchPoints = 0) {
    const isAppleDevice = /iPad|iPhone|iPod/.test(userAgent);
    const isIpadOsDesktopUa = userAgent.includes('Macintosh') && maxTouchPoints > 1;

    return isAppleDevice || isIpadOsDesktopUa;
}

function dismissKey(mode) {
    return `nominapp_install_dismissed_${mode}`;
}

/**
 * @param {'mark'|'terminal'} mode
 * @param {{getItem: (key: string) => string|null}} [storage] - inyectable para tests; localStorage en producción
 */
export function isDismissed(mode, storage = localStorage) {
    return storage.getItem(dismissKey(mode)) === '1';
}

/**
 * @param {'mark'|'terminal'} mode
 * @param {{setItem: (key: string, value: string) => void}} [storage] - inyectable para tests; localStorage en producción
 */
export function dismiss(mode, storage = localStorage) {
    storage.setItem(dismissKey(mode), '1');
}
