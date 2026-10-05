{{--
    Pantalla bloqueante: el terminal no tiene un token de sincronización válido
    (nunca reclamó el enlace de setup, otro navegador/perfil, datos borrados,
    token revocado, o este navegador pertenece a otro terminal). Sin token no
    puede identificar ni sincronizar a nadie, así que no se arranca cámara ni
    identificación. El JS completa #unlinkedDetail según el motivo.
--}}
<section id="unlinkedScreen" class="terminal-screen hidden" role="region" aria-label="Terminal sin vincular">
    <div class="screen-body">
        <div class="result-card result-card--warning">
            <div class="result-icon result-icon--warning" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 9v4"/><path d="M12 17h.01"/>
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                </svg>
            </div>

            <h1 class="result-headline">Terminal sin vincular</h1>

            <div class="error-message" role="alert" aria-live="assertive">
                <span id="unlinkedDetail">Este terminal no está vinculado.</span>
                Pedí un nuevo enlace de configuración al administrador.
            </div>

            <div class="terminal-actions">
                <button type="button" id="btnUnlinkedReload" class="terminal-btn terminal-btn-primary">Recargar</button>
            </div>
        </div>
    </div>
</section>
