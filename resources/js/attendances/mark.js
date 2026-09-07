import L from 'leaflet';
import { captureFaceSamples } from '../shared/face-capture-core.js';
import { migrateTokenFromLocalStorage, getMeta, getOwnEmployee, resetDb } from './mobile-offline/db.js';
import { identifyEmployee as matchDescriptor } from './mobile-offline/matcher.js';
import { heartbeat, getFaceConfig, unlinkDevice, MobileAuthError } from './mobile-offline/sync.js';
import {
    getOwnStatus,
    enqueueMark,
    flushQueue,
    countPendingEvents,
    countConflictEvents,
    dismissConflictEvents,
} from './mobile-offline/queue.js';

/**
 * =============================================================================
 * MARCACIÓN FACIAL - MODO MANUAL
 * =============================================================================
 *
 * @fileoverview Sistema de marcación de asistencia mediante reconocimiento facial.
 *               Este módulo permite a los empleados registrar su asistencia
 *               identificándose mediante su rostro y proporcionando su ubicación GPS.
 *
 * @description Flujo de uso:
 *              1. Usuario inicia la cámara
 *              2. Usuario presiona "Identificar" para capturar su rostro
 *              3. Sistema identifica al empleado y muestra eventos permitidos
 *              4. Usuario obtiene su ubicación GPS
 *              5. Usuario selecciona tipo de evento y registra la marcación
 *
 * @requires face-api.js - Biblioteca de reconocimiento facial
 * @requires Los modelos deben estar en /models (tinyFaceDetector, faceLandmark68, faceRecognition)
 *
 * @author Sistema RRHH
 * @version 2.0.0
 */

document.addEventListener("DOMContentLoaded", () => {
    // ==========================================================================
    // ELEMENTOS DEL DOM
    // ==========================================================================

    /** @type {HTMLElement} Overlay de pantalla de carga */
    const loadingOverlay = document.getElementById("loadingOverlay");

    /** @type {HTMLElement} Mensaje de la pantalla de carga */
    const loadingMessage = document.getElementById("loadingMessage");

    /** @type {HTMLElement} Barra de progreso */
    const loadingProgressBar = document.getElementById("loadingProgressBar");

    /** @type {HTMLElement} Texto de porcentaje de progreso */
    const loadingProgressText = document.getElementById("loadingProgressText");

    /** @type {HTMLVideoElement} Elemento de video para la cámara */
    const video = document.getElementById("video");

    /** @type {HTMLCanvasElement} Canvas para dibujar las detecciones faciales */
    const overlay = document.getElementById("overlay");

    /** @type {CanvasRenderingContext2D|null} Contexto 2D del canvas */
    const ctx = overlay?.getContext("2d");

    /** @type {HTMLButtonElement} Fallback para iOS: toca para activar cámara */
    const cameraFallback = document.getElementById("cameraFallback");

    /** @type {HTMLButtonElement} Botón para identificar al empleado */
    const btnIdentify = document.getElementById("btnIdentify");

    /** @type {HTMLButtonElement} Botón para registrar la marcación */
    const btnMark = document.getElementById("btnMark");

    /** @type {HTMLSelectElement} Selector de tipo de evento */
    const eventTypeEl = document.getElementById("eventType");

    // New UI elements added in the redesign
    const videoWrap       = document.getElementById("videoWrap");
    const statusDot       = document.getElementById("statusDot");
    const statusText      = document.getElementById("statusText");
    const locationStatus  = document.getElementById("locationStatus");
    const locationCoords  = document.getElementById("locationCoords");
    const locationMapEl   = document.getElementById("locationMap");
    const btnGeoRetry     = document.getElementById("btnGeoRetry");
    const headerClock     = document.getElementById("headerClock");
    const eventBtns       = document.querySelectorAll(".event-btn");

    // Step sections — wizard de un solo screen
    const step1Section    = document.getElementById("step1Section");
    const step2Section    = document.getElementById("step2Section");

    // Barra compacta de empleado en Paso 2
    const step2EmployeeBar  = document.getElementById("step2EmployeeBar");
    const step2Avatar       = document.getElementById("step2Avatar");
    const step2Name         = document.getElementById("step2Name");
    const step2Meta         = document.getElementById("step2Meta");
    const step2LastEventEl  = document.getElementById("step2LastEvent");
    const step2BtnBack      = document.getElementById("step2BtnBack");

const statusBar           = document.getElementById("statusBar");
    const captureProgress     = document.getElementById("captureProgress");
    const captureDots         = captureProgress ? Array.from(captureProgress.querySelectorAll(".capture-dot")) : [];
    const splashOverlay       = document.getElementById("splashOverlay");
    const gpsBanner           = document.getElementById("gpsBanner");
    const gpsBannerText       = document.getElementById("gpsBannerText");
    const gpsBannerRetry      = document.getElementById("gpsBannerRetry");
    const markHint            = document.getElementById("markHint");
    const syncStatusText      = document.getElementById("syncStatusText");
    const btnSyncNow          = document.getElementById("btnSyncNow");
    const btnUnlinkDevice     = document.getElementById("btnUnlinkDevice");
    const conflictBanner      = document.getElementById("conflictBanner");
    const btnDismissConflict  = document.getElementById("btnDismissConflict");
    const btnMyEvents         = document.getElementById("btnMyEvents");
    const btnCameraPause      = document.getElementById("btnCameraPause");
    const btnCameraPauseLabel = document.getElementById("btnCameraPauseLabel");
    const cameraPausedOverlay = document.getElementById("cameraPausedOverlay");

    // ==========================================================================
    // CONFIGURACIÓN Y CONSTANTES
    // ==========================================================================

    /**
     * URI donde se encuentran los modelos de face-api.js
     * @constant {string}
     */
    const MODELS_URI = "/models";

    /**
     * Tamaño mínimo de rostro en píxeles para aceptar una detección
     * @constant {number}
     */
    const MIN_FACE_SIZE = 100;

    /** HTML del spinner para estados de carga en botones */
    const SPINNER_SVG = '<svg class="btn-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 2a10 10 0 0 1 10 10"/></svg>';

    /**
     * Milisegundos entre cada dot del dwell de auto-identificación
     * 5 dots × 300ms = 1.5s de permanencia antes de disparar la identificación
     */
    const DWELL_STEP_MS = 300;

    /**
     * Textos descriptivos para cada tipo de evento de marcación
     * @constant {Object.<string, string>}
     */
    const EVENT_TEXTS = {
        check_in: "Ingreso",
        break_start: "Inicio descanso",
        break_end: "Fin descanso",
        check_out: "Salida",
    };

    // ==========================================================================
    // ESTADO GLOBAL
    // ==========================================================================

    /**
     * Stream de medios de la cámara
     * @type {MediaStream|null}
     */
    let stream = null;

    /**
     * Pausa manual de cámara (btnCameraPause) — el empleado la activa para usar
     * Sincronizar/Mis marcaciones/Desvincular sin riesgo de que el dwell lo
     * identifique de encima. Independiente de `stream`: se consulta al volver
     * a Paso 1 (transitionToStep1) para no reactivar la cámara sola.
     * @type {boolean}
     */
    let cameraPaused = false;

    /**
     * Auto-reanudado de seguridad — evita que una pausa olvidada bloquee la
     * marcación indefinidamente sin que el empleado lo note.
     * @type {number|null}
     */
    let cameraPauseResumeTimer = null;

    /** Milisegundos de pausa antes del auto-reanudado de seguridad */
    const CAMERA_PAUSE_AUTO_RESUME_MS = 120_000;

    /**
     * Indica si los modelos de face-api.js están cargados
     * @type {boolean}
     */
    let modelsLoaded = false;

    /**
     * Indica si el bucle de dibujo está activo
     * @type {boolean}
     */
    let drawLoopActive = false;

    /**
     * Estado actual de detección facial en el bucle
     * @type {null|'found'|'absent'}
     */
    let faceInFrameState = null;

    /**
     * Timestamp de la última detección para limitar frecuencia
     * @type {number}
     */
    let lastDetectionTime = 0;

    /**
     * Refresca el saludo/estado del splash y la sucursal — empresa del header
     * persistente con el propio empleado cacheado. Reasignada en el bloque del
     * splash con la función real — permite que initializeOfflineSync() la vuelva
     * a disparar tras el heartbeat inicial sin acoplar ambos bloques
     * directamente. No-op si la página no tiene splash.
     * @type {() => void}
     */
    let refreshOwnEmployeeUi = () => {};

    /** Instancia del mapa Leaflet del mini-mapa de ubicación */
    let locationMap    = null;
    /** Marcador del mini-mapa */
    let locationMarker = null;

    /** Controla si el splash ya fue procesado (evita doble disparo) */
    let splashHandled = false;

    /**
     * Estado de la aplicación con datos del empleado y ubicación
     * @type {{employee: Object|null, allowed: string[], location: {lat: number, lng: number}|null}}
     */
    let state = {
        employee: null,
        allowed: [],
        location: null,
    };

    /**
     * Indica si ya se agregó el listener al modal de éxito
     * @type {boolean}
     */
    let modalListenerAdded = false;

    /**
     * Indica si ya se agregó el listener al modal de error
     * @type {boolean}
     */
    let errorModalListenerAdded = false;

    /**
     * Elemento que tenía el foco antes de abrir un modal
     * @type {HTMLElement|null}
     */
    let previousActiveElement = null;

    /** @type {boolean} Indica si hay una identificación en curso (evita re-entradas) */
    let isIdentifying = false;

    /** @type {number} Timestamp hasta el cual el drawLoop no actualiza el estado visual ni se inicia un nuevo dwell */
    let notRecognizedUntil = 0;

    /** @type {number|null} ID del setInterval del dwell de auto-identificación */
    let autoIdDotInterval = null;

    /** @type {boolean} Indica si el usuario ya ha interactuado (requerido por Web Audio API) */
    let userHasInteracted = false;

    /** @type {boolean} Indica si el modal de error está visible (pausa el dwell de auto-identificación) */
    let errorModalVisible = false;

    /** @type {{employee: object, lastEvent: string|null}|null} Datos pendientes de paso 2 cuando GPS falla */
    let pendingStep2 = null;

    /** @type {number|null} Último código de error de geolocalización (1=denegado, 2=no disponible, 3=timeout) */
    let lastGpsErrorCode = null;

    /** @type {{employeeId: number, eventType: string, time: number}|null} Última marcación registrada (para ventana anti-duplicados) */
    let lastMark = null;

    /** @type {boolean} Indica si se está esperando confirmación de duplicado (segundo tap) */
    let requireDuplicateConfirm = false;

    /** @type {number|null} Timeout que restablece el estado de confirmación de duplicado */
    let duplicateConfirmTimeout = null;

    /** @type {AudioContext|null} Contexto de audio compartido */
    let audioCtx = null;

    /** @type {boolean} Indica si ya se agregó el listener de teclado del modal de error */
    let errorKeyListenerAdded = false;

    /**
     * Timeout actual de la alerta para poder cancelarlo
     * @type {number|null}
     */
    // ==========================================================================
    // CONFIGURACIÓN FACE-API
    // ==========================================================================

    // Verificar que face-api.js está cargado
    if (typeof faceapi === "undefined") {
        logError("face-api.js no está cargado. Asegúrate de incluir la librería antes de este script.");
        return;
    }

    /**
     * Opciones de configuración para TinyFaceDetector
     * @description inputSize: 416 para mejor precisión (aumentado de 320)
     *              scoreThreshold: 0.6 para rechazar detecciones de baja calidad (aumentado de 0.5)
     * @type {faceapi.TinyFaceDetectorOptions}
     */
    const tinyOptions = new faceapi.TinyFaceDetectorOptions({
        inputSize: 416,      // Aumentado de 320 para mejor precisión
        scoreThreshold: 0.6, // Aumentado de 0.5 para rechazar detecciones de baja calidad
    });

    // ==========================================================================
    // FUNCIONES UTILITARIAS
    // ==========================================================================

    /**
     * Pausa la ejecución por un tiempo determinado
     * @param {number} ms - Milisegundos a esperar
     * @returns {Promise<void>} Promesa que se resuelve después del tiempo especificado
     */
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    /**
     * Registra un mensaje informativo en la consola
     * @param {string} message
     */
    const logStatus = (message) => {
        console.log("[Status]", message);
    };

    /**
     * Registra una advertencia recuperable en la consola
     * @param {string} message
     * @param {*} [detail]
     */
    const logWarn = (message, detail) => {
        detail !== undefined ? console.warn("[Warn]", message, detail) : console.warn("[Warn]", message);
    };

    /**
     * Registra un error real en la consola (nivel error, con stack trace)
     * @param {string} message
     * @param {*} [errorObj]
     */
    const logError = (message, errorObj) => {
        errorObj !== undefined ? console.error("[Error]", message, errorObj) : console.error("[Error]", message);
    };

    // --------------------------------------------------------------------------
    // MENSAJES DE ERROR DETALLADOS
    // --------------------------------------------------------------------------
    /**
     * Transforma un mensaje de error crudo del servidor en un mensaje amigable
     * con sugerencias concretas para el usuario.
     * @param {string|null} rawMessage
     * @returns {string}
     */
    function buildDetailedError(rawMessage) {
        if (!rawMessage) return "No se pudo completar la marcación. Por favor, intente nuevamente.";
        const msg = rawMessage.toLowerCase();
        // Chequeo primero — sin esto, el mensaje específico lanzado cuando el propio
        // perfil todavía no sincronizó (ver identifyEmployee(), "ownEmployee" null)
        // no matcheaba ninguna de las ramas de abajo y caía al genérico "error
        // inesperado", escondiendo que el problema real es de sincronización, no de
        // reconocimiento facial.
        if (msg.includes("sincronizó tu perfil") || msg.includes("conectate a internet")) {
            return "Tu perfil todavía no se sincronizó con este dispositivo. Conectate a internet y volvé a intentar en unos segundos.";
        }
        if (msg.includes("ambiguo") || msg.includes("ambiguous") || msg.includes("múltiple") || msg.includes("multiple face")) {
            return "Se detectaron múltiples rostros o el rostro no es claro. Asegúrese de estar solo frente a la cámara y reposicione su cara.";
        }
        if (msg.includes("no identificado") || msg.includes("not found") || msg.includes("no match")) {
            return "No se pudo reconocer su rostro. Asegúrese de estar frente a la cámara con buena iluminación, sin lentes de sol ni gorras, y mantenga el rostro quieto.";
        }
        if (msg.includes("descriptor") || msg.includes("muestra") || msg.includes("sample")) {
            return "No se detectó un rostro válido. Acerque el rostro a la cámara (30–60 cm) y asegúrese de tener buena iluminación frontal.";
        }
        if (msg.includes("conexión") || msg.includes("network") || msg.includes("fetch")) {
            return !navigator.onLine
                ? "Sin conexión a internet. Verifique la red del dispositivo y vuelva a intentar."
                : "Error de conexión al servidor. Verifique que el dispositivo tenga acceso a la red y vuelva a intentar.";
        }
        if (msg.includes("event") || msg.includes("evento") || msg.includes("allowed")) {
            return "No hay tipos de marcación disponibles para este empleado en este momento. Consulte con el departamento de RRHH.";
        }
        return "Ocurrió un error inesperado. Por favor, intente nuevamente.";
    }

    // --------------------------------------------------------------------------
    // AUDIO FEEDBACK (Web Audio API)
    // --------------------------------------------------------------------------
    function getAudioCtx() {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        return audioCtx;
    }

    function playTone(freq, duration, gain = 0.25, delay = 0) {
        try {
            const ctx = getAudioCtx();
            ctx.resume();
            const osc = ctx.createOscillator();
            const env = ctx.createGain();
            osc.connect(env);
            env.connect(ctx.destination);
            osc.type = "sine";
            osc.frequency.value = freq;
            const t = ctx.currentTime + delay;
            env.gain.setValueAtTime(gain, t);
            env.gain.exponentialRampToValueAtTime(0.001, t + duration);
            osc.start(t);
            osc.stop(t + duration + 0.01);
        } catch (_) { /* audio no disponible */ }
    }

    function playBeep(type) {
        if (!userHasInteracted) return;
        if (type === "success") {
            playTone(880,  0.08, 0.22, 0.0);
            playTone(1100, 0.12, 0.22, 0.1);
        } else if (type === "error") {
            playTone(440, 0.08, 0.22, 0.0);
            playTone(330, 0.14, 0.22, 0.1);
        }
    }

    // --------------------------------------------------------------------------
    // BANNER OFFLINE
    // --------------------------------------------------------------------------
    function setOfflineBanner(isOffline) {
        const banner = document.getElementById("offlineBanner");
        if (!banner) return;
        banner.classList.toggle("is-visible", isOffline);
        banner.setAttribute("aria-hidden", String(!isOffline));
        // Re-evaluar botón de marcación al cambiar estado de conexión
        checkEnableMark();
    }

    // --------------------------------------------------------------------------
    // ESTADO DE SINCRONIZACIÓN OFFLINE — paridad con refreshIdleSyncStatus()
    // de terminal.js: mismo texto/prioridad (conflictos > pendientes >
    // sincronizado), pero acá además hay un aviso separado (conflictBanner)
    // porque un conflicto en el dispositivo es del propio empleado, no de un
    // tercero — amerita más que una línea de texto discreta.
    // --------------------------------------------------------------------------
    function updateSyncStatus(text) {
        if (syncStatusText) syncStatusText.textContent = text;
    }

    /**
     * Refleja en la fila de estado la cola de eventos offline real (no solo
     * el último resultado puntual) — se llama después de cada intento de
     * sincronización (marcación, sync de fondo, botón manual, carga inicial).
     */
    async function refreshSyncStatus() {
        const [pending, conflicts] = await Promise.all([countPendingEvents(), countConflictEvents()]);

        if (conflicts > 0) {
            updateSyncStatus(`${conflicts} marcación(es) requieren revisión`);
        } else if (pending > 0) {
            updateSyncStatus(`${pending} marcación(es) pendiente(s) de sincronizar`);
        } else {
            updateSyncStatus(navigator.onLine ? "Sincronizado" : "Sin conexión — usando datos locales");
        }

        if (conflictBanner) {
            conflictBanner.classList.toggle("is-visible", conflicts > 0);
            conflictBanner.setAttribute("aria-hidden", String(conflicts === 0));
        }
    }

    // Botón "Entendido" del aviso de conflicto — el empleado no puede resolverlo
    // (ya lo revisa RRHH en Filament), solo confirma que lo vio. Limpia la copia
    // local para que el aviso no quede pegado para siempre.
    if (btnDismissConflict) {
        btnDismissConflict.addEventListener("click", async () => {
            btnDismissConflict.disabled = true;
            try {
                await dismissConflictEvents();
                await refreshSyncStatus();
            } finally {
                btnDismissConflict.disabled = false;
            }
        });
    }

    // Botón "Sincronizar" manual — mismo patrón que btnForceSync en terminal.js.
    if (btnSyncNow) {
        btnSyncNow.addEventListener("click", async () => {
            btnSyncNow.disabled = true;
            updateSyncStatus("Sincronizando...");
            try {
                await heartbeat();
                await flushQueue();
                await refreshSyncStatus();
            } catch (error) {
                if (error instanceof MobileAuthError) {
                    window.location.href = "/vincular-dispositivo";
                    return;
                }
                logWarn("Sincronización manual falló:", error.message);
                await refreshSyncStatus();
            } finally {
                btnSyncNow.disabled = false;
            }
        });
    }

    // Botón "Desvincular dispositivo" — auto-servicio del empleado (ej. antes de
    // vender o prestar el dispositivo), a diferencia de "Revocar sesión móvil"
    // en EmployeeResource (accionada por un admin). Intenta sincronizar lo
    // pendiente primero para no perder marcaciones sin necesidad; si igual
    // queda algo sin confirmar, avisa antes de continuar (informa, no bloquea
    // — el empleado decide).
    if (btnUnlinkDevice) {
        btnUnlinkDevice.addEventListener("click", async () => {
            btnUnlinkDevice.disabled = true;
            try {
                if (navigator.onLine) {
                    try {
                        await flushQueue();
                    } catch (error) {
                        logWarn("No se pudo sincronizar antes de desvincular:", error.message);
                    }
                }

                const pending = await countPendingEvents();
                const warning = pending > 0
                    ? `Tenés ${pending} marcación(es) sin sincronizar — se van a perder. `
                    : "";
                const confirmed = window.confirm(
                    `${warning}¿Seguro que querés desvincular este dispositivo? Vas a necesitar tu CI y fecha de nacimiento para volver a vincularlo.`
                );
                if (!confirmed) return;

                await unlinkDevice();
                await resetDb();
                window.location.href = "/vincular-dispositivo";
            } catch (error) {
                if (error instanceof MobileAuthError) {
                    // El token ya no era válido — el dispositivo ya está desvinculado
                    // del lado del servidor, solo falta limpiar la copia local.
                    await resetDb();
                    window.location.href = "/vincular-dispositivo";
                    return;
                }
                window.alert(error.message || "No se pudo desvincular el dispositivo. Intentá de nuevo.");
            } finally {
                btnUnlinkDevice.disabled = false;
            }
        });
    }

    // Switch "Pausar cámara" — apaga el stream para que el empleado pueda usar
    // Sincronizar/Mis marcaciones/Desvincular sin riesgo de que el dwell lo
    // identifique de encima (ver pauseCamera()/resumeCamera() más abajo).
    if (btnCameraPause) {
        btnCameraPause.addEventListener("click", () => {
            if (cameraPaused) {
                resumeCamera();
            } else {
                pauseCamera();
            }
        });
    }
    if (cameraPausedOverlay) {
        cameraPausedOverlay.addEventListener("click", () => { resumeCamera(); });
    }

    // Botón "Mis marcaciones" — no requiere pasar por la cámara, el dispositivo
    // ya sabe de quién es (vinculación 1:1). Reusa getOwnStatus() (mismo
    // camino que la identificación) para no duplicar el manejo de auth/red.
    if (btnMyEvents) {
        btnMyEvents.addEventListener("click", () => { openMyEventsModal(); });
    }
    const closeMyEventsBtn = document.getElementById("closeMyEventsModal");
    if (closeMyEventsBtn) {
        closeMyEventsBtn.addEventListener("click", () => { closeMyEventsModal(); });
    }
    const myEventsModalEl = document.getElementById("myEventsModal");
    if (myEventsModalEl) {
        myEventsModalEl.addEventListener("click", (e) => {
            if (e.target === myEventsModalEl) closeMyEventsModal();
        });
    }
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && myEventsModalEl && !myEventsModalEl.classList.contains("hidden")) {
            closeMyEventsModal();
        }
    });

    // --------------------------------------------------------------------------
    // RELOJ EN TIEMPO REAL
    // --------------------------------------------------------------------------
    function updateClock() {
        if (headerClock) {
            const now = new Date();
            const day   = String(now.getDate()).padStart(2, "0");
            const month = String(now.getMonth() + 1).padStart(2, "0");
            const year  = now.getFullYear();
            const dateStr = `${day}/${month}/${year}`;
            const timeStr = now.toLocaleTimeString("es-BO", {
                hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false,
            });
            headerClock.innerHTML =
                `<span class="clock-date">${dateStr}</span><span class="clock-time">${timeStr}</span>`;
        }
    }
    updateClock();
    setInterval(updateClock, 1000);

    // --------------------------------------------------------------------------
    // ESTADO VISUAL DEL VIDEO
    // --------------------------------------------------------------------------
    function setVideoState(stateClass) {
        if (!videoWrap) return;
        videoWrap.classList.remove(
            "video-wrap--detecting", "video-wrap--face-found",
            "video-wrap--success", "video-wrap--error"
        );
        if (stateClass) videoWrap.classList.add(`video-wrap--${stateClass}`);
    }

    function setStatusBar(text, dotClass) {
        if (statusText) {
            statusText.classList.remove("status-text--new");
            void statusText.offsetWidth; // reiniciar animación
            statusText.textContent = text;
            statusText.classList.add("status-text--new");
        }
        if (statusBar) {
            statusBar.classList.remove("status-bar--detecting", "status-bar--found", "status-bar--error");
            if (dotClass) statusBar.classList.add(`status-bar--${dotClass}`);
        }
        if (statusDot) {
            statusDot.classList.remove(
                "status-dot--active", "status-dot--found",
                "status-dot--success", "status-dot--error"
            );
            const cssMap = { detecting: "active", found: "found", success: "success", error: "error" };
            const cssClass = cssMap[dotClass] ?? dotClass;
            if (cssClass) statusDot.classList.add(`status-dot--${cssClass}`);
        }
    }

    // --------------------------------------------------------------------------
    // PROGRESO DE CAPTURA FACIAL
    // --------------------------------------------------------------------------
    function showCaptureProgress() {
        if (!captureProgress) return;
        captureDots.forEach(dot => dot.classList.remove("capture-dot--filled"));
        captureProgress.classList.remove("hidden");
    }

    function updateCaptureProgress(count) {
        captureDots.forEach((dot, i) => {
            dot.classList.toggle("capture-dot--filled", i < count);
        });
    }

    function hideCaptureProgress() {
        if (!captureProgress) return;
        captureProgress.classList.add("hidden");
        captureDots.forEach(dot => dot.classList.remove("capture-dot--filled", "capture-dot--success", "capture-dot--error"));
    }

    /**
     * Finaliza la animación de captura mostrando todos los dots en el color del resultado
     * (éxito = verde, error = rojo) durante 400ms antes de ocultarlos.
     * @param {"success"|"error"} outcome
     */
    async function finishCaptureProgress(outcome) {
        if (!captureProgress) return;
        captureDots.forEach(dot => {
            dot.classList.remove("capture-dot--filled", "capture-dot--success", "capture-dot--error");
            dot.classList.add(`capture-dot--${outcome}`);
        });
        captureProgress.classList.remove("hidden");
        await sleep(400);
        hideCaptureProgress();
    }

    // --------------------------------------------------------------------------
    // GPS EN SEGUNDO PLANO
    // --------------------------------------------------------------------------
    function showGPSBanner(message) {
        if (!gpsBanner || !gpsBannerText) return;
        gpsBannerText.textContent = message;
        gpsBanner.classList.remove("hidden");
    }

    const pulseIcon = L.divIcon({
        className: '',
        html: '<div class="map-pulse-marker"><div class="map-pulse-ring"></div><div class="map-pulse-dot"></div></div>',
        iconSize: [28, 28],
        iconAnchor: [14, 14],
    });

    function showLocationMap(lat, lng) {
        if (!locationMapEl) return;
        locationMapEl.classList.remove("hidden");
        locationMapEl.removeAttribute("aria-hidden");
        if (btnGeoRetry) btnGeoRetry.classList.remove("hidden");
        // El mapa hace las coordenadas redundantes — ocultar el span
        if (locationCoords) locationCoords.classList.add("hidden");

        setTimeout(() => {
            if (!locationMap) {
                locationMap = L.map(locationMapEl, {
                    zoomControl: false,
                    attributionControl: false,
                    dragging: false,
                    scrollWheelZoom: false,
                    doubleClickZoom: false,
                    touchZoom: false,
                }).setView([lat, lng], 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                }).addTo(locationMap);
                locationMarker = L.marker([lat, lng], { icon: pulseIcon }).addTo(locationMap);
                setTimeout(() => locationMap?.invalidateSize(), 50);
            } else {
                locationMap.setView([lat, lng], 16);
                locationMarker.setLatLng([lat, lng]);
                locationMap.invalidateSize();
            }
        }, 120);
    }

    function hideGPSBanner() {
        if (gpsBanner) gpsBanner.classList.add("hidden");
    }

    /**
     * Muestra un contador regresivo en locationStatus durante la solicitud GPS.
     * @param {number} totalSec - Segundos totales del timeout GPS
     * @returns {function} Función para detener el contador
     */
    function startGPSProgress(totalSec = 10) {
        let remaining = totalSec;
        const dots = ["·", "··", "···"];
        let dotIdx = 0;

        const tick = () => {
            if (locationStatus) {
                locationStatus.textContent = `Obteniendo GPS ${dots[dotIdx % 3]} ${remaining}s`;
            }
            dotIdx++;
            remaining--;
        };
        tick(); // mostrar inmediatamente
        const id = setInterval(tick, 1000);
        return () => clearInterval(id);
    }

    function requestGPSBackground(onSuccess, onError) {
        if (!navigator.geolocation) {
            lastGpsErrorCode = null;
            if (locationStatus) locationStatus.textContent = "GPS no disponible en este dispositivo";
            onError?.();
            return;
        }

        const stopProgress = startGPSProgress(10);

        navigator.geolocation.getCurrentPosition(
            (pos) => {
                stopProgress();
                lastGpsErrorCode = null;
                hideGPSBanner();
                state.location = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                if (locationStatus) locationStatus.textContent = "Ubicación obtenida";
                if (locationCoords) {
                    locationCoords.textContent = `${pos.coords.latitude.toFixed(4)}, ${pos.coords.longitude.toFixed(4)}`;
                }
                showLocationMap(pos.coords.latitude, pos.coords.longitude);
                checkEnableMark();
                onSuccess?.();
            },
            (err) => {
                stopProgress();
                lastGpsErrorCode = err.code;
                const msgs = {
                    1: "Ubicación denegada. Active el permiso en su navegador y toque Reintentar.",
                    2: "No se pudo obtener la ubicación. Verifique que el GPS esté activo.",
                    3: "Tiempo de espera agotado al obtener la ubicación.",
                };
                const msg = msgs[err.code] || "No se pudo obtener la ubicación.";
                if (locationStatus) locationStatus.textContent = msg;
                showGPSBanner(msg);
                logWarn("GPS en segundo plano:", msg);
                onError?.();
            },
            { timeout: 10000, maximumAge: 60000, enableHighAccuracy: true }
        );
    }

    // ==========================================================================
    // FUNCIONES DE VERIFICACIÓN
    // ==========================================================================

    /**
     * Verifica que todos los elementos del DOM necesarios existan
     * @description Comprueba cada elemento requerido y muestra error si falta alguno
     * @returns {boolean} true si todos los elementos existen, false en caso contrario
     */
    function verifyDOMElements() {
        const requiredElements = {
            video,
            overlay,
            btnMark,
            eventTypeEl,
        };

        const missingElements = [];
        for (const [name, element] of Object.entries(requiredElements)) {
            if (!element) {
                missingElements.push(name);
            }
        }

        if (missingElements.length > 0) {
            logError("Elementos del DOM faltantes:", missingElements);
            const errorMsg = "La página no cargó correctamente. Por favor, recárguela.";
            showErrorModal("No se pudo cargar la página", errorMsg);
            return false;
        }
        return true;
    }

    // ==========================================================================
    // FUNCIONES DE PANTALLA DE CARGA
    // ==========================================================================

    /**
     * Actualiza el progreso de la pantalla de carga
     * @param {number} percentage - Porcentaje de progreso (0-100)
     * @param {string} message - Mensaje a mostrar
     * @returns {void}
     */
    function updateLoadingProgress(percentage, message) {
        if (loadingProgressBar) {
            loadingProgressBar.style.width = `${percentage}%`;
        }
        if (loadingProgressText) {
            loadingProgressText.textContent = `${percentage}%`;
        }
        if (loadingMessage && message) {
            loadingMessage.textContent = message;
        }
    }

    /**
     * Oculta la pantalla de carga
     * @returns {void}
     */
    function hideLoadingScreen() {
        if (loadingOverlay) {
            loadingOverlay.classList.add("hidden");
        }
    }

    function showSplash() {
        if (splashOverlay) splashOverlay.classList.remove("hidden");
    }

    function hideSplash(callback) {
        if (!splashOverlay) { callback?.(); return; }
        splashOverlay.classList.add("fading");
        setTimeout(() => {
            splashOverlay.classList.add("hidden");
            splashOverlay.classList.remove("fading");
            callback?.();
        }, 300);
    }

    function returnToSplash() {
        // Resetear estado
        state.employee = null;
        state.allowed  = [];
        state.location = null;
        isIdentifying  = false;
        cancelAutoIdentifyDwell?.();
        drawLoopActive = false;
        faceInFrameState = null;
        requireDuplicateConfirm = false;
        if (duplicateConfirmTimeout) { clearTimeout(duplicateConfirmTimeout); duplicateConfirmTimeout = null; }

        // Detener stream
        if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        if (video) video.srcObject = null;

        // Resetear UI de empleado y eventos
        if (step2EmployeeBar) step2EmployeeBar.classList.add("hidden");
        eventBtns.forEach(btn => {
            btn.disabled = true;
            btn.setAttribute("aria-disabled", "true");
            btn.setAttribute("aria-pressed", "false");
            btn.classList.remove("hidden");
        });
        if (eventTypeEl) {
            eventTypeEl.innerHTML = '<option value="">— primero identifícate —</option>';
            eventTypeEl.disabled = true;
        }
        if (btnMark) {
            btnMark.disabled = true;
            btnMark.setAttribute("aria-disabled", "true");
            btnMark.textContent = "Confirmar marcación";
        }
        setStatusBar("Inicie la cámara para comenzar", null);

        // Resetear ubicación y mapa
        if (locationStatus) locationStatus.textContent = "Solicitando ubicación...";
        if (locationCoords) { locationCoords.textContent = ""; locationCoords.classList.remove("hidden"); }
        if (locationMapEl) { locationMapEl.classList.add("hidden"); locationMapEl.setAttribute("aria-hidden", "true"); }
        if (btnGeoRetry) btnGeoRetry.classList.add("hidden");
        if (locationMap) { locationMap.remove(); locationMap = null; locationMarker = null; }

        // Volver al wizard paso 1 (sin animación — el splash lo cubre)
        if (step2Section) step2Section.classList.add("hidden");
        if (step1Section) step1Section.classList.remove("hidden");

        // Mostrar splash y permitir nuevo toque
        splashHandled = false;
        showSplash();
        window.scrollTo({ top: 0, behavior: "instant" });

        // Refrescar la tarjeta "Hoy: ..." con la marcación recién registrada —
        // sin esto queda con el texto de antes de marcar hasta recargar la página.
        refreshOwnEmployeeUi();
    }

    /**
     * Inicializa el sistema cargando los modelos
     * @async
     * @returns {Promise<void>}
     */
    async function initializeSystem() {
        try {
            // Paso 1: Verificar compatibilidad
            updateLoadingProgress(10, "Iniciando...");
            await sleep(200);

            // Verificar que face-api esté disponible
            if (typeof faceapi === "undefined") {
                throw new Error("La biblioteca face-api.js no está disponible");
            }

            // Verificar soporte de getUserMedia
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error("Tu navegador no soporta acceso a la cámara");
            }

            updateLoadingProgress(30, "Preparando reconocimiento facial...");
            await sleep(200);

            // Paso 2: Cargar modelos
            updateLoadingProgress(40, "Cargando modelos de identificación...");

            if (!modelsLoaded) {
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODELS_URI),
                    faceapi.nets.faceLandmark68Net.loadFromUri(MODELS_URI),
                    faceapi.nets.faceRecognitionNet.loadFromUri(MODELS_URI),
                ]);
                modelsLoaded = true;
            }

            updateLoadingProgress(80, "Casi listo...");
            await sleep(300);

            // Paso 3: Sistema listo
            updateLoadingProgress(100, "¡Todo listo!");
            await sleep(500);

            // Mostrar splash primero (queda detrás del loading por z-index),
            // luego ocultar loading para que el splash aparezca sin flash de la vista principal
            console.log("Sistema inicializado correctamente");
            showSplash();
            hideLoadingScreen();

        } catch (error) {
            logError("Error en la inicialización:", error);

            // Mostrar error en la pantalla de carga
            if (loadingMessage) {
                loadingMessage.textContent = `Error: ${error.message}`;
                loadingMessage.style.color = "#ef4444";
            }

            // Después de 3 segundos, ocultar y mostrar error
            await sleep(3000);
            hideLoadingScreen();
            showErrorModal(
                "El sistema no pudo iniciar",
                "El sistema no pudo iniciarse correctamente. Recargue la página para continuar."
            );
        }
    }

    // Verificar elementos al inicio
    if (!verifyDOMElements()) {
        return;
    }

    /**
     * Confirma que el dispositivo ya fue vinculado (CI + fecha de nacimiento en
     * /vincular-dispositivo, ver MobileLinkController) antes de arrancar la
     * cámara — sin token no hay nada que identificar localmente. Migra
     * primero el token que device-link.blade.php deja en localStorage tras
     * una vinculación exitosa (mismo patrón que ya usa terminal.js).
     * @returns {Promise<boolean>}
     */
    async function ensureLinkedDevice() {
        await migrateTokenFromLocalStorage();
        const token = await getMeta("api_token");
        if (!token) {
            window.location.href = "/vincular-dispositivo";
            return false;
        }
        return true;
    }

    /**
     * Sincroniza la configuración de reconocimiento facial y el descriptor
     * facial propio (heartbeat) antes de identificar — sin esto no hay nada
     * contra qué comparar localmente. Si el token fue revocado (empleado
     * desvinculado o baja), redirige a vincular de nuevo. Un fallo de red
     * simple no bloquea el arranque: se sigue con lo que ya estaba cacheado
     * de una sesión anterior (o, si nunca hubo una, la identificación
     * fallará con un mensaje claro hasta que haya conexión al menos una vez).
     */
    async function initializeOfflineSync() {
        updateSyncStatus("Sincronizando...");
        try {
            await heartbeat();
            await flushQueue();
        } catch (error) {
            if (error instanceof MobileAuthError) {
                window.location.href = "/vincular-dispositivo";
                return;
            }
            logWarn("No se pudo sincronizar con el servidor (heartbeat):", error.message);
        }
        await refreshSyncStatus();
        // Un dispositivo recién vinculado todavía no tenía own_employee cacheado la
        // primera vez que se llamó (ver bloque del splash) — ahora que el heartbeat
        // ya lo escribió en IndexedDB, reintentar para completar el saludo.
        refreshOwnEmployeeUi();
    }

    /**
     * Reintenta heartbeat y vaciar la cola de marcaciones pendientes en
     * segundo plano — mismo patrón que ya usa terminal.js, adaptado sin
     * sync de empleados (acá no existe, ver mobile-offline/sync.js).
     */
    function startBackgroundSync() {
        const runHeartbeat = () => {
            if (!navigator.onLine) return;
            heartbeat().catch((error) => logWarn("Heartbeat en segundo plano falló:", error.message));
        };
        const runQueueFlush = () => {
            if (!navigator.onLine) return;
            flushQueue()
                .then(() => refreshSyncStatus())
                .catch((error) => logWarn("Sincronización de cola en segundo plano falló:", error.message));
        };

        setInterval(runHeartbeat, 90 * 1000);
        setInterval(runQueueFlush, 30 * 1000);

        window.addEventListener("online", () => {
            runHeartbeat();
            runQueueFlush();
        });
    }

    // Iniciar el sistema con pantalla de carga — solo si el dispositivo ya está vinculado.
    (async () => {
        if (!(await ensureLinkedDevice())) return;
        await initializeOfflineSync();
        await initializeSystem();
        startBackgroundSync();
    })();

    // ==========================================================================
    // FUNCIONES DE CÁMARA Y MODELOS
    // ==========================================================================

    /**
     * Carga los modelos de reconocimiento facial de face-api.js
     * @async
     * @description Carga en paralelo los tres modelos necesarios si no están ya cargados.
     *              Los modelos normalmente se cargan en initializeSystem(), esta función
     *              es un fallback por si se necesita llamar manualmente.
     * @throws {Error} Si no se pueden cargar los modelos
     * @returns {Promise<void>}
     */
    async function loadModels() {
        // Si ya están cargados, no hacer nada
        if (modelsLoaded) {
            logStatus("Modelos ya están cargados");
            return;
        }

        try {
            logStatus("Cargando modelos de reconocimiento facial...");
            setStatusBar("Cargando modelos de reconocimiento facial...", "detecting");

            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(MODELS_URI),
                faceapi.nets.faceLandmark68Net.loadFromUri(MODELS_URI),
                faceapi.nets.faceRecognitionNet.loadFromUri(MODELS_URI),
            ]);

            modelsLoaded = true;
            logStatus("Modelos cargados correctamente");
            setStatusBar("Modelos listos — presione Iniciar cámara", null);
        } catch (error) {
            modelsLoaded = false;
            const errorMsg = "No se pudieron cargar los datos necesarios. Recargue la página. Si el problema persiste, verifique su conexión a internet.";
            logError("Error al cargar los modelos:", error);
            showErrorModal("Error de carga", errorMsg);
            throw error;
        }
    }

    /**
     * Inicia la cámara del dispositivo y configura el video
     * @async
     * @description Solicita acceso a la cámara frontal, configura el elemento video
     *              y ajusta el canvas al tamaño del video
     * @throws {Error} Si no se puede acceder a la cámara (permiso denegado, no encontrada, en uso)
     * @returns {Promise<void>}
     */
    async function startCamera() {
        try {
            // Verificar soporte de getUserMedia
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                logStatus("Tu navegador no soporta acceso a la cámara.");
                return;
            }

            // Solicitar acceso a la cámara
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "user" },
                audio: false,
            });

            // Configurar el video
            video.srcObject = stream;
            await new Promise((res) => (video.onloadedmetadata = res));

            // Ajustar el tamaño del lienzo
            overlay.width = video.videoWidth;
            overlay.height = video.videoHeight;

            // Verificar que los modelos estén cargados antes de iniciar el draw loop
            if (modelsLoaded) {
                faceInFrameState = null;
                notRecognizedUntil = 0;
                drawLoopActive = true;
                setTimeout(drawLoop, 0);
            } else {
                const errorMsg = "El sistema no está listo aún. Por favor, recargue la página.";
                logStatus(errorMsg);
                showErrorModal("Sistema no listo", errorMsg);
                return;
            }

            setVideoState("detecting");
            setStatusBar("Coloque su rostro dentro del óvalo · 30–50 cm", "detecting");
            logStatus("Cámara iniciada correctamente");
        } catch (e) {
            let errorTitle = "Error de cámara";
            let errorMsg = "";

            if (e.name === "NotAllowedError") {
                errorTitle = "Permiso denegado";
                errorMsg = "Habilite el acceso a la cámara en la configuración de su navegador y toque Reintentar.";
            } else if (e.name === "NotFoundError") {
                errorTitle = "Cámara no encontrada";
                errorMsg = "No se detectó ninguna cámara en este dispositivo. Conecte una cámara y toque Reintentar.";
            } else if (e.name === "NotReadableError") {
                errorTitle = "Cámara en uso";
                errorMsg = "La cámara está siendo utilizada por otra aplicación. Ciérrela e intente nuevamente.";
            } else {
                errorMsg = "No se pudo iniciar la cámara. Intente nuevamente.";
            }

            logError("Error al iniciar la cámara:", e);
            setVideoState("error");
            showErrorModal(errorTitle, errorMsg, async () => {
                setStatusBar("Iniciando cámara...", "detecting");
                await startCamera();
            });
        }
    }

    /** Refleja `cameraPaused` en el switch, el overlay del video y el status bar. */
    function setCameraPausedUi(paused) {
        if (btnCameraPause) {
            btnCameraPause.setAttribute("aria-pressed", String(paused));
        }
        if (btnCameraPauseLabel) {
            btnCameraPauseLabel.textContent = paused ? "Reanudar cámara" : "Pausar cámara";
        }
        if (cameraPausedOverlay) {
            cameraPausedOverlay.classList.toggle("hidden", !paused);
        }
    }

    /**
     * Pausa manual de la cámara — apaga el stream por completo (no solo el
     * dwell) para que el indicador de cámara del navegador se apague también.
     * Rearma un auto-reanudado de seguridad para que una pausa olvidada no
     * bloquee la marcación indefinidamente.
     * @returns {void}
     */
    function pauseCamera() {
        if (cameraPaused || !stream) return;

        cancelAutoIdentifyDwell();
        stream.getTracks().forEach((t) => t.stop());
        stream = null;
        drawLoopActive = false;
        faceInFrameState = null;
        if (video) video.srcObject = null;

        cameraPaused = true;
        setCameraPausedUi(true);
        setStatusBar("Cámara en pausa", null);
        logStatus("Cámara pausada manualmente");

        clearTimeout(cameraPauseResumeTimer);
        cameraPauseResumeTimer = setTimeout(() => { resumeCamera(); }, CAMERA_PAUSE_AUTO_RESUME_MS);
    }

    /**
     * Reanuda la cámara tras una pausa manual. Si el empleado ya avanzó a
     * Paso 2 mientras estaba pausada, solo limpia el estado — Paso 2 maneja
     * su propio apagado/reinicio de cámara al volver a Paso 1.
     * @returns {Promise<void>}
     */
    async function resumeCamera() {
        if (!cameraPaused) return;

        clearTimeout(cameraPauseResumeTimer);
        cameraPauseResumeTimer = null;
        cameraPaused = false;
        setCameraPausedUi(false);

        const onStep1 = step1Section && !step1Section.classList.contains("hidden");
        if (onStep1) {
            await startCamera();
        }
    }

    // ==========================================================================
    // FUNCIONES DE DETECCIÓN FACIAL
    // ==========================================================================

    /**
     * Bucle de dibujo que detecta y dibuja rostros en tiempo real
     * @async
     * @description Se ejecuta continuamente mientras drawLoopActive sea true.
     *              Limita la frecuencia de detección a 200ms para optimizar rendimiento.
     *              Dibuja el recuadro de detección y los puntos de referencia faciales.
     * @returns {Promise<void>}
     */
    async function drawLoop() {
        if (!drawLoopActive) return;

        try {
            if (video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0 && modelsLoaded) {
                const detection = await faceapi
                    .detectSingleFace(video, tinyOptions)
                    .withFaceLandmarks();

                ctx.clearRect(0, 0, overlay.width, overlay.height);

                // Durante captura activa, cooldown post-error o modal de error visible, el drawLoop no toca el estado visual
                if (!isIdentifying && Date.now() > notRecognizedUntil && !errorModalVisible) {
                    if (detection && video.videoWidth > 0 && video.videoHeight > 0) {
                        if (overlay.width !== video.videoWidth || overlay.height !== video.videoHeight) {
                            faceapi.matchDimensions(overlay, video);
                        }
                        faceapi.resizeResults(detection, {
                            width: video.videoWidth,
                            height: video.videoHeight,
                        });

                        // Feedback inmediato si la cara está muy chica — evita que el usuario
                        // espere todo el ciclo de captura (5 muestras) para enterarse recién al fallar
                        const box = detection.detection.box;
                        if (box.width < MIN_FACE_SIZE || box.height < MIN_FACE_SIZE) {
                            if (faceInFrameState !== "small") {
                                faceInFrameState = "small";
                                cancelAutoIdentifyDwell();
                                setVideoState("detecting");
                                setStatusBar("Acérquese un poco más a la cámara", "detecting");
                            }
                        } else if (faceInFrameState !== "found") {
                            faceInFrameState = "found";
                            setVideoState("face-found");
                            setStatusBar("Quédate quieto...", "found");
                            startAutoIdentifyDwell();
                        }
                    } else {
                        ctx.clearRect(0, 0, overlay.width, overlay.height);
                        if (faceInFrameState !== "absent") {
                            faceInFrameState = "absent";
                            cancelAutoIdentifyDwell();
                            setVideoState("detecting");
                            setStatusBar("Coloque su rostro dentro del óvalo · 30–50 cm", "detecting");
                        }
                    }
                }
            }
        } catch (error) {
            logError("Error en drawLoop:", error);
        }

        if (drawLoopActive) {
            setTimeout(drawLoop, 200);
        }
    }

    /**
     * Captura múltiples muestras del descriptor facial y las promedia
     * @async
     * @description Captura varias muestras del rostro detectado, valida que el rostro
     *              tenga un tamaño mínimo aceptable, y promedia los descriptores
     *              para obtener un resultado más estable y preciso.
     * @param {number} [samples=5] - Número de muestras a capturar
     * @param {number} [intervalMs=150] - Intervalo en milisegundos entre capturas
     * @throws {Error} Si los parámetros son inválidos
     * @throws {Error} Si los modelos no están cargados
     * @throws {Error} Si no se capturan suficientes muestras válidas
     * @returns {Promise<number[]>} Array de 128 números representando el descriptor facial promediado
     */
    async function captureDescriptor(samples = 5, intervalMs = 150, onProgress = null) {
        const { averaged } = await captureFaceSamples(video, tinyOptions, {
            samples,
            intervalMs,
            minFaceSize: MIN_FACE_SIZE,
            minRequired: 3,
            onProgress,
            onRejectedSample: (reason, detail) => {
                if (reason === 'too_small') {
                    logWarn(`Rostro muy pequeño: ${Math.round(detail.width)}x${Math.round(detail.height)}px (mínimo ${MIN_FACE_SIZE}px)`);
                } else if (reason === 'error') {
                    logWarn('Error capturando muestra:', detail);
                }
            },
        });
        return averaged;
    }

    // ==========================================================================
    // FUNCIONES DE INTERFAZ DE USUARIO
    // ==========================================================================

    /**
     * Habilita el paso 2 del flujo con los eventos de marcación permitidos
     * @description Limpia el selector de eventos y lo llena con los eventos permitidos
     *              según el estado actual del empleado (check_in, break_start, etc.)
     * @param {string[]} allowed - Array de tipos de eventos permitidos
     * @returns {void}
     */
    function enableStep2(allowed) {
        try {
            if (!Array.isArray(allowed)) {
                logError('El parámetro "allowed" debe ser un arreglo.');
                return;
            }

            eventTypeEl.innerHTML = "";

            // Update visual event buttons — show only allowed, hide the rest
            eventBtns.forEach((btn) => {
                const eventVal = btn.getAttribute("data-event");
                if (allowed.includes(eventVal)) {
                    btn.classList.remove("hidden");
                    btn.disabled = false;
                    btn.removeAttribute("aria-disabled");
                } else {
                    btn.classList.add("hidden");
                }
            });

            if (allowed.length === 0) {
                addOption(eventTypeEl, "", "No hay eventos permitidos para hoy");
                disableElements([eventTypeEl, btnMark]);
                return;
            }

            addOption(eventTypeEl, "", "— selecciona el tipo —");

            allowed.forEach((event) => {
                const text = EVENT_TEXTS[event] || event;
                addOption(eventTypeEl, event, text);
            });

            enableElements([eventTypeEl]);
        } catch (error) {
            logError("Error en enableStep2:", error);
        }
    }

    /**
     * Agrega una opción a un elemento select
     * @param {HTMLSelectElement} parent - Elemento select padre
     * @param {string} value - Valor de la opción
     * @param {string} text - Texto visible de la opción
     * @returns {void}
     */
    function addOption(parent, value, text) {
        const opt = document.createElement("option");
        opt.value = value;
        opt.textContent = text;
        parent.appendChild(opt);
    }

    /**
     * Deshabilita múltiples elementos del DOM
     * @param {HTMLElement[]} elements - Array de elementos a deshabilitar
     * @returns {void}
     */
    function disableElements(elements) {
        elements.forEach((el) => {
            if (el) {
                el.disabled = true;
                el.setAttribute("aria-disabled", "true");
            }
        });
    }

    /**
     * Habilita múltiples elementos del DOM
     * @param {HTMLElement[]} elements - Array de elementos a habilitar
     * @returns {void}
     */
    function enableElements(elements) {
        elements.forEach((el) => {
            if (el) {
                el.disabled = false;
                el.removeAttribute("aria-disabled");
            }
        });
    }

    /**
     * Verifica si se cumplen las condiciones para habilitar el botón de marcación
     * @description El botón se habilita solo si:
     *              - Hay un empleado identificado
     *              - Se ha seleccionado un tipo de evento
     *              - Se ha obtenido la ubicación GPS
     * @returns {void}
     */
    function checkEnableMark() {
        try {
            if (!state || !eventTypeEl || !btnMark) {
                logError("Elementos necesarios no están definidos.");
                return;
            }

            const isEmployeeSelected = !!(state.employee && state.employee.id);
            const isEventSelected = !!eventTypeEl.value;
            const isLocationSet = !!state.location;
            // Sin conexión ya no bloquea: la marcación se guarda en el dispositivo (cola
            // offline, ver mobile-offline/queue.js) y se sincroniza cuando haya señal.
            const canMark = isEmployeeSelected && isEventSelected && isLocationSet;

            btnMark.disabled = !canMark;
            btnMark.setAttribute("aria-disabled", canMark ? "false" : "true");

            if (markHint) {
                if (canMark) {
                    markHint.textContent = "";
                    markHint.classList.add("hidden");
                } else if (isEmployeeSelected && !isEventSelected) {
                    markHint.textContent = "Seleccioná el tipo de marcación para continuar";
                    markHint.classList.remove("hidden");
                } else if (isEmployeeSelected && isEventSelected && !isLocationSet) {
                    markHint.textContent = "Esperando ubicación GPS...";
                    markHint.classList.remove("hidden");
                } else {
                    markHint.textContent = "";
                    markHint.classList.add("hidden");
                }
            }
        } catch (error) {
            logError("Error en checkEnableMark:", error);
        }
    }

    /**
     * Traduce los tipos de eventos de inglés a español
     * @param {string|null} eventType - Tipo de evento en inglés
     * @returns {string} Tipo de evento en español
     */
    function translateEventType(eventType) {
        const translations = {
            check_in: "Entrada",
            break_start: "Inicio de descanso",
            break_end: "Fin de descanso",
            check_out: "Salida",
        };

        return translations[eventType] || eventType || "—";
    }

    /**
     * Actualiza la interfaz de usuario con los datos del empleado identificado
     * @param {Object} employee - Datos del empleado
     * @param {string} [employee.first_name] - Nombre del empleado
     * @param {string} [employee.last_name] - Apellido del empleado
     * @param {string} [employee.ci] - Documento de identidad
     * @param {string|null} lastEvent - Último evento registrado del empleado
     * @returns {void}
     */
    // ==========================================================================
    // CÁMARA POST-IDENTIFICACIÓN
    // ==========================================================================

// ==========================================================================
    // AUTO-IDENTIFICACIÓN CON DWELL
    // ==========================================================================

    /**
     * Inicia el dwell de auto-identificación llenando los dots uno a uno.
     * Al completar los 5 dots dispara runIdentification() automáticamente.
     */
    function startAutoIdentifyDwell() {
        if (isIdentifying || autoIdDotInterval || Date.now() <= notRecognizedUntil || errorModalVisible) return;

        let dotsFilled = 0;
        showCaptureProgress();

        autoIdDotInterval = setInterval(() => {
            dotsFilled++;
            updateCaptureProgress(dotsFilled);
            if (dotsFilled >= captureDots.length) {
                clearInterval(autoIdDotInterval);
                autoIdDotInterval = null;
                runIdentification(true);
            }
        }, DWELL_STEP_MS);
    }

    /**
     * Cancela el dwell en curso y oculta los dots (si no hay identificación activa).
     */
    function cancelAutoIdentifyDwell() {
        if (autoIdDotInterval) {
            clearInterval(autoIdDotInterval);
            autoIdDotInterval = null;
        }
        if (!isIdentifying) hideCaptureProgress();
    }

    /**
     * Ejecuta el flujo completo de identificación facial.
     * Puede ser llamado por el dwell automático o por el botón manual.
     */
    async function runIdentification(fromDwell = false) {
        if (isIdentifying) return;
        isIdentifying = true;

        cancelAutoIdentifyDwell();
        if (btnIdentify) btnIdentify.disabled = true;

        if (fromDwell) {
            // Dots already full from dwell — pause briefly so the user sees the completed state
            await sleep(250);
        } else {
            // Manual trigger — show empty dots that fill as samples are captured
            showCaptureProgress();
        }

        try {
            logStatus("Iniciando captura de rostro...");
            const descriptor = await captureDescriptor(5, 150, fromDwell ? null : (count) => {
                updateCaptureProgress(count);
            });

            logStatus("Identificando...");
            setStatusBar("Identificando empleado...", "found");

            // Matching 100% local contra el único descriptor cacheado (el propio, sincronizado
            // vía heartbeat) — sin esto no hay red de contingencia: el dispositivo solo conoce a su dueño.
            const { threshold, minGap } = await getFaceConfig();
            const ownEmployee = await getOwnEmployee();

            if (threshold == null || minGap == null || !ownEmployee) {
                throw new Error("Todavía no se sincronizó tu perfil — conectate a internet al menos una vez.");
            }

            const { employee: matched, reason } = matchDescriptor(descriptor, [ownEmployee], threshold, minGap);

            if (!matched) {
                const messages = {
                    no_match: "No se pudo identificar el rostro. Intente nuevamente.",
                    ambiguous: "Rostro ambiguo. Por favor, reposicione su cara e intente de nuevo.",
                };
                throw new Error(messages[reason] || "No identificado");
            }

            const employee = {
                id: matched.id,
                first_name: matched.first_name,
                last_name: matched.last_name,
                ci: matched.ci,
                photo_url: matched.photo_thumbnail || null,
            };

            // Estado del día: intenta consulta en línea (y la cachea); si no hay red, cae a lo
            // que el propio dispositivo ya sabe (ver mobile-offline/queue.js).
            const status = await getOwnStatus();

            state.employee = employee;
            state.allowed  = status.allowed_events || [];

            await finishCaptureProgress("success");

            if (state.allowed.length === 0) {
                // Jornada completa — detener cámara y mostrar aviso
                if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
                drawLoopActive = false;
                faceInFrameState = null;
                if (video) video.srcObject = null;

                const fullName = `${employee.first_name || ""} ${employee.last_name || ""}`.trim();
                const titleEl = document.getElementById("successModalTitle");
                const descEl  = document.getElementById("successModalDesc");
                if (titleEl) titleEl.textContent = "¡Jornada completada!";
                if (descEl)  descEl.textContent  = `${fullName ? fullName + ", ya" : "Ya"} no tienes más marcaciones disponibles por hoy. ¡Hasta mañana!`;
                showSuccessModal();
                navigator.vibrate?.(80);
                playBeep("success");
                logStatus("Jornada completa — sin eventos disponibles");
                return;
            }

            enableStep2(state.allowed);
            checkEnableMark();
            transitionToStep2(employee, status.last_event, status.last_event_time);
            navigator.vibrate?.(80);
            logStatus("Empleado identificado ✓");

            // Auto-seleccionar el evento si solo hay uno disponible
            if (state.allowed.length === 1) {
                const singleEvent = state.allowed[0];
                const autoBtn = [...eventBtns].find(b => b.getAttribute("data-event") === singleEvent);
                if (autoBtn) {
                    eventBtns.forEach(b => b.setAttribute("aria-pressed", "false"));
                    autoBtn.setAttribute("aria-pressed", "true");
                    if (eventTypeEl) {
                        eventTypeEl.value = singleEvent;
                        eventTypeEl.dispatchEvent(new Event("change"));
                    }
                }
            }

        } catch (e) {
            logError("Error en la identificación:", e);
            if (e instanceof MobileAuthError) {
                await finishCaptureProgress("error");
                playBeep("error");
                showErrorModal(
                    "Dispositivo desvinculado",
                    "Este dispositivo ya no está vinculado a tu cuenta. Volvé a identificarte con tu CI y fecha de nacimiento.",
                    () => { window.location.href = "/vincular-dispositivo"; }
                );
                return;
            }
            const detailed = buildDetailedError(e.message);
            // Cooldown breve: drawLoop no actualiza estado visual ni se inicia nuevo dwell.
            // Reducido de 3s a 1.5s — el modal de error ya frena al usuario, no hace falta duplicar la espera.
            notRecognizedUntil = Date.now() + 1500;
            setVideoState("error");
            setStatusBar("No se pudo identificar", "error");
            await finishCaptureProgress("error");
            playBeep("error");
            showErrorModal("No se pudo identificar", detailed);
        } finally {
            isIdentifying = false;
        }
    }

    // ==========================================================================
    // TRANSICIONES DE PASO (STEP WIZARD)
    // ==========================================================================

    /**
     * Transiciona de Paso 1 (cámara) a Paso 2 (selección de evento).
     * Detiene la cámara, puebla la barra de empleado y anima el cambio.
     */
    function transitionToStep2(employee, lastEvent, lastEventTime) {
        // Verificar que la ubicación GPS esté disponible antes de continuar
        if (!state.location) {
            pendingStep2 = { employee, lastEvent, lastEventTime };

            const gpsMsg = lastGpsErrorCode === 1
                ? "El permiso de ubicación está denegado. Vaya a la configuración de su navegador, habilite la ubicación para este sitio y toque Reintentar."
                : lastGpsErrorCode === 2
                    ? "No se pudo obtener la ubicación GPS. Verifique que el GPS esté activado en el dispositivo y toque Reintentar."
                    : "No se pudo obtener la ubicación GPS. Esto es necesario para registrar la marcación. Intente nuevamente.";

            showErrorModal("Ubicación no disponible", gpsMsg, () => {
                // Mantener errorModalVisible=true durante la solicitud GPS para
                // evitar que el drawLoop dispare un nuevo dwell y re-identifique al empleado
                errorModalVisible = true;
                setStatusBar("Obteniendo ubicación GPS...", "detecting");
                requestGPSBackground(
                    () => {
                        // GPS obtenido — continuar al paso 2
                        errorModalVisible = false;
                        const pending = pendingStep2;
                        pendingStep2 = null;
                        if (pending) transitionToStep2(pending.employee, pending.lastEvent, pending.lastEventTime);
                    },
                    () => {
                        // GPS falló de nuevo — liberar y mostrar modal otra vez
                        errorModalVisible = false;
                        const pending = pendingStep2;
                        pendingStep2 = null;
                        if (pending) transitionToStep2(pending.employee, pending.lastEvent, pending.lastEventTime);
                    }
                );
            });
            return;
        }

        pendingStep2 = null;

        // Detener cámara
        if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        drawLoopActive = false;
        faceInFrameState = null;
        if (video) video.srcObject = null;
        if (ctx) ctx.clearRect(0, 0, overlay.width, overlay.height);

        // Poblar barra de empleado en Paso 2
        const fullName = `${employee.first_name || ""} ${employee.last_name || ""}`.trim();
        const initials = [employee.first_name?.[0], employee.last_name?.[0]]
            .filter(Boolean).join("").toUpperCase() || "?";

        if (step2Avatar) {
            step2Avatar.textContent = "";
            if (employee.photo_url) {
                const img = document.createElement("img");
                img.src       = employee.photo_url;
                img.alt       = fullName;
                img.className = "step2-avatar-img";
                step2Avatar.appendChild(img);
            } else {
                step2Avatar.textContent = initials;
            }
        }
        if (step2Name)        step2Name.textContent       = fullName;
        if (step2Meta)        step2Meta.textContent       = employee.ci ? `CI: ${employee.ci}` : (employee.branch_name ?? "");
        if (step2LastEventEl) step2LastEventEl.textContent = lastEvent
            ? `Última: ${translateEventType(lastEvent)}${lastEventTime ? ` · ${lastEventTime}` : ""}`
            : "Sin marcaciones previas hoy";
        if (step2EmployeeBar) step2EmployeeBar.classList.remove("hidden");

        // Animar salida de Paso 1
        if (step1Section) {
            step1Section.classList.add("step-leaving");

            const onStep1Leave = (e) => {
                if (e.target !== step1Section) return; // ignorar bubbling de hijos
                step1Section.removeEventListener("animationend", onStep1Leave);
                step1Section.classList.add("hidden");
                step1Section.classList.remove("step-leaving");

                // Animar entrada de Paso 2
                if (step2Section) {
                    step2Section.classList.remove("hidden");
                    void step2Section.offsetWidth; // forzar reflow
                    step2Section.classList.add("step-entering");

                    const onStep2Enter = (e2) => {
                        if (e2.target !== step2Section) return;
                        step2Section.removeEventListener("animationend", onStep2Enter);
                        step2Section.classList.remove("step-entering");
                    };
                    step2Section.addEventListener("animationend", onStep2Enter);
                }
                window.scrollTo({ top: 0, behavior: "smooth" });
            };
            step1Section.addEventListener("animationend", onStep1Leave);
        }
    }

    /**
     * Transiciona de Paso 2 (selección) de vuelta a Paso 1 (cámara).
     * Limpia el estado del empleado y reinicia la cámara.
     */
    async function transitionToStep1() {
        // Limpiar estado del empleado y auto-identificación
        state.employee = null;
        state.allowed  = [];
        isIdentifying  = false;
        cancelAutoIdentifyDwell();

        // Limpiar barra de empleado
        if (step2EmployeeBar) step2EmployeeBar.classList.add("hidden");

        // Deshabilitar eventos y botón de marcación
        eventBtns.forEach(btn => {
            btn.disabled = true;
            btn.setAttribute("aria-disabled", "true");
            btn.setAttribute("aria-pressed", "false");
            btn.classList.remove("hidden");
        });
        if (eventTypeEl) {
            eventTypeEl.innerHTML = '<option value="">— primero identifícate —</option>';
            eventTypeEl.disabled = true;
        }
        if (btnMark) {
            btnMark.disabled = true;
            btnMark.setAttribute("aria-disabled", "true");
            btnMark.textContent = "Confirmar marcación";
        }

        // Animar salida de Paso 2
        if (step2Section) {
            step2Section.classList.add("step-leaving");

            const onStep2Leave = (e) => {
                if (e.target !== step2Section) return; // ignorar bubbling de hijos
                step2Section.removeEventListener("animationend", onStep2Leave);
                step2Section.classList.add("hidden");
                step2Section.classList.remove("step-leaving");

                // Animar entrada de Paso 1
                if (step1Section) {
                    step1Section.classList.remove("hidden");
                    void step1Section.offsetWidth;
                    step1Section.classList.add("step-entering");

                    const onStep1Enter = (e2) => {
                        if (e2.target !== step1Section) return;
                        step1Section.removeEventListener("animationend", onStep1Enter);
                        step1Section.classList.remove("step-entering");
                    };
                    step1Section.addEventListener("animationend", onStep1Enter);
                }
                window.scrollTo({ top: 0, behavior: "smooth" });
            };
            step2Section.addEventListener("animationend", onStep2Leave);
        }

        // Resetear ubicación y mapa
        state.location = null;
        if (locationStatus) locationStatus.textContent = "Solicitando ubicación...";
        if (locationCoords) { locationCoords.textContent = ""; locationCoords.classList.remove("hidden"); }
        if (locationMapEl) {
            locationMapEl.classList.add("hidden");
            locationMapEl.setAttribute("aria-hidden", "true");
        }
        if (btnGeoRetry) btnGeoRetry.classList.add("hidden");
        if (locationMap) {
            locationMap.remove();
            locationMap    = null;
            locationMarker = null;
        }

        // Reiniciar cámara en paralelo con la animación — salvo que el empleado la
        // haya pausado manualmente (btnCameraPause): en ese caso queda pausada y se
        // vuelve a mostrar el overlay correspondiente en vez de reactivar el stream.
        faceInFrameState = null;
        if (cameraPaused) {
            setCameraPausedUi(true);
            return;
        }
        try {
            await startCamera();
        } catch (error) {
            logError("Error al reiniciar cámara:", error);
            showErrorModal("Error de cámara", "No se pudo reiniciar la cámara. Intente nuevamente.", async () => {
                await startCamera();
            });
        }
    }

    // ==========================================================================
    // FUNCIONES DE MODALES
    // ==========================================================================

    /**
     * Muestra el modal de éxito después de una marcación exitosa
     * @description Muestra un modal con animación, maneja accesibilidad (foco, aria),
     *              y configura eventos para cerrarlo (botón, backdrop, tecla ESC)
     * @returns {void}
     */
    function showSuccessModal(name, eventType, time, { queued = false } = {}) {
        const modal = document.getElementById("successModal");
        const closeModal = document.getElementById("closeModal");

        if (!modal || !closeModal) {
            logError("Elementos del modal no encontrados");
            return;
        }

        // Poblar bloque de detalle si se proveyeron datos
        const metaEl    = document.getElementById("successModalMeta");
        const nameEl    = document.getElementById("successModalMetaName");
        const eventEl   = document.getElementById("successModalMetaEvent");
        const timeEl    = document.getElementById("successModalMetaTime");
        const queuedEl  = document.getElementById("successModalQueuedNotice");

        if (metaEl && name && eventType && time) {
            if (nameEl)  nameEl.textContent  = name;
            if (eventEl) eventEl.textContent = translateEventType(eventType);
            if (timeEl)  timeEl.textContent  = time.toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit" });
            metaEl.classList.remove("hidden");
        } else if (metaEl) {
            metaEl.classList.add("hidden");
        }

        // Marcación guardada en el dispositivo pero todavía no confirmada por el servidor
        // (sin red en el momento) — avisar sin asustar: no se perdió, se sincroniza sola.
        if (queuedEl) queuedEl.style.display = queued ? "" : "none";

        previousActiveElement = document.activeElement;

        modal.setAttribute("aria-hidden", "false");
        modal.classList.remove("hidden");
        void modal.offsetWidth; // Forzar reflow para animación

        requestAnimationFrame(() => {
            modal.classList.add("show");
        });

        setTimeout(() => {
            closeModal.focus();
        }, 100);

        document.body.classList.add("modal-open");

        if (!modalListenerAdded) {
            closeModal.addEventListener("click", closeModalHandler);

            modal.addEventListener("click", (e) => {
                if (e.target === modal) {
                    closeModalHandler();
                }
            });

            document.addEventListener("keydown", (e) => {
                if (e.key === "Escape" && modal.classList.contains("show")) {
                    closeModalHandler();
                }
            });

            modalListenerAdded = true;
        }
    }

    /**
     * Cierra el modal de éxito con animación
     * @description Oculta el modal, restaura el foco al elemento anterior,
     *              y resetea el sistema para una nueva marcación
     * @returns {void}
     */
    function closeModalHandler() {
        const modal = document.getElementById("successModal");

        if (!modal) return;

        if (modal.contains(document.activeElement)) {
            document.activeElement.blur();
        }

        modal.setAttribute("aria-hidden", "true");
        modal.classList.remove("show");

        setTimeout(() => {
            modal.classList.add("hidden");
            document.body.classList.remove("modal-open");
            returnToSplash();
        }, 250);
    }

    /**
     * Muestra el modal de error con un mensaje personalizado
     * @param {string} title - Título del error
     * @param {string} message - Descripción detallada del error
     * @returns {void}
     */
    function showErrorModal(title, message, onRetry) {
        const modal   = document.getElementById("errorModal");
        const titleEl = document.getElementById("errorModalTitle");
        const descEl  = document.getElementById("errorModalDesc");
        const retryBtn = document.getElementById("retryErrorModal");

        if (!modal || !titleEl || !descEl) {
            logError("Elementos del modal de error no encontrados");
            return;
        }

        previousActiveElement = document.activeElement;

        titleEl.textContent = title || "Error";
        descEl.textContent  = message || "Ha ocurrido un error inesperado.";

        // onRetry === false → ocultar botón Reintentar (ej: sesión expirada, reintentar no ayudaría)
        if (retryBtn) {
            const hideRetry = onRetry === false;
            retryBtn.classList.toggle("hidden", hideRetry);
            retryBtn._onRetry = hideRetry ? null : (onRetry || null);
        }

        errorModalVisible = true;
        cancelAutoIdentifyDwell();

        modal.setAttribute("aria-hidden", "false");
        modal.classList.remove("hidden");
        void modal.offsetWidth;

        requestAnimationFrame(() => { modal.classList.add("show"); });

        if (retryBtn) retryBtn._shownAt = Date.now();
        const focusTarget = (onRetry !== false ? retryBtn : null) ?? document.getElementById("closeErrorModal");
        setTimeout(() => { focusTarget?.focus(); }, 100);

        document.body.classList.add("modal-open");
    }

    /**
     * Cierra el modal de error con animación
     * @returns {void}
     */
    function closeErrorModalHandler() {
        errorModalVisible = false;
        setTimeout(() => window.location.reload(), 250);
    }

    /**
     * Abre el modal "Mis marcaciones de hoy" y consulta la lista completa de
     * eventos del día vía getOwnStatus() (mismo camino que la identificación,
     * con su misma resiliencia offline) — a diferencia del resto de esa
     * respuesta, `today_events` no tiene resolución local equivalente: sin
     * red, se avisa en vez de mostrar una lista parcial/desactualizada.
     * @returns {Promise<void>}
     */
    async function openMyEventsModal() {
        const modal      = document.getElementById("myEventsModal");
        const listEl      = document.getElementById("myEventsList");
        const emptyEl     = document.getElementById("myEventsEmpty");
        const offlineEl   = document.getElementById("myEventsOffline");
        const loadingEl   = document.getElementById("myEventsLoading");
        const closeBtn    = document.getElementById("closeMyEventsModal");
        if (!modal || !listEl) return;

        listEl.innerHTML = "";
        emptyEl?.classList.add("hidden");
        offlineEl?.classList.add("hidden");
        loadingEl?.classList.remove("hidden");

        modal.setAttribute("aria-hidden", "false");
        modal.classList.remove("hidden");
        void modal.offsetWidth;
        requestAnimationFrame(() => { modal.classList.add("show"); });
        document.body.classList.add("modal-open");
        setTimeout(() => { closeBtn?.focus(); }, 100);

        try {
            const status = await getOwnStatus();
            loadingEl?.classList.add("hidden");

            const events = status.today_events;
            if (!Array.isArray(events)) {
                // getOwnStatus() cayó al fallback offline — ese fallback no reconstruye
                // la lista completa, solo el último evento (ver resolveOwnStatus()).
                offlineEl?.classList.remove("hidden");
                return;
            }
            if (events.length === 0) {
                emptyEl?.classList.remove("hidden");
                return;
            }
            for (const event of events) {
                const li = document.createElement("li");
                li.className = "my-events-item";

                const typeSpan = document.createElement("span");
                typeSpan.className = "my-events-item-type";
                typeSpan.textContent = event.event_type_label || translateEventType(event.event_type);

                const timeSpan = document.createElement("span");
                timeSpan.className = "my-events-item-time";
                timeSpan.textContent = event.time || "";

                li.append(typeSpan, timeSpan);
                listEl.appendChild(li);
            }
        } catch (error) {
            loadingEl?.classList.add("hidden");
            if (error instanceof MobileAuthError) {
                closeMyEventsModal();
                window.location.href = "/vincular-dispositivo";
                return;
            }
            logWarn("No se pudo consultar mis marcaciones:", error.message);
            offlineEl?.classList.remove("hidden");
        }
    }

    /** Cierra el modal "Mis marcaciones de hoy" con animación. */
    function closeMyEventsModal() {
        const modal = document.getElementById("myEventsModal");
        if (!modal) return;

        modal.setAttribute("aria-hidden", "true");
        modal.classList.remove("show");
        setTimeout(() => {
            modal.classList.add("hidden");
            document.body.classList.remove("modal-open");
        }, 250);
    }

    // ==========================================================================
    // FUNCIONES DE RESET
    // ==========================================================================

    /**
     * Resetea el sistema a su estado inicial
     * @description Limpia el estado, detiene la cámara, limpia el canvas,
     *              resetea los campos del formulario y los botones
     * @returns {void}
     */
    function resetSystem() {
        try {
            // Limpiar estado global
            state.employee = null;
            state.allowed = [];
            state.location = null;

            // Detener stream de video
            if (stream) {
                stream.getTracks().forEach((track) => track.stop());
                stream = null;
            }

            drawLoopActive = false;
            faceInFrameState = null;
            notRecognizedUntil = 0;

            if (video) {
                video.srcObject = null;
            }

            if (ctx) {
                ctx.clearRect(0, 0, overlay.width, overlay.height);
            }

            // Limpiar campos
            if (eventTypeEl) {
                eventTypeEl.innerHTML = '<option value="">— primero identificate —</option>';
            }

            // Resetear botones
            if (btnIdentify) {
                btnIdentify.disabled = true;
                btnIdentify.setAttribute("aria-disabled", "true");
            }
            if (btnMark) {
                btnMark.disabled = true;
                btnMark.setAttribute("aria-disabled", "true");
            }

            setVideoState(null);
            setStatusBar("Inicie la cámara para comenzar", null);
            eventBtns.forEach((btn) => {
                btn.disabled = true;
                btn.setAttribute("aria-disabled", "true");
                btn.setAttribute("aria-pressed", "false");
                btn.classList.remove("hidden");
            });

            // Resetear fila de ubicación
            if (locationStatus) locationStatus.textContent = "Solicitando ubicación...";
            if (locationCoords) { locationCoords.textContent = ""; locationCoords.classList.remove("hidden"); }
            if (locationMapEl) {
                locationMapEl.classList.add("hidden");
                locationMapEl.setAttribute("aria-hidden", "true");
            }
            if (btnGeoRetry) btnGeoRetry.classList.add("hidden");
            if (locationMap) {
                locationMap.remove();
                locationMap    = null;
                locationMarker = null;
            }

            logStatus("Sistema reiniciado");
        } catch (error) {
            logError("Error al resetear el sistema:", error);
        }
    }

    // ==========================================================================
    // EVENT LISTENERS
    // ==========================================================================

    // Evento: Splash — primer toque del usuario (desbloquea audio/vibración e inicia cámara)
    if (splashOverlay) {
        // Poblar saludo, hora y fecha dinámicamente
        const splashGreetingEl = document.getElementById("splashGreeting");
        const splashTimeEl     = document.getElementById("splashTime");
        const splashDateEl     = document.getElementById("splashDate");
        const splashStatusEl   = document.getElementById("splashStatus");
        const headerLocationEl = document.getElementById("headerLocation");
        const headerLogoEl     = document.getElementById("headerLogo");

        // Nombre propio cacheado (mismo dato que ya usa Mis marcaciones) — normalmente
        // ya está en IndexedDB de una sesión anterior, así que suele completarse antes
        // de que el empleado termine de leer la pantalla. Se recalcula el saludo con
        // él ya incorporado en cuanto resuelve, sin bloquear el reloj mientras tanto.
        let ownFirstName = null;

        const updateSplashClock = () => {
            const now  = new Date();
            const hour = now.getHours();

            if (splashGreetingEl) {
                const greeting = hour < 12 ? "Buenos días" : hour < 19 ? "Buenas tardes" : "Buenas noches";
                splashGreetingEl.textContent = ownFirstName ? `${greeting}, ${ownFirstName}` : greeting;
            }
            if (splashTimeEl) {
                splashTimeEl.textContent = now.toLocaleTimeString("es-AR", { hour: "2-digit", minute: "2-digit", hour12: false });
            }
            if (splashDateEl) {
                const d = String(now.getDate()).padStart(2, "0");
                const m = String(now.getMonth() + 1).padStart(2, "0");
                const y = now.getFullYear();
                splashDateEl.textContent = `${d}/${m}/${y}`;
            }
        };

        updateSplashClock();
        const splashClockInterval = setInterval(updateSplashClock, 10000);

        // Se llama dos veces: acá mismo (caché de una sesión anterior, si existe) y de
        // nuevo desde initializeOfflineSync() tras el heartbeat inicial — un dispositivo
        // recién vinculado todavía no tiene own_employee cacheado en este primer intento,
        // solo lo consigue una vez que ese heartbeat termina de escribirlo.
        refreshOwnEmployeeUi = () => {
            getOwnEmployee()
                .then((employee) => {
                    if (employee?.first_name) {
                        ownFirstName = employee.first_name;
                        updateSplashClock();
                    }
                    if (headerLocationEl && (employee?.branch_name || employee?.company_name)) {
                        headerLocationEl.textContent = [employee.branch_name, employee.company_name]
                            .filter(Boolean)
                            .join(" — ");
                    }
                    // Logo de la empresa — ya viaja embebido como data URI en el propio
                    // heartbeat (mismo caché offline que branch_name/company_name).
                    if (headerLogoEl && employee?.company_logo) {
                        headerLogoEl.src = employee.company_logo;
                        headerLogoEl.classList.remove("hidden");
                    }
                    // Título de pestaña — la empresa no se conoce server-side en esta
                    // página (la identidad del empleado se resuelve recién acá, vía
                    // IndexedDB/Sanctum), así que se corrige client-side apenas resuelve.
                    if (employee?.company_name) {
                        document.title = `${employee.company_name} — Marcación Facial`;
                    }

                    // Sin descriptor propio cacheado, cualquier intento de marcar va a
                    // fallar sin importar cuántas veces lo intente el empleado — mostrarlo
                    // acá (en vez de esperar a que falle un intento real) deja claro que el
                    // problema es de sincronización, no de la cámara/rostro. Se resuelve
                    // ANTES de tocar getOwnStatus() (no en paralelo) para que ese resultado
                    // no pise este aviso con "Todavía no marcaste hoy" — getOwnStatus() no
                    // depende de own_employee, así que sin este orden ganaría la carrera.
                    if (!employee) {
                        if (splashStatusEl) {
                            splashStatusEl.textContent = "⚠ Tu perfil todavía no se sincronizó. Conectate a internet.";
                            splashStatusEl.classList.remove("hidden");
                        }
                        return;
                    }

                    getOwnStatus()
                        .then((status) => {
                            if (!splashStatusEl) return;
                            splashStatusEl.textContent = status.last_event
                                ? `Hoy: ${translateEventType(status.last_event)}${status.last_event_time ? ` · ${status.last_event_time}` : ""}`
                                : "Todavía no marcaste hoy";
                            splashStatusEl.classList.remove("hidden");
                        })
                        .catch(() => {}); // sin red y sin nada cacheado todavía — se deja oculto, no vale la pena mostrar un error acá
                })
                .catch(() => {}); // sin caché todavía — se queda con el saludo genérico
        };
        refreshOwnEmployeeUi();

        const handleSplashTap = () => {
            if (splashHandled) return;
            splashHandled = true;
            clearInterval(splashClockInterval);
            userHasInteracted = true;
            hideSplash(async () => {
                try {
                    await startCamera();
                } catch (cameraError) {
                    if (cameraError.name === "NotAllowedError" && cameraFallback) {
                        cameraFallback.classList.remove("hidden");
                        setStatusBar("Toca la imagen para activar la cámara", null);
                    } else {
                        showErrorModal("Error de cámara", "No se pudo iniciar la cámara. Recargue la página e intente nuevamente.");
                    }
                }
            });
        };

        const splashBtn = document.getElementById("splashBtn");
        if (splashBtn) {
            splashBtn.addEventListener("click",      handleSplashTap);
            splashBtn.addEventListener("touchstart", (e) => { e.preventDefault(); handleSplashTap(); });
        }
        splashOverlay.addEventListener("keydown", (e) => { if (e.key === "Enter" || e.key === " ") handleSplashTap(); });
    }

    // Fallback de cámara para iOS/Safari
    if (cameraFallback) {
        cameraFallback.addEventListener("click", async () => {
            userHasInteracted = true;
            cameraFallback.classList.add("hidden");
            try {
                await startCamera();
            } catch (error) {
                logError("Error al iniciar cámara desde fallback:", error);
                showErrorModal("Error de cámara", "No se pudo iniciar la cámara. Intente nuevamente.", async () => {
                    await startCamera();
                });
            }
        });
    }

// Botón "Cambiar" en Paso 2 — volver a identificación
    if (step2BtnBack) {
        step2BtnBack.addEventListener("click", () => {
            transitionToStep1();
        });
    }

    // Evento: Identificar empleado (manual — el dwell también llama runIdentification)
    if (btnIdentify) {
        btnIdentify.addEventListener("click", () => {
            runIdentification();
        });
    }

    // Solicitar GPS manualmente (usado como retry desde el modal de error)
    function requestGPSManual() {
        userHasInteracted = true;
        if (!navigator.geolocation) {
            const errorMsg = "Este dispositivo no puede obtener la ubicación GPS. Si el problema persiste, contacte a RRHH.";
            logStatus(errorMsg);
            showErrorModal("Ubicación no disponible", errorMsg);
            return;
        }

        logStatus("Solicitando ubicación GPS...");
        const stopProgress = startGPSProgress(10);

        navigator.geolocation.getCurrentPosition(
            (pos) => {
                stopProgress();
                state.location = { lat: pos.coords.latitude, lng: pos.coords.longitude };
                if (locationStatus) locationStatus.textContent = "Ubicación obtenida";
                if (locationCoords) {
                    locationCoords.textContent = `${pos.coords.latitude.toFixed(4)}, ${pos.coords.longitude.toFixed(4)}`;
                }
                showLocationMap(pos.coords.latitude, pos.coords.longitude);
                checkEnableMark();
                logStatus("Ubicación obtenida correctamente");
            },
            (err) => {
                stopProgress();
                logError("Error de geolocalización:", err);
                let errorMsg = "";

                switch (err.code) {
                    case 1:
                        errorMsg = "Permiso de ubicación denegado. Habilite el GPS en su navegador e intente nuevamente.";
                        break;
                    case 2:
                        errorMsg = "No se pudo obtener la ubicación. Verifique que el GPS esté activado e intente nuevamente.";
                        break;
                    case 3:
                        errorMsg = "La obtención de ubicación tardó demasiado. Intente nuevamente.";
                        break;
                    default:
                        errorMsg = "No se pudo obtener la ubicación. Por favor, intente nuevamente.";
                        break;
                }

                showErrorModal("Ubicación no disponible", errorMsg, requestGPSManual);
            },
            {
                timeout: 10000,
                maximumAge: 60000,
                enableHighAccuracy: true,
            }
        );
    }

    // Evento: Registrar marcación
    if (btnMark) {
        btnMark.addEventListener("click", async () => {
            btnMark.disabled = true;

            try {
                // Validar datos antes de enviar
                if (!state.employee || !state.employee.id) {
                    throw new Error("Empleado no identificado.");
                }
                if (!eventTypeEl || !eventTypeEl.value) {
                    throw new Error("Tipo de evento no seleccionado.");
                }
                if (!state.location) {
                    throw new Error("Ubicación no válida.");
                }

                // Ventana anti-duplicados: bloquear el mismo empleado + evento dentro de 60s
                const DUPLICATE_WINDOW_MS = 60000;
                const isDuplicate = lastMark
                    && lastMark.employeeId === state.employee.id
                    && lastMark.eventType  === eventTypeEl.value
                    && Date.now() - lastMark.time < DUPLICATE_WINDOW_MS;

                if (isDuplicate && !requireDuplicateConfirm) {
                    requireDuplicateConfirm = true;
                    btnMark.disabled = false;
                    const secsAgo = Math.round((Date.now() - lastMark.time) / 1000);
                    if (markHint) {
                        markHint.textContent = `Ya registraste "${translateEventType(eventTypeEl.value)}" hace ${secsAgo}s. Toca nuevamente para confirmar.`;
                        markHint.classList.remove("hidden");
                        markHint.classList.add("mark-hint--warn");
                    }
                    duplicateConfirmTimeout = setTimeout(() => {
                        requireDuplicateConfirm = false;
                        if (markHint) {
                            markHint.classList.add("hidden");
                            markHint.classList.remove("mark-hint--warn");
                        }
                    }, 6000);
                    return;
                }

                // Segundo tap o fuera de ventana — limpiar estado y proceder
                if (requireDuplicateConfirm) {
                    clearTimeout(duplicateConfirmTimeout);
                    requireDuplicateConfirm = false;
                    if (markHint) {
                        markHint.classList.add("hidden");
                        markHint.classList.remove("mark-hint--warn");
                    }
                }

                logStatus("Registrando marcación...");
                btnMark.classList.add("btn-loading");
                btnMark.innerHTML = `${SPINNER_SVG} Registrando...`;

                // Encolar SIEMPRE primero — la marcación queda a salvo en el dispositivo
                // aunque el envío falle o no haya red (ver mobile-offline/queue.js).
                const { client_event_id: clientEventId, recorded_at: recordedAt } = await enqueueMark(
                    eventTypeEl.value,
                    { lat: state.location.lat, lng: state.location.lng },
                );

                let queued = true;
                let rejectedMessage = null;

                if (navigator.onLine) {
                    try {
                        const { results } = await flushQueue();
                        const ownResult = results.find((r) => r.client_event_id === clientEventId);

                        if (ownResult && ownResult.status !== "synced" && ownResult.status !== "duplicate") {
                            // El servidor ya respondió que esta marcación puntual es inválida (ej.
                            // secuencia rota) — no tiene sentido dejarla "pendiente", se informa directo.
                            rejectedMessage = ownResult.message || "La marcación fue rechazada por el servidor.";
                        } else {
                            // Sincronizada (ownResult existe y es synced/duplicate) o todavía en cola
                            // porque la red falló a mitad de camino (ownResult ausente) — a salvo en ambos casos.
                            queued = !ownResult;
                        }
                    } catch (flushError) {
                        if (flushError instanceof MobileAuthError) throw flushError;
                        logWarn("No se pudo sincronizar de inmediato, queda en cola:", flushError.message);
                    }
                }

                if (rejectedMessage) throw new Error(rejectedMessage);

                logStatus(queued ? "Marcación guardada — pendiente de sincronizar" : "Marcación registrada correctamente");
                lastMark = { employeeId: state.employee.id, eventType: eventTypeEl.value, time: Date.now() };
                playBeep("success");
                navigator.vibrate?.(120);
                const fullName = `${state.employee.first_name || ""} ${state.employee.last_name || ""}`.trim();
                showSuccessModal(fullName, eventTypeEl.value, new Date(recordedAt), { queued });
            } catch (e) {
                logError("Error en la marcación:", e);
                if (e instanceof MobileAuthError) {
                    playBeep("error");
                    showErrorModal(
                        "Dispositivo desvinculado",
                        "Este dispositivo ya no está vinculado a tu cuenta. Volvé a identificarte con tu CI y fecha de nacimiento.",
                        () => { window.location.href = "/vincular-dispositivo"; }
                    );
                    return;
                }
                const detailed = buildDetailedError(e.message || "No se pudo registrar la marcación. Por favor, intenta de nuevo.");
                playBeep("error");
                showErrorModal("No se pudo registrar", detailed);
            } finally {
                btnMark.classList.remove("btn-loading");
                btnMark.textContent = "Confirmar marcación";
                btnMark.disabled = false;
                refreshSyncStatus();
            }
        });
    }

    // Evento: Cambio en selector de tipo de evento
    if (eventTypeEl) {
        eventTypeEl.addEventListener("change", checkEnableMark);
    }

    // Evento: Botones visuales de tipo de evento (sincronizan con el select oculto)
    eventBtns.forEach((btn) => {
        btn.addEventListener("click", () => {
            const eventVal = btn.getAttribute("data-event");
            eventBtns.forEach((b) => b.setAttribute("aria-pressed", "false"));
            btn.setAttribute("aria-pressed", "true");
            if (eventTypeEl) {
                eventTypeEl.value = eventVal;
                eventTypeEl.dispatchEvent(new Event("change"));
            }
        });
    });

    // Evento: Botones visuales de tipo de evento — marcar interacción de usuario
    eventBtns.forEach((btn) => {
        btn.addEventListener("click", () => { userHasInteracted = true; }, { once: true });
    });

    // Evento: Botón actualizar ubicación del mini-mapa
    if (btnGeoRetry) {
        btnGeoRetry.addEventListener("click", () => requestGPSManual());
    }

    // Evento: Botón reintentar GPS del banner inline
    if (gpsBannerRetry) {
        gpsBannerRetry.addEventListener("click", () => {
            hideGPSBanner();
            requestGPSBackground();
        });
    }

    // Evento: Limpiar recursos al salir de la página
    window.addEventListener("beforeunload", () => {
        if (stream) {
            stream.getTracks().forEach((t) => t.stop());
        }
        drawLoopActive = false;
    });

    // Evento: Detección de conexión online/offline
    setOfflineBanner(!navigator.onLine);
    window.addEventListener("offline", () => setOfflineBanner(true));
    window.addEventListener("online",  () => setOfflineBanner(false));

    // Evento: Botón "Reintentar" del modal de error
    const retryErrorBtn = document.getElementById("retryErrorModal");
    if (retryErrorBtn) {
        retryErrorBtn.addEventListener("click", () => {
            // Guardia contra tap-through: ignorar si el click llega < 400ms después de mostrar el modal
            if (Date.now() - (retryErrorBtn._shownAt || 0) < 400) return;

            // Blur antes de ocultar el modal para evitar la advertencia ARIA
            if (document.activeElement === retryErrorBtn) retryErrorBtn.blur();

            // Cerrar modal
            const modal = document.getElementById("errorModal");
            if (modal) {
                modal.setAttribute("aria-hidden", "true");
                modal.classList.remove("show");
                setTimeout(() => {
                    modal.classList.add("hidden");
                    document.body.classList.remove("modal-open");
                    errorModalVisible = false;
                    if (previousActiveElement?.focus && !modal.contains(previousActiveElement)) {
                        previousActiveElement.focus();
                    }
                }, 250);
            }
            // Ejecutar callback de reintento si fue provisto (ej: re-solicitar GPS)
            const onRetry = retryErrorBtn._onRetry;
            retryErrorBtn._onRetry = null;
            if (typeof onRetry === "function") {
                setTimeout(onRetry, 260); // esperar a que el modal termine de ocultarse
                return;
            }

            // Dar feedback mientras el cooldown de 3s expira
            setStatusBar("Reintentando en un momento...", "detecting");
            // No limpiar notRecognizedUntil — dejar que el cooldown de 3s expire por sí solo.
            // drawLoop retomará el estado visual automáticamente cuando el cooldown termine.
            if (drawLoopActive) {
                faceInFrameState = null;
            }
        });
    }

    // Evento: Botón "Recargar" del modal de error
    const closeErrorBtn = document.getElementById("closeErrorModal");
    if (closeErrorBtn && !errorModalListenerAdded) {
        closeErrorBtn.addEventListener("click", closeErrorModalHandler);
        errorModalListenerAdded = true;
    }

    // Evento: Teclado en modal de error — Escape cierra, Tab queda atrapado dentro del modal
    if (!errorKeyListenerAdded) {
        document.addEventListener("keydown", (e) => {
            if (!errorModalVisible) return;
            const modal = document.getElementById("errorModal");
            if (!modal || modal.classList.contains("hidden")) return;

            if (e.key === "Escape") {
                // Solo cerrar con Escape si el botón "Reintentar" está visible;
                // si está oculto (ej: sesión expirada) no hacer nada para evitar
                // recargar la página accidentalmente.
                const retryBtn = document.getElementById("retryErrorModal");
                if (retryBtn && !retryBtn.classList.contains("hidden")) {
                    retryBtn.click();
                }
                return;
            }

            if (e.key === "Tab") {
                // Focus trap: Tab cicla solo entre los botones visibles del modal
                const retryBtn  = document.getElementById("retryErrorModal");
                const closeBtn  = document.getElementById("closeErrorModal");
                const focusable = [retryBtn, closeBtn].filter(
                    el => el && !el.classList.contains("hidden")
                );
                if (focusable.length <= 1) return;
                const first = focusable[0];
                const last  = focusable[focusable.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });
        errorKeyListenerAdded = true;
    }

    // ==========================================================================
    // TEMA CLARO / OSCURO
    // ==========================================================================
    // Mismo mecanismo que ya usa terminal.js — localStorage con clave
    // propia para no interferir si algún dispositivo llegara a usarse en los dos modos.
    (function initTheme() {
        const saved = localStorage.getItem("mark-theme");
        const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
        const isDark = saved === "dark" || (!saved && prefersDark);
        document.documentElement.setAttribute("data-theme", isDark ? "dark" : "light");
    })();

    const btnThemeToggle = document.getElementById("btnThemeToggle");
    if (btnThemeToggle) {
        btnThemeToggle.addEventListener("click", () => {
            const isDark = document.documentElement.getAttribute("data-theme") === "dark";
            const next = isDark ? "light" : "dark";
            document.documentElement.setAttribute("data-theme", next);
            localStorage.setItem("mark-theme", next);
        });
    }

    // ==========================================================================
    // INICIALIZACIÓN
    // ==========================================================================

    logStatus("Sistema de marcación facial inicializado");

    // Solicitar GPS en segundo plano al cargar la página
    requestGPSBackground();
});
