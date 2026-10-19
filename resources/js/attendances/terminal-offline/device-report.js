/**
 * =============================================================================
 * REPORTE DE ESTADO DEL DISPOSITIVO (heartbeat del terminal)
 * =============================================================================
 *
 * @fileoverview Junta lo que el navegador permite saber del dispositivo —
 * versión de la app, modo instalado, batería, cámara, almacenamiento — para
 * mandarlo en el heartbeat y mostrarlo en el panel. Todo es best-effort: cada
 * dato se omite si el navegador no lo informa (la Battery API no existe en
 * iOS/Firefox, `permissions.query('camera')` falla en Safari, etc.) y un fallo
 * al recolectar nunca debe romper el heartbeat. Funciones puras sobre
 * `navigator`/`window` inyectables, para poder probarlas sin DOM.
 */

const MB = 1024 * 1024;

/**
 * Nivel de batería (0-100) y si está cargando, según la Battery API.
 * @param {Navigator} nav
 * @returns {Promise<{battery_level?: number, battery_charging?: boolean}>}
 */
async function readBattery(nav) {
    if (typeof nav?.getBattery !== 'function') return {};
    try {
        const battery = await nav.getBattery();
        return {
            battery_level: Math.round(battery.level * 100),
            battery_charging: Boolean(battery.charging),
        };
    } catch {
        return {};
    }
}

/**
 * Estado del permiso de cámara: granted | denied | prompt | unavailable.
 * @param {Navigator} nav
 * @returns {Promise<string|undefined>} undefined si no se puede determinar.
 */
async function readCameraState(nav) {
    if (!nav?.mediaDevices?.getUserMedia) return 'unavailable';
    if (typeof nav.permissions?.query !== 'function') return undefined;
    try {
        const status = await nav.permissions.query({ name: 'camera' });
        return ['granted', 'denied', 'prompt'].includes(status.state) ? status.state : undefined;
    } catch {
        return undefined;
    }
}

/**
 * Uso y cuota de almacenamiento del origen, en MB.
 * @param {Navigator} nav
 * @returns {Promise<{storage_used_mb?: number, storage_quota_mb?: number}>}
 */
async function readStorage(nav) {
    if (typeof nav?.storage?.estimate !== 'function') return {};
    try {
        const { usage, quota } = await nav.storage.estimate();
        return {
            ...(Number.isFinite(usage) ? { storage_used_mb: Math.round(usage / MB) } : {}),
            ...(Number.isFinite(quota) ? { storage_quota_mb: Math.round(quota / MB) } : {}),
        };
    } catch {
        return {};
    }
}

/** Modos de visualización que no son una pestaña del navegador, en el orden en que se comprueban. */
const DEDICATED_DISPLAY_MODES = ['fullscreen', 'standalone', 'minimal-ui', 'window-controls-overlay'];

/**
 * Cómo se muestra el terminal: `browser` (pestaña), `standalone` (app instalada), `fullscreen` (pantalla completa o
 * modo kiosko), `minimal-ui` o `window-controls-overlay`. Nunca lanza.
 * @param {Window} win
 * @param {Navigator} [nav] - `navigator.standalone` es el equivalente de iOS Safari.
 * @returns {string|null} null si el navegador no permite saberlo (`matchMedia` falla).
 */
export function detectDisplayMode(win, nav = globalThis.navigator) {
    try {
        for (const mode of DEDICATED_DISPLAY_MODES) {
            if (win.matchMedia(`(display-mode: ${mode})`).matches) return mode;
        }
    } catch {
        return null;
    }

    return nav?.standalone === true ? 'standalone' : 'browser';
}

/**
 * Recolecta el reporte de estado del dispositivo. Nunca lanza: lo que no se
 * pueda leer simplemente no viaja.
 * @param {object} [options]
 * @param {Navigator} [options.nav] - navigator inyectable (tests).
 * @param {Window} [options.win] - window inyectable (tests).
 * @param {string|null} [options.appVersion] - versión/build de la app que corre (la inyecta el servidor).
 * @param {number|null} [options.cachedEmployees] - empleados en la caché local.
 * @param {number|null} [options.clockOffsetMs] - diferencia servidor − dispositivo del último heartbeat.
 * @param {boolean|null} [options.wakeLock] - si el terminal logró mantener la pantalla encendida (Wake Lock); null si no se sabe.
 * @param {string|null} [options.wakeLockError] - motivo del último intento fallido de Wake Lock; solo viaja si `wakeLock` es false.
 * @returns {Promise<Record<string, string|number|boolean>>}
 */
export async function collectDeviceReport({
    nav = globalThis.navigator,
    win = globalThis.window,
    appVersion = null,
    cachedEmployees = null,
    clockOffsetMs = null,
    wakeLock = null,
    wakeLockError = null,
} = {}) {
    const report = {};

    if (appVersion) report.app_version = String(appVersion).slice(0, 40);

    const displayMode = typeof win?.matchMedia === 'function' ? detectDisplayMode(win, nav) : null;
    if (displayMode) {
        report.display_mode = displayMode;
        report.standalone = displayMode !== 'browser';
    }

    const visibility = win?.document?.visibilityState;
    if (typeof visibility === 'string') report.visible = visibility !== 'hidden';

    if (nav?.serviceWorker) report.sw_active = Boolean(nav.serviceWorker.controller);

    if (Number.isFinite(cachedEmployees)) report.cached_employees = cachedEmployees;
    if (Number.isFinite(clockOffsetMs)) report.clock_skew_seconds = Math.round(clockOffsetMs / 1000);
    if (typeof wakeLock === 'boolean') report.wake_lock = wakeLock;
    if (wakeLock === false && wakeLockError) report.wake_lock_error = String(wakeLockError).slice(0, 40);

    const [battery, camera, storage] = await Promise.all([readBattery(nav), readCameraState(nav), readStorage(nav)]);
    Object.assign(report, battery, storage);
    if (camera) report.camera = camera;

    return report;
}
