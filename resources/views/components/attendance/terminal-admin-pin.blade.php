{{--
    Diálogo del PIN de administrador: lo abre la compuerta de terminal/admin-pin.js antes de una acción protegida
    (hoy, «Usar este equipo en este terminal»). Se verifica en el equipo, sin conexión.
--}}
<div id="adminPinModal" class="privacy-modal hidden" aria-hidden="true">
    <div class="privacy-modal-backdrop"></div>
    <form id="adminPinForm" class="privacy-modal-panel" role="dialog" aria-modal="true" aria-labelledby="adminPinTitle" autocomplete="off">
        <h2 id="adminPinTitle" class="privacy-modal-title">PIN de administrador</h2>
        <p class="privacy-modal-body">Ingresar el PIN para continuar.</p>
        <input id="adminPinInput" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" class="admin-pin-input" autocomplete="off" aria-label="PIN de administrador">
        <p id="adminPinError" class="admin-pin-error hidden" role="alert"></p>
        <div class="terminal-actions">
            <button type="submit" class="terminal-btn terminal-btn-primary">Continuar</button>
            <button type="button" id="btnAdminPinCancel" class="terminal-btn terminal-btn-ghost">Cancelar</button>
        </div>
    </form>
</div>
