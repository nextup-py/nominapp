// resources/js/attendances/terminal/camera.js
/**
 * =============================================================================
 * TERMINAL.JS — CÁMARA Y FACE-API
 * =============================================================================
 *
 * @fileoverview Ciclo de vida del stream de cámara, el loop de dibujo/detección
 * liviana (feedback visual mientras el empleado se posiciona frente a la
 * cámara), y la captura de descriptores faciales para matching. Extraído de
 * terminal.js como parte de su descomposición en módulos más chicos — mismo
 * comportamiento que el código original.
 *
 * No conoce pantallas ni el flujo de identificación: expone únicamente
 * primitivas de cámara/detección. `identification-flow.js` es quien decide
 * qué hacer con sus resultados.
 *
 * `hasStream()`/`adoptStream()` existen porque el stream de cámara puede
 * originarse en `idle-detection.js` (detección de presencia en modo reposo,
 * con un detector liviano) y luego reutilizarse acá sin volver a pedir
 * permiso de cámara al salir del reposo — mismo comportamiento que el
 * `terminalState.stream` compartido del código original.
 */

import { captureFaceSamples } from '../../shared/face-capture-core.js';

const MODELS_URI = '/models';
const MIN_FACE_SIZE = 100;

/**
 * `faceapi` es un global cargado vía `<script defer>` (face-api.min.js), no
 * un import — construir estas opciones a nivel de módulo asumiría que ese
 * script ya corrió, lo cual depende del orden de los `<script>` en el HTML
 * (bug real: ver `resources/js/attendances/terminal/camera.js` git history,
 * `ReferenceError: faceapi is not defined` en producción cuando el `<script>`
 * de face-api.min.js queda después del `@vite` de terminal.js). Construcción
 * perezosa (primer uso real, después de DOMContentLoaded) para no depender
 * de esa carrera.
 */
let tinyOptions = null;
let lightOptions = null;

function getTinyOptions() {
    if (!tinyOptions) tinyOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.6 });
    return tinyOptions;
}

function getLightOptions() {
    if (!lightOptions) lightOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 160, scoreThreshold: 0.5 });
    return lightOptions;
}

let stream = null;
let drawLoopActive = false;
let faceDetected = false;
let notRecognizedUntil = 0;
let inNotRecognizedCooldown = false;
let modelsLoaded = false;

/**
 * Carga los 3 modelos de face-api.js (detector, landmarks, reconocimiento).
 * Idempotente — no vuelve a cargar si ya están listos.
 * @returns {Promise<{ok: boolean, message?: string}>}
 */
export async function loadModels() {
    if (modelsLoaded) return { ok: true };
    try {
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODELS_URI),
            faceapi.nets.faceLandmark68Net.loadFromUri(MODELS_URI),
            faceapi.nets.faceRecognitionNet.loadFromUri(MODELS_URI),
        ]);
        modelsLoaded = true;
        return { ok: true };
    } catch (error) {
        console.error('Error cargando modelos Face-API:', error);
        return { ok: false, message: 'Error al cargar el sistema de reconocimiento facial. Por favor, recargue la página.' };
    }
}

/** @returns {boolean} */
export function hasStream() {
    return stream !== null;
}

/**
 * Adopta un stream ya abierto por otro módulo (idle-detection.js durante la
 * detección de presencia) para que startCamera() no vuelva a pedir permiso.
 * @param {MediaStream} adoptedStream
 */
export function adoptStream(adoptedStream) {
    stream = adoptedStream;
}

/**
 * @param {HTMLVideoElement} videoEl
 * @param {HTMLCanvasElement} overlayEl
 * @returns {Promise<{ok: boolean, message?: string}>}
 */
export async function startCamera(videoEl, overlayEl) {
    if (stream) return { ok: true };
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
        videoEl.srcObject = stream;
        await new Promise((resolve) => { videoEl.onloadedmetadata = resolve; });
        overlayEl.width = videoEl.videoWidth;
        overlayEl.height = videoEl.videoHeight;
        return { ok: true };
    } catch (error) {
        console.error('Error al iniciar cámara:', error);
        let message = 'No se pudo acceder a la cámara. ';
        if (error.name === 'NotAllowedError') message += 'Por favor, permita el acceso a la cámara.';
        else if (error.name === 'NotFoundError') message += 'No se encontró ninguna cámara conectada.';
        else if (error.name === 'NotReadableError') message += 'La cámara está siendo usada por otra aplicación.';
        else message += 'Error desconocido: ' + error.message;
        return { ok: false, message };
    }
}

/** @param {HTMLVideoElement} videoEl */
export function stopCamera(videoEl) {
    if (stream) {
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
        videoEl.srcObject = null;
    }
    stopDrawLoop();
}

/**
 * Arranca el loop de dibujo/detección liviana (cada 200ms) — solo decide el
 * color/estado visual y si hay un rostro lo bastante grande para intentar
 * capturar; no calcula el descriptor de 128 dimensiones (eso lo hace
 * captureDescriptor() aparte, cuando ya se detectó un rostro).
 * @param {HTMLVideoElement} videoEl
 * @param {HTMLCanvasElement} overlayEl
 * @param {CanvasRenderingContext2D} ctx
 * @param {{isProcessing: () => boolean, onFrame: (state: {detected: boolean, tooSmall: boolean, justExitedCooldown: boolean}) => void}} callbacks
 */
export function startDrawLoop(videoEl, overlayEl, ctx, callbacks) {
    if (drawLoopActive) return;
    drawLoopActive = true;
    drawLoopTick(videoEl, overlayEl, ctx, callbacks);
}

/** Detiene el loop de dibujo/detección. */
export function stopDrawLoop() {
    drawLoopActive = false;
}

async function drawLoopTick(videoEl, overlayEl, ctx, callbacks) {
    if (!drawLoopActive) return;

    try {
        if (videoEl.readyState >= 2 && videoEl.videoWidth > 0 && videoEl.videoHeight > 0) {
            const detection = await faceapi.detectSingleFace(videoEl, getLightOptions());
            ctx.clearRect(0, 0, overlayEl.width, overlayEl.height);

            if (!callbacks.isProcessing() && Date.now() > notRecognizedUntil) {
                const justExitedCooldown = inNotRecognizedCooldown;
                if (inNotRecognizedCooldown) inNotRecognizedCooldown = false;

                if (detection) {
                    const box = detection.box;
                    const tooSmall = box.width < MIN_FACE_SIZE || box.height < MIN_FACE_SIZE;
                    faceDetected = !tooSmall;
                    callbacks.onFrame({ detected: true, tooSmall, justExitedCooldown });
                } else {
                    faceDetected = false;
                    callbacks.onFrame({ detected: false, tooSmall: false, justExitedCooldown });
                }
            }
        }
    } catch (error) {
        console.error('Error en drawLoop:', error);
    }

    if (drawLoopActive) {
        setTimeout(() => drawLoopTick(videoEl, overlayEl, ctx, callbacks), 200);
    }
}

/**
 * @param {HTMLVideoElement} videoEl
 * @param {number} [samples]
 * @param {number} [intervalMs]
 * @param {(count: number) => void} [onProgress]
 * @returns {Promise<Float32Array>}
 */
export async function captureDescriptor(videoEl, samples = 5, intervalMs = 150, onProgress = null) {
    const { averaged } = await captureFaceSamples(videoEl, getTinyOptions(), {
        samples,
        intervalMs,
        minFaceSize: MIN_FACE_SIZE,
        minRequired: 3,
        onProgress,
        onRejectedSample: (reason, detail) => {
            if (reason === 'error') console.error('Error capturando muestra:', detail);
        },
    });
    return averaged;
}

/** @returns {boolean} */
export function isFaceDetected() {
    return faceDetected;
}

/** @returns {boolean} */
export function isInCooldown() {
    return Date.now() <= notRecognizedUntil;
}

/** @param {number} ms */
export function setNotRecognizedCooldown(ms) {
    notRecognizedUntil = Date.now() + ms;
    inNotRecognizedCooldown = true;
}
