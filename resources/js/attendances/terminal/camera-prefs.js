// resources/js/attendances/terminal/camera-prefs.js
/**
 * @fileoverview Preferencias de cámara del dispositivo (cuál usar y si se ve
 * en espejo). Viven en `localStorage` del navegador: son del dispositivo, no
 * del terminal en el servidor, y cualquier fallo del almacenamiento (modo
 * privado, datos bloqueados) degrada a los valores por defecto.
 */

const STORAGE_KEY = 'terminal-camera-prefs';

/** @typedef {{deviceId: string|null, mirror: boolean}} CameraPrefs */

/** @returns {CameraPrefs} */
export function getCameraPrefs() {
    try {
        const raw = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        return {
            deviceId: typeof raw.deviceId === 'string' && raw.deviceId ? raw.deviceId : null,
            mirror: raw.mirror === true,
        };
    } catch {
        return { deviceId: null, mirror: false };
    }
}

/**
 * Guarda un cambio parcial de las preferencias.
 * @param {Partial<CameraPrefs>} patch
 * @returns {CameraPrefs} preferencias resultantes
 */
export function setCameraPrefs(patch) {
    const next = { ...getCameraPrefs(), ...patch };
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    } catch { /* sin almacenamiento: la preferencia dura solo esta carga */ }
    return next;
}

/**
 * Restricciones de `getUserMedia` según la cámara elegida; sin elección usa la frontal.
 * @param {CameraPrefs} [prefs]
 * @returns {MediaStreamConstraints}
 */
export function buildVideoConstraints(prefs = getCameraPrefs()) {
    return {
        video: prefs.deviceId ? { deviceId: { exact: prefs.deviceId } } : { facingMode: 'user' },
        audio: false,
    };
}

/**
 * Abre la cámara elegida; si ya no existe (se desconectó o cambió el id) olvida la
 * elección y vuelve a la frontal en vez de dejar el terminal sin cámara.
 * @param {MediaDevices} [mediaDevices]
 * @returns {Promise<MediaStream>}
 */
export async function openCameraStream(mediaDevices = navigator.mediaDevices) {
    const prefs = getCameraPrefs();
    try {
        return await mediaDevices.getUserMedia(buildVideoConstraints(prefs));
    } catch (error) {
        if (prefs.deviceId && (error.name === 'OverconstrainedError' || error.name === 'NotFoundError')) {
            setCameraPrefs({ deviceId: null });
            return mediaDevices.getUserMedia(buildVideoConstraints({ deviceId: null, mirror: prefs.mirror }));
        }
        throw error;
    }
}

/**
 * Cámaras disponibles, con un nombre legible aunque el navegador no informe etiquetas.
 * @param {MediaDevices} [mediaDevices]
 * @returns {Promise<Array<{id: string, label: string}>>}
 */
export async function listVideoInputs(mediaDevices = navigator.mediaDevices) {
    try {
        const devices = await mediaDevices.enumerateDevices();
        return devices
            .filter((d) => d.kind === 'videoinput' && d.deviceId)
            .map((d, i) => ({ id: d.deviceId, label: d.label || `Cámara ${i + 1}` }));
    } catch {
        return [];
    }
}
