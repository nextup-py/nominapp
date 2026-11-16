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

/**
 * Datos del equipo que el navegador entrega por sí solo: memoria, núcleos, tipo de conexión, pantalla, idioma, zona
 * horaria y plataforma. Sirven para diagnosticar equipos lentos o poco aptos como terminal; ninguno identifica a una
 * persona. Cada uno se omite si el navegador no lo informa (`deviceMemory` y `connection` solo existen en Chromium).
 * @param {Navigator} nav
 * @param {Window} win
 * @returns {{device_memory_gb?: number, cpu_cores?: number, connection_type?: string, screen?: string, language?: string, timezone?: string, platform?: string}}
 */
export function readDeviceInfo(nav, win) {
    const info = {};

    if (Number.isFinite(nav?.deviceMemory) && nav.deviceMemory > 0) info.device_memory_gb = nav.deviceMemory;
    if (Number.isFinite(nav?.hardwareConcurrency) && nav.hardwareConcurrency > 0) info.cpu_cores = Math.round(nav.hardwareConcurrency);

    const connection = nav?.connection?.effectiveType;
    if (['slow-2g', '2g', '3g', '4g'].includes(connection)) info.connection_type = connection;

    const width = win?.screen?.width;
    const height = win?.screen?.height;
    if (Number.isFinite(width) && Number.isFinite(height) && width > 0 && height > 0) info.screen = `${Math.round(width)}x${Math.round(height)}`;

    if (typeof nav?.language === 'string' && /^[A-Za-z0-9_-]{1,20}$/.test(nav.language)) info.language = nav.language;

    try {
        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (typeof timezone === 'string' && /^[A-Za-z0-9_/+-]{1,60}$/.test(timezone)) info.timezone = timezone;
    } catch {
        // Sin Intl: no viaja.
    }

    const platform = nav?.userAgentData?.platform;
    if (typeof platform === 'string' && platform !== '') info.platform = platform.slice(0, 40);

    return info;
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
 * @param {string|null} [options.deviceId] - identificador aleatorio de este equipo (ver device-identity.js); el servidor lo usa para aprender el del dispositivo vinculado.
 * @param {{samples: number, median_ms: number, p90_ms: number}|null} [options.identification] - tiempo de identificación de las últimas marcaciones (ver identification-stats.js); null si todavía no hubo ninguna.
 * @param {{attempts: number, failures: number, successes: number, msTotal: number}|null} [options.identificationTotals] - contadores acumulados de identificación desde que se cargó la página; el servidor suma la diferencia entre reportes.
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
    identification = null,
    identificationTotals = null,
    deviceId = null,
} = {}) {
    const report = {};

    if (deviceId) report.device_id = String(deviceId).slice(0, 64);
    Object.assign(report, readDeviceInfo(nav, win));

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

    if (identificationTotals && Number.isFinite(identificationTotals.attempts)) {
        report.identification_attempts = Math.round(identificationTotals.attempts);
        report.identification_failures = Math.round(identificationTotals.failures);
        report.identification_successes = Math.round(identificationTotals.successes);
        report.identification_ms_total = Math.round(identificationTotals.msTotal);
    }

    if (identification && Number.isFinite(identification.median_ms) && Number.isFinite(identification.p90_ms)) {
        report.identification_median_ms = Math.round(identification.median_ms);
        report.identification_p90_ms = Math.round(identification.p90_ms);
        report.identification_samples = Math.round(identification.samples);
    }

    const [battery, camera, storage] = await Promise.all([readBattery(nav), readCameraState(nav), readStorage(nav)]);
    Object.assign(report, battery, storage);
    if (camera) report.camera = camera;

    return report;
}
