<section id="identificationScreen" class="terminal-screen hidden" role="region" aria-label="Identificación facial">
    <div class="screen-body">
        <div class="identification-layout">
            {{-- Envoltorio que fusiona visualmente el video y el panel de estado en un solo
            bloque (borde/sombra compartidos) — el video conserva su propio aspect-ratio +
            overflow:hidden sin cambios, el panel de estado es un hermano debajo que toma el
            color de estado vía combinador de hermanos (ver .video-wrapper--* ~ .identification-status
            en terminal.css), ya que setTerminalVideoState() solo togglea clases en #terminalVideoWrap. --}}
            <div class="identification-frame">
                <div class="video-wrapper" id="terminalVideoWrap">
                    <video id="terminalVideo" autoplay playsinline muted disablepictureinpicture
                        aria-label="Vista previa de la cámara">
                        Tu navegador no soporta video HTML5.
                    </video>
                    <canvas id="terminalOverlay" aria-hidden="true"></canvas>
                    <div class="terminal-blur-overlay" aria-hidden="true"></div>
                    <div class="terminal-face-guide" aria-hidden="true">
                        <div class="terminal-face-oval"></div>
                    </div>
                    {{-- Marco de escaneo — corchetes en las esquinas del óvalo guía, en vez del
                    anillo con glow que rodeaba todo el marco de video. --}}
                    <span class="terminal-scan-corner terminal-scan-corner--tl" aria-hidden="true"></span>
                    <span class="terminal-scan-corner terminal-scan-corner--tr" aria-hidden="true"></span>
                    <span class="terminal-scan-corner terminal-scan-corner--bl" aria-hidden="true"></span>
                    <span class="terminal-scan-corner terminal-scan-corner--br" aria-hidden="true"></span>
                    {{-- Progreso de captura superpuesto en la parte inferior del video --}}
                    <div id="terminalCaptureProgress" class="capture-progress hidden" aria-hidden="true">
                        <span class="capture-dot"></span>
                        <span class="capture-dot"></span>
                        <span class="capture-dot"></span>
                        <span class="capture-dot"></span>
                        <span class="capture-dot"></span>
                    </div>
                </div>

                <div class="identification-status" id="identificationStatus" role="status" aria-live="polite">
                    <span class="id-status-dot" id="idStatusDot"></span>
                    <span class="id-status-text">Posicione su rostro dentro del óvalo...</span>
                </div>
            </div>

            {{--
                Aparece después de varios intentos fallidos seguidos (ver
                CONSECUTIVE_FAILURES_FOR_MANUAL_SEARCH en terminal.js) — no reemplaza el
                reconocimiento facial, abre una búsqueda por CI que igual pide confirmar
                con la cámara contra ese único candidato antes de registrar la marcación.
            --}}
            <button type="button" id="btnManualSearch" class="manual-search-link hidden">
                <svg class="manual-search-link-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                ¿No te reconoce? Buscar por CI
            </button>

            {{-- Cancel button kept in DOM for JS compatibility, hidden via CSS --}}
            <button
                type="button"
                id="btnCancelIdentification"
                class="terminal-btn terminal-btn-secondary"
                aria-label="Cancelar y volver a selección de tipo">
                Cancelar
            </button>
        </div>
    </div>
</section>

{{--
    Overlay de búsqueda manual por CI — fallback cuando el reconocimiento facial
    falla repetidamente (mala foto de enrolamiento, ángulo, luz). Elegir un
    candidato acá NO registra la marcación por sí solo: solo acota la
    identificación facial a esa persona (ver terminalState.manualCandidate en
    terminal.js) y vuelve a pedir la cámara para confirmar que es esa persona —
    el rostro sigue siendo el control real, esto solo evita comparar contra
    todos los empleados de la sucursal a la vez.
--}}
<div id="manualSearchOverlay" class="manual-search-overlay hidden" role="dialog" aria-modal="true" aria-label="Buscar por CI">
    <div class="manual-search-card">
        <h2 class="manual-search-title">Buscar por CI</h2>
        <p class="manual-search-subtitle">Vas a tener que confirmar con la cámara igual — esto solo te ayuda a encontrarte en la lista.</p>
        <input
            type="text"
            inputmode="numeric"
            id="manualSearchInput"
            class="manual-search-input"
            placeholder="Ingresá tu número de CI"
            autocomplete="off">
        <div id="manualSearchResults" class="manual-search-results" role="listbox" aria-label="Resultados de búsqueda"></div>
        <p id="manualSearchEmpty" class="manual-search-empty hidden">No se encontraron empleados con ese CI.</p>
        <button type="button" id="btnManualSearchCancel" class="terminal-btn terminal-btn-ghost">Cancelar</button>
    </div>
</div>
