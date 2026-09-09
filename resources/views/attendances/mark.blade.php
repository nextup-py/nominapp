<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <x-favicon-links />
    @vite('resources/css/attendances/styles.css')
    <x-theme-vars />
    <x-pwa-meta manifest-url="{{ route('mark.manifest') }}" app-title="Nominapp Marcación" />
    @vite('resources/js/attendances/mark.js')
</head>

<body>
    <x-skip-links />

    <noscript>
        <div class="no-js-warning">Esta página requiere JavaScript para funcionar correctamente.</div>
    </noscript>

    <x-attendance.mark-loading />

    {{-- Splash: requiere toque del usuario para desbloquear audio/vibración y arrancar cámara --}}
    <div id="splashOverlay" class="splash-overlay" aria-label="Pantalla de bienvenida">
        <div class="splash-body">

            <div class="splash-top">
                <p class="splash-greeting" id="splashGreeting"></p>
                <p class="splash-time"     id="splashTime"></p>
                <p class="splash-date"     id="splashDate"></p>
            </div>

            {{-- Última marcación de hoy — datos ya cacheados en el dispositivo (mismo
            camino que "Mis marcaciones"), se completa apenas resuelve sin bloquear
            la pantalla. Oculto hasta tener una respuesta para no mostrar "cargando". --}}
            <p id="splashStatus" class="splash-status hidden" aria-live="polite"></p>

            <div class="splash-center">
                <div class="splash-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 3H5a2 2 0 0 0-2 2v4m0 6v4a2 2 0 0 0 2 2h4m6-18h4a2 2 0 0 1 2 2v4m0 6v4a2 2 0 0 1-2 2h-4"/>
                        <circle cx="12" cy="10" r="2"/>
                        <path d="M9 16c0 1.657 1.343 3 3 3s3-1.343 3-3"/>
                    </svg>
                </div>
                <p class="splash-title">Registro de Asistencia</p>
                <p class="splash-hint">Posicionate frente a la cámara<br>para identificarte automáticamente</p>
            </div>

            <div class="splash-bottom">
                <button id="splashBtn" type="button" class="splash-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                        <circle cx="12" cy="13" r="4"/>
                    </svg>
                    Identificarme
                </button>
            </div>

        </div>
    </div>

    <header class="app-header">
        <div class="app-header-brand">
            <span class="app-mode-badge">Marcación Facial</span>
            <img id="headerLogo" class="header-logo hidden" alt="">
            <span id="headerLocation" class="app-location-badge"></span>
            <button type="button" id="btnThemeToggle" class="theme-toggle" aria-label="Cambiar tema claro/oscuro">
                <svg class="icon-moon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
                <svg class="icon-sun" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
            </button>
            <button type="button" id="btnInstallApp" class="install-toggle hidden" aria-label="Instalar aplicación">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
            </button>
        </div>
        <div class="app-clock" id="headerClock" aria-live="off" aria-label="Hora actual"></div>
    </header>

    <div id="offlineBanner" class="offline-banner" role="alert" aria-live="assertive" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <line x1="1" y1="1" x2="23" y2="23"/>
            <path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/>
            <path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/>
            <path d="M10.71 5.05A16 16 0 0 1 22.56 9"/>
            <path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/>
            <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
            <circle cx="12" cy="20" r="1" fill="currentColor"/>
        </svg>
        <span>Sin conexión — la marcación se guarda en el dispositivo y se sincroniza al reconectar</span>
    </div>

    {{-- Conflicto de sincronización: una marcación encolada offline fue rechazada por el
    servidor al reconectar (ej. secuencia inválida) — no es accionable por el empleado, solo
    informativo (RRHH la revisa en AttendanceMarkFailureResource); se puede cerrar el aviso. --}}
    <div id="conflictBanner" class="conflict-banner" role="alert" aria-live="polite" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <span>Una marcación reciente no pudo confirmarse — RRHH la va a revisar.</span>
        <button type="button" id="btnDismissConflict" class="conflict-dismiss-btn">Entendido</button>
    </div>

    <div id="installBanner" class="install-banner" role="status" aria-live="polite" aria-hidden="true">
        <span id="installBannerText">Instalá esta app en tu pantalla de inicio para acceso rápido</span>
        <button type="button" id="btnInstallNow" class="install-banner-btn">Instalar</button>
        <button type="button" id="btnDismissInstall" class="install-banner-dismiss">Ahora no</button>
    </div>

    {{-- Estado de sincronización offline + sync manual — paridad con el botón "Sincronizar"
    del terminal (terminal-idle.blade.php). --}}
    <div class="sync-status-row" id="syncStatusRow">
        <span class="sync-status-text" id="syncStatusText" aria-live="polite"></span>
        <button type="button" id="btnSyncNow" class="sync-status-btn" aria-label="Sincronizar marcaciones pendientes">
            Sincronizar
        </button>
        <button type="button" id="btnMyEvents" class="sync-status-btn" aria-label="Ver mis marcaciones de hoy">
            Mis marcaciones
        </button>
        {{-- Pausa manual de la cámara — apaga la detección facial para que el empleado pueda
        usar Sincronizar/Mis marcaciones/Desvincular sin riesgo de que el dwell lo identifique
        de encima mientras tanto. Ver cameraPausedOverlay en video-section.blade.php. --}}
        <button type="button" id="btnCameraPause" class="sync-status-btn sync-status-btn--camera-pause"
            aria-pressed="false" aria-label="Pausar identificación por cámara">
            <svg class="camera-pause-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                <circle cx="12" cy="13" r="4"/>
            </svg>
            <span id="btnCameraPauseLabel">Pausar cámara</span>
        </button>
        <button type="button" id="btnUnlinkDevice" class="sync-status-btn sync-status-btn--unlink" aria-label="Desvincular este dispositivo">
            Desvincular dispositivo
        </button>
    </div>

    <main id="main-content" class="page-wrapper">
        <div class="main-grid">
            <x-attendance.video-section />
            <x-attendance.form-section />
        </div>
    </main>

    <x-modal
        id="successModal"
        type="success"
        title="Marcación registrada"
        description="Su marcación se ha registrado correctamente."
        buttonText="Listo"
    />

    {{-- Mis marcaciones de hoy — no requiere pasar por la cámara: el dispositivo ya
    sabe de quién es (vinculación 1:1), así que se consulta directo al abrir. --}}
    <div id="myEventsModal" class="modal hidden" role="dialog" aria-modal="true"
        aria-labelledby="myEventsModalTitle">
        <div class="modal-content modal-info">
            <div class="modal-icon modal-icon--info" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <h2 id="myEventsModalTitle">Mis marcaciones de hoy</h2>

            <ul id="myEventsList" class="my-events-list" aria-live="polite"></ul>
            <p id="myEventsEmpty" class="my-events-status hidden">Todavía no tenés marcaciones registradas hoy.</p>
            <p id="myEventsOffline" class="my-events-status hidden">No se pudo consultar sin conexión — probá de nuevo cuando tengas internet.</p>
            <p id="myEventsLoading" class="my-events-status hidden">Consultando...</p>

            <div class="modal-actions">
                <button id="closeMyEventsModal" type="button" class="btn-modal-dismiss">Cerrar</button>
            </div>
        </div>
    </div>

    <div id="errorModal" class="modal hidden" role="dialog" aria-modal="true"
        aria-labelledby="errorModalTitle" aria-describedby="errorModalDesc">
        <div class="modal-content modal-error">
            <div class="modal-icon modal-icon--error" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <h2 id="errorModalTitle">Error al registrar</h2>
            <p id="errorModalDesc"></p>
            <div class="modal-actions">
                <button id="retryErrorModal" type="button" class="btn-modal-retry">Reintentar</button>
                <button id="closeErrorModal" type="button" class="btn-modal-dismiss">
                    Recargar página
                    <span class="btn-modal-dismiss-hint">Solo si el problema persiste</span>
                </button>
            </div>
        </div>
    </div>

    <script defer src="{{ asset('js/face-api.min.js') }}"></script>

    {{-- Service worker offline — scope acotado a /marcar (sin barra final: la ruta
    real no la tiene), no afecta /vincular-dispositivo ni el resto de la app. --}}
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js', { scope: '/marcar' })
                .catch((error) => console.warn('No se pudo registrar el service worker:', error));
        }
    </script>
</body>

</html>
