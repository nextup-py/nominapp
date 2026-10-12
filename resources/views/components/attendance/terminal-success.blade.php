<section id="successScreen" class="terminal-screen hidden" role="region" aria-label="Confirmación de marcación">
    <div class="screen-body">
        <div class="result-card result-card--success">
            <div class="result-icon result-icon--success" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M9 12 L11 14 L15 10" pathLength="1"/>
                </svg>
            </div>

            <h1 class="result-headline success-title">Marcación registrada</h1>

            <div class="employee-photo-wrap">
                <img id="successEmployeePhoto" class="employee-photo" src="" alt="" aria-hidden="true">
            </div>

            <div class="employee-name" id="successEmployeeName"></div>
            <div class="employee-detail" id="successEmployeeCI"></div>

            <div class="mark-stats" aria-label="Detalle de la marcación">
                <div class="mark-stat">
                    <span class="mark-stat-label">Tipo</span>
                    <span class="mark-stat-value" id="successEventType"></span>
                </div>
                <div class="mark-stat-divider" aria-hidden="true"></div>
                <div class="mark-stat">
                    <span class="mark-stat-label">Hora</span>
                    <span class="mark-stat-value mark-stat-time" id="successTime"></span>
                </div>
            </div>

            <p class="success-queued-notice" id="successQueuedNotice" style="display:none" role="status">
                Guardado en el dispositivo — se sincronizará cuando haya conexión.
            </p>

            {{-- Recordatorio de salida: solo tras una entrada, con la hora de fin del turno informada por el servidor --}}
            <p class="success-exit-reminder" id="successExitReminder" style="display:none" role="status"></p>

            <div class="countdown-bar" aria-hidden="true">
                <div class="countdown-fill" id="countdownFill"></div>
            </div>
            <p class="auto-close-message" aria-live="polite">
                Siguiente en <span id="countdown">5</span>s
            </p>

            <div class="terminal-actions">
                <button
                    type="button"
                    id="btnMarkAnother"
                    class="terminal-btn terminal-btn-primary"
                    aria-label="Marcar otra persona inmediatamente">
                    Marcar otra persona
                </button>
            </div>
        </div>
    </div>
</section>

<section id="dayCompleteScreen" class="terminal-screen hidden" role="region" aria-label="Jornada completada">
    <div class="screen-body">
        <div class="result-card result-card--complete">
            <div class="result-icon result-icon--complete" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
            </div>

            <h1 class="result-headline complete-title">¡Hasta mañana!</h1>

            <div class="employee-photo-wrap">
                <img id="dayCompleteEmployeePhoto" class="employee-photo" src="" alt="" aria-hidden="true">
            </div>

            <div class="employee-name" id="dayCompleteEmployeeName"></div>
            <div class="employee-detail complete-subtitle">Tu jornada del día ha sido completada.</div>

            <div class="countdown-bar" aria-hidden="true">
                <div class="countdown-fill" id="dayCompleteCountdownFill"></div>
            </div>
            <p class="auto-close-message" aria-live="polite">
                Volviendo en <span id="dayCompleteCountdown">5</span>s
            </p>
        </div>
    </div>
</section>

<section id="errorScreen" class="terminal-screen hidden" role="region" aria-label="Error en marcación">
    <div class="screen-body">
        <div class="result-card result-card--error">
            <div class="result-icon result-icon--error" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
            </div>

            <h1 class="result-headline error-title">No se pudo registrar</h1>

            <div class="error-message" id="errorMessage" role="alert" aria-live="assertive">
                No se pudo completar la marcación. Por favor, intente nuevamente.
            </div>

            <div class="terminal-actions">
                <button
                    type="button"
                    id="btnRetry"
                    class="terminal-btn terminal-btn-primary"
                    aria-label="Intentar nuevamente">
                    Intentar nuevamente
                </button>
                <button
                    type="button"
                    id="btnReload"
                    class="terminal-btn terminal-btn-ghost"
                    aria-label="Recargar la página">
                    Recargar página
                </button>
            </div>
        </div>
    </div>
</section>
