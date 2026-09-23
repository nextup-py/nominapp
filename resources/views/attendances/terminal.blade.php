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
                </div>
            </div>
            <div class="terminal-header-right">
                <span class="connectivity-dot" id="connectivityDot" aria-hidden="true"></span>
                {{-- Región viva accesible: el dot es solo visual y el texto vive dentro del
                sheet (aria-hidden mientras está cerrado), así que ninguno de los dos anuncia
                cambios a lectores de pantalla — este span espejo sí lo hace. --}}
                <span id="connectivityLabelSr" class="sr-only" aria-live="polite"></span>
                <button type="button" id="btnTerminalMenu" class="menu-trigger" aria-haspopup="dialog"
                    aria-expanded="false" aria-controls="terminalMenuSheet" aria-label="Más opciones">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="5" r="1.5"/>
                        <circle cx="12" cy="12" r="1.5"/>
                        <circle cx="12" cy="19" r="1.5"/>
                    </svg>
                </button>
                <div class="terminal-clock" id="terminalHeaderClock" aria-live="off" aria-label="Hora actual">--:--:--</div>
            </div>
        </header>

        <x-attendance.terminal-menu-sheet />

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

        <main id="main-content">
            <x-attendance.terminal-start-gate />
            <x-attendance.terminal-loading />
            <x-attendance.terminal-idle />
            <x-attendance.terminal-type-selector />
            <x-attendance.terminal-video-section />
            <x-attendance.terminal-success />
            {{--
                Solo puede mostrarse en /terminal (legacy, sin código) — si $terminal ya
                está presente (arquitectura actual, /terminal/{code}), no hay nada que migrar.
                El JS decide si corresponde: busca el terminal_code ya guardado en IndexedDB
                por una provisión anterior y, si lo encuentra, reemplaza TODA la pantalla por
                esta (bloqueante, sin botón "Comenzar") — la ruta legacy va a darse de baja,
                así que no debe poder seguir usándose para marcar una vez detectada.
            --}}
            @empty($terminal)
                <x-attendance.terminal-legacy-migration />
            @endempty
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
