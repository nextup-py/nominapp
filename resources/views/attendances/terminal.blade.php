<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <x-favicon-links />
    @vite('resources/css/attendances/terminal.css')
    <x-theme-vars />
    @isset($terminal)
        <x-pwa-meta manifest-url="{{ route('terminal.manifest', $terminal->code) }}" app-title="Nominapp Terminal" />
    @endisset
    @vite('resources/js/attendances/terminal.js')
</head>

<body>
    <noscript>
        <div class="no-js-warning">Esta página requiere JavaScript para funcionar correctamente.</div>
    </noscript>

    <div class="terminal-container">
        <header class="terminal-header">
            <div class="terminal-header-brand">
                <img id="terminalHeaderLogo" class="header-logo hidden" alt="">
                <div class="terminal-header-titles">
                    <span class="terminal-mode-badge">Modo Terminal</span>
                    <span id="terminalHeaderLocation" class="terminal-location-badge"></span>
                    <div class="terminal-header-meta" id="terminalHeaderMeta">
                        <span id="terminalHeaderDevice" class="terminal-meta-item hidden"></span>
                        <span class="terminal-meta-item terminal-connectivity" id="terminalConnectivity">
                            <span class="connectivity-dot" id="connectivityDot" aria-hidden="true"></span>
                            <span id="connectivityLabel">En línea</span>
                        </span>
                        <span id="terminalHeaderLastSync" class="terminal-meta-item hidden"></span>
                    </div>
                </div>
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
            </div>
            <div class="terminal-clock" id="terminalHeaderClock" aria-live="off" aria-label="Hora actual">--:--:--</div>
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
            <span>Sin conexión — las marcaciones no pueden registrarse</span>
        </div>

        {{--
            Solo puede mostrarse en /terminal (legacy, sin código) — si $terminal ya
            está presente (arquitectura actual, /terminal/{code}), no hay nada que migrar.
            El JS decide si corresponde mostrarlo: busca el terminal_code ya guardado en
            IndexedDB por una provisión anterior y arma el link a la URL correcta, sin
            necesidad de que un admin busque el código en Filament.
        --}}
        @empty($terminal)
        <div id="legacyMigrationBanner" class="legacy-migration-banner" role="alert" aria-live="polite" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 9v4"/><path d="M12 17h.01"/>
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
            </svg>
            <span>Esta URL quedará fuera de servicio. Actualizá el acceso de este dispositivo a: <a id="legacyMigrationLink" href="#"></a></span>
        </div>
        @endempty

        <main id="main-content">
            <x-attendance.terminal-start-gate />
            <x-attendance.terminal-loading />
            <x-attendance.terminal-idle />
            <x-attendance.terminal-type-selector />
            <x-attendance.terminal-video-section />
            <x-attendance.terminal-success />
        </main>
    </div>

    <script defer src="{{ asset('js/face-api.min.js') }}"></script>
    @isset($terminal)
    {{-- Datos de la terminal identificada — disponibles en window.terminalData para el JS --}}
    <script>
        window.terminalData = {
            id: {{ $terminal->id }},
            code: @json($terminal->code),
            name: @json($terminal->name),
            branch_id: {{ $terminal->branch_id ?? 'null' }},
            branch_name: @json($terminal->branch?->name),
            company_name: @json($terminal->branch?->company?->name),
            company_logo: @json($terminal->branch?->company?->logo_thumbnail),
            device_brand: @json($terminal->device_brand),
            device_model: @json($terminal->device_model),
        };
    </script>
    @endisset

    {{-- Service worker offline — scope acotado a /terminal/, no afecta el resto de la app --}}
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js', { scope: '/terminal/' })
                .catch((error) => console.warn('No se pudo registrar el service worker:', error));
        }
    </script>
</body>

</html>
