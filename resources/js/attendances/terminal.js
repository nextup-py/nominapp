// resources/js/attendances/terminal.js
import * as idleDetection from './terminal/idle-detection.js';
import * as screenState from './terminal/screen-state.js';
import * as markRegistration from './terminal/mark-registration.js';
import * as identificationFlow from './terminal/identification-flow.js';
import * as bootstrap from './terminal/bootstrap.js';
import * as pairingFlow from './terminal/pairing-flow.js';
import { createEmptyStateController, HR_NOTICE } from './terminal/empty-state.js';
import { updateClock, updateIdleDate } from './terminal/ui-feedback.js';
import { setOffline, updateIdleSyncStatus, setSyncRefreshHook } from './terminal/sync-status-ui.js';
import { createSyncControl } from './terminal/sync-control.js';
import { initManualSearch } from './terminal/manual-search.js';
import { createCameraControl } from './terminal/camera-control.js';
import { createInstallNotice } from './terminal/install-notice.js';
import { createPrivacyNotice } from './terminal/privacy-notice.js';
import { getCameraPrefs, setCameraPrefs, listVideoInputs } from './terminal/camera-prefs.js';
import { initThemeToggle } from '../shared/theme-toggle.js';
import { captureInstallPrompt, triggerInstallPrompt, isDismissed, dismiss } from '../shared/install-prompt.js';
import { createMenuSheet } from '../shared/menu-sheet.js';
import { heartbeat, syncEmployees, TerminalAuthError } from './terminal-offline/sync.js';
import { detectDisplayMode } from './terminal-offline/device-report.js';
import { flushQueue, countPendingEvents, countConflictEvents } from './terminal-offline/queue.js';
import { getMeta, countCachedEmployees } from './terminal-offline/db.js';

document.addEventListener('DOMContentLoaded', () => {
    // ============================================================================
    // ELEMENTOS DEL DOM
    // ============================================================================
    const screens = {
        startGate:       document.getElementById('startGateScreen'),
        loading:         document.getElementById('loadingScreen'),
        idle:            document.getElementById('idleScreen'),
        typeSelection:   document.getElementById('typeSelectionScreen'),
        identification:  document.getElementById('identificationScreen'),
        success:         document.getElementById('successScreen'),
        dayComplete:     document.getElementById('dayCompleteScreen'),
        error:           document.getElementById('errorScreen'),
        legacyMigration: document.getElementById('legacyMigrationScreen'),
        unlinked:        document.getElementById('unlinkedScreen'),
        empty:           document.getElementById('emptyScreen'),
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
        successExitReminder:  document.getElementById('successExitReminder'),
        successReviewNotice:  document.getElementById('successReviewNotice'),
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

    const idleCompanyLogo = document.getElementById('idleCompanyLogo');
    if (terminalData && idleCompanyLogo && terminalData.company_logo) {
        idleCompanyLogo.src = terminalData.company_logo;
        idleCompanyLogo.classList.remove('hidden');
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

    /** Guarda re-entrancy de exitIdle() — evita arrancar una segunda auto-identificación
     *  si dos disparadores casi simultáneos (touch + presence-check) llaman a exitIdle()
     *  antes de que el primero termine de salir del modo reposo. */
    let isIdle = false;

    /** Bundle de refs pasado a identification-flow.js — un único objeto reutilizado en cada llamada. */
    bootstrap.setBusyProbe(() => identificationFlow.isIdentificationBusy() || Boolean(markRegistration.getPendingEmployee()));

    const identificationRefs = {
        screens, video, overlay, ctx, identificationStatus,
        successDom, errorMessageEl, dayCompleteDom, typeSelectionDom,
        onIdleTimeout: enterIdle,
        onUnlinked: (reason, storedCode) => showUnlinked(reason, storedCode),
    };

    // ============================================================================
    // WAKE LOCK — pedirlo al cargar (sin esperar el toque de «Comenzar») y mantenerlo:
    // el vigía reintenta cada minuto, al volver la pestaña y en la primera interacción
    // ============================================================================
    idleDetection.startWakeLockKeeper();

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
        isIdle = true;
        updateIdleDate();
        screenState.showScreen(screens, 'idle');
        if (!cameraControl.isPaused()) idleDetection.startPresenceCheck(video, exitIdle);
        if (terminalHeader) terminalHeader.classList.add('terminal-header--idle');
    }

    function exitIdle() {
        if (!isIdle) return;
        isIdle = false;
        idleDetection.stopPresenceCheck();
        if (terminalHeader) terminalHeader.classList.remove('terminal-header--idle');
        identificationFlow.startIdentificationFlow(identificationRefs, { onIdleTimeout: enterIdle });
    }

    /** El código se pide solo una vez por carga de página: tras vencer, renovarlo es manual ("Pedir código nuevo"),
     *  porque el sync de fondo sigue disparando showUnlinked() y cada código nuevo notifica a los admins. */
    let pairingAutoStarted = false;

    /** Callbacks de UI de la vinculación por código (ver terminal/pairing-flow.js). */
    const pairingBlock = document.getElementById('pairingBlock');
    const pairingUi = {
        showCode: (code) => {
            const el = document.getElementById('pairingCode');
            if (el) el.textContent = code;
        },
        setStatus: (text) => {
            const el = document.getElementById('pairingStatus');
            if (el) el.textContent = text;
        },
        setCountdown: (seconds) => {
            const el = document.getElementById('pairingCountdown');
            if (!el) return;
            const mm = String(Math.floor(seconds / 60)).padStart(2, '0');
            const ss = String(seconds % 60).padStart(2, '0');
            el.textContent = seconds > 0 ? `El código vence en ${mm}:${ss}` : '';
        },
        showRetry: (visible) => {
            document.getElementById('btnPairingNew')?.classList.toggle('hidden', !visible);
        },
        showInactive: (message) => {
            pairingBlock?.classList.add('hidden');
            document.getElementById('unlinkedLinkHint')?.classList.add('hidden');
            const detail = document.getElementById('unlinkedDetail');
            if (detail) detail.textContent = message;
        },
        onLinked: () => window.location.reload(),
    };

    /**
     * Bloquea la marcación: detiene cámara, presencia, timers y countdowns, y
     * deja la pantalla "sin vincular". El latch vive en identification-flow
     * (`blockIdentification`), así ningún countdown ni reset vuelve a arrancar
     * la identificación. Se llama al arrancar sin token, ante una revocación en
     * caliente (sync en segundo plano / botones de sync) o al identificar sin token.
     *
     * Sin token (`no_token`/`revoked`) y con código de terminal en la URL, además
     * pide un código de emparejamiento para que un admin lo apruebe — el terminal
     * no queda mudo esperando un enlace. Con `other_terminal` (el navegador
     * pertenece a otro terminal) no se vincula nada: el usuario debe volver a esa URL.
     * @param {'no_token'|'other_terminal'|'revoked'|string} reason
     * @param {string|null} [storedCode] - code del terminal que tenía este navegador (reason 'other_terminal')
     */
    function showUnlinked(reason, storedCode = null) {
        idleDetection.clearIdleTimer();
        idleDetection.stopPresenceCheck();
        screenState.stopCountdown();
        markRegistration.clearPendingEmployee();
        identificationFlow.blockIdentification(video);
        isIdle = false;
        if (terminalHeader) terminalHeader.classList.remove('terminal-header--idle');

        const canPair = (reason === 'no_token' || reason === 'revoked') && Boolean(terminalData?.code);

        const detail = document.getElementById('unlinkedDetail');
        if (detail) {
            detail.textContent = reason === 'other_terminal'
                ? `Este navegador está configurado como otro terminal${storedCode ? ` ("${storedCode}")` : ''}. Abrí /terminal/${storedCode ?? '{código}'} o pedí un enlace para este.`
                : reason === 'revoked'
                    ? 'El acceso de este terminal fue revocado.'
                    : 'Este terminal no está vinculado.';
        }

        pairingBlock?.classList.toggle('hidden', !canPair);
        // Con código de emparejamiento a la vista, "pedí un enlace" sobra: el admin aprueba el código.
        document.getElementById('unlinkedLinkHint')?.classList.toggle('hidden', canPair);
        screenState.showScreen(screens, 'unlinked');

        if (canPair && !pairingAutoStarted) {
            pairingAutoStarted = true;
            pairingFlow.getClientHintModel().then((deviceModelHint) => {
                pairingFlow.startPairing(pairingUi, { terminalCode: terminalData.code, deviceModelHint });
            });
        }
    }

    // ============================================================================
    // SIN EMPLEADOS — cámara apagada + pantalla que explica la causa (ver terminal/empty-state.js)
    // ============================================================================
    const emptyState = createEmptyStateController({
        isSuspended: () => identificationFlow.isIdentificationBlocked(),
        getInputs: async () => ({
            cachedCount: await countCachedEmployees(),
            syncedAt: await getMeta('employees_synced_at'),
            online: navigator.onLine,
            roster: await getMeta('branch_roster'),
        }),
        onBlock: (state) => {
            idleDetection.clearIdleTimer();
            idleDetection.stopPresenceCheck();
            identificationFlow.stopAutoIdentification(video);
            screenState.stopCountdown();
            markRegistration.clearPendingEmployee();
            isIdle = false;
            if (terminalHeader) terminalHeader.classList.remove('terminal-header--idle');
            renderEmptyState(state);
            screenState.showScreen(screens, 'empty');
        },
        onUpdate: (state) => renderEmptyState(state),
        onResume: () => identificationFlow.startIdentificationFlow(identificationRefs, { onIdleTimeout: enterIdle }),
        sync: async () => {
            try {
                await heartbeat();
                await syncEmployees();
            } catch (error) {
                if (error instanceof TerminalAuthError) showUnlinked('revoked');
                throw error;
            }
        },
        onTick: (secondsLeft, syncing) => {
            const el = document.getElementById('emptyRetry');
            if (el) el.textContent = syncing ? 'Sincronizando…' : `Se vuelve a intentar en ${secondsLeft} s`;
            const btn = document.getElementById('btnEmptySync');
            if (btn) btn.disabled = syncing;
        },
    });
    identificationFlow.setStartGuard(() => emptyState.isBlocked());

    /** Completa la pantalla "sin empleados" con los textos de la causa. */
    function renderEmptyState(state) {
        const set = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
        set('emptyHeadline', state.headline);
        set('emptyMessage', state.message);
        set('emptyDetailText', state.detail);
        set('emptyTerminalCode', terminalData?.code ?? '—');
        set('emptyBranchName', terminalData?.branch_name ?? '—');
        const hr = document.getElementById('emptyHrNotice');
        if (hr) {
            hr.textContent = HR_NOTICE;
            hr.classList.toggle('hidden', !state.notifiesHr);
        }
    }

    document.getElementById('btnEmptySync')?.addEventListener('click', () => emptyState.retryNow());

    // ============================================================================
    // CÁMARA — apagado manual, selector y espejo (menú ⋮)
    // ============================================================================
    const cameraNotice = document.getElementById('cameraOffNotice');
    const cameraResumeEl = document.getElementById('cameraOffResume');
    const btnMenuCamera = document.getElementById('btnMenuCamera');
    const cameraSub = document.getElementById('btnMenuCameraSub');
    const videoWrap = document.getElementById('terminalVideoWrap');

    /** Texto "Se enciende sola a las HH:MM" (vacío si no hay reencendido automático). */
    const resumeText = (resumeAt) => resumeAt
        ? `Se enciende sola a las ${new Date(resumeAt).toLocaleTimeString('es-PY', { hour: '2-digit', minute: '2-digit' })}`
        : '';

    const cameraControl = createCameraControl({
        getResumeMinutes: async () => (await getMeta('camera_resume_minutes')) ?? 2,
        onChange: ({ paused, resumeAt }) => {
            const text = resumeText(resumeAt);
            cameraNotice?.classList.toggle('hidden', !paused);
            if (cameraResumeEl) cameraResumeEl.textContent = text;
            btnMenuCamera?.setAttribute('aria-pressed', String(paused));
            const label = document.getElementById('btnMenuCameraLabel');
            if (label) label.textContent = paused ? 'Encender cámara' : 'Apagar cámara';
            if (cameraSub) {
                cameraSub.textContent = text;
                cameraSub.classList.toggle('hidden', !text);
            }

            if (paused) {
                identificationFlow.stopAutoIdentification(video);
                idleDetection.stopPresenceCheck();
                const statusTextEl = identificationStatus?.querySelector('.id-status-text');
                if (statusTextEl) statusTextEl.textContent = 'Cámara apagada';
            } else if (isIdle) {
                idleDetection.startPresenceCheck(video, exitIdle);
            } else if (!screens.identification?.classList.contains('hidden')) {
                identificationFlow.startAutoIdentification(identificationRefs);
            }
        },
    });
    identificationFlow.setCameraPausedProbe(() => cameraControl.isPaused());

    btnMenuCamera?.addEventListener('click', () => cameraControl.toggle());
    document.getElementById('btnCameraOn')?.addEventListener('click', () => cameraControl.resume());

    const mirrorCheck = document.getElementById('cameraMirror');
    const applyMirror = (mirror) => videoWrap?.classList.toggle('video-wrapper--mirror', mirror);
    applyMirror(getCameraPrefs().mirror);
    if (mirrorCheck) {
        mirrorCheck.checked = getCameraPrefs().mirror;
        mirrorCheck.addEventListener('change', () => {
            setCameraPrefs({ mirror: mirrorCheck.checked });
            applyMirror(mirrorCheck.checked);
        });
    }

    /** Completa el selector de cámara al abrir el menú; se oculta si hay una sola. */
    async function refreshCameraSelect() {
        const select = document.getElementById('cameraSelect');
        const row = document.getElementById('cameraSelectRow');
        if (!select || !row) return;
        const inputs = await listVideoInputs();
        row.classList.toggle('hidden', inputs.length < 2);
        const current = getCameraPrefs().deviceId;
        select.innerHTML = '';
        inputs.forEach((input) => {
            const option = document.createElement('option');
            option.value = input.id;
            option.textContent = input.label;
            option.selected = input.id === current;
            select.appendChild(option);
        });
    }
    document.getElementById('btnTerminalMenu')?.addEventListener('click', refreshCameraSelect);
    document.getElementById('cameraSelect')?.addEventListener('change', (event) => {
        setCameraPrefs({ deviceId: event.target.value || null });
        // Si la cámara estaba en uso, se reabre con la elegida; apagada o en reposo, se usa en el próximo arranque.
        if (!cameraControl.isPaused() && !isIdle && !screens.identification?.classList.contains('hidden')) {
            identificationFlow.stopAutoIdentification(video);
            identificationFlow.startAutoIdentification(identificationRefs);
        }
    });

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

            // Re-entrancy guard: un doble-tap en pantalla táctil no debe encolar
            // dos marcaciones para el mismo empleado — ver mark.js (btnMark.disabled)
            // para el mismo patrón en el modo móvil.
            // «Salida» en la pregunta «¿Entrada o salida?» (olvidó marcar la entrada): va a revisión de RR.HH.
            const exitWithoutEntry = markRegistration.isEntryOrExitPending() && eventType === 'check_out';
            markRegistration.clearPendingEmployee();
            typeButtons.forEach((btn) => { btn.disabled = true; });

            idleDetection.clearIdleTimer();
            const markCallbacks = {
                onStatusUpdate: (text) => {
                    const statusTextEl = identificationStatus?.querySelector('.id-status-text');
                    if (statusTextEl) statusTextEl.textContent = text;
                },
                onSuccess: (emp, markData, evtType, opts) => screenState.showSuccessScreen(
                    screens, successDom, emp, markData, evtType, opts,
                    () => identificationFlow.startIdentificationFlow(identificationRefs, { onIdleTimeout: enterIdle }),
                ),
                onError: (message) => screenState.showError(screens, errorMessageEl, message),
            };

            if (exitWithoutEntry) {
                await markRegistration.registerExitWithoutEntry(employee, markCallbacks);
            } else {
                await markRegistration.registerMark(employee, eventType, markCallbacks);
            }
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
    document.getElementById('btnUnlinkedReload')?.addEventListener('click', () => window.location.reload());
    document.getElementById('btnPairingNew')?.addEventListener('click', async () => {
        if (!terminalData?.code) return;
        await pairingFlow.restartPairing(pairingUi, {
            terminalCode: terminalData.code,
            deviceModelHint: await pairingFlow.getClientHintModel(),
        });
    });

    // ============================================================================
    // CONECTIVIDAD
    // ============================================================================
    setOffline(!navigator.onLine);
    window.addEventListener('offline', () => setOffline(true));
    window.addEventListener('online',  () => setOffline(false));

    bootstrap.initInteractionTracking();

    // ============================================================================
    // SINCRONIZAR — un solo control con resultado claro (ver terminal/sync-control.js)
    // ============================================================================
    const headerSyncBtn = document.getElementById('btnHeaderSync');
    const menuSyncBtn = document.getElementById('btnMenuSync');
    const menuSyncIcon = document.getElementById('btnMenuSyncIcon');
    const menuSyncResult = document.getElementById('terminalHeaderLastSync');
    const menuSyncDetail = document.getElementById('terminalSyncDetail');
    const updateAppButtons = [document.getElementById('btnMenuUpdateApp'), document.getElementById('btnIdleUpdateApp')];

    /** Pinta el resumen de sincronización en todos los lugares donde se muestra. */
    function renderSync(summary) {
        const busy = summary.tone === 'busy';
        updateIdleSyncStatus(summary.headline);
        document.getElementById('idleSyncStatus')?.setAttribute('data-tone', summary.tone);

        [btnForceSync, menuSyncBtn, headerSyncBtn].forEach((btn) => { if (btn) btn.disabled = busy; });
        menuSyncIcon?.classList.toggle('is-syncing', busy);
        headerSyncBtn?.classList.toggle('is-syncing', busy);
        if (headerSyncBtn) {
            if (summary.badge) headerSyncBtn.setAttribute('data-badge', summary.badge);
            else headerSyncBtn.removeAttribute('data-badge');
            headerSyncBtn.title = summary.headline;
        }

        if (menuSyncResult) {
            menuSyncResult.textContent = summary.headline;
            menuSyncResult.setAttribute('data-tone', summary.tone);
            menuSyncResult.classList.remove('hidden');
        }
        if (menuSyncDetail) {
            menuSyncDetail.textContent = summary.detail;
            menuSyncDetail.classList.remove('hidden');
        }
        updateAppButtons.forEach((btn) => btn?.classList.toggle('hidden', !summary.appOutdated));
    }

    const syncControl = createSyncControl({
        run: async () => {
            await heartbeat();
            await syncEmployees();
            await flushQueue();
            await emptyState.refresh();
        },
        getCounts: async () => ({
            pending: await countPendingEvents(),
            conflicts: await countConflictEvents(),
            employees: await countCachedEmployees(),
            lastSyncAt: await getMeta('last_heartbeat_at'),
            serverVersion: await getMeta('server_app_version'),
        }),
        onChange: renderSync,
        isAuthError: (error) => error instanceof TerminalAuthError,
        onAuthError: () => showUnlinked('revoked'),
        currentVersion: window.terminalData?.app_version ?? null,
    });
    // Aviso de privacidad de la empresa: el caché del latido manda; al cargar sirve el dato de la página (ver terminal/privacy-notice.js)
    const privacyNotice = createPrivacyNotice({
        loadNotice: async () => {
            const cached = await getMeta('privacy_notice');
            return cached !== undefined ? cached : (window.terminalData?.privacy ?? null);
        },
        elements: {
            footer: document.getElementById('privacyFooter'),
            identLine: document.getElementById('privacyIdentLine'),
            trigger: document.getElementById('btnPrivacyInfo'),
            modal: document.getElementById('privacyModal'),
            body: document.getElementById('privacyModalBody'),
            qr: document.getElementById('privacyModalQr'),
            link: document.getElementById('privacyModalLink'),
            closeButtons: Array.from(document.querySelectorAll('[data-privacy-close]')),
        },
    });
    privacyNotice.refresh();
    setSyncRefreshHook(() => {
        syncControl.refresh();
        privacyNotice.refresh();
    });

    [btnForceSync, menuSyncBtn, headerSyncBtn].forEach((btn) => {
        btn?.addEventListener('click', (event) => {
            event.stopPropagation();
            syncControl.sync();
        });
    });
    // Recargar es seguro: la cola de marcaciones vive en IndexedDB.
    updateAppButtons.forEach((btn) => btn?.addEventListener('click', (event) => {
        event.stopPropagation();
        window.location.reload();
    }));
    syncControl.refresh();

    // ============================================================================
    // INSTALACIÓN — aviso en reposo si corre en una pestaña y botón «Instalar app» (ver terminal/install-notice.js)
    // ============================================================================
    let installNoticeDismissed = false;
    const installNotice = createInstallNotice({
        getDisplayMode: () => detectDisplayMode(window),
        isDismissed: () => {
            if (installNoticeDismissed) return true;
            try { return isDismissed('terminal', window.sessionStorage); } catch { return false; }
        },
        dismiss: () => {
            installNoticeDismissed = true;
            try { dismiss('terminal', window.sessionStorage); } catch { /* sin storage: se oculta solo en esta carga */ }
        },
        triggerInstall: triggerInstallPrompt,
        elements: {
            notice: document.getElementById('installNotice'),
            text: document.getElementById('installNoticeText'),
            installButtons: [document.getElementById('btnInstallNoticeInstall'), document.getElementById('btnMenuInstallApp')],
            dismissButton: document.getElementById('btnInstallNoticeDismiss'),
        },
    });
    captureInstallPrompt(() => installNotice.setCanInstall(true));
    installNotice.render();

    // ============================================================================
    // TEMA CLARO / OSCURO
    // ============================================================================
    initThemeToggle('terminal-theme');

    // ============================================================================
    // MENÚ ⋮ — estado del terminal (dispositivo, conectividad) + sync + tema
    // ============================================================================
    (function initTerminalMenu() {
        const sheet = document.getElementById('terminalMenuSheet');
        const backdrop = document.getElementById('terminalMenuSheetBackdrop');
        const panel = document.getElementById('terminalMenuSheetPanel');
        const trigger = document.getElementById('btnTerminalMenu');
        if (!sheet || !backdrop || !panel || !trigger) return;

        createMenuSheet({ sheet, backdrop, panel, trigger });
    })();

    // ============================================================================
    // INICIALIZACIÓN
    // ============================================================================
    console.log('Terminal de marcación inicializado');

    const btnStartGate = document.getElementById('btnStartGate');

    // El click de "Comenzar" recién se cablea DESPUÉS de resolver esta verificación —
    // mientras está pendiente, tocar el botón no hace nada (sin listener todavía). Así
    // se evita la ventana en la que un dispositivo que ya necesita migrar podría arrancar
    // igual el flujo normal antes de que se reemplace la pantalla por la de bloqueo.
    bootstrap.checkLegacyTerminalMigration().then(({ needsMigration, url }) => {
        if (needsMigration) {
            const link = document.getElementById('legacyMigrationLink');
            if (link) {
                link.href = url;
                link.textContent = url;
            }
            screenState.showScreen(screens, 'legacyMigration');
            return;
        }

        // Sin esperar el toque: el terminal recargado vuelve a hablar con el servidor y confirma comandos (ver bootstrap.js).
        bootstrap.startOfflineSyncEarly({ onUnlinked: showUnlinked });

        const startSystem = () => {
            screenState.showScreen(screens, 'loading');
            bootstrap.initializeSystem(loadingDom, {
                onReady: async () => {
                    await emptyState.refresh();
                    if (!emptyState.isBlocked()) enterIdle();
                },
                onError: (message) => screenState.showError(screens, errorMessageEl, message),
                onUnlinked: showUnlinked,
            });
        };
        if (btnStartGate) {
            btnStartGate.addEventListener('click', startSystem, { once: true });
        } else {
            startSystem();
        }
    });
});
