// resources/js/attendances/terminal.js
import * as camera from './terminal/camera.js';
import * as idleDetection from './terminal/idle-detection.js';
import * as screenState from './terminal/screen-state.js';
import * as markRegistration from './terminal/mark-registration.js';
import * as identificationFlow from './terminal/identification-flow.js';
import * as bootstrap from './terminal/bootstrap.js';
import { updateClock, updateIdleDate } from './terminal/ui-feedback.js';
import { setOffline, updateIdleSyncStatus, refreshIdleSyncStatus } from './terminal/sync-status-ui.js';
import { initManualSearch } from './terminal/manual-search.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { heartbeat, syncEmployees, TerminalAuthError } from './terminal-offline/sync.js';
import { flushQueue } from './terminal-offline/queue.js';

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
