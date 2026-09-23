<section id="idleScreen" class="terminal-screen hidden" role="region" aria-label="Terminal en reposo">
    <div class="idle-body">
        <div class="idle-center">
            <img id="idleCompanyLogo" class="idle-company-logo hidden" alt="">
            <div class="idle-clock" id="idleClock">--:--:--</div>
            <div class="idle-date" id="idleDate"></div>
            <div class="idle-terminal-info" id="idleTerminalInfo" style="display:none">
                <svg class="idle-terminal-info-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="16" rx="2"/>
                    <path d="M3 9h18"/>
                    <path d="M9 21V9"/>
                </svg>
                <span id="idleTerminalName"></span>
                <span id="idleTerminalBranch"></span>
            </div>
            <div class="idle-sync-row">
                <span class="idle-sync-status" id="idleSyncStatus" aria-live="polite"></span>
                <button
                    type="button"
                    id="btnForceSync"
                    class="idle-sync-btn"
                    aria-label="Forzar sincronización de empleados">
                    <svg class="idle-sync-btn-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"/>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                    </svg>
                    Sincronizar
                </button>
            </div>
        </div>
        <div class="idle-hint">
            <span class="idle-hint-dot" aria-hidden="true"></span>
            <span>Acérquese para registrar asistencia</span>
        </div>
        <x-branding-footer />
    </div>
</section>
