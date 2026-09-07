import { captureFaceSamples } from '../shared/face-capture-core.js';
import { migrateTokenFromLocalStorage, getMeta, getCachedEmployees, countCachedEmployees, clearTerminalState } from './terminal-offline/db.js';
import { identifyEmployee as matchDescriptor } from './terminal-offline/matcher.js';
import { heartbeat, syncEmployees, getFaceConfig, TerminalAuthError } from './terminal-offline/sync.js';
import { getEmployeeStatus, enqueueMark, flushQueue, countPendingEvents, countConflictEvents } from './terminal-offline/queue.js';

document.addEventListener("DOMContentLoaded", () => {
    // ============================================================================
    // ELEMENTOS DEL DOM
    // ============================================================================
    const screens = {
        startGate:      document.getElementById("startGateScreen"),
        loading:        document.getElementById("loadingScreen"),
        idle:           document.getElementById("idleScreen"),
        typeSelection:  document.getElementById("typeSelectionScreen"),
        identification: document.getElementById("identificationScreen"),
        success:        document.getElementById("successScreen"),
        dayComplete:    document.getElementById("dayCompleteScreen"),
        error:          document.getElementById("errorScreen"),
    };

    // Loading screen elements
    const loadingMessage    = document.getElementById("loadingMessage");
    const loadingProgress   = document.getElementById("loadingProgress");
    const loadingPercentage = document.getElementById("loadingPercentage");
    const loadingStep1      = document.getElementById("step1");
    const loadingStep2      = document.getElementById("step2");
    const loadingStep3      = document.getElementById("step3");

    const video   = document.getElementById("terminalVideo");
    const overlay = document.getElementById("terminalOverlay");
    const ctx     = overlay?.getContext("2d");

    const identificationStatus = document.getElementById("identificationStatus");

    const terminalHeaderClock = document.getElementById("terminalHeaderClock");
    const terminalVideoWrap   = document.getElementById("terminalVideoWrap");
    const idStatusDot         = document.getElementById("idStatusDot");
    const idleClock           = document.getElementById("idleClock");
    const idleDate            = document.getElementById("idleDate");
    const idleSyncStatus      = document.getElementById("idleSyncStatus");
    const btnForceSync        = document.getElementById("btnForceSync");

    const terminalCaptureProgress = document.getElementById("terminalCaptureProgress");
    const terminalCaptureDots     = terminalCaptureProgress
        ? Array.from(terminalCaptureProgress.querySelectorAll(".capture-dot"))
        : [];

    const typeButtons   = document.querySelectorAll(".terminal-type-btn");
    const btnCancel      = document.getElementById("btnCancelIdentification");
    const btnMarkAnother = document.getElementById("btnMarkAnother");
    const btnRetry       = document.getElementById("btnRetry");
    const btnReload      = document.getElementById("btnReload");
    const btnThemeToggle = document.getElementById("btnThemeToggle");

    // Búsqueda manual por CI (fallback cuando el reconocimiento facial falla seguido)
    const btnManualSearch       = document.getElementById("btnManualSearch");
    const manualSearchOverlay   = document.getElementById("manualSearchOverlay");
    const manualSearchInput     = document.getElementById("manualSearchInput");
    const manualSearchResults   = document.getElementById("manualSearchResults");
    const manualSearchEmpty     = document.getElementById("manualSearchEmpty");
    const btnManualSearchCancel = document.getElementById("btnManualSearchCancel");

    // Day complete screen elements
    const dayCompleteEmployeePhoto  = document.getElementById("dayCompleteEmployeePhoto");
    const dayCompleteEmployeeName   = document.getElementById("dayCompleteEmployeeName");
    const dayCompleteCountdownEl    = document.getElementById("dayCompleteCountdown");
    const dayCompleteCountdownFill  = document.getElementById("dayCompleteCountdownFill");

    // Success screen elements
    const successEmployeePhoto = document.getElementById("successEmployeePhoto");
    const successEmployeeName  = document.getElementById("successEmployeeName");
    const successEmployeeCI   = document.getElementById("successEmployeeCI");
    const successEventType    = document.getElementById("successEventType");
    const successTime         = document.getElementById("successTime");
    const successQueuedNotice = document.getElementById("successQueuedNotice");
    const countdownEl         = document.getElementById("countdown");
    const countdownFill       = document.getElementById("countdownFill");

    // Error screen elements
    const errorMessage = document.getElementById("errorMessage");

    const MODELS_URI      = "/models";
    const IDLE_TIMEOUT_MS = 5 * 60 * 1000; // 5 minutos sin marcaciones

    /** Datos de la terminal identificada (inyectados por PHP cuando se accede via /terminal/{code}) */
    const terminalData = window.terminalData || null;

    // Poblar nombre y sucursal en la pantalla idle si hay datos de terminal
    const idleTerminalInfo   = document.getElementById("idleTerminalInfo");
    const idleTerminalName   = document.getElementById("idleTerminalName");
    const idleTerminalBranch = document.getElementById("idleTerminalBranch");
    if (terminalData && idleTerminalInfo) {
        if (idleTerminalName)   idleTerminalName.textContent   = terminalData.name || "";
        if (idleTerminalBranch) idleTerminalBranch.textContent = terminalData.branch_name || "";
        idleTerminalInfo.style.display = "";
    }

    // Empresa — sucursal en el header persistente (a diferencia del bloque idle de
    // arriba, este queda visible también durante la identificación activa).
    const headerLocation = document.getElementById("terminalHeaderLocation");
    if (terminalData && headerLocation && (terminalData.branch_name || terminalData.company_name)) {
        headerLocation.textContent = [terminalData.branch_name, terminalData.company_name]
            .filter(Boolean)
            .join(" — ");
    }

    // Logo de la empresa — ya viaja embebido como data URI en window.terminalData
    // (inyectado server-side), así que queda cacheado junto con el shell del terminal
    // por el service worker sin necesidad de un fetch aparte.
    const headerLogo = document.getElementById("terminalHeaderLogo");
    if (terminalData && headerLogo && terminalData.company_logo) {
        headerLogo.src = terminalData.company_logo;
        headerLogo.classList.remove("hidden");
    }

    // Marca/modelo del dispositivo físico (device_brand/device_model en Terminal) — ambos
    // son opcionales y siempre editables desde Filament, así que se omite el elemento por
    // completo si el admin nunca los cargó, en vez de mostrar un texto vacío o "null null".
    const headerDevice = document.getElementById("terminalHeaderDevice");
    if (terminalData && headerDevice) {
        const deviceLabel = [terminalData.device_brand, terminalData.device_model].filter(Boolean).join(" ");
        if (deviceLabel) {
            headerDevice.textContent = deviceLabel;
            headerDevice.classList.remove("hidden");
        }
    }

    // ============================================================================
    // ESTADO GLOBAL
    // ============================================================================
    let terminalState = {
        stream:                 null,
        modelsLoaded:           false,
        identifyInterval:       null,
        drawLoopActive:         false,
        employee:               null,   // empleado identificado (para selección de tipo)
        countdownTimer:         null,
        isProcessing:           false,
        faceDetected:           false,  // drawLoop lo actualiza; interval lo lee antes de capturar
        notRecognizedUntil:     0,      // timestamp hasta el cual drawLoop no sobreescribe el estado naranja
        inNotRecognizedCooldown: false, // true mientras el cooldown está activo; drawLoop lo usa para resetear el texto al salir
        wakeLock:               null,
        idleTimer:              null,
        presenceCheckInterval:  null,
        isIdle:                 false,
        userHasInteracted:      false,  // vibración solo permitida tras gesto del usuario
        backgroundSyncStarted:  false,  // evita registrar los setInterval de sync más de una vez
        consecutiveFailures:    0,      // intentos de reconocimiento fallidos seguidos — habilita la búsqueda manual por CI
        manualCandidate:        null,   // empleado elegido en la búsqueda manual — acota identifyEmployee() a un único candidato
    };

    const tinyOptions  = new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.6 });
    const lightOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: 160, scoreThreshold: 0.5 });

    /** Tamaño mínimo de rostro en píxeles para aceptar una detección (drawLoop y captureDescriptor) */
    const MIN_FACE_SIZE = 100;

    /** Intentos de reconocimiento fallidos seguidos antes de ofrecer la búsqueda manual por CI. */
    const CONSECUTIVE_FAILURES_FOR_MANUAL_SEARCH = 2;

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    const eventTypeNames = {
        check_in:    "Entrada",
        break_start: "Inicio descanso",
        break_end:   "Fin descanso",
        check_out:   "Salida",
    };

    // ============================================================================
    // WAKE LOCK — mantiene la pantalla encendida
    // ============================================================================
    async function acquireWakeLock() {
        if (!("wakeLock" in navigator)) return;
        try {
            terminalState.wakeLock = await navigator.wakeLock.request("screen");
            terminalState.wakeLock.addEventListener("release", () => {
                terminalState.wakeLock = null;
            });
            console.log("Wake lock adquirido");
        } catch (err) {
            console.warn("Wake lock no disponible:", err.message);
        }
    }

    // Re-adquirir wake lock si el tab vuelve al foco
    document.addEventListener("visibilitychange", async () => {
        if (document.visibilityState === "visible" && !terminalState.wakeLock) {
            await acquireWakeLock();
        }
    });

    // ============================================================================
    // RELOJ EN TIEMPO REAL
    // ============================================================================
    function updateClock() {
        const now = new Date();
        const day   = String(now.getDate()).padStart(2, "0");
        const month = String(now.getMonth() + 1).padStart(2, "0");
        const year  = now.getFullYear();
        const dateStr = `${day}/${month}/${year}`;
        const timeStr = now.toLocaleTimeString("es-BO", {
            hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false,
        });
        if (terminalHeaderClock) {
            terminalHeaderClock.innerHTML =
                `<span class="clock-date">${dateStr}</span><span class="clock-time">${timeStr}</span>`;
        }
        if (idleClock) idleClock.textContent = timeStr;
    }
    updateClock();
    setInterval(updateClock, 1000);

    function updateIdleDate() {
        if (!idleDate) return;
        const now = new Date();
        const day   = String(now.getDate()).padStart(2, "0");
        const month = String(now.getMonth() + 1).padStart(2, "0");
        const year  = now.getFullYear();
        const weekday = now.toLocaleDateString("es-BO", { weekday: "long" });
        idleDate.textContent = `${weekday.charAt(0).toUpperCase() + weekday.slice(1)} ${day}/${month}/${year}`;
    }

    // ============================================================================
    // ESTADO VISUAL DEL VIDEO
    // ============================================================================
    function setTerminalVideoState(stateClass) {
        if (!terminalVideoWrap) return;
        terminalVideoWrap.classList.remove(
            "video-wrapper--detecting",
            "video-wrapper--face-found",
            "video-wrapper--success",
            "video-wrapper--error"
        );
        if (stateClass) terminalVideoWrap.classList.add(`video-wrapper--${stateClass}`);
    }

    function setIdStatusDot(dotClass) {
        if (!idStatusDot) return;
        idStatusDot.classList.remove(
            "id-status-dot--searching",
            "id-status-dot--processing",
            "id-status-dot--found",
            "id-status-dot--error"
        );
        // detecting → searching (naranja), face-found → processing (teal), success → found (verde)
        const cssMap = { detecting: "searching", "face-found": "processing", success: "found", error: "error" };
        const cssClass = cssMap[dotClass] || dotClass;
        if (cssClass) idStatusDot.classList.add(`id-status-dot--${cssClass}`);
    }

    // ============================================================================
    // PROGRESO DE CAPTURA FACIAL
    // ============================================================================
    function showCaptureProgress() {
        if (!terminalCaptureProgress) return;
        terminalCaptureDots.forEach(dot => dot.classList.remove("capture-dot--filled"));
        terminalCaptureProgress.classList.remove("hidden");
    }

    function updateCaptureProgress(count) {
        terminalCaptureDots.forEach((dot, i) => {
            dot.classList.toggle("capture-dot--filled", i < count);
        });
    }

    function hideCaptureProgress() {
        if (!terminalCaptureProgress) return;
        terminalCaptureProgress.classList.add("hidden");
        terminalCaptureDots.forEach(dot => {
            dot.classList.remove("capture-dot--filled", "capture-dot--success", "capture-dot--error");
        });
    }

    async function finishCaptureProgress(outcome) {
        // Asegurar que los 5 dots están visibles con el color del resultado
        terminalCaptureDots.forEach(dot => {
            dot.classList.remove("capture-dot--filled", "capture-dot--success", "capture-dot--error");
            dot.classList.add(`capture-dot--${outcome}`);
        });
        await sleep(400);
        hideCaptureProgress();
    }

    // ============================================================================
    // IDLE — gestión de reposo
    // ============================================================================
    function resetIdleTimer() {
        clearIdleTimer();
        terminalState.idleTimer = setTimeout(enterIdle, IDLE_TIMEOUT_MS);
    }

    function clearIdleTimer() {
        if (terminalState.idleTimer) {
            clearTimeout(terminalState.idleTimer);
            terminalState.idleTimer = null;
        }
    }

    const terminalHeader = document.querySelector(".terminal-header");

    function enterIdle() {
        clearIdleTimer();
        stopAutoIdentification();
        stopCountdown();
        terminalState.isIdle = true;
        updateIdleDate();
        showScreen("idle");
        startPresenceCheck();
        if (terminalHeader) terminalHeader.classList.add("terminal-header--idle");
    }

    function exitIdle() {
        if (!terminalState.isIdle) return;
        terminalState.isIdle = false;
        stopPresenceCheck();
        if (terminalHeader) terminalHeader.classList.remove("terminal-header--idle");
        startIdentificationFlow();
    }

    // Detección de presencia en modo reposo (detector liviano, sin descriptor)
    async function startPresenceCheck() {
        stopPresenceCheck();

        if (!terminalState.stream) {
            try {
                terminalState.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: "user" }, audio: false,
                });
                video.srcObject = terminalState.stream;
                await new Promise(resolve => { video.onloadedmetadata = resolve; });
            } catch (e) {
                console.warn("Cámara no disponible para detección de presencia:", e);
                return;
            }
        }

        presenceCheckLoop();
    }

    async function presenceCheckLoop() {
        if (!terminalState.isIdle) return;

        try {
            if (video.readyState >= 2 && video.videoWidth > 0) {
                const detection = await faceapi.detectSingleFace(video, lightOptions);
                if (detection && terminalState.isIdle) {
                    exitIdle();
                    return;
                }
            }
        } catch (e) { /* ignorar errores en detección de presencia */ }

        if (terminalState.isIdle) {
            terminalState.presenceCheckInterval = setTimeout(presenceCheckLoop, 2500);
        }
    }

    function stopPresenceCheck() {
        if (terminalState.presenceCheckInterval) {
            clearTimeout(terminalState.presenceCheckInterval);
            terminalState.presenceCheckInterval = null;
        }
    }

    // ============================================================================
    // FLUJO DE IDENTIFICACIÓN (nuevo punto de entrada principal)
    // ============================================================================
    function startIdentificationFlow() {
        resetIdleTimer();
        // Cada vez que arranca un ciclo de identificación nuevo (nuevo empleado frente a
        // la cámara) se vuelve al reconocimiento contra todos los candidatos — el estado
        // de la búsqueda manual del empleado anterior no debe seguir acotando el match.
        terminalState.consecutiveFailures = 0;
        terminalState.manualCandidate = null;
        hideManualSearchLink();
        closeManualSearch();
        showScreen("identification");
        startAutoIdentification();
        setTerminalVideoState("detecting");
    }


    // ============================================================================
    // MENSAJES DE ERROR DETALLADOS
    // ============================================================================
    function buildDetailedError(rawMessage) {
        if (!rawMessage) return "No se pudo completar la marcación. Por favor, intente nuevamente.";
        const msg = rawMessage.toLowerCase();
        if (msg.includes("no identificado") || msg.includes("not found") || msg.includes("no match")) {
            return "No se pudo reconocer su rostro. Asegúrese de estar frente a la cámara con buena iluminación, sin lentes de sol ni gorras, y mantenga el rostro quieto.";
        }
        if (msg.includes("descriptor") || msg.includes("muestra") || msg.includes("sample")) {
            return "No se detectó un rostro válido. Acerque el rostro a la cámara (30-60 cm) y asegúrese de tener buena iluminación frontal.";
        }
        if (msg.includes("conexión") || msg.includes("network") || msg.includes("fetch")) {
            return !navigator.onLine
                ? "Sin conexión a internet. Verifique la red del dispositivo y vuelva a intentar."
                : "Error de conexión al servidor. Verifique que el dispositivo tenga acceso a la red y vuelva a intentar.";
        }
        if (msg.includes("csrf") || msg.includes("419")) {
            return "La sesión expiró. Por favor, recargue la página para continuar.";
        }
        if (msg.includes("event") || msg.includes("evento") || msg.includes("allowed")) {
            return "No hay tipos de marcación disponibles para este empleado en este momento. Consulte con el departamento de RRHH.";
        }
        return rawMessage;
    }

    // ============================================================================
    // PANTALLA DE CARGA
    // ============================================================================
    function updateLoadingProgress(percentage, message, stepNumber) {
        if (loadingProgress) loadingProgress.style.width = `${percentage}%`;
        if (loadingPercentage) loadingPercentage.textContent = `${percentage}%`;
        if (loadingMessage && message) loadingMessage.textContent = message;

        const steps = [loadingStep1, loadingStep2, loadingStep3];
        steps.forEach((step, index) => {
            if (!step) return;
            step.classList.remove("active", "completed");
            if (index + 1 < stepNumber) {
                step.classList.add("completed");
            } else if (index + 1 === stepNumber) {
                step.classList.add("active");
            }
        });
    }

    /**
     * Si esta página es /terminal (legacy, sin código) y el dispositivo ya tiene un
     * terminal_code guardado en IndexedDB de una provisión anterior, muestra un banner
     * con el link directo a /terminal/{code} — evita depender de que alguien busque el
     * código en Filament para migrar el acceso guardado del dispositivo. No requiere
     * red (el código ya vive localmente) ni bloquea el resto de la inicialización.
     */
    async function checkLegacyTerminalMigration() {
        if (window.terminalData) return; // ya estamos en /terminal/{code}, nada que migrar

        const banner = document.getElementById("legacyMigrationBanner");
        const link = document.getElementById("legacyMigrationLink");
        if (!banner || !link) return;

        try {
            const code = await getMeta("terminal_code");
            if (!code) return;

            const url = `${window.location.origin}/terminal/${code}`;
            link.href = url;
            link.textContent = url;
            banner.classList.add("is-visible");
            banner.setAttribute("aria-hidden", "false");
        } catch (error) {
            console.warn("No se pudo verificar el código de terminal guardado localmente:", error);
        }
    }

    async function initializeSystem() {
        try {
            updateLoadingProgress(10, "Verificando compatibilidad del navegador...", 1);
            await sleep(300);

            if (typeof faceapi === "undefined") {
                throw new Error("La biblioteca face-api.js no está disponible");
            }
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error("Tu navegador no soporta acceso a la cámara");
            }

            updateLoadingProgress(30, "Navegador compatible ✓", 1);
            await sleep(200);

            updateLoadingProgress(40, "Cargando modelos de reconocimiento facial...", 2);
            if (!terminalState.modelsLoaded) {
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODELS_URI),
                    faceapi.nets.faceLandmark68Net.loadFromUri(MODELS_URI),
                    faceapi.nets.faceRecognitionNet.loadFromUri(MODELS_URI),
                ]);
                terminalState.modelsLoaded = true;
            }

            updateLoadingProgress(80, "Modelos cargados correctamente ✓", 2);
            await sleep(300);

            updateLoadingProgress(90, "Preparando sistema de marcación...", 3);
            await sleep(300);

            updateLoadingProgress(100, "Sistema listo ✓", 3);
            await sleep(500);

            // Adquirir wake lock para mantener pantalla encendida
            await acquireWakeLock();

            await initializeOfflineSync();

            console.log("Sistema inicializado correctamente");

            // Mostrar pantalla idle primero — requiere toque para activar audio/vibración
            enterIdle();

        } catch (error) {
            console.error("Error en la inicialización:", error);
            if (loadingMessage) {
                loadingMessage.textContent = `Error: ${error.message}`;
                loadingMessage.style.color = "#ef4444";
            }
            await sleep(3000);
            showError("Error al inicializar el sistema. " + error.message + " Por favor, recargue la página.");
        }
    }

    // ============================================================================
    // NAVEGACIÓN ENTRE PANTALLAS
    // ============================================================================
    function showScreen(screenName) {
        const current = Object.values(screens).find(s => s && !s.classList.contains("hidden"));
        const next = screens[screenName];

        const activate = () => {
            Object.values(screens).forEach(s => {
                if (s) { s.classList.remove("screen-leaving"); s.classList.add("hidden"); }
            });
            if (next) next.classList.remove("hidden");
        };

        if (current && current !== next) {
            current.classList.add("screen-leaving");
            setTimeout(activate, 150);
        } else {
            activate();
        }
    }

    // ============================================================================
    // CÁMARA Y FACE-API
    // ============================================================================
    async function loadModels() {
        if (terminalState.modelsLoaded) return true;
        try {
            updateStatus("Cargando modelos de reconocimiento facial...", "loading");
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(MODELS_URI),
                faceapi.nets.faceLandmark68Net.loadFromUri(MODELS_URI),
                faceapi.nets.faceRecognitionNet.loadFromUri(MODELS_URI),
            ]);
            terminalState.modelsLoaded = true;
            return true;
        } catch (error) {
            console.error("Error cargando modelos Face-API:", error);
            showError("Error al cargar el sistema de reconocimiento facial. Por favor, recargue la página.");
            return false;
        }
    }

    async function startCamera() {
        if (terminalState.stream) return true;
        try {
            updateStatus("Iniciando cámara...", "loading");
            terminalState.stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "user" }, audio: false,
            });
            video.srcObject = terminalState.stream;
            await new Promise((resolve) => { video.onloadedmetadata = resolve; });
            overlay.width  = video.videoWidth;
            overlay.height = video.videoHeight;
            return true;
        } catch (error) {
            console.error("Error al iniciar cámara:", error);
            let message = "No se pudo acceder a la cámara. ";
            if (error.name === "NotAllowedError")   message += "Por favor, permita el acceso a la cámara.";
            else if (error.name === "NotFoundError") message += "No se encontró ninguna cámara conectada.";
            else if (error.name === "NotReadableError") message += "La cámara está siendo usada por otra aplicación.";
            else message += "Error desconocido: " + error.message;
            showError(message);
            return false;
        }
    }

    function stopCamera() {
        if (terminalState.stream) {
            terminalState.stream.getTracks().forEach((track) => track.stop());
            terminalState.stream = null;
            video.srcObject = null;
        }
        stopDrawLoop();
    }

    function startDrawLoop() {
        if (terminalState.drawLoopActive) return;
        terminalState.drawLoopActive = true;
        drawLoop();
    }

    function stopDrawLoop() {
        terminalState.drawLoopActive = false;
    }

    async function drawLoop() {
        if (!terminalState.drawLoopActive) return;

        try {
            if (video.readyState >= 2 && video.videoWidth > 0 && video.videoHeight > 0) {
                // Detector liviano (mismo usado en presenceCheckLoop) — este loop corre cada
                // 200ms solo para decidir el color del óvalo y si hay un rostro lo bastante
                // grande para intentar capturar. No necesita landmarks ni el descriptor de 128
                // dimensiones (eso lo calcula aparte captureDescriptor() cuando ya hay un rostro
                // detectado) — encadenar .withFaceLandmarks().withFaceDescriptor() acá corría la
                // extracción más pesada de face-api.js 5 veces por segundo sin usar el resultado,
                // el principal cuello de botella de CPU/GPU en tablets de gama baja.
                const detection = await faceapi.detectSingleFace(video, lightOptions);

                // Limpiar canvas — sin dibujo de bounding box ni landmarks
                ctx.clearRect(0, 0, overlay.width, overlay.height);

                // drawLoop es el dueño exclusivo del estado visual (nunca lo sobreescribe updateStatus)
                if (!terminalState.isProcessing && Date.now() > terminalState.notRecognizedUntil) {
                    // Primera vez que se sale del cooldown: resetear texto junto con el color
                    if (terminalState.inNotRecognizedCooldown) {
                        terminalState.inNotRecognizedCooldown = false;
                        updateStatus("Posicione su rostro dentro del óvalo...");
                    }
                    if (detection) {
                        // Feedback inmediato si la cara está muy chica — evita intentos de captura
                        // que fallarían igual al final del ciclo de 5 muestras
                        const box = detection.box;
                        const tooSmall = box.width < MIN_FACE_SIZE || box.height < MIN_FACE_SIZE;
                        terminalState.faceDetected = !tooSmall;
                        setTerminalVideoState(tooSmall ? "detecting" : "face-found");
                        setIdStatusDot(tooSmall ? "detecting" : "face-found");
                        if (tooSmall) updateStatus("Acérquese un poco más a la cámara");
                    } else {
                        terminalState.faceDetected = false;
                        setTerminalVideoState("detecting");   // naranja — sin rostro
                        setIdStatusDot("detecting");
                    }
                }
            }
        } catch (error) {
            console.error("Error en drawLoop:", error);
        }

        if (terminalState.drawLoopActive) {
            setTimeout(drawLoop, 200);
        }
    }

    async function captureDescriptor(samples = 5, intervalMs = 150, onProgress = null) {
        const { averaged } = await captureFaceSamples(video, tinyOptions, {
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

    // updateStatus solo actualiza el texto — el estado visual del video lo maneja drawLoop
    function updateStatus(text) {
        if (!identificationStatus) return;
        const statusTextEl = identificationStatus.querySelector(".id-status-text");
        if (statusTextEl) {
            statusTextEl.textContent = text;
        } else {
            identificationStatus.innerHTML = `
                <span class="id-status-dot" id="idStatusDot"></span>
                <span class="id-status-text">${text}</span>
            `;
        }
    }

    // ============================================================================
    // IDENTIFICACIÓN — matching client-side contra la caché local (IndexedDB)
    // ============================================================================
    /**
     * Identifica al empleado comparando el descriptor capturado contra la caché
     * local de empleados (employees_cache), con el mismo algoritmo de distancia
     * euclidiana + umbral/gap que corre en el servidor (ver terminal-offline/matcher.js).
     * El estado del día (último evento / eventos permitidos) se resuelve vía
     * getEmployeeStatus(), que intenta la consulta en línea y cae a lo que el
     * terminal ya sabe localmente si no hay red — ver terminal-offline/queue.js.
     */
    async function identifyEmployee(descriptor) {
        try {
            const { threshold, minGap } = await getFaceConfig();
            if (threshold == null || minGap == null) {
                return { ok: false, message: "Terminal sincronizando por primera vez, espere un momento." };
            }

            // Si el empleado se identificó por la búsqueda manual de CI, acotar el match a
            // ese único candidato — con un solo candidato, matchDescriptor() ya omite el
            // chequeo de "gap" contra un segundo mejor (no aplica con N=1), pero el rostro
            // capturado igual tiene que superar el umbral de distancia normal contra ESE
            // descriptor: la búsqueda manual ayuda a encontrarse en la lista, no reemplaza
            // la verificación facial.
            const candidates = terminalState.manualCandidate
                ? [terminalState.manualCandidate]
                : await getCachedEmployees();
            const { employee, distance, reason } = matchDescriptor(descriptor, candidates, threshold, minGap);

            if (!employee) {
                const messages = {
                    no_candidates: "No hay empleados sincronizados en este terminal.",
                    ambiguous: "Rostro ambiguo. Por favor, reposicione su cara e intente de nuevo.",
                    no_match: "No se pudo identificar el rostro. Intente nuevamente.",
                };
                return { ok: false, message: messages[reason] || "No identificado", reason };
            }

            const status = await getEmployeeStatus(employee.id);

            return {
                ok: true,
                employee: {
                    id: employee.id,
                    first_name: employee.first_name,
                    last_name: employee.last_name,
                    ci: employee.ci,
                    // Thumbnail 64x64 pre-generado (ver EmployeePhotoThumbnailService), sincronizado
                    // como data URI junto al descriptor facial — funciona 100% offline. Sin foto
                    // propia (empleado sin foto cargada), cae al ícono genérico.
                    photo_url: employee.photo_thumbnail || "/images/default-avatar.png",
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
            console.error("Error en identificación:", error);
            return { ok: false, message: "Error de conexión" };
        }
    }

    async function startAutoIdentification() {
        const modelsLoaded = await loadModels();
        if (!modelsLoaded) return;

        const cameraStarted = await startCamera();
        if (!cameraStarted) return;

        startDrawLoop();
        updateStatus("Posicione su rostro dentro del óvalo...");

        terminalState.identifyInterval = setInterval(async () => {
            if (terminalState.isProcessing) return;

            // Esperar a que expire el cooldown de "no reconocido" antes de reintentar
            if (Date.now() <= terminalState.notRecognizedUntil) return;

            // No intentar capturar si drawLoop no detectó ningún rostro recientemente
            if (!terminalState.faceDetected) return;

            try {
                terminalState.isProcessing = true;
                // Forzar teal al iniciar captura: no depender del estado visual previo
                setTerminalVideoState("face-found");
                setIdStatusDot("face-found");
                updateStatus("Analizando rostro, mantenga la posición...");
                showCaptureProgress();

                const descriptor = await captureDescriptor(5, 150, (count) => {
                    updateCaptureProgress(count);
                });

                const result = await identifyEmployee(descriptor);

                if (result.needsProvisioning) {
                    stopAutoIdentification();
                    await finishCaptureProgress("error");
                    showError(result.message);
                    return;
                }

                if (result.ok && result.employee) {
                    terminalState.consecutiveFailures = 0;
                    terminalState.manualCandidate = null;
                    hideManualSearchLink();

                    if (terminalState.userHasInteracted) navigator.vibrate?.(80);
                    stopAutoIdentification();

                    await finishCaptureProgress("success");

                    // Flash verde — identificación exitosa
                    setTerminalVideoState("success");
                    setIdStatusDot("success");

                    const allowedEvents = result.allowed_events || [];

                    if (allowedEvents.length === 1) {
                        // Un único evento válido — registrar automáticamente sin selección
                        await registerMark(result.employee, allowedEvents[0]);
                    } else if (allowedEvents.length > 1) {
                        // Múltiples eventos válidos — mostrar selección al empleado
                        showTypeSelectionForEmployee(result.employee, allowedEvents, result.last_event, result.last_event_time);
                    } else {
                        // Sin eventos disponibles — jornada ya completada
                        showDayComplete(result.employee);
                    }
                } else {
                    await finishCaptureProgress("error");

                    // No reconocido — forzar naranja y bloquear drawLoop.
                    // Reducido de 2s a 1.2s para que el siguiente intento no se sienta tan lento.
                    terminalState.notRecognizedUntil = Date.now() + 1200;
                    terminalState.inNotRecognizedCooldown = true;
                    setTerminalVideoState("detecting");
                    setIdStatusDot("detecting");
                    // `result.reason` distingue "no hay nadie cacheado" (problema del terminal,
                    // no del empleado) de un intento normal que no matcheó — antes se pisaban
                    // ambos casos con el mismo texto genérico, escondiendo la falla real de sync.
                    const idleStatusMessages = {
                        no_candidates: "Terminal sin empleados sincronizados. Contacte al administrador.",
                        ambiguous: "Rostro ambiguo. Reposicione su cara e intente de nuevo.",
                        no_match: "Rostro no reconocido. Mantenga el rostro quieto frente a la cámara.",
                    };
                    updateStatus(idleStatusMessages[result.reason] || result.message || "Rostro no reconocido. Mantenga el rostro quieto frente a la cámara.");
                    console.log("No se pudo identificar", result.reason);

                    // Tras varios intentos fallidos seguidos, ofrecer la búsqueda manual por CI
                    // en vez de dejar al empleado atrapado repitiendo el mismo intento sin salida.
                    terminalState.consecutiveFailures++;
                    if (terminalState.consecutiveFailures >= CONSECUTIVE_FAILURES_FOR_MANUAL_SEARCH) {
                        showManualSearchLink();
                    }
                }
            } catch (error) {
                await finishCaptureProgress("error");
                console.error("Error en auto-identificación:", error);
                updateStatus("Error al analizar. Asegúrese de tener buena iluminación.");
            } finally {
                if (terminalState.identifyInterval) terminalState.isProcessing = false;
            }
        }, 1500); // Reducido de 3000ms: con el cooldown ya acotado, un intervalo más corto evita esperas innecesarias entre intentos
    }

    function stopAutoIdentification() {
        if (terminalState.identifyInterval) {
            clearInterval(terminalState.identifyInterval);
            terminalState.identifyInterval = null;
        }
        stopCamera();
    }

    // ============================================================================
    // BÚSQUEDA MANUAL POR CI — fallback cuando el reconocimiento facial falla seguido
    // ============================================================================
    /**
     * NO reemplaza la verificación facial: elegir un candidato acá solo acota
     * identifyEmployee() a esa única persona (ver terminalState.manualCandidate) — la
     * cámara sigue activa y el empleado igual tiene que superar el umbral de distancia
     * normal contra ese descriptor específico antes de que se registre cualquier marcación.
     */
    const MAX_MANUAL_SEARCH_RESULTS = 8;

    function showManualSearchLink() {
        if (btnManualSearch) btnManualSearch.classList.remove("hidden");
    }

    function hideManualSearchLink() {
        if (btnManualSearch) btnManualSearch.classList.add("hidden");
    }

    function openManualSearch() {
        if (!manualSearchOverlay) return;
        manualSearchOverlay.classList.remove("hidden");
        if (manualSearchInput) {
            manualSearchInput.value = "";
            manualSearchInput.focus();
        }
        renderManualSearchResults("");
    }

    function closeManualSearch() {
        if (manualSearchOverlay) manualSearchOverlay.classList.add("hidden");
    }

    async function renderManualSearchResults(query) {
        if (!manualSearchResults) return;
        manualSearchResults.innerHTML = "";

        const digits = (query || "").trim();
        if (digits.length < 2) {
            if (manualSearchEmpty) manualSearchEmpty.classList.add("hidden");
            return;
        }

        const candidates = await getCachedEmployees();
        const matches = candidates
            .filter((employee) => employee.ci && String(employee.ci).includes(digits))
            .slice(0, MAX_MANUAL_SEARCH_RESULTS);

        if (manualSearchEmpty) manualSearchEmpty.classList.toggle("hidden", matches.length > 0);

        matches.forEach((employee) => {
            const fullName = `${employee.first_name || ""} ${employee.last_name || ""}`.trim() || "Empleado";
            const item = document.createElement("button");
            item.type = "button";
            item.className = "manual-search-result";
            item.setAttribute("role", "option");
            item.innerHTML = `
                <img src="${employee.photo_thumbnail || "/images/default-avatar.png"}" alt="" aria-hidden="true">
                <span>
                    <span class="manual-search-result-name">${fullName}</span><br>
                    <span class="manual-search-result-ci">CI: ${employee.ci}</span>
                </span>
            `;
            item.addEventListener("click", () => selectManualCandidate(employee));
            manualSearchResults.appendChild(item);
        });
    }

    function selectManualCandidate(employee) {
        terminalState.manualCandidate = employee;
        terminalState.consecutiveFailures = 0;
        hideManualSearchLink();
        closeManualSearch();
        updateStatus(`Mirá la cámara para confirmar que sos ${employee.first_name || "vos"}...`);
    }

    if (btnManualSearch) {
        btnManualSearch.addEventListener("click", openManualSearch);
    }
    if (btnManualSearchCancel) {
        btnManualSearchCancel.addEventListener("click", closeManualSearch);
    }
    if (manualSearchOverlay) {
        manualSearchOverlay.addEventListener("click", (event) => {
            if (event.target === manualSearchOverlay) closeManualSearch();
        });
    }
    if (manualSearchInput) {
        manualSearchInput.addEventListener("input", (event) => {
            renderManualSearchResults(event.target.value);
        });
    }

    // ============================================================================
    // SELECCIÓN DE TIPO POST-IDENTIFICACIÓN (solo si hay múltiples eventos válidos)
    // ============================================================================
    function showTypeSelectionForEmployee(employee, allowedEvents, lastEvent, lastEventTime) {
        terminalState.employee = employee;

        // Mostrar solo los botones de tipos permitidos para este empleado
        typeButtons.forEach(btn => {
            const evtType = btn.getAttribute("data-event-type");
            btn.style.display = allowedEvents.includes(evtType) ? "" : "none";
        });

        // Ajustar columnas del grid según cantidad de opciones visibles
        const visibleCount = allowedEvents.length;
        const typeGrid = screens.typeSelection?.querySelector(".type-grid");
        if (typeGrid) {
            typeGrid.style.gridTemplateColumns = visibleCount === 1 ? "1fr" : "1fr 1fr";
            typeGrid.style.maxWidth = visibleCount === 1 ? "320px" : "";
        }

        const fullName = `${employee.first_name || ""} ${employee.last_name || ""}`.trim();

        const screenTitle   = document.getElementById("typeSelectionTitle");
        const screenEyebrow = document.getElementById("typeSelectionEyebrow");
        if (screenTitle)   screenTitle.textContent   = fullName ? `Hola, ${fullName}` : "Seleccione marcación";
        if (screenEyebrow) screenEyebrow.textContent = fullName ? "Empleado verificado ✓" : "Seleccione marcación";

        // Recordatorio de la última marcación conocida — ayuda a elegir, por ejemplo,
        // entre "Fin descanso" y "Salida" sin que el empleado tenga que recordarlo.
        const lastMarkEl = document.getElementById("typeSelectionLastMark");
        if (lastMarkEl) {
            if (lastEvent) {
                const lastEventName = eventTypeNames[lastEvent] || lastEvent;
                lastMarkEl.textContent = lastEventTime
                    ? `Última marcación: ${lastEventName}, ${lastEventTime}`
                    : `Última marcación: ${lastEventName}`;
                lastMarkEl.classList.remove("hidden");
            } else {
                lastMarkEl.textContent = "";
                lastMarkEl.classList.add("hidden");
            }
        }

        showScreen("typeSelection");
    }

    // ============================================================================
    // REGISTRO DE MARCACIÓN — cola offline + sync
    // ============================================================================
    /**
     * Encola la marcación en IndexedDB (durable — sobrevive un cierre de
     * pestaña o un corte de luz) y de inmediato intenta sincronizarla. Si hay
     * red, el empleado ve la confirmación normal. Si no la hay (o el envío
     * falla), la marcación NO se pierde — queda en la cola y se reintenta
     * solo en segundo plano — se le muestra una pantalla de éxito igual,
     * aclarando que se sincronizará más tarde, para no asustar al empleado
     * con un error cuando en realidad ya se guardó.
     */
    async function registerMark(employee, eventType) {
        // Mensaje con el tipo de evento (no genérico) y tiempo suficiente para leerse
        // (700ms) antes de pasar a la pantalla de éxito — sin esto, sobre todo en el
        // registro automático (un único evento válido, sin que el empleado toque nada),
        // el paso de "identificando" a "listo" era instantáneo y el empleado podía no
        // darse cuenta de que el sistema ya había decidido y registrado por él.
        const eventName = eventTypeNames[eventType] || eventType;
        updateStatus(`Registrando: ${eventName}...`, "loading");
        await sleep(700);

        const { client_event_id: clientEventId, recorded_at: recordedAt } = await enqueueMark(employee.id, eventType);

        if (!navigator.onLine) {
            showSuccessScreen(employee, { recorded_at: recordedAt }, eventType, { queued: true });
            await refreshIdleSyncStatus();
            return;
        }

        try {
            const { results } = await flushQueue();
            const ownResult = results.find((r) => r.client_event_id === clientEventId);

            if (ownResult && ownResult.status !== "synced" && ownResult.status !== "duplicate") {
                // El servidor ya respondió que esta marcación puntual es inválida (ej. secuencia
                // rota) — no tiene sentido dejarla "pendiente", se le informa directo al empleado.
                showError(ownResult.message || "La marcación fue rechazada por el servidor.");
            } else {
                // Sincronizada (ownResult existe y es synced/duplicate) o todavía en cola porque la
                // red falló a mitad de camino (ownResult ausente) — en ambos casos ya está a salvo.
                showSuccessScreen(employee, { recorded_at: recordedAt }, eventType, { queued: !ownResult });
            }
        } catch (error) {
            console.error("Error al sincronizar la cola:", error);
            // La marcación ya está encolada de forma segura — no se pierde aunque esto falle.
            showSuccessScreen(employee, { recorded_at: recordedAt }, eventType, { queued: true });
        }

        await refreshIdleSyncStatus();
    }

    // ============================================================================
    // AUDIO FEEDBACK (Web Audio API — sin archivos, sin permisos)
    // ============================================================================
    let audioCtx = null;

    function getAudioCtx() {
        if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        return audioCtx;
    }

    function playTone(freq, duration, gain = 0.25, delay = 0) {
        try {
            const ctx = getAudioCtx();
            ctx.resume();
            const osc  = ctx.createOscillator();
            const env  = ctx.createGain();
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
        if (!terminalState.userHasInteracted) return;
        if (type === "success") {
            // Doble beep ascendente — confirmación positiva
            playTone(880,  0.08, 0.22, 0.0);
            playTone(1100, 0.12, 0.22, 0.1);
        } else if (type === "error") {
            // Beep grave descendente — advertencia
            playTone(440, 0.08, 0.22, 0.0);
            playTone(330, 0.14, 0.22, 0.1);
        }
    }

    // ============================================================================
    // PANTALLAS DE RESULTADO
    // ============================================================================
    /**
     * @param {object} employee
     * @param {{recorded_at?: string}} markData
     * @param {string} eventType
     * @param {{queued?: boolean}} [options] - queued=true: la marcación se guardó localmente
     *        pero todavía no se confirmó con el servidor (sin red en el momento) — se avisa
     *        sin asustar al empleado, ya que la marcación NO se perdió.
     */
    function showSuccessScreen(employee, markData, eventType, { queued = false } = {}) {
        const fullName = `${employee.first_name || ""} ${employee.last_name || ""}`.trim();
        if (successEmployeePhoto) {
            successEmployeePhoto.src = employee.photo_url || "";
            successEmployeePhoto.alt = fullName;
        }
        successEmployeeName.textContent = fullName || "Empleado";
        successEmployeeCI.textContent   = employee.ci ? `CI: ${employee.ci}` : "";
        successEventType.textContent    = eventTypeNames[eventType] || eventType;

        const now = new Date();
        successTime.textContent = now.toLocaleTimeString("es-BO", {
            hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false,
        });

        if (successQueuedNotice) {
            successQueuedNotice.style.display = queued ? "" : "none";
        }

        playBeep("success");
        showScreen("success");
        startCountdown(5);
    }

    function showError(message) {
        const detailed = buildDetailedError(message);
        if (errorMessage) errorMessage.textContent = detailed;
        setTerminalVideoState(null);
        playBeep("error");
        showScreen("error");
    }

    function showDayComplete(employee) {
        const fullName = `${employee.first_name || ""} ${employee.last_name || ""}`.trim();
        if (dayCompleteEmployeePhoto) {
            dayCompleteEmployeePhoto.src = employee.photo_url || "";
            dayCompleteEmployeePhoto.alt = fullName;
        }
        if (dayCompleteEmployeeName) dayCompleteEmployeeName.textContent = fullName || "Empleado";
        setTerminalVideoState(null);
        playBeep("success");
        showScreen("dayComplete");
        startDayCompleteCountdown(5);
    }

    function startDayCompleteCountdown(seconds) {
        let remaining = seconds;
        if (dayCompleteCountdownEl)   dayCompleteCountdownEl.textContent = remaining;
        if (dayCompleteCountdownFill) {
            dayCompleteCountdownFill.style.transition = "none";
            dayCompleteCountdownFill.style.width = "100%";
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    dayCompleteCountdownFill.style.transition = `width ${seconds}s linear`;
                    dayCompleteCountdownFill.style.width = "0%";
                });
            });
        }
        terminalState.countdownTimer = setInterval(() => {
            remaining--;
            if (dayCompleteCountdownEl) dayCompleteCountdownEl.textContent = remaining;
            if (remaining <= 0) {
                clearInterval(terminalState.countdownTimer);
                resetTerminal();
            }
        }, 1000);
    }

    function startCountdown(seconds) {
        let remaining = seconds;
        if (countdownEl)   countdownEl.textContent = remaining;
        if (countdownFill) {
            countdownFill.style.transition = "none";
            countdownFill.style.width = "100%";
            // Forzar reflow para que la transición arranque desde 100%
            countdownFill.offsetWidth;
            countdownFill.style.transition = `width ${seconds}s linear`;
            countdownFill.style.width = "0%";
        }

        terminalState.countdownTimer = setInterval(() => {
            remaining--;
            if (countdownEl) countdownEl.textContent = remaining;
            if (remaining <= 0) {
                clearInterval(terminalState.countdownTimer);
                resetTerminal();
            }
        }, 1000);
    }

    function stopCountdown() {
        if (terminalState.countdownTimer) {
            clearInterval(terminalState.countdownTimer);
            terminalState.countdownTimer = null;
        }
    }

    // ============================================================================
    // RESET
    // ============================================================================
    function resetTerminal() {
        stopAutoIdentification();
        stopCountdown();

        terminalState.employee     = null;
        terminalState.isProcessing = false;

        // Restaurar visibilidad de todos los botones de tipo
        typeButtons.forEach(btn => { btn.style.display = ""; });

        // Restaurar título de pantalla de tipo
        const screenTitle = screens.typeSelection?.querySelector(".screen-title");
        if (screenTitle) screenTitle.textContent = "Marcación";

        setTerminalVideoState(null);

        // Volver a identificación directamente
        startIdentificationFlow();
    }

    // ============================================================================
    // EVENT LISTENERS
    // ============================================================================

    // Botones de tipo (ahora usados solo tras identificación con múltiples eventos)
    typeButtons.forEach((button) => {
        button.addEventListener("click", async () => {
            const eventType = button.getAttribute("data-event-type");
            if (!terminalState.employee) return;
            clearIdleTimer();
            await registerMark(terminalState.employee, eventType);
        });
    });

    // Toque en pantalla de reposo → despertar
    if (screens.idle) {
        screens.idle.addEventListener("click", () => exitIdle());
    }

    // Botón cancelar identificación
    if (btnCancel) {
        btnCancel.addEventListener("click", () => {
            stopAutoIdentification();
            resetTerminal();
        });
    }

    // Botón marcar otra persona (desde pantalla de éxito)
    if (btnMarkAnother) {
        btnMarkAnother.addEventListener("click", () => resetTerminal());
    }

    // Botón reintentar (desde pantalla de error)
    if (btnRetry) {
        btnRetry.addEventListener("click", () => resetTerminal());
    }

    // Botón recargar página (desde pantalla de error — para errores irrecuperables)
    if (btnReload) {
        btnReload.addEventListener("click", () => window.location.reload());
    }

    // ============================================================================
    // CONECTIVIDAD
    // ============================================================================
    const offlineBanner    = document.getElementById("offlineBanner");
    const connectivityDot  = document.getElementById("connectivityDot");
    const connectivityLabel = document.getElementById("connectivityLabel");

    /**
     * A diferencia del banner (que solo aparece mientras el navegador está sin red),
     * el indicador del header queda siempre visible — refleja `navigator.onLine`, no la
     * conectividad real con el servidor (eso lo indica por separado "Últ. sync", más
     * abajo: un dispositivo puede tener wifi pero sin salida a internet real).
     */
    function setOffline(isOffline) {
        if (offlineBanner) {
            offlineBanner.classList.toggle("is-visible", isOffline);
            offlineBanner.setAttribute("aria-hidden", String(!isOffline));
        }
        if (connectivityDot)   connectivityDot.classList.toggle("is-offline", isOffline);
        if (connectivityLabel) connectivityLabel.textContent = isOffline ? "Sin conexión" : "En línea";
    }

    // Estado inicial (por si la página carga sin red)
    setOffline(!navigator.onLine);

    window.addEventListener("offline", () => setOffline(true));
    window.addEventListener("online",  () => setOffline(false));

    // Marcar interacción del usuario para habilitar Vibration API
    const markInteraction = () => {
        terminalState.userHasInteracted = true;
        document.removeEventListener("click",      markInteraction);
        document.removeEventListener("touchstart", markInteraction);
    };
    document.addEventListener("click",      markInteraction, { once: true });
    document.addEventListener("touchstart", markInteraction, { once: true });

    // ============================================================================
    // SINCRONIZACIÓN OFFLINE — token, caché de empleados y config facial
    // ============================================================================
    function updateIdleSyncStatus(text) {
        if (idleSyncStatus) idleSyncStatus.textContent = text;
    }

    /**
     * Refleja en la pantalla idle el estado de la cola de eventos offline —
     * se llama después de cada intento de sincronización (registerMark, sync
     * en segundo plano, botón manual) para que el texto visible siempre
     * refleje la cola real en IndexedDB, no solo el último resultado puntual.
     */
    async function refreshIdleSyncStatus() {
        // Prioridad más alta: sin empleados cacheados el terminal no puede identificar
        // a NADIE — más grave que marcaciones pendientes/en conflicto, que sí sabe
        // resolver localmente. Antes esto solo se notaba al fallar un intento real de
        // reconocimiento, con un mensaje genérico que no distinguía la causa.
        const employeeCount = await countCachedEmployees();
        if (employeeCount === 0) {
            updateIdleSyncStatus("⚠ Sin empleados sincronizados — verifique la conexión o contacte al administrador");
            await refreshLastSyncLabel();
            return;
        }

        const [pending, conflicts] = await Promise.all([countPendingEvents(), countConflictEvents()]);
        if (conflicts > 0) {
            updateIdleSyncStatus(`${conflicts} marcación(es) requieren revisión`);
        } else if (pending > 0) {
            updateIdleSyncStatus(`${pending} marcación(es) pendiente(s) de sincronizar`);
        } else {
            updateIdleSyncStatus(navigator.onLine ? "Sincronizado" : "Sin conexión — usando datos locales");
        }
        await refreshLastSyncLabel();
    }

    const terminalHeaderLastSync = document.getElementById("terminalHeaderLastSync");

    /**
     * Última vez que un heartbeat exitoso confirmó contacto real con el servidor
     * (`last_heartbeat_at` en terminal_meta, escrito por heartbeat() en sync.js) — a
     * diferencia del indicador "En línea"/"Sin conexión" (que solo refleja
     * `navigator.onLine`), esto sirve para detectar un terminal con wifi pero sin
     * conectividad real al backend. Se lee de IndexedDB en cada llamada, así que
     * sobrevive a recargas de página sin depender del estado de esta sesión.
     */
    async function refreshLastSyncLabel() {
        if (!terminalHeaderLastSync) return;
        const lastHeartbeatAt = await getMeta("last_heartbeat_at");
        if (!lastHeartbeatAt) {
            terminalHeaderLastSync.classList.add("hidden");
            return;
        }
        const time = new Date(lastHeartbeatAt).toLocaleTimeString("es-BO", {
            hour: "2-digit", minute: "2-digit", hour12: false,
        });
        terminalHeaderLastSync.textContent = `Últ. sync: ${time}`;
        terminalHeaderLastSync.classList.remove("hidden");
    }

    /**
     * Pide al navegador que la cuota de almacenamiento de este origen sea
     * "persistente" — reduce el riesgo de que el navegador borre IndexedDB
     * (empleados cacheados, cola de marcaciones pendientes) bajo presión de
     * espacio en disco. Best-effort: no todos los navegadores lo soportan, y
     * la concesión depende de heurísticas del navegador (ej. Chrome la
     * otorga más fácil en un dispositivo instalado como PWA/terminal).
     */
    async function requestPersistentStorage() {
        if (!navigator.storage?.persist) return;
        try {
            const granted = await navigator.storage.persist();
            console.log(granted ? "Almacenamiento persistente concedido" : "Almacenamiento persistente no concedido (best-effort)");
        } catch (error) {
            console.warn("No se pudo solicitar almacenamiento persistente:", error.message);
        }
    }

    /**
     * Migra el token de configuración (si venía de localStorage, ver
     * terminal-setup.blade.php) y hace la primera sincronización antes de
     * arrancar el reposo. Si el terminal no está provisionado, no bloquea el
     * arranque — solo informa en la pantalla idle, la auto-identificación
     * fallará limpiamente con "terminal sin configurar" hasta que se resuelva.
     *
     * Regresión de seguridad: `nominapp-terminal` (IndexedDB) es una única
     * base por navegador, no separada por terminal — sin este chequeo, un
     * dispositivo que alguna vez reclamó un token para OTRO terminal seguía
     * autenticando y sincronizando en silencio como ese terminal viejo al
     * abrir la URL pública de cualquier terminal nuevo, sin pasar de nuevo
     * por el enlace de configuración de un solo uso.
     *
     * Compara por `terminal_id` (estable) cuando está disponible, no por
     * `terminal_code` — "Cambiar URL del terminal" cambia el code del MISMO
     * terminal a propósito sin afectar el token (ver ViewTerminal), así que
     * comparar solo por code trataría ese caso legítimo como si fuera un
     * terminal distinto y borraría un token todavía válido. Cae a comparar
     * por code únicamente en dispositivos provisionados antes de este fix,
     * que todavía no tienen terminal_id guardado.
     */
    async function initializeOfflineSync() {
        await migrateTokenFromLocalStorage();

        const storedId = await getMeta("terminal_id");
        const storedCode = await getMeta("terminal_code");
        const currentId = window.terminalData?.id;
        const currentCode = window.terminalData?.code;

        const belongsToOtherTerminal = (storedId != null && currentId != null)
            ? storedId !== currentId
            : (storedCode != null && currentCode != null && storedCode !== currentCode);

        if (belongsToOtherTerminal) {
            console.warn(`Datos locales pertenecen a otro terminal (id ${storedId ?? 'desconocido'}, code "${storedCode}") — limpiando antes de continuar.`);
            await clearTerminalState();
        }

        const token = await getMeta("api_token");

        if (!token) {
            console.warn("Terminal sin token de sincronización — falta provisión.");
            updateIdleSyncStatus("Terminal sin configurar");
            return;
        }

        await requestPersistentStorage();

        try {
            updateIdleSyncStatus("Sincronizando...");
            await heartbeat();
            await syncEmployees();
            await flushQueue();
            await refreshIdleSyncStatus();
        } catch (error) {
            console.warn("Sincronización inicial falló (se reintentará en segundo plano):", error.message);
            updateIdleSyncStatus(navigator.onLine ? "Error al sincronizar" : "Sin conexión — usando datos locales");
            // Mostrar igual el último sync exitoso conocido (de una sesión anterior, ya
            // persistido en IndexedDB) aunque el de ahora haya fallado.
            await refreshLastSyncLabel();
        }

        startBackgroundSync();
    }

    /**
     * Heartbeat + sync de empleados + vaciado de la cola de eventos,
     * periódicos mientras haya conexión, más un intento al recuperarla.
     */
    function startBackgroundSync() {
        if (terminalState.backgroundSyncStarted) return;
        terminalState.backgroundSyncStarted = true;

        const runHeartbeat = () => {
            if (!navigator.onLine) return;
            heartbeat().catch((error) => console.warn("Heartbeat en segundo plano falló:", error.message));
        };
        const runEmployeeSync = () => {
            if (!navigator.onLine) return;
            syncEmployees().catch((error) => console.warn("Sync de empleados en segundo plano falló:", error.message));
        };
        const runQueueFlush = () => {
            if (!navigator.onLine) return;
            flushQueue()
                .then(() => refreshIdleSyncStatus())
                .catch((error) => console.warn("Sincronización de cola en segundo plano falló:", error.message));
        };

        setInterval(runHeartbeat, 90 * 1000);
        setInterval(runEmployeeSync, 5 * 60 * 1000);
        setInterval(runQueueFlush, 30 * 1000);

        window.addEventListener("online", () => {
            runHeartbeat();
            runEmployeeSync();
            runQueueFlush();
        });
    }

    // Botón "Forzar sincronización" en la pantalla de reposo
    if (btnForceSync) {
        btnForceSync.addEventListener("click", async (event) => {
            event.stopPropagation(); // no disparar exitIdle() (despertaría la cámara sin necesidad)
            btnForceSync.disabled = true;
            updateIdleSyncStatus("Sincronizando...");
            try {
                await heartbeat();
                await syncEmployees();
                await flushQueue();
                await refreshIdleSyncStatus();
            } catch (error) {
                updateIdleSyncStatus(error instanceof TerminalAuthError ? "Terminal sin configurar" : "Error al sincronizar");
            } finally {
                btnForceSync.disabled = false;
            }
        });
    }

    // ============================================================================
    // TEMA CLARO / OSCURO
    // ============================================================================
    (function initTheme() {
        const saved = localStorage.getItem("terminal-theme");
        const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
        const isDark = saved === "dark" || (!saved && prefersDark);
        if (isDark) document.documentElement.setAttribute("data-theme", "dark");
        else document.documentElement.setAttribute("data-theme", "light");
    })();

    if (btnThemeToggle) {
        btnThemeToggle.addEventListener("click", () => {
            const isDark = document.documentElement.getAttribute("data-theme") === "dark";
            const next = isDark ? "light" : "dark";
            document.documentElement.setAttribute("data-theme", next);
            localStorage.setItem("terminal-theme", next);
        });
    }

    // ============================================================================
    // INICIALIZACIÓN
    // ============================================================================
    console.log("Terminal de marcación inicializado");

    // checkLegacyTerminalMigration() no depende de un gesto — es solo informativo,
    // se ejecuta apenas carga la página sin esperar el toque de inicio.
    checkLegacyTerminalMigration();

    // Pantalla de inicio ("Toque para comenzar"): el resto del sistema (cámara,
    // reconocimiento, idle con auto-despertar por presencia) recién arranca
    // después de un toque explícito. Ese primer toque del día es lo que habilita
    // el beep de confirmación (Web Audio) para todas las marcaciones manos-libres
    // que vengan después — sin él, el caso más común (empleado se acerca, se
    // reconoce y se marca solo, sin tocar nada) queda sin ningún sonido de
    // confirmación porque el navegador nunca autorizó el audio.
    const btnStartGate = document.getElementById("btnStartGate");
    if (btnStartGate) {
        btnStartGate.addEventListener("click", () => {
            showScreen("loading");
            initializeSystem();
        }, { once: true });
    } else {
        // Fallback defensivo si el botón no está en el DOM — no debería pasar.
        showScreen("loading");
        initializeSystem();
    }
});
