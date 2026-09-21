@props(['sectionId' => 'step2-title'])

<section id="step2Section" class="card hidden" aria-labelledby="{{ $sectionId }}" role="region">
    <div class="card-header">
        <h2 id="{{ $sectionId }}" tabindex="-1" class="sr-only">Paso 2 · Datos de marcación</h2>
        <x-attendance.mark-stepper :active="2" />
    </div>
    <div class="card-body">
        {{-- Barra compacta del empleado identificado --}}
        <div id="step2EmployeeBar" class="step2-employee-bar hidden" role="status">
            <div id="step2Avatar" class="step2-avatar" aria-hidden="true"></div>
            <div class="step2-employee-info">
                <p id="step2Name" class="step2-emp-name"></p>
                <p id="step2Meta" class="step2-emp-meta"></p>
                <p id="step2LastEvent" class="step2-emp-last"></p>
            </div>
            <button id="step2BtnBack" type="button" class="btn-reidentify">Cambiar</button>
        </div>

        {{-- Visual event-type buttons — sync with hidden select via JS --}}
        <div class="event-grid" role="group" aria-label="Tipo de marcación">
            <button type="button" class="event-btn" data-event="check_in"
                aria-pressed="false" disabled aria-disabled="true">
                {{-- Entrada --}}
                <svg class="event-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                    <polyline points="10 17 15 12 10 7"/>
                    <line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
                Entrada
            </button>
            <button type="button" class="event-btn" data-event="break_start"
                aria-pressed="false" disabled aria-disabled="true">
                {{-- Inicio descanso: pausa --}}
                <svg class="event-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="6" y="4" width="4" height="16"/>
                    <rect x="14" y="4" width="4" height="16"/>
                </svg>
                Inicio descanso
            </button>
            <button type="button" class="event-btn" data-event="break_end"
                aria-pressed="false" disabled aria-disabled="true">
                {{-- Fin descanso: play --}}
                <svg class="event-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="5 3 19 12 5 21 5 3"/>
                </svg>
                Fin descanso
            </button>
            <button type="button" class="event-btn" data-event="check_out"
                aria-pressed="false" disabled aria-disabled="true">
                {{-- Salida --}}
                <svg class="event-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                Salida
            </button>
        </div>

        {{-- Hidden select preserved for JS compatibility --}}
        <select id="eventType" aria-hidden="true" tabindex="-1" disabled style="display:none">
            <option value="">— primero identifícate —</option>
        </select>

        {{-- Location status row --}}
        <div class="location-row" id="locationRow" role="status" aria-live="polite">
            <div class="location-header">
                <svg class="location-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/>
                    <circle cx="12" cy="9" r="2.5"/>
                </svg>
                <span class="location-status" id="locationStatus">Solicitando ubicación...</span>
                <span class="location-coords" id="locationCoords"></span>
                <button id="btnGeoRetry" type="button" class="btn-geo-retry hidden"
                    aria-label="Actualizar ubicación GPS">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polyline points="23 4 23 10 17 10"/>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                    </svg>
                    Actualizar
                </button>
            </div>
            <div id="locationMap" class="location-map hidden" aria-hidden="true"></div>
        </div>

        <button id="btnMark" type="button" class="btn btn-primary btn-full"
            aria-label="Confirmar y registrar marcación"
            disabled
            aria-disabled="true">
            Confirmar marcación
        </button>

        <p id="markHint" class="mark-hint hidden" aria-live="polite"></p>
    </div>
</section>
