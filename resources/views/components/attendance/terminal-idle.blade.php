<section id="idleScreen" class="terminal-screen hidden" role="region" aria-label="Terminal en reposo">
    <div class="idle-body">
        <div class="idle-center">
            <div class="idle-clock" id="idleClock">--:--:--</div>
            <div class="idle-date" id="idleDate"></div>
            <div class="idle-terminal-info" id="idleTerminalInfo" style="display:none">
                <span id="idleTerminalName"></span>
                <span id="idleTerminalBranch"></span>
            </div>
            <div class="idle-sync-row">
                <span class="idle-sync-status" id="idleSyncStatus" aria-live="polite"></span>
                <button
                    type="button"
                    id="btnForceSync"
                    class="terminal-btn terminal-btn-ghost idle-sync-btn"
                    aria-label="Forzar sincronización de empleados">
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
