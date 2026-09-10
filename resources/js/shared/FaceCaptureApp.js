/**
 * =============================================================================
 * MÓDULO COMPARTIDO - CAPTURA FACIAL
 * =============================================================================
 *
 * @fileoverview Clase base y configuración para captura de descriptores faciales.
 *               Usado tanto por el enrolamiento de admin como por el auto-registro
 *               de empleados.
 *
 * @requires face-api.js - Biblioteca de reconocimiento facial
 * @requires Los modelos deben estar en /models (tinyFaceDetector, faceLandmark68, faceRecognition)
 *
 * @author Sistema RRHH
 * @version 2.1.0
 */

import { captureFaceSamples } from './face-capture-core.js';

/**
 * Configuración global de la aplicación
 */
export const CONFIG = {
    // Ruta a los modelos de face-api.js
    MODELS_URI: "/models",

    // Configuración de detección facial (sincronizado con terminal.js y mark.js)
    DETECTION: {
        inputSize: 416,      // Aumentado de 320 para mejor precisión
        scoreThreshold: 0.6, // Aumentado de 0.5 para rechazar detecciones de baja calidad
        maxFaces: 1,
    },

    // Configuración de captura (más muestras para registro que para identificación)
    CAPTURE: {
        samples: 7,          // Número de muestras a intentar capturar
        minSamples: 5,       // Mínimo de muestras válidas requeridas (flexible)
        intervalMs: 120,     // Intervalo entre capturas en ms
        descriptorLength: 128, // Longitud del descriptor facial (fijo)
        minFaceSize: 120,    // Tamaño mínimo de rostro (más estricto que terminal)
    },

    // Configuración de video/cámara
    VIDEO: {
        facingMode: "user",
        width: { ideal: 640 },
        height: { ideal: 480 },
        frameRate: { ideal: 30, max: 30 },
    },

    // Configuración de rendimiento
    PERFORMANCE: {
        drawLoopInterval: 100, // ms entre frames de dibujo
        maxRetries: 3,         // Reintentos máximos
        timeoutMs: 10000,      // Timeout para operaciones
    },

    // Mensajes de la aplicación
    MESSAGES: {
        LOADING_MODELS: "Preparando sistema de reconocimiento facial...",
        MODELS_LOADED: "Modelos cargados correctamente",
        MODELS_ERROR: "Error al cargar los modelos de reconocimiento",
        CAMERA_STARTING: "Iniciando cámara...",
        CAMERA_READY: "Cámara lista. Ubica tu rostro dentro del óvalo",
        CAMERA_ERROR: "No se pudo acceder a la cámara",
        CAMERA_STOPPED: "Cámara detenida",
        FACE_DETECTED: "Rostro detectado. Listo para capturar",
        NO_FACE: "No se detecta ningún rostro. Acércate o mejora la iluminación",
        FACE_OUT_OVAL: "Centra tu rostro dentro del óvalo guía",
        CAPTURING: "Analizando tu rostro... no te muevas",
        CAPTURE_SUCCESS: "Rostro capturado correctamente",
        CAPTURE_ERROR: "Error durante la captura",
        SAVING: "Guardando...",
        SAVE_SUCCESS: "Rostro guardado correctamente",
        SAVE_ERROR: "Error al guardar el rostro",
    },
};

// =============================================================================
// CLASE PRINCIPAL
// =============================================================================

/**
 * Clase principal para el manejo de captura facial.
 *
 * @class FaceCaptureApp
 * @description Gestiona todo el proceso de captura de descriptores faciales:
 *              inicialización de cámara, detección en tiempo real, captura
 *              de múltiples muestras, promediado de descriptores y guardado.
 */
export class FaceCaptureApp {
    // =========================================================================
    // CONSTRUCTOR E INICIALIZACIÓN
    // =========================================================================

    constructor() {
        this.initializeElements();
        this.initializeState();
        this.setupEventListeners();
        this.setupFaceApiOptions();
    }

    /**
     * Inicializa las referencias a elementos del DOM necesarios.
     */
    initializeElements() {
        // Elementos de pantalla de carga
        this.loadingOverlay = document.getElementById("loadingOverlay");
        this.loadingMessage = document.getElementById("loadingMessage");
        this.loadingProgressBar = document.getElementById("loadingProgressBar");
        this.loadingProgressText = document.getElementById("loadingProgressText");

        // Elementos de video y canvas
        this.video = document.getElementById("video");
        this.overlay = document.getElementById("overlay");
        this.ctx = this.overlay?.getContext("2d", { willReadFrequently: true });
        this.videoWrap = this.video?.closest(".video-wrap");
        this.faceGuideOval = this.videoWrap?.querySelector(".face-guide-oval");

        // Botones de control
        this.btnStart = document.getElementById("btnStart");
        this.btnCapture = document.getElementById("btnCapture");
        this.btnSave = document.getElementById("btnSave");
        this.btnCancel = document.getElementById("btnCancel");

        // Elementos de estado
        this.statusEl = document.getElementById("status");
        this.descValueEl       = document.getElementById("descValue");
        this.descSamplesValueEl = document.getElementById("descSamplesValue");
        this.descQualityValueEl = document.getElementById("descQualityValue");
        this.descTimeValueEl   = document.getElementById("descTimeValue");
        this.descRowSamples    = document.getElementById("descRowSamples");
        this.descRowQuality    = document.getElementById("descRowQuality");
        this.descRowTime       = document.getElementById("descRowTime");
        this.descTimeLabelEl   = document.getElementById("descTimeLabel");
        this.hiddenDescriptor  = document.getElementById("faceDescriptor");

        // Progreso de captura
        this.captureProgressEl = document.getElementById("captureProgress");
        this.captureDots = this.captureProgressEl
            ? Array.from(this.captureProgressEl.querySelectorAll(".capture-dot"))
            : [];

        // Modal
        this.modal = document.getElementById("confirmationModal");
        this.closeModalBtn = document.getElementById("closeModal");

        // Formulario
        this.saveForm = document.getElementById("saveForm");

        // Validar elementos críticos
        this.validateCriticalElements();
    }

    /**
     * Valida que los elementos críticos del DOM existan.
     */
    validateCriticalElements() {
        const critical = [
            { element: this.video, name: "video" },
            { element: this.overlay, name: "overlay" },
            { element: this.ctx, name: "canvas context" },
            { element: this.statusEl, name: "status element" },
        ];

        const missing = critical.filter((item) => !item.element);
        if (missing.length > 0) {
            const missingNames = missing.map((item) => item.name).join(", ");
            throw new Error(
                `Elementos críticos no encontrados: ${missingNames}`
            );
        }
    }

    /**
     * Inicializa el estado interno de la aplicación.
     */
    initializeState() {
        this.stream = null;
        this.isDetecting = false;
        this.isCapturing = false;
        this.modelsLoaded = false;
        this.currentDescriptor = null;
        this.detectionAnimationId = null;
        this.retryCount = 0;
        this._snapshotImageData = null;
        this._lastCaptureSamples = null;
        this._lastCaptureAvgScore = null;
        this._lastFaceCropBase64 = null;
        this._btnCaptureEnabled = false;
    }

    /**
     * Configura todos los event listeners de la aplicación.
     */
    setupEventListeners() {
        // Botones principales
        this.btnStart?.addEventListener(
            "click",
            this.handleStartCamera.bind(this)
        );
        this.btnCapture?.addEventListener(
            "click",
            this.handleCaptureDescriptor.bind(this)
        );
        this.btnSave?.addEventListener(
            "click",
            this.handleSaveDescriptor.bind(this)
        );

        // Modal
        this.closeModalBtn?.addEventListener(
            "click",
            this.handleCloseModal.bind(this)
        );
        this.modal?.addEventListener(
            "click",
            this.handleModalBackdropClick.bind(this)
        );

        // Formulario
        this.saveForm?.addEventListener(
            "submit",
            this.handleFormSubmit.bind(this)
        );

        // Eventos de ventana
        window.addEventListener(
            "beforeunload",
            this.handleBeforeUnload.bind(this)
        );
        window.addEventListener("resize", this.handleResize.bind(this));

        // Eventos de teclado para accesibilidad
        document.addEventListener("keydown", this.handleKeydown.bind(this));

        // Eventos de video
        this.video?.addEventListener(
            "loadedmetadata",
            this.handleVideoLoaded.bind(this)
        );
        this.video?.addEventListener("error", this.handleVideoError.bind(this));
    }

    /**
     * Configura las opciones de face-api.js para detección.
     */
    setupFaceApiOptions() {
        if (typeof faceapi !== "undefined") {
            this.tinyOptions = new faceapi.TinyFaceDetectorOptions({
                inputSize: CONFIG.DETECTION.inputSize,
                scoreThreshold: CONFIG.DETECTION.scoreThreshold,
            });
        }
    }

    // =========================================================================
    // HANDLERS DE EVENTOS PRINCIPALES
    // =========================================================================

    async handleStartCamera() {
        try {
            this.clearSnapshot();
            const isRestarting = this.currentDescriptor !== null;
            const buttonText = isRestarting ? "Reiniciando..." : "Iniciando...";

            this.setButtonState(this.btnStart, false, buttonText);
            this.updateStatus(CONFIG.MESSAGES.CAMERA_STARTING);

            if (!this.modelsLoaded) {
                await this.loadModels();
            }

            await this.startCamera();

            this._btnCaptureEnabled = false;
            if (isRestarting) {
                this.setButtonState(this.btnStart, false, "Cámara Activa");
                this.setButtonState(this.btnCapture, false, "Recapturar Descriptor");
                this.updateStatus("Cámara lista. Centra tu rostro en el óvalo.");
            } else {
                this.setButtonState(this.btnStart, false, "Cámara Activa");
                this.setButtonState(this.btnCapture, false);
                this.updateStatus(CONFIG.MESSAGES.CAMERA_READY);
            }
        } catch (error) {
            this.handleError("Error al iniciar cámara", error);
            this.setButtonState(this.btnStart, true, "Reintentar");
        }
    }

    async handleCaptureDescriptor() {
        if (this.isCapturing) return;

        try {
            this.isCapturing = true;
            this.btnCapture.innerHTML = `${this.SPINNER_SVG} Capturando...`;
            this.btnCapture.disabled = true;
            this.showCaptureProgress();
            this.updateStatus(CONFIG.MESSAGES.CAPTURING);

            const descriptor = await this.captureDescriptor(
                CONFIG.CAPTURE.samples,
                CONFIG.CAPTURE.intervalMs,
                (count) => this.updateCaptureProgress(count)
            );
            this.hideCaptureProgress();
            navigator.vibrate?.(80);

            this.currentDescriptor = descriptor;
            this.hiddenDescriptor.value = JSON.stringify(descriptor);

            this.captureSnapshot();
            await this.stopCamera();
            this.showSnapshot();

            this.setButtonState(this.btnStart, true, "Reiniciar Cámara");
            this.setButtonState(this.btnCapture, false, "Capturar Descriptor");

            this.updateDescriptorState("Capturado ✓");
            this.updateCaptureDetails();
        } catch (error) {
            this.hideCaptureProgress();
            this.handleError("Error en captura", error);
        } finally {
            this.isCapturing = false;
        }
    }

    async handleSaveDescriptor(event) {
        if (event) {
            event.preventDefault();
        }

        if (!this.currentDescriptor) {
            this.updateStatus("No hay rostro capturado para guardar");
            return;
        }

        try {
            this.btnSave.innerHTML = `${this.SPINNER_SVG} Guardando...`;
            this.btnSave.disabled = true;
            this.setButtonState(this.btnStart, false);
            this.updateStatus(CONFIG.MESSAGES.SAVING);

            await this.saveDescriptorAjax();

            console.log("Guardado exitoso, mostrando modal...");
            this.showSuccessModal();
        } catch (error) {
            this.handleError("Error al guardar", error);
            this.setButtonState(this.btnSave, true, "Guardar");
        }
    }

    async handleRecapture() {
        try {
            this.currentDescriptor = null;
            this.hiddenDescriptor.value = "";

            if (!this.isDetecting) {
                await this.handleStartCamera();
            }

            this.setButtonState(this.btnSave, false, "Guardar");
            this.setButtonState(this.btnCapture, true, "Capturar Descriptor");
            this.updateDescriptorState("No capturado");
            this.updateStatus("Listo para nueva captura");
        } catch (error) {
            this.handleError("Error al reiniciar captura", error);
        }
    }

    // =========================================================================
    // MÉTODOS DE COMUNICACIÓN CON SERVIDOR
    // =========================================================================

    async saveDescriptorAjax() {
        const csrfToken = document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute("content");
        const formData = new FormData();
        formData.append("face_descriptor", JSON.stringify(this.currentDescriptor));
        formData.append("_token", csrfToken);

        if (this._lastFaceCropBase64) {
            formData.append("face_snapshot", this._lastFaceCropBase64);
        }
        if (this._lastCaptureSamples !== null) {
            formData.append("samples_count", this._lastCaptureSamples);
        }
        if (this._lastCaptureAvgScore !== null) {
            formData.append("face_score", this._lastCaptureAvgScore.toFixed(4));
        }

        const response = await fetch(this.saveForm.action, {
            method: "POST",
            body: formData,
            headers: {
                "X-Requested-With": "XMLHttpRequest",
            },
        });

        console.log("Respuesta del servidor:", response);

        if (!response.ok) {
            throw new Error(`Error del servidor: ${response.status}`);
        }

        const data = await response.json();
        console.log("Datos recibidos:", data);
        if (data.success) {
            this.updateStatus(CONFIG.MESSAGES.SAVE_SUCCESS);
        } else {
            throw new Error(data.message || "Error desconocido al guardar");
        }

        return data;
    }

    // =========================================================================
    // MÉTODOS DE PANTALLA DE CARGA
    // =========================================================================

    updateLoadingProgress(percentage, message) {
        if (this.loadingProgressBar) {
            this.loadingProgressBar.style.width = `${percentage}%`;
        }
        if (this.loadingProgressText) {
            this.loadingProgressText.textContent = `${percentage}%`;
        }
        if (this.loadingMessage && message) {
            this.loadingMessage.textContent = message;
        }
    }

    hideLoadingScreen() {
        if (this.loadingOverlay) {
            this.loadingOverlay.classList.add("hidden");
        }
    }

    async initializeSystem() {
        try {
            this.updateLoadingProgress(10, "Verificando compatibilidad del navegador...");
            await this.sleep(200);

            if (typeof faceapi === "undefined") {
                throw new Error("La biblioteca face-api.js no está disponible");
            }

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error("Tu navegador no soporta acceso a la cámara");
            }

            this.updateLoadingProgress(30, "Navegador compatible ✓");
            await this.sleep(200);

            this.updateLoadingProgress(40, "Preparando sistema de reconocimiento facial...");

            if (!this.modelsLoaded) {
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(CONFIG.MODELS_URI),
                    faceapi.nets.faceLandmark68Net.loadFromUri(CONFIG.MODELS_URI),
                    faceapi.nets.faceRecognitionNet.loadFromUri(CONFIG.MODELS_URI),
                ]);
                this.modelsLoaded = true;
            }

            this.updateLoadingProgress(80, "Modelos cargados correctamente ✓");
            await this.sleep(300);

            this.updateLoadingProgress(100, "Sistema listo ✓");
            await this.sleep(500);

            console.log("Sistema inicializado correctamente");
            this.hideLoadingScreen();

            const hasFace = document.body.dataset.hasFace === 'true';
            if (hasFace) {
                const faceDate = document.body.dataset.faceDate || '';
                this.showExistingFaceInfo(faceDate);
                this.updateStatus("Este empleado ya tiene un rostro registrado. Inicia la cámara para reemplazarlo.");
            } else {
                this.updateStatus("Sistema listo. Presiona «Iniciar Cámara» para comenzar.");
            }

        } catch (error) {
            console.error("Error en la inicialización:", error);

            if (this.loadingMessage) {
                this.loadingMessage.textContent = `Error: ${error.message}`;
                this.loadingMessage.style.color = "#ef4444";
            }

            await this.sleep(3000);
            this.hideLoadingScreen();
            this.updateStatus(`Error: ${error.message}. Por favor, recarga la página.`);
        }
    }

    // =========================================================================
    // MÉTODOS DE PROGRESO DE CAPTURA
    // =========================================================================

    get SPINNER_SVG() {
        return '<svg class="btn-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 2a10 10 0 0 1 10 10"/></svg>';
    }

    showCaptureProgress() {
        if (!this.captureProgressEl) return;
        this.captureDots.forEach(dot => dot.classList.remove("capture-dot--filled"));
        this.captureProgressEl.classList.remove("hidden");
    }

    updateCaptureProgress(count) {
        this.captureDots.forEach((dot, i) => {
            dot.classList.toggle("capture-dot--filled", i < count);
        });
    }

    hideCaptureProgress() {
        if (!this.captureProgressEl) return;
        this.captureProgressEl.classList.add("hidden");
        this.captureDots.forEach(dot => dot.classList.remove("capture-dot--filled"));
    }

    // =========================================================================
    // MÉTODOS DE CARGA DE MODELOS Y CÁMARA
    // =========================================================================

    async loadModels() {
        if (this.modelsLoaded) {
            console.log("Modelos ya están cargados");
            return;
        }
        if (typeof faceapi === "undefined") {
            throw new Error("La biblioteca face-api.js no está disponible");
        }

        this.updateStatus(CONFIG.MESSAGES.LOADING_MODELS);

        try {
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(CONFIG.MODELS_URI),
                faceapi.nets.faceLandmark68Net.loadFromUri(CONFIG.MODELS_URI),
                faceapi.nets.faceRecognitionNet.loadFromUri(CONFIG.MODELS_URI),
            ]);

            this.modelsLoaded = true;
            this.updateStatus(CONFIG.MESSAGES.MODELS_LOADED);
        } catch (error) {
            throw new Error(
                `${CONFIG.MESSAGES.MODELS_ERROR}: ${error.message}`
            );
        }
    }

    async startCamera() {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error("Tu navegador no soporta acceso a la cámara");
        }

        try {
            await this.stopCamera();

            this.stream = await navigator.mediaDevices.getUserMedia({
                video: CONFIG.VIDEO,
                audio: false,
            });

            this.video.srcObject = this.stream;

            await new Promise((resolve, reject) => {
                const timeout = setTimeout(() => {
                    reject(new Error("Timeout al cargar video"));
                }, CONFIG.PERFORMANCE.timeoutMs);

                this.video.onloadedmetadata = () => {
                    clearTimeout(timeout);
                    resolve();
                };

                this.video.onerror = () => {
                    clearTimeout(timeout);
                    reject(new Error("Error al cargar video"));
                };
            });

            this.setupCanvas();
            this.startDetection();
            this.updateStatus(CONFIG.MESSAGES.CAMERA_READY);
        } catch (error) {
            await this.stopCamera();
            throw new Error(
                `${CONFIG.MESSAGES.CAMERA_ERROR}: ${error.message}`
            );
        }
    }

    setupCanvas() {
        this.overlay.width = this.video.videoWidth;
        this.overlay.height = this.video.videoHeight;

        this.ctx.imageSmoothingEnabled = true;
        this.ctx.imageSmoothingQuality = "high";
    }

    async stopCamera() {
        if (this.stream) {
            this.stream.getTracks().forEach((track) => {
                track.stop();
            });
            this.stream = null;
        }

        this.isDetecting = false;
        this.setVideoState(null);

        if (this.detectionAnimationId) {
            clearTimeout(this.detectionAnimationId);
            this.detectionAnimationId = null;
        }

        this.clearCanvas();
        this.updateStatus(CONFIG.MESSAGES.CAMERA_STOPPED);
    }

    // =========================================================================
    // MÉTODOS DE DETECCIÓN FACIAL
    // =========================================================================

    setVideoState(state) {
        if (!this.videoWrap) return;
        this.videoWrap.classList.remove("detecting", "face-found");
        if (state) this.videoWrap.classList.add(state);
    }

    startDetection() {
        this.isDetecting = true;
        this.setVideoState("detecting");
        this.detectLoop();
    }

    async detectLoop() {
        if (!this.isDetecting) return;

        try {
            if (this.video.readyState < 2 || this.video.videoWidth === 0 || this.video.videoHeight === 0) {
                this.detectionAnimationId = setTimeout(() => {
                    requestAnimationFrame(() => this.detectLoop());
                }, CONFIG.PERFORMANCE.drawLoopInterval);
                return;
            }

            const detection = await faceapi
                .detectSingleFace(this.video, this.tinyOptions)
                .withFaceLandmarks();

            if (!this.isDetecting) return;

            this.clearCanvas();

            if (detection) {
                const inOval = this.isFaceInOval(detection.detection.box);
                if (inOval) {
                    this.setVideoState("face-found");
                    if (!this.isCapturing && !this._btnCaptureEnabled) {
                        this.setButtonState(this.btnCapture, true);
                        this._btnCaptureEnabled = true;
                        this.updateStatus(CONFIG.MESSAGES.FACE_DETECTED);
                    }
                } else {
                    this.setVideoState("detecting");
                    if (!this.isCapturing) {
                        if (this._btnCaptureEnabled) {
                            this.setButtonState(this.btnCapture, false);
                            this._btnCaptureEnabled = false;
                        }
                        this.updateStatus(CONFIG.MESSAGES.FACE_OUT_OVAL);
                    }
                }
            } else {
                this.setVideoState("detecting");
                if (!this.isCapturing) {
                    if (this._btnCaptureEnabled) {
                        this.setButtonState(this.btnCapture, false);
                        this._btnCaptureEnabled = false;
                    }
                    this.updateStatus(CONFIG.MESSAGES.NO_FACE);
                }
            }
        } catch (error) {
            console.warn("Error en detección:", error);
        }

        this.detectionAnimationId = setTimeout(() => {
            requestAnimationFrame(() => this.detectLoop());
        }, CONFIG.PERFORMANCE.drawLoopInterval);
    }

    clearCanvas() {
        this.ctx.clearRect(0, 0, this.overlay.width, this.overlay.height);
    }

    drawDetection(_detection) {
        // Sin dibujo — el feedback visual se da mediante los estados CSS
        // del video-wrap (.detecting / .face-found) y el color del óvalo.
    }

    /**
     * Verifica si el rostro detectado está centrado dentro del óvalo guía
     * y tiene un tamaño mínimo del 50% del ancho del óvalo.
     *
     * Convierte las coords CSS del óvalo a píxeles de video teniendo en
     * cuenta el escalado de object-fit:cover.
     *
     * @param {Object} box - Bounding box del rostro {x, y, width, height} en px de video
     * @returns {boolean}
     */
    isFaceInOval(box) {
        const vw = this.video.videoWidth;
        const vh = this.video.videoHeight;
        if (!vw || !vh || !this.faceGuideOval || !this.videoWrap) return true;

        const wrapRect = this.videoWrap.getBoundingClientRect();
        const ovalRect = this.faceGuideOval.getBoundingClientRect();

        // Escala de object-fit:cover: cuántos px CSS representa 1px de video
        const scale = Math.max(wrapRect.width / vw, wrapRect.height / vh);

        // Cuánto video queda fuera del contenedor en cada eje (en px CSS)
        const cropX = (vw * scale - wrapRect.width) / 2;
        const cropY = (vh * scale - wrapRect.height) / 2;

        // Centro y semiejes del óvalo en coords de video
        const ovalCX_css = ovalRect.left - wrapRect.left + ovalRect.width  / 2;
        const ovalCY_css = ovalRect.top  - wrapRect.top  + ovalRect.height / 2;
        const ovalCX = (ovalCX_css + cropX) / scale;
        const ovalCY = (ovalCY_css + cropY) / scale;
        const ovalRx = (ovalRect.width  / 2) / scale;
        const ovalRy = (ovalRect.height / 2) / scale;

        // Centro del rostro en coords de video
        const faceCX = box.x + box.width  / 2;
        const faceCY = box.y + box.height / 2;

        // Verificar que los 4 puntos medios de los bordes del bbox estén dentro del óvalo.
        // Esto asegura que el rostro no sobresalga por ningún lado.
        const inEllipse = (px, py) =>
            ((px - ovalCX) / ovalRx) ** 2 + ((py - ovalCY) / ovalRy) ** 2 <= 1;

        return inEllipse(faceCX, box.y)              // borde superior
            && inEllipse(faceCX, box.y + box.height) // borde inferior
            && inEllipse(box.x,  faceCY)             // borde izquierdo
            && inEllipse(box.x + box.width, faceCY); // borde derecho
    }

    async captureDescriptor(
        samples = CONFIG.CAPTURE.samples,
        intervalMs = CONFIG.CAPTURE.intervalMs,
        onProgress = null
    ) {
        const minFaceSize = CONFIG.CAPTURE.minFaceSize || 120;
        const minRequired = CONFIG.CAPTURE.minSamples || Math.ceil(samples * 0.7);
        const maxAttempts = samples * 3;

        let validCount = 0;

        const result = await captureFaceSamples(this.video, this.tinyOptions, {
            samples,
            intervalMs,
            minFaceSize,
            minRequired,
            maxAttempts,
            onProgress: (count) => {
                validCount = count;
                if (onProgress) onProgress(count);
                this.updateStatus(`Capturando muestra ${count} de ${samples}... no te muevas`);
            },
            onRejectedSample: (reason, detail) => {
                if (reason === 'too_small') {
                    console.warn(`Rostro muy pequeño: ${Math.round(detail.width)}x${Math.round(detail.height)}px (mínimo ${minFaceSize}px)`);
                    this.updateStatus(`Acércate más a la cámara (${validCount} de ${samples})`);
                } else if (reason === 'no_face') {
                    this.updateStatus(`Buscando rostro... (${validCount} de ${samples})`);
                } else if (reason === 'error') {
                    console.warn('Error en captura individual:', detail);
                }
            },
        });

        if (result.samples.length < samples) {
            console.info(`Capturadas ${result.samples.length} de ${samples} muestras (mínimo ${minRequired} cumplido)`);
        }

        const lastValid = result.samples[result.samples.length - 1];
        const scores = result.samples.map((s) => s.score);

        this._lastCaptureSamples = result.samples.length;
        this._lastCaptureAvgScore = scores.length
            ? scores.reduce((a, b) => a + b, 0) / scores.length
            : 0;
        this._lastFaceCropBase64 = lastValid
            ? this.extractFaceCrop(lastValid.box)
            : null;

        return result.averaged;
    }

    // =========================================================================
    // MÉTODOS DE UI - MODALES
    // =========================================================================

    showSuccessModal() {
        console.log("Intentando mostrar el modal de éxito...");
        if (this.modal) {
            console.log("Modal encontrado, configurando estilos...");
            this.modal.classList.remove("hidden");

            setTimeout(() => {
                console.log("Enfocando el botón de cerrar...");
                this.closeModalBtn?.focus();
            }, 100);
        } else {
            console.error("Modal de éxito no encontrado");
        }
    }

    hideModal() {
        if (this.modal) {
            this.modal.classList.add("hidden");

            window.location.href = "/employees";
        }
    }

    // =========================================================================
    // HANDLERS DE EVENTOS SECUNDARIOS
    // =========================================================================

    handleCloseModal() {
        this.hideModal();
        this.resetCaptureState();
        this.stopCamera();
    }

    handleModalBackdropClick(event) {
        if (event.target === this.modal) {
            this.hideModal();
        }
    }

    handleFormSubmit(event) {
        return true;
    }

    async handleBeforeUnload() {
        await this.stopCamera();
    }

    handleResize() {
        if (this.video && this.overlay && this.video.videoWidth > 0) {
            this.setupCanvas();
        }
    }

    handleKeydown(event) {
        if (event.key === "Escape" && this.modal && !this.modal.classList.contains("hidden")) {
            event.preventDefault();
            this.hideModal();
        }
    }

    handleVideoLoaded() {
        this.setupCanvas();
    }

    handleVideoError(event) {
        this.handleError(
            "Error de video",
            new Error("Error al reproducir video")
        );
    }

    // =========================================================================
    // MÉTODOS UTILITARIOS
    // =========================================================================

    updateStatus(message) {
        if (this.statusEl) {
            this.statusEl.textContent = message;
            this.statusEl.setAttribute("aria-live", "polite");
            console.log(`Status: ${message}`);
        }
    }

    updateDescriptorState(state) {
        if (this.descValueEl) {
            this.descValueEl.textContent = state;
        }
    }

    updateCaptureDetails() {
        const samples = this._lastCaptureSamples ?? 0;
        const score   = this._lastCaptureAvgScore ?? 0;
        const now     = new Date();

        if (this.descSamplesValueEl) {
            this.descSamplesValueEl.textContent = `${samples} de ${CONFIG.CAPTURE.samples}`;
        }
        this.descRowSamples?.classList.remove("hidden");

        let qualityTier;
        if (this.descQualityValueEl) {
            let label, cls;
            if (score >= 0.85)      { label = "Alta";  cls = "desc-value--high"; qualityTier = "alta"; }
            else if (score >= 0.70) { label = "Media"; cls = "desc-value--mid";  qualityTier = "media"; }
            else                    { label = "Baja";  cls = "desc-value--low";  qualityTier = "baja"; }
            this.descQualityValueEl.textContent = label;
            this.descQualityValueEl.className   = `desc-value ${cls}`;
        }
        this.descRowQuality?.classList.remove("hidden");

        if (qualityTier === "baja") {
            this.setButtonState(this.btnSave, false, "Guardar");
            this.updateStatus("Calidad insuficiente. Reinicia la cámara e intenta nuevamente con mejor iluminación.");
        } else if (qualityTier === "media") {
            this.setButtonState(this.btnSave, true);
            this.updateStatus("Calidad media. Puedes guardar, pero se recomienda volver a capturar para mejores resultados.");
        } else {
            this.setButtonState(this.btnSave, true);
            this.updateStatus("Rostro capturado correctamente. Presiona Guardar para continuar.");
        }

        if (this.descTimeLabelEl) this.descTimeLabelEl.textContent = "Hora";
        if (this.descTimeValueEl) {
            this.descTimeValueEl.textContent = now.toLocaleTimeString("es", {
                hour: "2-digit", minute: "2-digit", second: "2-digit",
            });
        }
        this.descRowTime?.classList.remove("hidden");
    }

    showExistingFaceInfo(date) {
        this.updateDescriptorState("Ya registrado");
        if (this.descValueEl) this.descValueEl.className = "desc-value desc-value--high";

        if (date) {
            if (this.descTimeLabelEl) this.descTimeLabelEl.textContent = "Registrado";
            if (this.descTimeValueEl) this.descTimeValueEl.textContent = date;
            this.descRowTime?.classList.remove("hidden");
        }

        this.descRowSamples?.classList.add("hidden");
        this.descRowQuality?.classList.add("hidden");
    }

    setButtonState(button, enabled, text = null) {
        if (!button) return;

        button.disabled = !enabled;
        button.setAttribute("aria-disabled", (!enabled).toString());

        if (text) {
            const iconSpan = button.querySelector('span[aria-hidden="true"]');
            if (iconSpan) {
                const iconHtml = iconSpan.innerHTML;
                button.innerHTML = `<span aria-hidden="true">${iconHtml}</span> ${text}`;
            } else {
                button.textContent = text;
            }
        }
    }

    resetCaptureState() {
        this.currentDescriptor = null;
        this.hiddenDescriptor.value = "";
        this.clearSnapshot();
        this._lastCaptureSamples  = null;
        this._lastCaptureAvgScore = null;
        this._lastFaceCropBase64  = null;
        this.setButtonState(this.btnSave, false, "Guardar");
        this.setButtonState(this.btnStart, true, "Iniciar Cámara");
        this.setButtonState(this.btnCapture, false, "Capturar Descriptor");
        this.updateDescriptorState("No capturado");
        if (this.descValueEl) this.descValueEl.className = "desc-value";
        if (this.descTimeLabelEl) this.descTimeLabelEl.textContent = "Hora";
        this.descRowSamples?.classList.add("hidden");
        this.descRowQuality?.classList.add("hidden");
        this.descRowTime?.classList.add("hidden");
        this.updateStatus("Presiona «Iniciar Cámara» para comenzar");
    }

    handleError(context, error) {
        const message = `${context}: ${error.message}`;
        console.error(message, error);
        this.updateStatus(message);

        if (context.includes("modelos") || context.includes("cámara")) {
            alert(message);
        }
    }

    sleep(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    // =========================================================================
    // MÉTODOS DE SNAPSHOT
    // =========================================================================

    /**
     * Extrae el recorte del rostro del frame actual del video usando el bounding box.
     * Añade un margen del 20% alrededor del box para incluir frente y mentón.
     * @param {Object} box - Objeto con x, y, width, height del detection box
     * @returns {string|null} Base64 JPEG del recorte, o null si falla
     */
    extractFaceCrop(box) {
        try {
            const margin = 0.20;
            const vw = this.video.videoWidth;
            const vh = this.video.videoHeight;

            const x = Math.max(0, Math.round(box.x - box.width * margin));
            const y = Math.max(0, Math.round(box.y - box.height * margin));
            const w = Math.min(vw - x, Math.round(box.width * (1 + 2 * margin)));
            const h = Math.min(vh - y, Math.round(box.height * (1 + 2 * margin)));

            const cropCanvas = document.createElement('canvas');
            cropCanvas.width  = w;
            cropCanvas.height = h;
            const cropCtx = cropCanvas.getContext('2d', { willReadFrequently: true });
            cropCtx.drawImage(this.video, x, y, w, h, 0, 0, w, h);

            return cropCanvas.toDataURL('image/jpeg', 0.85);
        } catch (e) {
            console.warn('No se pudo extraer el recorte facial:', e);
            return null;
        }
    }

    captureSnapshot() {
        if (!this.video || !this.ctx || this.video.readyState < 2) return;
        this.setupCanvas();
        this.ctx.drawImage(this.video, 0, 0, this.overlay.width, this.overlay.height);
        this._snapshotImageData = this.ctx.getImageData(0, 0, this.overlay.width, this.overlay.height);
    }

    showSnapshot() {
        if (this._snapshotImageData && this.ctx) {
            this.ctx.putImageData(this._snapshotImageData, 0, 0);
        }
    }

    clearSnapshot() {
        this._snapshotImageData = null;
    }
}
