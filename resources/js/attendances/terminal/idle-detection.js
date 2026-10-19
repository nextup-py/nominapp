// resources/js/attendances/terminal/idle-detection.js
/**
 * =============================================================================
 * TERMINAL.JS — DETECCIÓN DE INACTIVIDAD, WAKE LOCK Y PRESENCE-CHECK
 * =============================================================================
 *
 * @fileoverview Timer de inactividad (vuelve a la pantalla idle tras 5min sin
 * marcaciones), wake lock (mantiene la pantalla encendida), y la detección de
 * presencia en modo reposo (detector liviano sin descriptor, para "despertar"
 * el terminal cuando alguien se acerca). Extraído de terminal.js como parte
 * de su descomposición en módulos más chicos — mismo comportamiento que el
 * código original.
 *
 * No decide qué pantalla mostrar ni detiene la identificación activa — eso
 * es orquestación de terminal.js, que recibe `onIdle`/`onPresenceDetected`
 * como callbacks.
 */

import { hasStream, adoptStream } from './camera.js';
import { openCameraStream } from './camera-prefs.js';

const DEFAULT_IDLE_TIMEOUT_MS = 5 * 60 * 1000;

/**
 * `faceapi` es un global cargado vía `<script defer>` (face-api.min.js), no
 * un import — construir esta opción a nivel de módulo asumiría que ese
 * script ya corrió, lo cual depende del orden de los `<script>` en el HTML.
 * Construcción perezosa (primer uso real) para no depender de esa carrera.
 * Ver la misma nota en `camera.js`.
 */
let lightOptions = null;

function getLightOptions() {
    if (!lightOptions) lightOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 160, scoreThreshold: 0.5 });
    return lightOptions;
}

let idleTimer = null;
let presenceCheckActive = false;
let presenceCheckTimer = null;
let wakeLock = null;
/** Motivo del último intento fallido de mantener la pantalla encendida (`unsupported` o el `name` del error del navegador). */
let wakeLockError = null;
/** Solicitud en curso, para no pedir dos veces el bloqueo a la vez. */
let wakeLockRequest = null;
/** Detiene el vigía del bloqueo si está activo. */
let stopWakeLockKeeper = null;

/** Cada cuánto se reintenta el bloqueo mientras no se tenga (un equipo que se despierta de la suspensión lo pierde). */
export const WAKE_LOCK_RETRY_MS = 60 * 1000;

/**
 * @param {() => void} onIdle
 * @param {number} [timeoutMs]
 */
export function resetIdleTimer(onIdle, timeoutMs = DEFAULT_IDLE_TIMEOUT_MS) {
    clearIdleTimer();
    idleTimer = setTimeout(onIdle, timeoutMs);
}

/** Cancela el timer de inactividad pendiente, si hay uno. */
export function clearIdleTimer() {
    if (idleTimer) {
        clearTimeout(idleTimer);
        idleTimer = null;
    }
}

/**
 * Detección de presencia en modo reposo — reutiliza el stream de cámara si
 * camera.js ya tiene uno abierto (hasStream()), o abre uno propio y se lo
 * entrega a camera.js (adoptStream()) para que startCamera() no vuelva a
 * pedir permiso al salir del reposo.
 * @param {HTMLVideoElement} videoEl
 * @param {() => void} onPresenceDetected
 */
export async function startPresenceCheck(videoEl, onPresenceDetected) {
    stopPresenceCheck();
    presenceCheckActive = true;

    if (!hasStream()) {
        try {
            const stream = await openCameraStream();
            adoptStream(stream);
            videoEl.srcObject = stream;
            await new Promise((resolve) => { videoEl.onloadedmetadata = resolve; });
        } catch (e) {
            console.warn('Cámara no disponible para detección de presencia:', e);
            presenceCheckActive = false;
            return;
        }
    }

    presenceCheckTick(videoEl, onPresenceDetected);
}

async function presenceCheckTick(videoEl, onPresenceDetected) {
    if (!presenceCheckActive) return;

    try {
        if (videoEl.readyState >= 2 && videoEl.videoWidth > 0) {
            const detection = await faceapi.detectSingleFace(videoEl, getLightOptions());
            if (detection && presenceCheckActive) {
                stopPresenceCheck();
                onPresenceDetected();
                return;
            }
        }
    } catch (e) { /* ignorar errores en detección de presencia */ }

    if (presenceCheckActive) {
        presenceCheckTimer = setTimeout(() => presenceCheckTick(videoEl, onPresenceDetected), 2500);
    }
}

/** Detiene la detección de presencia. */
export function stopPresenceCheck() {
    presenceCheckActive = false;
    if (presenceCheckTimer) {
        clearTimeout(presenceCheckTimer);
        presenceCheckTimer = null;
    }
}

/**
 * Solicita mantener la pantalla encendida (y con ella el equipo despierto) — best-effort, no todos los
 * navegadores lo soportan. Es idempotente: si ya se tiene o hay una solicitud en curso no pide otra.
 * Ante un fallo guarda el motivo (`getWakeLockError()`) para informarlo en el reporte del dispositivo.
 * @param {Navigator} [nav] - inyectable para tests; navigator en producción
 * @returns {Promise<boolean>} true si el bloqueo está activo.
 */
export async function acquireWakeLock(nav = navigator) {
    if (!('wakeLock' in nav)) {
        wakeLockError = 'unsupported';
        return false;
    }
    if (wakeLock) return true;
    if (wakeLockRequest) return wakeLockRequest;

    wakeLockRequest = (async () => {
        try {
            const lock = await nav.wakeLock.request('screen');
            wakeLock = lock;
            wakeLockError = null;
            lock.addEventListener('release', () => {
                if (wakeLock === lock) wakeLock = null;
            });
            console.log('Wake lock adquirido');
            return true;
        } catch (err) {
            wakeLockError = String(err?.name || 'error').replace(/[^A-Za-z0-9_.-]/g, '').slice(0, 40) || 'error';
            console.warn('Wake lock no disponible:', err?.message);
            return false;
        } finally {
            wakeLockRequest = null;
        }
    })();

    return wakeLockRequest;
}

/** @returns {boolean} true si el wake lock está activo actualmente. */
export function hasWakeLock() {
    return wakeLock !== null;
}

/** @returns {string|null} motivo del último intento fallido (`unsupported` o el nombre del error), null si no hay fallo. */
export function getWakeLockError() {
    return wakeLockError;
}

/**
 * Mantiene el bloqueo de pantalla: lo pide ya, lo reintenta cada `intervalMs` mientras no lo tenga, cuando la
 * pestaña vuelve a estar visible y en la primera interacción (algunos navegadores lo exigen). Sin esto un
 * equipo que se suspendió —o un terminal que se recargó y espera el toque de «Comenzar»— quedaba sin bloqueo
 * hasta que alguien recargaba, y volvía a dormirse. Es idempotente: una segunda llamada devuelve la misma función.
 * @param {object} [options]
 * @param {Navigator} [options.nav] - inyectable para tests.
 * @param {Document} [options.doc] - inyectable para tests.
 * @param {number} [options.intervalMs]
 * @returns {() => void} detiene el vigía.
 */
export function startWakeLockKeeper({ nav = navigator, doc = document, intervalMs = WAKE_LOCK_RETRY_MS } = {}) {
    if (stopWakeLockKeeper) return stopWakeLockKeeper;

    const tryAcquire = () => {
        if (!wakeLock && wakeLockError !== 'unsupported' && doc.visibilityState !== 'hidden') acquireWakeLock(nav);
    };

    tryAcquire();
    const timer = setInterval(tryAcquire, intervalMs);
    doc.addEventListener('visibilitychange', tryAcquire);
    doc.addEventListener('pointerdown', tryAcquire);
    doc.addEventListener('keydown', tryAcquire);

    stopWakeLockKeeper = () => {
        clearInterval(timer);
        doc.removeEventListener('visibilitychange', tryAcquire);
        doc.removeEventListener('pointerdown', tryAcquire);
        doc.removeEventListener('keydown', tryAcquire);
        stopWakeLockKeeper = null;
    };

    return stopWakeLockKeeper;
}
