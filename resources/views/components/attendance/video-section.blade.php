@props(['sectionId' => 'step1-title'])

<section id="step1Section" class="card" aria-labelledby="{{ $sectionId }}" role="region">
    <div class="card-header">
        <h2 id="{{ $sectionId }}" tabindex="-1" class="sr-only">Paso 1 · Identificación</h2>
        <x-attendance.mark-stepper :active="1" />
    </div>
    <div class="card-body">
        <div class="video-wrap" id="videoWrap" role="img" aria-label="Área de captura de video para reconocimiento facial">
            <video id="video" autoplay playsinline muted disablepictureinpicture
                aria-label="Vista previa de la cámara">
                Tu navegador no soporta video HTML5.
            </video>
            <canvas id="overlay" aria-hidden="true"></canvas>
            <div class="video-blur-overlay" aria-hidden="true"></div>
            <div class="face-guide" aria-hidden="true">
                <div class="face-guide-oval"></div>
            </div>
            <span class="scan-corner scan-corner--tl" aria-hidden="true"></span>
            <span class="scan-corner scan-corner--tr" aria-hidden="true"></span>
            <span class="scan-corner scan-corner--bl" aria-hidden="true"></span>
            <span class="scan-corner scan-corner--br" aria-hidden="true"></span>

            {{-- Dots de progreso de captura — sobre el video, debajo del óvalo --}}
            <div id="captureProgress" class="capture-progress hidden" aria-hidden="true">
                <span class="capture-dot"></span>
                <span class="capture-dot"></span>
                <span class="capture-dot"></span>
                <span class="capture-dot"></span>
                <span class="capture-dot"></span>
            </div>

            {{-- Fallback para iOS/Safari: solo visible si auto-start falla --}}
            <button id="cameraFallback" type="button" class="camera-fallback hidden"
                aria-label="Toca para activar la cámara">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                    <circle cx="12" cy="13" r="4"/>
                </svg>
                <span>Toca para activar la cámara</span>
            </button>

            {{-- Pausa manual de cámara (btnCameraPause en el header) — tocar el overlay
            también reanuda, además del switch de arriba. --}}
            <button id="cameraPausedOverlay" type="button" class="camera-paused-overlay hidden"
                aria-label="Cámara en pausa — tocá para reanudar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M1 1l22 22"/>
                    <path d="M21 21H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l.65.98"/>
                    <path d="M23 8v11a2 2 0 0 1-1.11 1.79"/>
                    <path d="M14.12 9.88a4 4 0 0 1 1.87 3.62"/>
                    <path d="M9.88 14.12A4 4 0 0 1 9 12"/>
                </svg>
                <span class="camera-paused-title">Cámara en pausa</span>
                <span class="camera-paused-hint">Tocá para reanudar la identificación</span>
            </button>
        </div>

        <div class="status-bar" id="statusBar" role="status" aria-live="polite">
            <span class="status-dot" id="statusDot"></span>
            <span class="status-text" id="statusText">Inicie la cámara para comenzar</span>
        </div>

        {{-- Banner de advertencia GPS — visible si la ubicación falló en segundo plano --}}
        <div id="gpsBanner" class="gps-banner hidden" role="alert">
            <svg class="gps-banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <span id="gpsBannerText" class="gps-banner-text"></span>
            <button id="gpsBannerRetry" type="button" class="gps-banner-btn">Reintentar</button>
        </div>

<p class="sr-only" id="step1-desc">
            Posicione su rostro dentro del óvalo para identificarse automáticamente.
        </p>
    </div>
</section>
