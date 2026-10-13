{{--
    Pantalla "sin empleados": el terminal no tiene a nadie en su caché, así que la cámara no podría
    reconocer a nadie y se mantiene apagada. Dos niveles de texto: un mensaje simple para el empleado
    (#emptyMessage) y un detalle técnico para quien instala dentro de <details> (#emptyDetailText).
    El JS (terminal/empty-state.js, cableado en terminal.js) completa los textos según la causa,
    cuenta hacia atrás hasta el próximo reintento de sincronización y vuelve solo a identificación
    cuando llegan empleados.
--}}
<section id="emptyScreen" class="terminal-screen hidden" role="region" aria-label="Terminal sin empleados">
    <div class="screen-body">
        <div class="result-card result-card--warning">
            <div class="result-icon result-icon--warning" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M22 11h-6"/>
                </svg>
            </div>

            <h1 id="emptyHeadline" class="result-headline">Terminal sin empleados</h1>

            <p id="emptyMessage" class="empty-message" role="status" aria-live="polite">Este terminal todavía no está listo para marcar.</p>

            <details class="empty-details">
                <summary>Ver detalle</summary>
                <p id="emptyDetailText" class="empty-detail-text"></p>
                <p class="empty-detail-text">
                    Terminal <strong id="emptyTerminalCode">—</strong> · Sucursal <strong id="emptyBranchName">—</strong>
                </p>
                <p id="emptyHrNotice" class="empty-detail-text hidden"></p>
            </details>

            <p id="emptyRetry" class="empty-retry" aria-live="off"></p>

            <div class="terminal-actions">
                <button type="button" id="btnEmptySync" class="terminal-btn terminal-btn-primary">Sincronizar ahora</button>
            </div>
        </div>
    </div>
</section>
