{{--
    Hoja inferior con las acciones secundarias de mark.js. Reemplaza los
    botones sueltos que antes vivían en el header (tema, instalar) y en
    .sync-status-row (sincronizar, mis marcaciones, pausar cámara,
    desvincular) — mismos IDs que ya maneja mark.js, sin cambios de lógica.
--}}
<div id="menuSheet" class="menu-sheet" aria-hidden="true">
    <div id="menuSheetBackdrop" class="menu-sheet-backdrop"></div>
    <div id="menuSheetPanel" class="menu-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="menuSheetTitle">
        <div class="menu-sheet-handle" aria-hidden="true"></div>
        <h2 id="menuSheetTitle" class="sr-only">Más opciones</h2>

        <span class="sync-status-text" id="syncStatusText" aria-live="polite"></span>

        <button type="button" id="btnSyncNow" class="menu-sheet-item" aria-label="Sincronizar marcaciones pendientes">
            <svg class="menu-sheet-item-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="23 4 23 10 17 10"/>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
            </svg>
            Sincronizar
        </button>

        <button type="button" id="btnMyEvents" class="menu-sheet-item" aria-label="Ver mis marcaciones de hoy">
            <svg class="menu-sheet-item-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            Mis marcaciones
        </button>

        <button type="button" id="btnCameraPause" class="menu-sheet-item" aria-pressed="false" aria-label="Pausar identificación por cámara">
            <svg class="menu-sheet-item-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                <circle cx="12" cy="13" r="4"/>
            </svg>
            <span id="btnCameraPauseLabel">Pausar cámara</span>
        </button>

        <div class="menu-sheet-item menu-sheet-item--theme">
            <x-theme-toggle-button />
            <span class="menu-sheet-item-label">Tema claro/oscuro</span>
        </div>

        <button type="button" id="btnInstallApp" class="menu-sheet-item hidden" aria-label="Instalar aplicación">
            <svg class="menu-sheet-item-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Instalar app
        </button>

        <button type="button" id="btnUnlinkDevice" class="menu-sheet-item menu-sheet-item--danger" aria-label="Desvincular este dispositivo">
            Desvincular dispositivo
        </button>
    </div>
</div>
