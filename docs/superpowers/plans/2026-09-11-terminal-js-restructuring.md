# Sub-proyecto D: Reestructuración de terminal.js — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Descomponer `resources/js/attendances/terminal.js` (1215 líneas) en 6 módulos funcionales por responsabilidad, dejando `terminal.js` como un composition root delgado (~300-400 líneas), sin cambio de comportamiento visible.

**Architecture:** Cada módulo nuevo exporta primitivas "hoja" que tocan solo su propia responsabilidad (cámara, idle/wake-lock, transición de pantallas, identificación, registro de marcación, bootstrap) y reciben sus referencias de DOM ya resueltas como parámetros (no hacen su propio `document.getElementById`) — esto las hace testeables con objetos falsos simples, sin jsdom. La **orquestación** que coordina varios módulos a la vez (p. ej. "al entrar en idle: detener identificación, detener countdown, mostrar pantalla idle, arrancar presence-check") permanece en `terminal.js`, evitando importaciones circulares entre módulos.

**Tech Stack:** Vanilla JS (módulos ES), Vitest, patrón dependency-injected ya establecido en `resources/js/shared/install-prompt.js`/`theme-toggle.js`.

**Spec:** docs/superpowers/specs/2026-09-11-terminal-js-restructuring-design.md

## Global Constraints

- Cero cambio de comportamiento visible — refactor puro. Cualquier diferencia debe ser indetectable para el usuario final.
- `mark.js` no se toca.
- La lógica interna de `terminal-offline/{db,matcher,queue,sync}.js` no se modifica.
- Todo módulo nuevo lleva JSDoc completo (función + parámetros) y comentario de cabecera `@fileoverview` describiendo el contexto de extracción, siguiendo el estilo ya usado en `terminal/sync-status-ui.js`/`manual-search.js`.
- Los módulos con lógica no trivial (`idle-detection.js`, `screen-state.js`, `mark-registration.js`, `identification-flow.js`) llevan tests Vitest. `camera.js` y `bootstrap.js` no llevan tests unitarios (justificado en la spec: APIs de MediaStream/canvas difíciles de simular con bajo valor; orquestación secuencial de arranque).
- Las funciones de los módulos nuevos reciben sus referencias de DOM ya resueltas como parámetros — no hacen sus propios `document.getElementById`. `terminal.js` sigue siendo el único lugar que resuelve refs de DOM desde cero.
- Comandos de test: `npx vitest run <archivo>` (Vitest), `php artisan test --compact --filter=TerminalViewTest` (Pest, no debería verse afectado — no toca la vista Blade).

---

### Task 1: `terminal/camera.js` — captura de cámara y face-api

**Files:**
- Create: `resources/js/attendances/terminal/camera.js`

**Interfaces:**
- Consumes: `captureFaceSamples` de `resources/js/shared/face-capture-core.js` (ya existe: `captureFaceSamples(video, tinyOptions, options)` → `Promise<{averaged}>`).
- Produces (consumido por Tarea 2 y Tarea 5):
  - `async function loadModels(): Promise<{ok: boolean, message?: string}>`
  - `function hasStream(): boolean`
  - `function adoptStream(stream: MediaStream): void`
  - `async function startCamera(videoEl: HTMLVideoElement, overlayEl: HTMLCanvasElement): Promise<{ok: boolean, message?: string}>`
  - `function stopCamera(videoEl: HTMLVideoElement): void`
  - `function startDrawLoop(videoEl: HTMLVideoElement, overlayEl: HTMLCanvasElement, ctx: CanvasRenderingContext2D, callbacks: {isProcessing: () => boolean, onFrame: (state: {detected: boolean, tooSmall: boolean, justExitedCooldown: boolean}) => void}): void`
  - `function stopDrawLoop(): void`
  - `async function captureDescriptor(videoEl: HTMLVideoElement, samples?: number, intervalMs?: number, onProgress?: (count: number) => void): Promise<Float32Array>`
  - `function isFaceDetected(): boolean`
  - `function isInCooldown(): boolean`
  - `function setNotRecognizedCooldown(ms: number): void`

No tests unitarios para este módulo (justificado en Global Constraints).

- [ ] **Step 1: Crear el módulo**

```js
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

const MODELS_URI = '/models';
const MIN_FACE_SIZE = 100;

const tinyOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.6 });
const lightOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 160, scoreThreshold: 0.5 });

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
            const detection = await faceapi.detectSingleFace(videoEl, lightOptions);
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
    const { averaged } = await captureFaceSamples(videoEl, tinyOptions, {
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
```

Nota: `captureFaceSamples` se usa como global import — agregar al inicio del archivo:
```js
import { captureFaceSamples } from '../../shared/face-capture-core.js';
```
(insertar esta línea justo antes del bloque de constantes `MODELS_URI`).

- [ ] **Step 2: Verificar que el archivo es sintácticamente válido**

Run: `node --check resources/js/attendances/terminal/camera.js` (ajustar si el proyecto usa una forma distinta de chequeo de sintaxis — alternativamente, correr `npx vitest run` completo al final del task para confirmar que Vite/Vitest puede parsear el módulo sin errores, ya que no hay test propio que lo importe todavía).

- [ ] **Step 3: Commit**

```bash
git add resources/js/attendances/terminal/camera.js
git commit -m "feat: extract terminal/camera.js from terminal.js"
```

---

### Task 2: `terminal/idle-detection.js` — timer de inactividad, wake lock, presence-check

**Files:**
- Create: `resources/js/attendances/terminal/idle-detection.js`
- Test: `resources/js/attendances/terminal/idle-detection.test.js`

**Interfaces:**
- Consumes: `hasStream()`, `adoptStream(stream)` de `camera.js` (Tarea 1).
- Produces (consumido por Tarea 7):
  - `function resetIdleTimer(onIdle: () => void, timeoutMs?: number): void`
  - `function clearIdleTimer(): void`
  - `function startPresenceCheck(videoEl: HTMLVideoElement, onPresenceDetected: () => void): void`
  - `function stopPresenceCheck(): void`
  - `async function acquireWakeLock(nav?: Navigator): Promise<void>`

- [ ] **Step 1: Escribir los tests que fallan**

```js
// resources/js/attendances/terminal/idle-detection.test.js
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { resetIdleTimer, clearIdleTimer, acquireWakeLock } from './idle-detection.js';

describe('resetIdleTimer / clearIdleTimer', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('llama a onIdle tras el timeout configurado', () => {
        const onIdle = vi.fn();
        resetIdleTimer(onIdle, 1000);

        vi.advanceTimersByTime(999);
        expect(onIdle).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(onIdle).toHaveBeenCalledTimes(1);
    });

    it('reinicia el timer al llamar resetIdleTimer de nuevo antes de que expire', () => {
        const onIdle = vi.fn();
        resetIdleTimer(onIdle, 1000);
        vi.advanceTimersByTime(600);
        resetIdleTimer(onIdle, 1000);
        vi.advanceTimersByTime(600);
        expect(onIdle).not.toHaveBeenCalled();
        vi.advanceTimersByTime(400);
        expect(onIdle).toHaveBeenCalledTimes(1);
    });

    it('clearIdleTimer cancela el timeout pendiente', () => {
        const onIdle = vi.fn();
        resetIdleTimer(onIdle, 1000);
        clearIdleTimer();
        vi.advanceTimersByTime(2000);
        expect(onIdle).not.toHaveBeenCalled();
    });

    it('usa 5 minutos por defecto si no se pasa timeoutMs', () => {
        const onIdle = vi.fn();
        resetIdleTimer(onIdle);
        vi.advanceTimersByTime(5 * 60 * 1000 - 1);
        expect(onIdle).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(onIdle).toHaveBeenCalledTimes(1);
    });
});

describe('acquireWakeLock', () => {
    it('no hace nada si navigator.wakeLock no existe', async () => {
        const fakeNav = {};
        await expect(acquireWakeLock(fakeNav)).resolves.toBeUndefined();
    });

    it('solicita el wake lock si la API está disponible', async () => {
        const mockLock = { addEventListener: vi.fn() };
        const request = vi.fn().mockResolvedValue(mockLock);
        const fakeNav = { wakeLock: { request } };

        await acquireWakeLock(fakeNav);

        expect(request).toHaveBeenCalledWith('screen');
        expect(mockLock.addEventListener).toHaveBeenCalledWith('release', expect.any(Function));
    });

    it('no revienta si wakeLock.request() rechaza', async () => {
        const request = vi.fn().mockRejectedValue(new Error('denied'));
        const fakeNav = { wakeLock: { request } };

        await expect(acquireWakeLock(fakeNav)).resolves.toBeUndefined();
    });
});
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `npx vitest run resources/js/attendances/terminal/idle-detection.test.js`
Expected: FAIL — el módulo no existe todavía.

- [ ] **Step 3: Crear el módulo**

```js
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
```

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `npx vitest run resources/js/attendances/terminal/idle-detection.test.js`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add resources/js/attendances/terminal/idle-detection.js resources/js/attendances/terminal/idle-detection.test.js
git commit -m "feat: extract terminal/idle-detection.js from terminal.js"
```

---

### Task 3: `terminal/screen-state.js` — máquina de estados de pantallas

**Files:**
- Create: `resources/js/attendances/terminal/screen-state.js`
- Test: `resources/js/attendances/terminal/screen-state.test.js`

**Interfaces:**
- Consumes: `setTerminalVideoState` de `terminal/ui-feedback.js` (ya existe), `buildDetailedError` de `terminal/text-helpers.js` (ya existe), `playBeep` de `shared/audio-feedback.js` (ya existe).
- Produces (consumido por Tarea 4, Tarea 5, Tarea 7):
  - `function showScreen(screens: Record<string, HTMLElement|null>, screenName: string): void`
  - `function showSuccessScreen(screens, dom, employee, markData, eventType, opts, onCountdownComplete): void`
  - `function showError(screens, errorMessageEl, message: string): void`
  - `function showDayComplete(screens, dom, employee, onCountdownComplete): void`
  - `function startCountdown(dom: {countdownEl, countdownFill}, seconds: number, onComplete: () => void): void`
  - `function startDayCompleteCountdown(dom: {dayCompleteCountdownEl, dayCompleteCountdownFill}, seconds: number, onComplete: () => void): void`
  - `function stopCountdown(): void`
  - `function resetTerminal(screens, typeButtons: NodeListOf<Element>, onReset: () => void): void`

- [ ] **Step 1: Escribir los tests que fallan**

```js
// resources/js/attendances/terminal/screen-state.test.js
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { showScreen, startCountdown, startDayCompleteCountdown, stopCountdown, resetTerminal } from './screen-state.js';

function fakeScreenEl() {
    const classes = new Set();
    return {
        classList: {
            contains: (c) => classes.has(c),
            add: (c) => classes.add(c),
            remove: (c) => classes.delete(c),
        },
        _classes: classes,
    };
}

describe('showScreen', () => {
    it('oculta todas las pantallas y muestra solo la indicada', () => {
        const idle = fakeScreenEl();
        const identification = fakeScreenEl();
        // simular que "idle" está visible (sin clase "hidden") al arrancar
        const screens = { idle, identification };

        showScreen(screens, 'identification');

        // showScreen usa un setTimeout de 150ms para la transición si había una pantalla
        // visible antes — sin pantalla previa visible, activa inmediato.
        expect(identification._classes.has('hidden')).toBe(false);
        expect(idle._classes.has('hidden')).toBe(true);
    });

    it('no revienta si una pantalla del mapa es null', () => {
        const screens = { idle: fakeScreenEl(), identification: null };
        expect(() => showScreen(screens, 'identification')).not.toThrow();
    });
});

describe('startCountdown / stopCountdown', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('decrementa cada segundo y llama a onComplete al llegar a 0', () => {
        const countdownEl = { textContent: '' };
        const countdownFill = { style: {}, offsetWidth: 0 };
        const onComplete = vi.fn();

        startCountdown({ countdownEl, countdownFill }, 3, onComplete);

        expect(countdownEl.textContent).toBe(3);
        vi.advanceTimersByTime(1000);
        expect(countdownEl.textContent).toBe(2);
        expect(onComplete).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1000);
        expect(countdownEl.textContent).toBe(1);
        vi.advanceTimersByTime(1000);
        expect(countdownEl.textContent).toBe(0);
        expect(onComplete).toHaveBeenCalledTimes(1);
    });

    it('stopCountdown cancela el intervalo antes de que llegue a 0', () => {
        const countdownEl = { textContent: '' };
        const countdownFill = { style: {}, offsetWidth: 0 };
        const onComplete = vi.fn();

        startCountdown({ countdownEl, countdownFill }, 3, onComplete);
        vi.advanceTimersByTime(1000);
        stopCountdown();
        vi.advanceTimersByTime(5000);
        expect(onComplete).not.toHaveBeenCalled();
    });
});

describe('startDayCompleteCountdown', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('decrementa y llama a onComplete al llegar a 0', () => {
        const dayCompleteCountdownEl = { textContent: '' };
        const dayCompleteCountdownFill = { style: {} };
        const onComplete = vi.fn();

        startDayCompleteCountdown({ dayCompleteCountdownEl, dayCompleteCountdownFill }, 2, onComplete);
        vi.advanceTimersByTime(1000);
        expect(onComplete).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1000);
        expect(onComplete).toHaveBeenCalledTimes(1);
    });
});

describe('resetTerminal', () => {
    it('restaura visibilidad de los botones de tipo y llama a onReset', () => {
        const btn1 = { style: {} };
        const btn2 = { style: { display: 'none' } };
        const typeButtons = [btn1, btn2];
        const screenTitleEl = { textContent: 'otro texto' };
        const typeSelectionScreen = { querySelector: () => screenTitleEl };
        const screens = { typeSelection: typeSelectionScreen };
        const onReset = vi.fn();

        resetTerminal(screens, typeButtons, onReset);

        expect(btn1.style.display).toBe('');
        expect(btn2.style.display).toBe('');
        expect(screenTitleEl.textContent).toBe('Marcación');
        expect(onReset).toHaveBeenCalledTimes(1);
    });
});
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `npx vitest run resources/js/attendances/terminal/screen-state.test.js`
Expected: FAIL — el módulo no existe todavía.

- [ ] **Step 3: Crear el módulo**

```js
// resources/js/attendances/terminal/screen-state.js
/**
 * =============================================================================
 * TERMINAL.JS — MÁQUINA DE ESTADOS DE PANTALLAS
 * =============================================================================
 *
 * @fileoverview Transición entre las pantallas del terminal (idle,
 * identificación, éxito, error, jornada completa, selección de tipo) y los
 * countdowns de auto-regreso. Extraído de terminal.js como parte de su
 * descomposición en módulos más chicos — mismo comportamiento que el código
 * original.
 *
 * No importa ningún otro módulo de terminal/ que a su vez pueda necesitar
 * volver a llamar acá (identification-flow.js, mark-registration.js) —
 * evita ciclos de importación. `resetTerminal`/`showSuccessScreen`/
 * `showDayComplete` reciben `onReset`/`onCountdownComplete` como callback en
 * vez de llamar directamente al siguiente paso del flujo.
 */

import { setTerminalVideoState } from './ui-feedback.js';
import { buildDetailedError } from './text-helpers.js';
import { playBeep } from '../../shared/audio-feedback.js';

let countdownTimer = null;

/**
 * @param {Record<string, HTMLElement|null|undefined>} screens
 * @param {string} screenName
 */
export function showScreen(screens, screenName) {
    const current = Object.values(screens).find((s) => s && !s.classList.contains('hidden'));
    const next = screens[screenName];

    const activate = () => {
        Object.values(screens).forEach((s) => {
            if (s) { s.classList.remove('screen-leaving'); s.classList.add('hidden'); }
        });
        if (next) next.classList.remove('hidden');
    };

    if (current && current !== next) {
        current.classList.add('screen-leaving');
        setTimeout(activate, 150);
    } else {
        activate();
    }
}

/**
 * @param {Record<string, HTMLElement|null>} screens
 * @param {{successEmployeePhoto?, successEmployeeName, successEmployeeCI, successEventType, successTime, successQueuedNotice?}} dom
 * @param {{first_name?: string, last_name?: string, ci?: string, photo_url?: string}} employee
 * @param {{recorded_at?: string}} markData
 * @param {string} eventType
 * @param {{queued?: boolean}} opts - queued=true: la marcación se guardó localmente pero
 *        todavía no se confirmó con el servidor — se avisa sin asustar al empleado.
 * @param {() => void} onCountdownComplete
 */
export function showSuccessScreen(screens, dom, employee, markData, eventType, { queued = false } = {}, onCountdownComplete) {
    const eventTypeNames = { check_in: 'Entrada', break_start: 'Inicio descanso', break_end: 'Fin descanso', check_out: 'Salida' };
    const fullName = `${employee.first_name || ''} ${employee.last_name || ''}`.trim();

    if (dom.successEmployeePhoto) {
        dom.successEmployeePhoto.src = employee.photo_url || '';
        dom.successEmployeePhoto.alt = fullName;
    }
    dom.successEmployeeName.textContent = fullName || 'Empleado';
    dom.successEmployeeCI.textContent = employee.ci ? `CI: ${employee.ci}` : '';
    dom.successEventType.textContent = eventTypeNames[eventType] || eventType;

    const now = new Date();
    dom.successTime.textContent = now.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });

    if (dom.successQueuedNotice) {
        dom.successQueuedNotice.style.display = queued ? '' : 'none';
    }

    playBeep('success');
    showScreen(screens, 'success');
    startCountdown({ countdownEl: dom.countdownEl, countdownFill: dom.countdownFill }, 5, onCountdownComplete);
}

/**
 * @param {Record<string, HTMLElement|null>} screens
 * @param {HTMLElement|null} errorMessageEl
 * @param {string} message
 */
export function showError(screens, errorMessageEl, message) {
    const detailed = buildDetailedError(message);
    if (errorMessageEl) errorMessageEl.textContent = detailed;
    setTerminalVideoState(null);
    playBeep('error');
    showScreen(screens, 'error');
}

/**
 * @param {Record<string, HTMLElement|null>} screens
 * @param {{dayCompleteEmployeePhoto?, dayCompleteEmployeeName?, dayCompleteCountdownEl, dayCompleteCountdownFill}} dom
 * @param {{first_name?: string, last_name?: string, photo_url?: string}} employee
 * @param {() => void} onCountdownComplete
 */
export function showDayComplete(screens, dom, employee, onCountdownComplete) {
    const fullName = `${employee.first_name || ''} ${employee.last_name || ''}`.trim();
    if (dom.dayCompleteEmployeePhoto) {
        dom.dayCompleteEmployeePhoto.src = employee.photo_url || '';
        dom.dayCompleteEmployeePhoto.alt = fullName;
    }
    if (dom.dayCompleteEmployeeName) dom.dayCompleteEmployeeName.textContent = fullName || 'Empleado';
    setTerminalVideoState(null);
    playBeep('success');
    showScreen(screens, 'dayComplete');
    startDayCompleteCountdown({ dayCompleteCountdownEl: dom.dayCompleteCountdownEl, dayCompleteCountdownFill: dom.dayCompleteCountdownFill }, 5, onCountdownComplete);
}

/**
 * @param {{dayCompleteCountdownEl?, dayCompleteCountdownFill?}} dom
 * @param {number} seconds
 * @param {() => void} onComplete
 */
export function startDayCompleteCountdown(dom, seconds, onComplete) {
    let remaining = seconds;
    if (dom.dayCompleteCountdownEl) dom.dayCompleteCountdownEl.textContent = remaining;
    if (dom.dayCompleteCountdownFill) {
        dom.dayCompleteCountdownFill.style.transition = 'none';
        dom.dayCompleteCountdownFill.style.width = '100%';
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                dom.dayCompleteCountdownFill.style.transition = `width ${seconds}s linear`;
                dom.dayCompleteCountdownFill.style.width = '0%';
            });
        });
    }
    countdownTimer = setInterval(() => {
        remaining--;
        if (dom.dayCompleteCountdownEl) dom.dayCompleteCountdownEl.textContent = remaining;
        if (remaining <= 0) {
            clearInterval(countdownTimer);
            onComplete();
        }
    }, 1000);
}

/**
 * @param {{countdownEl?, countdownFill?}} dom
 * @param {number} seconds
 * @param {() => void} onComplete
 */
export function startCountdown(dom, seconds, onComplete) {
    let remaining = seconds;
    if (dom.countdownEl) dom.countdownEl.textContent = remaining;
    if (dom.countdownFill) {
        dom.countdownFill.style.transition = 'none';
        dom.countdownFill.style.width = '100%';
        dom.countdownFill.offsetWidth; // forzar reflow para que la transición arranque desde 100%
        dom.countdownFill.style.transition = `width ${seconds}s linear`;
        dom.countdownFill.style.width = '0%';
    }

    countdownTimer = setInterval(() => {
        remaining--;
        if (dom.countdownEl) dom.countdownEl.textContent = remaining;
        if (remaining <= 0) {
            clearInterval(countdownTimer);
            onComplete();
        }
    }, 1000);
}

/** Cancela el countdown activo (success o day-complete), si hay uno. */
export function stopCountdown() {
    if (countdownTimer) {
        clearInterval(countdownTimer);
        countdownTimer = null;
    }
}

/**
 * @param {Record<string, HTMLElement|null>} screens
 * @param {NodeListOf<Element>|Element[]} typeButtons
 * @param {() => void} onReset
 */
export function resetTerminal(screens, typeButtons, onReset) {
    stopCountdown();

    typeButtons.forEach((btn) => { btn.style.display = ''; });

    const screenTitle = screens.typeSelection?.querySelector('.screen-title');
    if (screenTitle) screenTitle.textContent = 'Marcación';

    setTerminalVideoState(null);

    onReset();
}
```

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `npx vitest run resources/js/attendances/terminal/screen-state.test.js`
Expected: PASS (7 tests)

- [ ] **Step 5: Commit**

```bash
git add resources/js/attendances/terminal/screen-state.js resources/js/attendances/terminal/screen-state.test.js
git commit -m "feat: extract terminal/screen-state.js from terminal.js"
```

---

### Task 4: `terminal/mark-registration.js` — selección de tipo y registro de marcación

**Files:**
- Create: `resources/js/attendances/terminal/mark-registration.js`
- Test: `resources/js/attendances/terminal/mark-registration.test.js`

**Interfaces:**
- Consumes: `showScreen` de `screen-state.js` (Tarea 3, para `showTypeSelectionForEmployee`), `enqueueMark`/`flushQueue` de `terminal-offline/queue.js` (ya existen), `refreshIdleSyncStatus` de `terminal/sync-status-ui.js` (ya existe).
- Produces (consumido por Tarea 5, Tarea 7):
  - `function showTypeSelectionForEmployee(screens, dom, employee, allowedEvents: string[], lastEvent: string|null, lastEventTime: string|null): void`
  - `function getPendingEmployee(): object|null`
  - `function clearPendingEmployee(): void`
  - `async function registerMark(employee, eventType: string, callbacks: {onStatusUpdate: (text: string) => void, onSuccess: (employee, markData, eventType, opts) => void, onError: (message: string) => void}, nav?: Navigator): Promise<void>`

- [ ] **Step 1: Escribir los tests que fallan**

```js
// resources/js/attendances/terminal/mark-registration.test.js
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../terminal-offline/queue.js', () => ({
    enqueueMark: vi.fn(),
    flushQueue: vi.fn(),
}));
vi.mock('./sync-status-ui.js', () => ({
    refreshIdleSyncStatus: vi.fn().mockResolvedValue(undefined),
}));

import { enqueueMark, flushQueue } from '../terminal-offline/queue.js';
import { registerMark, showTypeSelectionForEmployee, getPendingEmployee, clearPendingEmployee } from './mark-registration.js';

describe('registerMark', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        enqueueMark.mockResolvedValue({ client_event_id: 'abc-123', recorded_at: '2026-09-11T10:00:00Z' });
    });

    it('sin conexión: muestra éxito encolado sin intentar flushQueue', async () => {
        const onStatusUpdate = vi.fn();
        const onSuccess = vi.fn();
        const onError = vi.fn();
        const fakeNav = { onLine: false };

        await registerMark({ id: 1 }, 'check_in', { onStatusUpdate, onSuccess, onError }, fakeNav);

        expect(flushQueue).not.toHaveBeenCalled();
        expect(onSuccess).toHaveBeenCalledWith({ id: 1 }, { recorded_at: '2026-09-11T10:00:00Z' }, 'check_in', { queued: true });
        expect(onError).not.toHaveBeenCalled();
    });

    it('con conexión y sync exitoso: muestra éxito sin encolar', async () => {
        flushQueue.mockResolvedValue({ results: [{ client_event_id: 'abc-123', status: 'synced' }] });
        const onSuccess = vi.fn();
        const fakeNav = { onLine: true };

        await registerMark({ id: 1 }, 'check_in', { onStatusUpdate: vi.fn(), onSuccess, onError: vi.fn() }, fakeNav);

        expect(onSuccess).toHaveBeenCalledWith({ id: 1 }, { recorded_at: '2026-09-11T10:00:00Z' }, 'check_in', { queued: false });
    });

    it('el servidor rechaza la marcación puntual: llama a onError', async () => {
        flushQueue.mockResolvedValue({ results: [{ client_event_id: 'abc-123', status: 'rejected', message: 'Secuencia inválida' }] });
        const onError = vi.fn();
        const fakeNav = { onLine: true };

        await registerMark({ id: 1 }, 'check_in', { onStatusUpdate: vi.fn(), onSuccess: vi.fn(), onError }, fakeNav);

        expect(onError).toHaveBeenCalledWith('Secuencia inválida');
    });

    it('flushQueue falla por red: la marcación ya encolada se muestra como éxito igual', async () => {
        flushQueue.mockRejectedValue(new Error('network down'));
        const onSuccess = vi.fn();
        const fakeNav = { onLine: true };

        await registerMark({ id: 1 }, 'check_in', { onStatusUpdate: vi.fn(), onSuccess, onError: vi.fn() }, fakeNav);

        expect(onSuccess).toHaveBeenCalledWith({ id: 1 }, { recorded_at: '2026-09-11T10:00:00Z' }, 'check_in', { queued: true });
    });
});

describe('showTypeSelectionForEmployee / getPendingEmployee / clearPendingEmployee', () => {
    it('guarda el empleado pendiente y lo expone via getPendingEmployee', () => {
        const typeButtons = [{ getAttribute: () => 'check_in', style: {} }];
        const typeGrid = { style: {} };
        const typeSelectionScreen = { querySelector: () => typeGrid };
        const screens = { typeSelection: typeSelectionScreen };
        const dom = {
            typeButtons,
            screenTitleEl: { textContent: '' },
            screenEyebrowEl: { textContent: '' },
            lastMarkEl: { textContent: '', classList: { add: vi.fn(), remove: vi.fn() } },
        };
        const employee = { id: 5, first_name: 'Juan', last_name: 'Pérez' };

        showTypeSelectionForEmployee(screens, dom, employee, ['check_in'], null, null);

        expect(getPendingEmployee()).toEqual(employee);
        expect(dom.screenTitleEl.textContent).toBe('Hola, Juan Pérez');

        clearPendingEmployee();
        expect(getPendingEmployee()).toBeNull();
    });
});
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `npx vitest run resources/js/attendances/terminal/mark-registration.test.js`
Expected: FAIL — el módulo no existe todavía.

- [ ] **Step 3: Crear el módulo**

```js
// resources/js/attendances/terminal/mark-registration.js
/**
 * =============================================================================
 * TERMINAL.JS — SELECCIÓN DE TIPO Y REGISTRO DE MARCACIÓN
 * =============================================================================
 *
 * @fileoverview Pantalla de selección de tipo de evento (cuando el empleado
 * tiene más de un evento válido) y el registro de marcación en sí — cola
 * offline + sync inmediato. Extraído de terminal.js como parte de su
 * descomposición en módulos más chicos — mismo comportamiento que el código
 * original.
 *
 * `getPendingEmployee()`/`clearPendingEmployee()` existen porque el click en
 * un botón de tipo ocurre en un momento posterior y distinto al de
 * showTypeSelectionForEmployee() — terminal.js (composition root) que cablea
 * el listener del botón necesita poder recuperar qué empleado quedó
 * pendiente de selección.
 */

import { showScreen } from './screen-state.js';
import { enqueueMark, flushQueue } from '../terminal-offline/queue.js';
import { refreshIdleSyncStatus } from './sync-status-ui.js';

const eventTypeNames = { check_in: 'Entrada', break_start: 'Inicio descanso', break_end: 'Fin descanso', check_out: 'Salida' };

let pendingEmployee = null;

/**
 * @param {Record<string, HTMLElement|null>} screens
 * @param {{typeButtons: NodeListOf<Element>|Element[], screenTitleEl, screenEyebrowEl, lastMarkEl}} dom
 * @param {{id: number, first_name?: string, last_name?: string}} employee
 * @param {string[]} allowedEvents
 * @param {string|null} lastEvent
 * @param {string|null} lastEventTime
 */
export function showTypeSelectionForEmployee(screens, dom, employee, allowedEvents, lastEvent, lastEventTime) {
    pendingEmployee = employee;

    dom.typeButtons.forEach((btn) => {
        const evtType = btn.getAttribute('data-event-type');
        btn.style.display = allowedEvents.includes(evtType) ? '' : 'none';
    });

    const visibleCount = allowedEvents.length;
    const typeGrid = screens.typeSelection?.querySelector('.type-grid');
    if (typeGrid) {
        typeGrid.style.gridTemplateColumns = visibleCount === 1 ? '1fr' : '1fr 1fr';
        typeGrid.style.maxWidth = visibleCount === 1 ? '320px' : '';
    }

    const fullName = `${employee.first_name || ''} ${employee.last_name || ''}`.trim();

    if (dom.screenTitleEl) dom.screenTitleEl.textContent = fullName ? `Hola, ${fullName}` : 'Seleccione marcación';
    if (dom.screenEyebrowEl) dom.screenEyebrowEl.textContent = fullName ? 'Empleado verificado ✓' : 'Seleccione marcación';

    if (dom.lastMarkEl) {
        if (lastEvent) {
            const lastEventName = eventTypeNames[lastEvent] || lastEvent;
            dom.lastMarkEl.textContent = lastEventTime ? `Última marcación: ${lastEventName}, ${lastEventTime}` : `Última marcación: ${lastEventName}`;
            dom.lastMarkEl.classList.remove('hidden');
        } else {
            dom.lastMarkEl.textContent = '';
            dom.lastMarkEl.classList.add('hidden');
        }
    }

    showScreen(screens, 'typeSelection');
}

/** @returns {object|null} el empleado en espera de selección de tipo, o null. */
export function getPendingEmployee() {
    return pendingEmployee;
}

/** Limpia el empleado pendiente (al cancelar o resetear). */
export function clearPendingEmployee() {
    pendingEmployee = null;
}

/**
 * Encola la marcación en IndexedDB (durable) e intenta sincronizarla de
 * inmediato. Si no hay red, o si la sincronización falla, la marcación NO se
 * pierde — queda en la cola y se reintenta en segundo plano; se muestra
 * éxito igual (queued: true) para no asustar al empleado con un error
 * cuando en realidad ya se guardó.
 * @param {{id: number}} employee
 * @param {string} eventType
 * @param {{onStatusUpdate: (text: string) => void, onSuccess: (employee, markData, eventType, opts: {queued: boolean}) => void, onError: (message: string) => void}} callbacks
 * @param {Navigator} [nav] - inyectable para tests; navigator en producción
 */
export async function registerMark(employee, eventType, { onStatusUpdate, onSuccess, onError }, nav = navigator) {
    const eventName = eventTypeNames[eventType] || eventType;
    onStatusUpdate(`Registrando: ${eventName}...`);
    await new Promise((r) => setTimeout(r, 700));

    const { client_event_id: clientEventId, recorded_at: recordedAt } = await enqueueMark(employee.id, eventType);

    if (!nav.onLine) {
        onSuccess(employee, { recorded_at: recordedAt }, eventType, { queued: true });
        await refreshIdleSyncStatus();
        return;
    }

    try {
        const { results } = await flushQueue();
        const ownResult = results.find((r) => r.client_event_id === clientEventId);

        if (ownResult && ownResult.status !== 'synced' && ownResult.status !== 'duplicate') {
            onError(ownResult.message || 'La marcación fue rechazada por el servidor.');
        } else {
            onSuccess(employee, { recorded_at: recordedAt }, eventType, { queued: !ownResult });
        }
    } catch (error) {
        console.error('Error al sincronizar la cola:', error);
        onSuccess(employee, { recorded_at: recordedAt }, eventType, { queued: true });
    }

    await refreshIdleSyncStatus();
}
```

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `npx vitest run resources/js/attendances/terminal/mark-registration.test.js`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add resources/js/attendances/terminal/mark-registration.js resources/js/attendances/terminal/mark-registration.test.js
git commit -m "feat: extract terminal/mark-registration.js from terminal.js"
```

---

### Task 5: `terminal/identification-flow.js` — orquestación del reconocimiento facial

**Files:**
- Create: `resources/js/attendances/terminal/identification-flow.js`
- Test: `resources/js/attendances/terminal/identification-flow.test.js`

**Interfaces:**
- Consumes: `camera.js` (Tarea 1: `loadModels`, `startCamera`, `stopCamera`, `startDrawLoop`, `captureDescriptor`, `isFaceDetected`, `isInCooldown`, `setNotRecognizedCooldown`), `idle-detection.js` (Tarea 2: `resetIdleTimer`), `screen-state.js` (Tarea 3: `showScreen`, `showSuccessScreen`, `showError`, `showDayComplete`), `mark-registration.js` (Tarea 4: `showTypeSelectionForEmployee`, `registerMark`), `identifyEmployee` de `terminal-offline/matcher.js`, `getCachedEmployees` de `terminal-offline/db.js`, `getFaceConfig`/`TerminalAuthError` de `terminal-offline/sync.js`, `getEmployeeStatus` de `terminal-offline/queue.js`, `setTerminalVideoState`/`setIdStatusDot`/`showCaptureProgress`/`updateCaptureProgress`/`finishCaptureProgress` de `terminal/ui-feedback.js`, `hasUserInteracted` de `shared/audio-feedback.js`, `showManualSearchLink`/`hideManualSearchLink`/`closeManualSearch` de `terminal/manual-search.js`.
- Produces (consumido por Tarea 7):
  - `async function identifyEmployeeFromDescriptor(descriptor: Float32Array, manualCandidate: object|null): Promise<{ok: boolean, employee?, distance?, last_event?, last_event_time?, allowed_events?, message?: string, reason?: string, needsProvisioning?: boolean}>`
  - `function setManualCandidate(employee: object|null): void`
  - `function getConsecutiveFailures(): number`
  - `function startIdentificationFlow(refs, callbacks: {onIdleTimeout: () => void}): void`
  - `function startAutoIdentification(refs): void`
  - `function stopAutoIdentification(videoEl: HTMLVideoElement): void`

- [ ] **Step 1: Escribir los tests que fallan**

```js
// resources/js/attendances/terminal/identification-flow.test.js
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../terminal-offline/db.js', () => ({ getCachedEmployees: vi.fn() }));
vi.mock('../terminal-offline/matcher.js', () => ({ identifyEmployee: vi.fn() }));
vi.mock('../terminal-offline/sync.js', () => {
    class TerminalAuthError extends Error {}
    return { getFaceConfig: vi.fn(), TerminalAuthError };
});
vi.mock('../terminal-offline/queue.js', () => ({ getEmployeeStatus: vi.fn() }));

import { getCachedEmployees } from '../terminal-offline/db.js';
import { identifyEmployee as matchDescriptor } from '../terminal-offline/matcher.js';
import { getFaceConfig, TerminalAuthError } from '../terminal-offline/sync.js';
import { getEmployeeStatus } from '../terminal-offline/queue.js';
import { identifyEmployeeFromDescriptor, setManualCandidate } from './identification-flow.js';

describe('identifyEmployeeFromDescriptor', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setManualCandidate(null);
    });

    it('retorna mensaje de sincronización si threshold/minGap no están listos', async () => {
        getFaceConfig.mockResolvedValue({ threshold: null, minGap: null });

        const result = await identifyEmployeeFromDescriptor(new Float32Array(128), null);

        expect(result).toEqual({ ok: false, message: 'Terminal sincronizando por primera vez, espere un momento.' });
        expect(getCachedEmployees).not.toHaveBeenCalled();
    });

    it('con manualCandidate seteado, acota el matching a ese único candidato', async () => {
        getFaceConfig.mockResolvedValue({ threshold: 0.5, minGap: 0.1 });
        const candidate = { id: 7, first_name: 'Ana' };
        setManualCandidate(candidate);
        matchDescriptor.mockReturnValue({ employee: null, distance: null, reason: 'no_match' });

        await identifyEmployeeFromDescriptor(new Float32Array(128), candidate);

        expect(getCachedEmployees).not.toHaveBeenCalled();
        expect(matchDescriptor).toHaveBeenCalledWith(expect.any(Float32Array), [candidate], 0.5, 0.1);
    });

    it('sin match: retorna el mensaje correcto según el reason', async () => {
        getFaceConfig.mockResolvedValue({ threshold: 0.5, minGap: 0.1 });
        getCachedEmployees.mockResolvedValue([]);
        matchDescriptor.mockReturnValue({ employee: null, distance: null, reason: 'no_candidates' });

        const result = await identifyEmployeeFromDescriptor(new Float32Array(128), null);

        expect(result).toEqual({ ok: false, message: 'No hay empleados sincronizados en este terminal.', reason: 'no_candidates' });
    });

    it('con match: retorna ok:true con los datos del empleado y su estado del día', async () => {
        getFaceConfig.mockResolvedValue({ threshold: 0.5, minGap: 0.1 });
        const employee = { id: 3, first_name: 'Juan', last_name: 'Pérez', ci: '1234567', photo_thumbnail: null };
        getCachedEmployees.mockResolvedValue([employee]);
        matchDescriptor.mockReturnValue({ employee, distance: 0.3, reason: null });
        getEmployeeStatus.mockResolvedValue({ last_event: 'check_in', last_event_time: '08:00', allowed_events: ['break_start', 'check_out'] });

        const result = await identifyEmployeeFromDescriptor(new Float32Array(128), null);

        expect(result.ok).toBe(true);
        expect(result.employee).toEqual({ id: 3, first_name: 'Juan', last_name: 'Pérez', ci: '1234567', photo_url: '/images/default-avatar.png' });
        expect(result.allowed_events).toEqual(['break_start', 'check_out']);
    });

    it('TerminalAuthError: retorna needsProvisioning', async () => {
        getFaceConfig.mockRejectedValue(new TerminalAuthError('sin token'));

        const result = await identifyEmployeeFromDescriptor(new Float32Array(128), null);

        expect(result).toEqual({ ok: false, message: 'sin token', needsProvisioning: true });
    });
});
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

Run: `npx vitest run resources/js/attendances/terminal/identification-flow.test.js`
Expected: FAIL — el módulo no existe todavía.

- [ ] **Step 3: Crear el módulo**

```js
// resources/js/attendances/terminal/identification-flow.js
/**
 * =============================================================================
 * TERMINAL.JS — ORQUESTACIÓN DEL RECONOCIMIENTO FACIAL
 * =============================================================================
 *
 * @fileoverview El corazón del flujo: arranca cámara + loop de detección
 * (camera.js), captura un descriptor cuando hay rostro, lo compara contra la
 * caché local de empleados (identifyEmployeeFromDescriptor — mismo algoritmo
 * de distancia euclidiana + umbral/gap que corre en el servidor, ver
 * terminal-offline/matcher.js), y decide el siguiente paso: registrar
 * directo, mostrar selección de tipo, o mostrar jornada completa. Extraído
 * de terminal.js como parte de su descomposición en módulos más chicos —
 * mismo comportamiento que el código original.
 *
 * Importa screen-state.js y mark-registration.js directamente (sin ciclos:
 * ninguno de los dos importa identification-flow.js de vuelta). Solo recibe
 * `onIdleTimeout` como callback porque ese destino (enterIdle) vive en
 * terminal.js, que coordina idle-detection + screen-state + este módulo a
 * la vez.
 */

import * as camera from './camera.js';
import { resetIdleTimer } from './idle-detection.js';
import { showScreen, showSuccessScreen, showError, showDayComplete } from './screen-state.js';
import { showTypeSelectionForEmployee, registerMark, getPendingEmployee, clearPendingEmployee } from './mark-registration.js';
import { identifyEmployee as matchDescriptor } from '../terminal-offline/matcher.js';
import { getCachedEmployees } from '../terminal-offline/db.js';
import { getFaceConfig, TerminalAuthError } from '../terminal-offline/sync.js';
import { getEmployeeStatus } from '../terminal-offline/queue.js';
import { setTerminalVideoState, setIdStatusDot, showCaptureProgress, updateCaptureProgress, finishCaptureProgress } from './ui-feedback.js';
import { hasUserInteracted } from '../../shared/audio-feedback.js';
import { showManualSearchLink, hideManualSearchLink, closeManualSearch } from './manual-search.js';

const CONSECUTIVE_FAILURES_FOR_MANUAL_SEARCH = 2;

let identifyInterval = null;
let isProcessing = false;
let consecutiveFailures = 0;
let manualCandidate = null;

/** @param {object|null} employee */
export function setManualCandidate(employee) {
    manualCandidate = employee;
}

/** @returns {number} */
export function getConsecutiveFailures() {
    return consecutiveFailures;
}

/**
 * Identifica al empleado comparando el descriptor capturado contra la caché
 * local de empleados, con el mismo algoritmo que el servidor.
 * @param {Float32Array} descriptor
 * @param {object|null} manualCandidateOverride - si se pasa, acota el match a este único candidato (búsqueda manual por CI)
 * @returns {Promise<{ok: boolean, employee?, distance?, last_event?, last_event_time?, allowed_events?, message?: string, reason?: string, needsProvisioning?: boolean}>}
 */
export async function identifyEmployeeFromDescriptor(descriptor, manualCandidateOverride = manualCandidate) {
    try {
        const { threshold, minGap } = await getFaceConfig();
        if (threshold == null || minGap == null) {
            return { ok: false, message: 'Terminal sincronizando por primera vez, espere un momento.' };
        }

        const candidates = manualCandidateOverride ? [manualCandidateOverride] : await getCachedEmployees();
        const { employee, distance, reason } = matchDescriptor(descriptor, candidates, threshold, minGap);

        if (!employee) {
            const messages = {
                no_candidates: 'No hay empleados sincronizados en este terminal.',
                ambiguous: 'Rostro ambiguo. Por favor, reposicione su cara e intente de nuevo.',
                no_match: 'No se pudo identificar el rostro. Intente nuevamente.',
            };
            return { ok: false, message: messages[reason] || 'No identificado', reason };
        }

        const status = await getEmployeeStatus(employee.id);

        return {
            ok: true,
            employee: {
                id: employee.id,
                first_name: employee.first_name,
                last_name: employee.last_name,
                ci: employee.ci,
                photo_url: employee.photo_thumbnail || '/images/default-avatar.png',
            },
            distance,
            last_event: status.last_event,
            last_event_time: status.last_event_time,
            allowed_events: status.allowed_events,
        };
    } catch (error) {
        if (error instanceof TerminalAuthError) {
            return { ok: false, message: error.message, needsProvisioning: true };
        }
        console.error('Error en identificación:', error);
        return { ok: false, message: 'Error de conexión' };
    }
}

function updateStatus(identificationStatusEl, text) {
    if (!identificationStatusEl) return;
    const statusTextEl = identificationStatusEl.querySelector('.id-status-text');
    if (statusTextEl) {
        statusTextEl.textContent = text;
    } else {
        identificationStatusEl.innerHTML = `<span class="id-status-dot" id="idStatusDot"></span><span class="id-status-text">${text}</span>`;
    }
}

/**
 * Punto de entrada principal del flujo — resetea el estado de intentos
 * previos, oculta la búsqueda manual, muestra la pantalla de identificación
 * y arranca la auto-identificación.
 * @param {{screens, video, overlay, ctx, identificationStatus}} refs
 * @param {{onIdleTimeout: () => void}} callbacks
 */
export function startIdentificationFlow(refs, { onIdleTimeout }) {
    resetIdleTimer(onIdleTimeout);
    consecutiveFailures = 0;
    manualCandidate = null;
    hideManualSearchLink();
    closeManualSearch();
    showScreen(refs.screens, 'identification');
    startAutoIdentification(refs);
    setTerminalVideoState('detecting');
}

/**
 * @param {{screens, video: HTMLVideoElement, overlay: HTMLCanvasElement, ctx: CanvasRenderingContext2D, identificationStatus, successDom, errorMessageEl, dayCompleteDom, typeSelectionDom}} refs
 */
export async function startAutoIdentification(refs) {
    const modelsResult = await camera.loadModels();
    if (!modelsResult.ok) {
        showError(refs.screens, refs.errorMessageEl, modelsResult.message);
        return;
    }

    const cameraResult = await camera.startCamera(refs.video, refs.overlay);
    if (!cameraResult.ok) {
        showError(refs.screens, refs.errorMessageEl, cameraResult.message);
        return;
    }

    camera.startDrawLoop(refs.video, refs.overlay, refs.ctx, {
        isProcessing: () => isProcessing,
        onFrame: (state) => {
            if (state.justExitedCooldown) {
                updateStatus(refs.identificationStatus, 'Posicione su rostro dentro del óvalo...');
            }
            if (state.detected) {
                setTerminalVideoState(state.tooSmall ? 'detecting' : 'face-found');
                setIdStatusDot(state.tooSmall ? 'detecting' : 'face-found');
                if (state.tooSmall) updateStatus(refs.identificationStatus, 'Acérquese un poco más a la cámara');
            } else {
                setTerminalVideoState('detecting');
                setIdStatusDot('detecting');
            }
        },
    });
    updateStatus(refs.identificationStatus, 'Posicione su rostro dentro del óvalo...');

    identifyInterval = setInterval(async () => {
        if (isProcessing) return;
        if (camera.isInCooldown()) return;
        if (!camera.isFaceDetected()) return;

        try {
            isProcessing = true;
            setTerminalVideoState('face-found');
            setIdStatusDot('face-found');
            updateStatus(refs.identificationStatus, 'Analizando rostro, mantenga la posición...');
            showCaptureProgress();

            const descriptor = await camera.captureDescriptor(refs.video, 5, 150, (count) => updateCaptureProgress(count));
            const result = await identifyEmployeeFromDescriptor(descriptor, manualCandidate);

            if (result.needsProvisioning) {
                stopAutoIdentification(refs.video);
                await finishCaptureProgress('error');
                showError(refs.screens, refs.errorMessageEl, result.message);
                return;
            }

            if (result.ok && result.employee) {
                consecutiveFailures = 0;
                manualCandidate = null;
                hideManualSearchLink();

                if (hasUserInteracted()) navigator.vibrate?.(80);
                stopAutoIdentification(refs.video);

                await finishCaptureProgress('success');
                setTerminalVideoState('success');
                setIdStatusDot('success');

                const allowedEvents = result.allowed_events || [];
                const onCountdownComplete = () => startIdentificationFlow(refs, { onIdleTimeout: refs.onIdleTimeout });
                const markCallbacks = {
                    onStatusUpdate: (text) => updateStatus(refs.identificationStatus, text),
                    onSuccess: (employee, markData, eventType, opts) => showSuccessScreen(refs.screens, refs.successDom, employee, markData, eventType, opts, onCountdownComplete),
                    onError: (message) => showError(refs.screens, refs.errorMessageEl, message),
                };

                if (allowedEvents.length === 1) {
                    await registerMark(result.employee, allowedEvents[0], markCallbacks);
                } else if (allowedEvents.length > 1) {
                    showTypeSelectionForEmployee(refs.screens, refs.typeSelectionDom, result.employee, allowedEvents, result.last_event, result.last_event_time);
                } else {
                    showDayComplete(refs.screens, refs.dayCompleteDom, result.employee, onCountdownComplete);
                }
            } else {
                await finishCaptureProgress('error');

                camera.setNotRecognizedCooldown(1200);
                setTerminalVideoState('detecting');
                setIdStatusDot('detecting');
                const idleStatusMessages = {
                    no_candidates: 'Terminal sin empleados sincronizados. Contacte al administrador.',
                    ambiguous: 'Rostro ambiguo. Reposicione su cara e intente de nuevo.',
                    no_match: 'Rostro no reconocido. Mantenga el rostro quieto frente a la cámara.',
                };
                updateStatus(refs.identificationStatus, idleStatusMessages[result.reason] || result.message || 'Rostro no reconocido. Mantenga el rostro quieto frente a la cámara.');
                console.log('No se pudo identificar', result.reason);

                consecutiveFailures++;
                if (consecutiveFailures >= CONSECUTIVE_FAILURES_FOR_MANUAL_SEARCH) {
                    showManualSearchLink();
                }
            }
        } catch (error) {
            await finishCaptureProgress('error');
            console.error('Error en auto-identificación:', error);
            updateStatus(refs.identificationStatus, 'Error al analizar. Asegúrese de tener buena iluminación.');
        } finally {
            if (identifyInterval) isProcessing = false;
        }
    }, 1500);
}

/** @param {HTMLVideoElement} videoEl */
export function stopAutoIdentification(videoEl) {
    if (identifyInterval) {
        clearInterval(identifyInterval);
        identifyInterval = null;
    }
    camera.stopCamera(videoEl);
}
```

Nota para quien implemente esta tarea: `refs.onIdleTimeout` se usa dentro de `startAutoIdentification` (vía el `onCountdownComplete` que vuelve a llamar `startIdentificationFlow`) — asegurarse de que `refs` incluya `onIdleTimeout` cuando `startIdentificationFlow` arma su propio `refs` para pasarlo a `startAutoIdentification` (ya lo hace, `refs` es el mismo objeto en ambas llamadas). `getPendingEmployee`/`clearPendingEmployee` importados de `mark-registration.js` no se usan directamente en este módulo — quedan disponibles para que Tarea 7 los use al cablear el listener de los botones de tipo en `terminal.js`; si al escribir el código no terminan usándose acá, quitar el import para no dejar código muerto.

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `npx vitest run resources/js/attendances/terminal/identification-flow.test.js`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add resources/js/attendances/terminal/identification-flow.js resources/js/attendances/terminal/identification-flow.test.js
git commit -m "feat: extract terminal/identification-flow.js from terminal.js"
```

---

### Task 6: `terminal/bootstrap.js` — arranque del sistema

**Files:**
- Create: `resources/js/attendances/terminal/bootstrap.js`

**Interfaces:**
- Consumes: `loadModels` de `camera.js` (Tarea 1), `acquireWakeLock` de `idle-detection.js` (Tarea 2), `migrateTokenFromLocalStorage`/`getMeta`/`clearTerminalState` de `terminal-offline/db.js`, `heartbeat`/`syncEmployees` de `terminal-offline/sync.js`, `flushQueue` de `terminal-offline/queue.js`, `updateIdleSyncStatus`/`refreshIdleSyncStatus`/`refreshLastSyncLabel` de `terminal/sync-status-ui.js`, `markUserInteracted` de `shared/audio-feedback.js`.
- Produces (consumido por Tarea 7):
  - `function updateLoadingProgress(dom: {loadingProgress?, loadingPercentage?, loadingMessage?, loadingStep1?, loadingStep2?, loadingStep3?}, percentage: number, message: string, stepNumber: number): void`
  - `async function checkLegacyTerminalMigration(dom: {banner, link}): Promise<void>`
  - `async function initializeSystem(dom: {loading progress refs..., errorMessageEl}, callbacks: {onReady: () => void, onError: (message: string) => void}): Promise<void>`
  - `async function requestPersistentStorage(): Promise<void>`
  - `async function initializeOfflineSync(): Promise<void>`
  - `function startBackgroundSync(): void`
  - `function initInteractionTracking(doc?: Document): void`

No tests unitarios para este módulo (justificado en Global Constraints: orquestación secuencial de arranque, bajo valor de test unitario).

- [ ] **Step 1: Crear el módulo**

```js
// resources/js/attendances/terminal/bootstrap.js
/**
 * =============================================================================
 * TERMINAL.JS — ARRANQUE DEL SISTEMA
 * =============================================================================
 *
 * @fileoverview Secuencia de arranque: verificación de compatibilidad, carga
 * de modelos (reusa camera.js — antes duplicaba la misma carga de 3 modelos
 * que loadModels(), ambas controladas por el mismo flag interno, así que
 * unificar acá no cambia comportamiento), wake lock, sync inicial, y sync en
 * segundo plano periódico. Extraído de terminal.js como parte de su
 * descomposición en módulos más chicos — mismo comportamiento que el código
 * original (salvo la deduplicación de carga de modelos, ver arriba).
 *
 * No decide qué pantalla mostrar al terminar — recibe `onReady`/`onError`
 * como callbacks, ese destino (enterIdle/showError) vive en terminal.js.
 */

import * as camera from './camera.js';
import { acquireWakeLock } from './idle-detection.js';
import { migrateTokenFromLocalStorage, getMeta, clearTerminalState } from '../terminal-offline/db.js';
import { heartbeat, syncEmployees } from '../terminal-offline/sync.js';
import { flushQueue } from '../terminal-offline/queue.js';
import { updateIdleSyncStatus, refreshIdleSyncStatus, refreshLastSyncLabel } from './sync-status-ui.js';
import { markUserInteracted } from '../../shared/audio-feedback.js';

let backgroundSyncStarted = false;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * @param {{loadingProgress?, loadingPercentage?, loadingMessage?, loadingStep1?, loadingStep2?, loadingStep3?}} dom
 * @param {number} percentage
 * @param {string} message
 * @param {number} stepNumber
 */
export function updateLoadingProgress(dom, percentage, message, stepNumber) {
    if (dom.loadingProgress) dom.loadingProgress.style.width = `${percentage}%`;
    if (dom.loadingPercentage) dom.loadingPercentage.textContent = `${percentage}%`;
    if (dom.loadingMessage && message) dom.loadingMessage.textContent = message;

    const steps = [dom.loadingStep1, dom.loadingStep2, dom.loadingStep3];
    steps.forEach((step, index) => {
        if (!step) return;
        step.classList.remove('active', 'completed');
        if (index + 1 < stepNumber) step.classList.add('completed');
        else if (index + 1 === stepNumber) step.classList.add('active');
    });
}

/**
 * Si esta página es /terminal (legacy, sin código) y el dispositivo ya tiene
 * un terminal_code guardado en IndexedDB de una provisión anterior, muestra
 * un banner con el link directo a /terminal/{code}.
 * @param {{banner: HTMLElement|null, link: HTMLElement|null}} dom
 */
export async function checkLegacyTerminalMigration(dom) {
    if (window.terminalData) return; // ya estamos en /terminal/{code}, nada que migrar
    if (!dom.banner || !dom.link) return;

    try {
        const code = await getMeta('terminal_code');
        if (!code) return;

        const url = `${window.location.origin}/terminal/${code}`;
        dom.link.href = url;
        dom.link.textContent = url;
        dom.banner.classList.add('is-visible');
        dom.banner.setAttribute('aria-hidden', 'false');
    } catch (error) {
        console.warn('No se pudo verificar el código de terminal guardado localmente:', error);
    }
}

/**
 * @param {{loadingProgress?, loadingPercentage?, loadingMessage?, loadingStep1?, loadingStep2?, loadingStep3?}} loadingDom
 * @param {{onReady: () => void, onError: (message: string) => void}} callbacks
 */
export async function initializeSystem(loadingDom, { onReady, onError }) {
    try {
        updateLoadingProgress(loadingDom, 10, 'Verificando compatibilidad del navegador...', 1);
        await sleep(300);

        if (typeof faceapi === 'undefined') {
            throw new Error('La biblioteca face-api.js no está disponible');
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Tu navegador no soporta acceso a la cámara');
        }

        updateLoadingProgress(loadingDom, 30, 'Navegador compatible ✓', 1);
        await sleep(200);

        updateLoadingProgress(loadingDom, 40, 'Cargando modelos de reconocimiento facial...', 2);
        const modelsResult = await camera.loadModels();
        if (!modelsResult.ok) {
            throw new Error(modelsResult.message);
        }

        updateLoadingProgress(loadingDom, 80, 'Modelos cargados correctamente ✓', 2);
        await sleep(300);

        updateLoadingProgress(loadingDom, 90, 'Preparando sistema de marcación...', 3);
        await sleep(300);

        updateLoadingProgress(loadingDom, 100, 'Sistema listo ✓', 3);
        await sleep(500);

        await acquireWakeLock();
        await initializeOfflineSync();

        console.log('Sistema inicializado correctamente');
        onReady();
    } catch (error) {
        console.error('Error en la inicialización:', error);
        if (loadingDom.loadingMessage) {
            loadingDom.loadingMessage.textContent = `Error: ${error.message}`;
            loadingDom.loadingMessage.style.color = '#ef4444';
        }
        await sleep(3000);
        onError('Error al inicializar el sistema. ' + error.message + ' Por favor, recargue la página.');
    }
}

/**
 * Pide almacenamiento persistente — reduce el riesgo de que el navegador
 * borre IndexedDB bajo presión de espacio en disco. Best-effort.
 */
export async function requestPersistentStorage() {
    if (!navigator.storage?.persist) return;
    try {
        const granted = await navigator.storage.persist();
        console.log(granted ? 'Almacenamiento persistente concedido' : 'Almacenamiento persistente no concedido (best-effort)');
    } catch (error) {
        console.warn('No se pudo solicitar almacenamiento persistente:', error.message);
    }
}

/**
 * Migra el token de configuración y hace la primera sincronización. Si el
 * terminal no está provisionado, no bloquea el arranque — solo informa en
 * la pantalla idle.
 *
 * Regresión de seguridad prevenida acá: compara terminal_id/terminal_code
 * guardados contra los del terminal actual — si no coinciden, limpia el
 * estado local antes de continuar (ver terminal.js original para el
 * contexto completo de esta protección).
 */
export async function initializeOfflineSync() {
    await migrateTokenFromLocalStorage();

    const storedId = await getMeta('terminal_id');
    const storedCode = await getMeta('terminal_code');
    const currentId = window.terminalData?.id;
    const currentCode = window.terminalData?.code;

    const belongsToOtherTerminal = (storedId != null && currentId != null)
        ? storedId !== currentId
        : (storedCode != null && currentCode != null && storedCode !== currentCode);

    if (belongsToOtherTerminal) {
        console.warn(`Datos locales pertenecen a otro terminal (id ${storedId ?? 'desconocido'}, code "${storedCode}") — limpiando antes de continuar.`);
        await clearTerminalState();
    }

    const token = await getMeta('api_token');

    if (!token) {
        console.warn('Terminal sin token de sincronización — falta provisión.');
        updateIdleSyncStatus('Terminal sin configurar');
        return;
    }

    await requestPersistentStorage();

    try {
        updateIdleSyncStatus('Sincronizando...');
        await heartbeat();
        await syncEmployees();
        await flushQueue();
        await refreshIdleSyncStatus();
    } catch (error) {
        console.warn('Sincronización inicial falló (se reintentará en segundo plano):', error.message);
        updateIdleSyncStatus(navigator.onLine ? 'Error al sincronizar' : 'Sin conexión — usando datos locales');
        await refreshLastSyncLabel();
    }

    startBackgroundSync();
}

/**
 * Heartbeat + sync de empleados + vaciado de la cola, periódicos mientras
 * haya conexión, más un intento al recuperarla.
 */
export function startBackgroundSync() {
    if (backgroundSyncStarted) return;
    backgroundSyncStarted = true;

    const runHeartbeat = () => {
        if (!navigator.onLine) return;
        heartbeat().catch((error) => console.warn('Heartbeat en segundo plano falló:', error.message));
    };
    const runEmployeeSync = () => {
        if (!navigator.onLine) return;
        syncEmployees().catch((error) => console.warn('Sync de empleados en segundo plano falló:', error.message));
    };
    const runQueueFlush = () => {
        if (!navigator.onLine) return;
        flushQueue().then(() => refreshIdleSyncStatus()).catch((error) => console.warn('Sincronización de cola en segundo plano falló:', error.message));
    };

    setInterval(runHeartbeat, 90 * 1000);
    setInterval(runEmployeeSync, 5 * 60 * 1000);
    setInterval(runQueueFlush, 30 * 1000);

    window.addEventListener('online', () => {
        runHeartbeat();
        runEmployeeSync();
        runQueueFlush();
    });
}

/**
 * Marca la primera interacción del usuario (click o touch) para habilitar
 * la Vibration API — se dispara una sola vez.
 * @param {Document} [doc] - inyectable para tests; document en producción
 */
export function initInteractionTracking(doc = document) {
    const markInteraction = () => {
        markUserInteracted();
        doc.removeEventListener('click', markInteraction);
        doc.removeEventListener('touchstart', markInteraction);
    };
    doc.addEventListener('click', markInteraction, { once: true });
    doc.addEventListener('touchstart', markInteraction, { once: true });
}
```

- [ ] **Step 2: Verificar que el archivo es sintácticamente válido**

Run: correr `npx vitest run` completo (sin filtro) al final del task para confirmar que Vite/Vitest puede parsear el módulo sin errores, ya que no hay test propio que lo importe todavía.

- [ ] **Step 3: Commit**

```bash
git add resources/js/attendances/terminal/bootstrap.js
git commit -m "feat: extract terminal/bootstrap.js from terminal.js"
```

---

### Task 7: Reescribir `terminal.js` como composition root

**Files:**
- Modify: `resources/js/attendances/terminal.js` (reescritura completa)

**Interfaces:**
- Consumes: los 6 módulos de las Tareas 1-6 completos (`camera.js`, `idle-detection.js`, `screen-state.js`, `mark-registration.js`, `identification-flow.js`, `bootstrap.js`), más los módulos ya existentes (`terminal-offline/*`, `terminal/ui-feedback.js`, `terminal/sync-status-ui.js`, `terminal/manual-search.js`, `shared/audio-feedback.js`, `shared/theme-toggle.js`).
- Produces: nada consumido por tareas posteriores — última tarea del plan.

- [ ] **Step 1: Reescribir `terminal.js` completo**

```js
// resources/js/attendances/terminal.js
import * as camera from './terminal/camera.js';
import * as idleDetection from './terminal/idle-detection.js';
import * as screenState from './terminal/screen-state.js';
import * as markRegistration from './terminal/mark-registration.js';
import * as identificationFlow from './terminal/identification-flow.js';
import * as bootstrap from './terminal/bootstrap.js';
import { updateClock, updateIdleDate } from './terminal/ui-feedback.js';
import { setOffline } from './terminal/sync-status-ui.js';
import { initManualSearch } from './terminal/manual-search.js';
import { initThemeToggle } from '../shared/theme-toggle.js';

document.addEventListener('DOMContentLoaded', () => {
    // ============================================================================
    // ELEMENTOS DEL DOM
    // ============================================================================
    const screens = {
        startGate:      document.getElementById('startGateScreen'),
        loading:        document.getElementById('loadingScreen'),
        idle:           document.getElementById('idleScreen'),
        typeSelection:  document.getElementById('typeSelectionScreen'),
        identification: document.getElementById('identificationScreen'),
        success:        document.getElementById('successScreen'),
        dayComplete:    document.getElementById('dayCompleteScreen'),
        error:          document.getElementById('errorScreen'),
    };

    const loadingDom = {
        loadingMessage:    document.getElementById('loadingMessage'),
        loadingProgress:   document.getElementById('loadingProgress'),
        loadingPercentage: document.getElementById('loadingPercentage'),
        loadingStep1:      document.getElementById('step1'),
        loadingStep2:      document.getElementById('step2'),
        loadingStep3:      document.getElementById('step3'),
    };

    const video   = document.getElementById('terminalVideo');
    const overlay = document.getElementById('terminalOverlay');
    const ctx     = overlay?.getContext('2d');

    const identificationStatus = document.getElementById('identificationStatus');
    const btnForceSync = document.getElementById('btnForceSync');

    const typeButtons   = document.querySelectorAll('.terminal-type-btn');
    const btnCancel      = document.getElementById('btnCancelIdentification');
    const btnMarkAnother = document.getElementById('btnMarkAnother');
    const btnRetry       = document.getElementById('btnRetry');
    const btnReload      = document.getElementById('btnReload');

    const dayCompleteDom = {
        dayCompleteEmployeePhoto: document.getElementById('dayCompleteEmployeePhoto'),
        dayCompleteEmployeeName:  document.getElementById('dayCompleteEmployeeName'),
        dayCompleteCountdownEl:   document.getElementById('dayCompleteCountdown'),
        dayCompleteCountdownFill: document.getElementById('dayCompleteCountdownFill'),
    };

    const successDom = {
        successEmployeePhoto: document.getElementById('successEmployeePhoto'),
        successEmployeeName:  document.getElementById('successEmployeeName'),
        successEmployeeCI:    document.getElementById('successEmployeeCI'),
        successEventType:     document.getElementById('successEventType'),
        successTime:          document.getElementById('successTime'),
        successQueuedNotice:  document.getElementById('successQueuedNotice'),
        countdownEl:          document.getElementById('countdown'),
        countdownFill:        document.getElementById('countdownFill'),
    };

    const typeSelectionDom = {
        typeButtons,
        screenTitleEl:   document.getElementById('typeSelectionTitle'),
        screenEyebrowEl: document.getElementById('typeSelectionEyebrow'),
        lastMarkEl:      document.getElementById('typeSelectionLastMark'),
    };

    const errorMessageEl = document.getElementById('errorMessage');

    /** Datos de la terminal identificada (inyectados por PHP cuando se accede via /terminal/{code}) */
    const terminalData = window.terminalData || null;

    const idleTerminalInfo   = document.getElementById('idleTerminalInfo');
    const idleTerminalName   = document.getElementById('idleTerminalName');
    const idleTerminalBranch = document.getElementById('idleTerminalBranch');
    if (terminalData && idleTerminalInfo) {
        if (idleTerminalName)   idleTerminalName.textContent   = terminalData.name || '';
        if (idleTerminalBranch) idleTerminalBranch.textContent = terminalData.branch_name || '';
        idleTerminalInfo.style.display = '';
    }

    const headerLocation = document.getElementById('terminalHeaderLocation');
    if (terminalData && headerLocation && (terminalData.branch_name || terminalData.company_name)) {
        headerLocation.textContent = [terminalData.branch_name, terminalData.company_name].filter(Boolean).join(' — ');
    }

    const headerLogo = document.getElementById('terminalHeaderLogo');
    if (terminalData && headerLogo && terminalData.company_logo) {
        headerLogo.src = terminalData.company_logo;
        headerLogo.classList.remove('hidden');
    }

    const headerDevice = document.getElementById('terminalHeaderDevice');
    if (terminalData && headerDevice) {
        const deviceLabel = [terminalData.device_brand, terminalData.device_model].filter(Boolean).join(' ');
        if (deviceLabel) {
            headerDevice.textContent = deviceLabel;
            headerDevice.classList.remove('hidden');
        }
    }

    const terminalHeader = document.querySelector('.terminal-header');
    const IDLE_TIMEOUT_MS = 5 * 60 * 1000;

    /** Bundle de refs pasado a identification-flow.js — un único objeto reutilizado en cada llamada. */
    const identificationRefs = {
        screens, video, overlay, ctx, identificationStatus,
        successDom, errorMessageEl, dayCompleteDom, typeSelectionDom,
        onIdleTimeout: enterIdle,
    };

    // ============================================================================
    // WAKE LOCK — re-adquirir si el tab vuelve al foco
    // ============================================================================
    document.addEventListener('visibilitychange', async () => {
        if (document.visibilityState === 'visible' && !idleDetection.hasWakeLock()) {
            await idleDetection.acquireWakeLock();
        }
    });

    // ============================================================================
    // RELOJ EN TIEMPO REAL
    // ============================================================================
    updateClock();
    setInterval(updateClock, 1000);

    // ============================================================================
    // ORQUESTACIÓN — IDLE / RESET (coordina idle-detection + screen-state + identification-flow)
    // ============================================================================
    function enterIdle() {
        idleDetection.clearIdleTimer();
        identificationFlow.stopAutoIdentification(video);
        screenState.stopCountdown();
        updateIdleDate();
        screenState.showScreen(screens, 'idle');
        idleDetection.startPresenceCheck(video, exitIdle);
        if (terminalHeader) terminalHeader.classList.add('terminal-header--idle');
    }

    function exitIdle() {
        idleDetection.stopPresenceCheck();
        if (terminalHeader) terminalHeader.classList.remove('terminal-header--idle');
        identificationFlow.startIdentificationFlow(identificationRefs, { onIdleTimeout: enterIdle });
    }

    function resetTerminal() {
        identificationFlow.stopAutoIdentification(video);
        markRegistration.clearPendingEmployee();
        screenState.resetTerminal(screens, typeButtons, () => identificationFlow.startIdentificationFlow(identificationRefs, { onIdleTimeout: enterIdle }));
    }

    // ============================================================================
    // BÚSQUEDA MANUAL POR CI
    // ============================================================================
    initManualSearch({
        onSelect: (employee) => {
            identificationFlow.setManualCandidate(employee);
            const statusTextEl = identificationStatus?.querySelector('.id-status-text');
            const text = `Mirá la cámara para confirmar que sos ${employee.first_name || 'vos'}...`;
            if (statusTextEl) statusTextEl.textContent = text;
            else if (identificationStatus) identificationStatus.innerHTML = `<span class="id-status-dot" id="idStatusDot"></span><span class="id-status-text">${text}</span>`;
        },
    });

    // ============================================================================
    // EVENT LISTENERS
    // ============================================================================
    typeButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            const eventType = button.getAttribute('data-event-type');
            const employee = markRegistration.getPendingEmployee();
            if (!employee) return;
            idleDetection.clearIdleTimer();
            await markRegistration.registerMark(employee, eventType, {
                onStatusUpdate: (text) => {
                    const statusTextEl = identificationStatus?.querySelector('.id-status-text');
                    if (statusTextEl) statusTextEl.textContent = text;
                },
                onSuccess: (emp, markData, evtType, opts) => screenState.showSuccessScreen(
                    screens, successDom, emp, markData, evtType, opts,
                    () => identificationFlow.startIdentificationFlow(identificationRefs, { onIdleTimeout: enterIdle }),
                ),
                onError: (message) => screenState.showError(screens, errorMessageEl, message),
            });
        });
    });

    if (screens.idle) {
        screens.idle.addEventListener('click', () => exitIdle());
    }

    if (btnCancel) {
        btnCancel.addEventListener('click', () => {
            identificationFlow.stopAutoIdentification(video);
            resetTerminal();
        });
    }

    if (btnMarkAnother) btnMarkAnother.addEventListener('click', () => resetTerminal());
    if (btnRetry) btnRetry.addEventListener('click', () => resetTerminal());
    if (btnReload) btnReload.addEventListener('click', () => window.location.reload());

    // ============================================================================
    // CONECTIVIDAD
    // ============================================================================
    setOffline(!navigator.onLine);
    window.addEventListener('offline', () => setOffline(true));
    window.addEventListener('online',  () => setOffline(false));

    bootstrap.initInteractionTracking();

    if (btnForceSync) {
        btnForceSync.addEventListener('click', async (event) => {
            event.stopPropagation();
            btnForceSync.disabled = true;
            const { heartbeat, syncEmployees } = await import('./terminal-offline/sync.js');
            const { flushQueue } = await import('./terminal-offline/queue.js');
            const { updateIdleSyncStatus, refreshIdleSyncStatus } = await import('./terminal/sync-status-ui.js');
            try {
                await heartbeat();
                await syncEmployees();
                await flushQueue();
                await refreshIdleSyncStatus();
            } catch (error) {
                const { TerminalAuthError } = await import('./terminal-offline/sync.js');
                updateIdleSyncStatus(error instanceof TerminalAuthError ? 'Terminal sin configurar' : 'Error al sincronizar');
            } finally {
                btnForceSync.disabled = false;
            }
        });
    }

    // ============================================================================
    // TEMA CLARO / OSCURO
    // ============================================================================
    initThemeToggle('terminal-theme');

    // ============================================================================
    // INICIALIZACIÓN
    // ============================================================================
    console.log('Terminal de marcación inicializado');

    bootstrap.checkLegacyTerminalMigration({
        banner: document.getElementById('legacyMigrationBanner'),
        link: document.getElementById('legacyMigrationLink'),
    });

    const btnStartGate = document.getElementById('btnStartGate');
    const startSystem = () => {
        screenState.showScreen(screens, 'loading');
        bootstrap.initializeSystem(loadingDom, {
            onReady: enterIdle,
            onError: (message) => screenState.showError(screens, errorMessageEl, message),
        });
    };
    if (btnStartGate) {
        btnStartGate.addEventListener('click', startSystem, { once: true });
    } else {
        startSystem();
    }
});
```

**Nota importante para quien implemente esta tarea:** el bloque `btnForceSync` de arriba usa `import()` dinámico para `heartbeat`/`syncEmployees`/`flushQueue`/`updateIdleSyncStatus`/`refreshIdleSyncStatus`/`TerminalAuthError` — esto es innecesariamente indirecto. Reemplazarlo por imports estáticos normales en la cabecera del archivo:
```js
import { heartbeat, syncEmployees, TerminalAuthError } from './terminal-offline/sync.js';
import { flushQueue } from './terminal-offline/queue.js';
import { updateIdleSyncStatus, refreshIdleSyncStatus } from './terminal/sync-status-ui.js';
```
y simplificar el listener de `btnForceSync` a:
```js
    if (btnForceSync) {
        btnForceSync.addEventListener('click', async (event) => {
            event.stopPropagation();
            btnForceSync.disabled = true;
            updateIdleSyncStatus('Sincronizando...');
            try {
                await heartbeat();
                await syncEmployees();
                await flushQueue();
                await refreshIdleSyncStatus();
            } catch (error) {
                updateIdleSyncStatus(error instanceof TerminalAuthError ? 'Terminal sin configurar' : 'Error al sincronizar');
            } finally {
                btnForceSync.disabled = false;
            }
        });
    }
```
(el `updateIdleSyncStatus('Sincronizando...')` inicial estaba en el código original y se omitió arriba por error de transcripción — debe preservarse).

- [ ] **Step 2: Correr el suite completo de Vitest y confirmar que todos los módulos nuevos siguen pasando**

Run: `npx vitest run`
Expected: PASS (todos los tests de las Tareas 2-5 + el resto del suite existente)

- [ ] **Step 3: Correr el test Pest de la vista del terminal**

Run: `php artisan test --compact --filter=TerminalViewTest`
Expected: PASS — no debería verse afectado, no se tocó la vista Blade.

- [ ] **Step 4: `npm run build`**

Run: `npm run build`
Expected: build exitoso, sin errores de resolución de módulos.

- [ ] **Step 5: Verificación manual en navegador con un terminal de prueba**

Provisionar un terminal de prueba (o reusar uno existente en un entorno de desarrollo) y verificar en `/terminal/{code}`:
1. Pantalla de inicio → toque → carga de modelos → pantalla idle.
2. Acercarse a la cámara desde idle → sale de reposo → pantalla de identificación.
3. Identificación exitosa con un único evento válido → registra automático → pantalla de éxito → countdown → vuelve a identificación.
4. Identificación exitosa con múltiples eventos válidos → pantalla de selección de tipo → elegir uno → registra → pantalla de éxito.
5. Reconocimiento fallido repetido (o forzar) → aparece el link de búsqueda manual por CI → usarlo → confirma con la cámara.
6. Cancelar desde identificación (botón cancelar) → vuelve a identificación limpia.
7. Dejar inactivo 5 minutos (o reducir `IDLE_TIMEOUT_MS` temporalmente para probar) → entra en reposo → detección de presencia lo despierta.
8. Desconectar la red → marcar → confirma con aviso de "se sincronizará luego" → reconectar → se sincroniza solo.
9. Botón "Forzar sincronización" desde idle.
10. Toggle de tema claro/oscuro sigue funcionando.

Si algo se comporta distinto al `terminal.js` original (comparar con el comportamiento documentado en cada paso), es un defecto de esta tarea — corregir antes de dar la tarea por completa.

- [ ] **Step 6: Commit**

```bash
git add resources/js/attendances/terminal.js
git commit -m "refactor: rewrite terminal.js as a thin composition root over the extracted modules"
```

---

## Cierre del sub-proyecto

Tras la Tarea 7, correr el suite completo (`npx vitest run` + `php artisan test --compact`) una vez más para confirmar cero regresiones en el resto del proyecto, y proceder con `superpowers:finishing-a-development-branch`.
