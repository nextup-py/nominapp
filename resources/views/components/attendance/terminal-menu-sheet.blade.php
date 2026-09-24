{{--
    Hoja inferior con la información/acciones secundarias del terminal.
    Antes vivían sueltas en el header (dispositivo, conectividad, última
    sincronización, toggle de tema) — acá no hay presión de espacio como en
    el celular, pero se consolidan igual por consistencia visual con
    mark-menu-sheet.blade.php. Mismos IDs que ya maneja
    terminal/sync-status-ui.js y terminal.js, sin cambios de lógica: esos
    módulos buscan los elementos por getElementById en cada llamada, así que
    no les importa dónde vivan en el DOM.

    #btnMenuSync es un botón real (a diferencia del resto de las filas, que
    son solo informativas) — dispara heartbeat+syncEmployees, igual que
    #btnForceSync en la pantalla idle. #terminalHeaderLastSync (el texto de
    "Últ. sync: HH:MM") vive como sub-label dentro del mismo botón.
--}}
<div id="terminalMenuSheet" class="menu-sheet" aria-hidden="true">
    <div id="terminalMenuSheetBackdrop" class="menu-sheet-backdrop"></div>
    <div id="terminalMenuSheetPanel" class="menu-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="terminalMenuSheetTitle">
        <div class="menu-sheet-handle" aria-hidden="true"></div>
        <h2 id="terminalMenuSheetTitle" class="sr-only">Estado del terminal</h2>

        <div class="menu-sheet-info-item">
            <svg class="menu-sheet-item-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="5" y="2" width="14" height="20" rx="2"/>
                <line x1="12" y1="18" x2="12.01" y2="18"/>
            </svg>
            <span id="terminalHeaderDevice" class="menu-sheet-item-label hidden">Dispositivo</span>
        </div>

        <div class="menu-sheet-info-item">
            <svg class="menu-sheet-item-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M5 12.55a11 11 0 0 1 14.08 0"/>
                <path d="M1.42 9a16 16 0 0 1 21.16 0"/>
                <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
                <line x1="12" y1="20" x2="12.01" y2="20"/>
            </svg>
            <span id="connectivityLabel" class="menu-sheet-item-label">En línea</span>
        </div>

        <button type="button" id="btnMenuSync" class="menu-sheet-item" aria-label="Sincronizar ahora">
            <svg id="btnMenuSyncIcon" class="menu-sheet-item-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="23 4 23 10 17 10"/>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
            </svg>
            <span class="menu-sheet-item-label-group">
                <span class="menu-sheet-item-label">Sincronizar ahora</span>
                <span id="terminalHeaderLastSync" class="menu-sheet-item-sublabel hidden"></span>
            </span>
        </button>

        <div class="menu-sheet-item menu-sheet-item--theme">
            <x-theme-toggle-button />
            <span class="menu-sheet-item-label">Tema claro/oscuro</span>
        </div>
    </div>
</div>
