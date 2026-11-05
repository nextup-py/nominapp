<section id="typeSelectionScreen" class="terminal-screen hidden" role="region" aria-label="Selección de tipo de marcación">
    <div class="screen-body">
        <p class="screen-eyebrow" id="typeSelectionEyebrow">Empleado verificado</p>
        <h1 class="screen-title" id="typeSelectionTitle">Marcación</h1>
        <p class="screen-subtitle">Seleccione el tipo de marcación</p>
        {{-- Recordatorio de la última marcación conocida — ayuda a elegir entre opciones
             parecidas (ej. "Fin descanso" vs. "Salida") sin tener que recordarlo de memoria.
             Vacío/oculto si no hay una marcación previa hoy. --}}
        <p class="type-selection-last-mark hidden" id="typeSelectionLastMark"></p>

        <div class="type-grid">
            <button
                type="button"
                class="terminal-type-btn btn-check-in"
                data-event-type="check_in"
                aria-label="Marcar entrada">
                <span class="type-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                        <polyline points="10 17 15 12 10 7"/>
                        <line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                </span>
                <span class="btn-text">Entrada</span>
            </button>

            <button
                type="button"
                class="terminal-type-btn btn-break-start"
                data-event-type="break_start"
                aria-label="Marcar inicio de descanso">
                <span class="type-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="6" y="4" width="4" height="16"/>
                        <rect x="14" y="4" width="4" height="16"/>
                    </svg>
                </span>
                <span class="btn-text">Inicio descanso</span>
            </button>

            <button
                type="button"
                class="terminal-type-btn btn-break-end"
                data-event-type="break_end"
                aria-label="Marcar fin de descanso">
                <span class="type-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="5 3 19 12 5 21 5 3"/>
                    </svg>
                </span>
                <span class="btn-text">Fin descanso</span>
            </button>

            <button
                type="button"
                class="terminal-type-btn btn-check-out"
                data-event-type="check_out"
                aria-label="Marcar salida">
                <span class="type-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                </span>
                <span class="btn-text">Salida</span>
            </button>
        </div>

        {{-- Cuenta regresiva y salida: si nadie toca un botón, vuelve a la cámara sin registrar nada.
             Evita que la salida o un descanso se registren solos al pasar frente al terminal. --}}
        <p class="type-selection-countdown" id="typeSelectionCountdown" aria-live="polite"></p>
        <button type="button" class="type-selection-cancel" id="btnTypeCancel">Cancelar</button>
    </div>
</section>

