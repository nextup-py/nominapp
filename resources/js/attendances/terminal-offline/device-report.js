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
 * Recolecta el reporte de estado del dispositivo. Nunca lanza: lo que no se
 * pueda leer simplemente no viaja.
 * @param {object} [options]
 * @param {Navigator} [options.nav] - navigator inyectable (tests).
 * @param {Window} [options.win] - window inyectable (tests).
 * @param {string|null} [options.appVersion] - versión/build de la app que corre (la inyecta el servidor).
 * @param {number|null} [options.cachedEmployees] - empleados en la caché local.
 * @param {number|null} [options.clockOffsetMs] - diferencia servidor − dispositivo del último heartbeat.
 * @returns {Promise<Record<string, string|number|boolean>>}
 */
export async function collectDeviceReport({
    nav = globalThis.navigator,
    win = globalThis.window,
    appVersion = null,
    cachedEmployees = null,
    clockOffsetMs = null,
} = {}) {
    const report = {};

    if (appVersion) report.app_version = String(appVersion).slice(0, 40);

    try {
        const displayStandalone = typeof win?.matchMedia === 'function' && win.matchMedia('(display-mode: standalone)').matches;
        report.standalone = Boolean(displayStandalone || nav?.standalone === true);
    } catch {
        // sin matchMedia: se omite
    }

    if (nav?.serviceWorker) report.sw_active = Boolean(nav.serviceWorker.controller);

    if (Number.isFinite(cachedEmployees)) report.cached_employees = cachedEmployees;
    if (Number.isFinite(clockOffsetMs)) report.clock_skew_seconds = Math.round(clockOffsetMs / 1000);

    const [battery, camera, storage] = await Promise.all([readBattery(nav), readCameraState(nav), readStorage(nav)]);
    Object.assign(report, battery, storage);
    if (camera) report.camera = camera;

    return report;
}
