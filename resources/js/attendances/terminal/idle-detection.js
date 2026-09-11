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

const DEFAULT_IDLE_TIMEOUT_MS = 5 * 60 * 1000;
const lightOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 160, scoreThreshold: 0.5 });

let idleTimer = null;
let presenceCheckActive = false;
let presenceCheckTimer = null;
let wakeLock = null;

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
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
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
            const detection = await faceapi.detectSingleFace(videoEl, lightOptions);
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
 * Solicita mantener la pantalla encendida — best-effort, no todos los
 * navegadores lo soportan.
 * @param {Navigator} [nav] - inyectable para tests; navigator en producción
 */
export async function acquireWakeLock(nav = navigator) {
    if (!('wakeLock' in nav)) return;
    try {
        wakeLock = await nav.wakeLock.request('screen');
        wakeLock.addEventListener('release', () => { wakeLock = null; });
        console.log('Wake lock adquirido');
    } catch (err) {
        console.warn('Wake lock no disponible:', err.message);
    }
}

/** @returns {boolean} true si el wake lock está activo actualmente. */
export function hasWakeLock() {
    return wakeLock !== null;
}
