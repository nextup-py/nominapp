{{--
    Aviso de privacidad completo del terminal: texto de la empresa + QR del enlace a la política.
    Lo abre el ícono ⓘ del encabezado o el pie de la pantalla de reposo (terminal/privacy-notice.js).
--}}
<div id="privacyModal" class="privacy-modal hidden" aria-hidden="true">
    <div class="privacy-modal-backdrop" data-privacy-close></div>
    <div class="privacy-modal-panel" role="dialog" aria-modal="true" aria-labelledby="privacyModalTitle">
        <h2 id="privacyModalTitle" class="privacy-modal-title">Aviso de privacidad</h2>
        <p id="privacyModalBody" class="privacy-modal-body"></p>
        <div class="privacy-modal-link-row">
            <img id="privacyModalQr" class="privacy-modal-qr hidden" alt="Código QR de la política de privacidad">
            <span id="privacyModalLink" class="privacy-modal-link hidden"></span>
        </div>
        <button type="button" id="btnPrivacyClose" class="terminal-btn terminal-btn-primary" data-privacy-close>Entendido</button>
    </div>
</div>
