/**
 * Lógica de la página de aprovisionamiento de terminal — vincula el
 * dispositivo (reclama el token Sanctum del enlace de un solo uso) y, tras
 * vincular exitosamente, ofrece instalar la PWA antes de continuar al modo
 * terminal. Extraído del <script> inline original para poder importar el
 * módulo compartido de instalación (ver resources/js/shared/install-prompt.js).
 */
import { captureInstallPrompt, triggerInstallPrompt, isStandalone, isIOS } from '../shared/install-prompt.js';

const btn = document.getElementById('btnClaim');
const statusEl = document.getElementById('status');
const installSection = document.getElementById('installSection');
const installInstructionsIos = document.getElementById('installInstructionsIos');
const btnInstallNow = document.getElementById('btnInstallNow');
const btnContinue = document.getElementById('btnContinue');
const csrf = document.querySelector('meta[name="csrf-token"]').content;

let terminalCode = null;

/**
 * Modelo real del dispositivo, solo disponible vía Client Hints en navegadores
 * Chromium sobre Android (Chrome, Edge...) — null en iOS/Safari/Firefox/desktop,
 * donde el servidor cae al parseo del User-Agent (ver DeviceHintsParser). Sirve
 * solo para prellenar marca/modelo como sugerencia editable en el panel.
 */
async function getClientHintModel() {
    if (!navigator.userAgentData?.getHighEntropyValues) {
        return null;
    }
    try {
        const hints = await navigator.userAgentData.getHighEntropyValues(['model']);
        return hints.model || null;
    } catch {
        return null;
    }
}

captureInstallPrompt(() => {
    btnInstallNow.hidden = false;
});

btn.addEventListener('click', async () => {
    btn.disabled = true;
    statusEl.textContent = 'Vinculando...';
    statusEl.className = 'status';

    try {
        const response = await fetch(window.location.pathname.replace(/\/$/, '') + '/claim', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ device_model_hint: await getClientHintModel() }),
        });
        const data = await response.json();

        if (!data.ok) {
            statusEl.textContent = data.message || 'No se pudo vincular el dispositivo.';
            statusEl.className = 'status status--error';
            btn.disabled = false;
            return;
        }

        // Almacenamiento provisorio del token — en la fase de sincronización offline
        // (IndexedDB, módulo terminal-offline/) este valor pasa a vivir en el store
        // `terminal_meta` en vez de localStorage. Se guarda el id (estable, no cambia
        // con "Cambiar URL del terminal") además del code (cambia con esa acción) —
        // terminal.js usa el id para detectar si el estado local pertenece a OTRO
        // terminal distinto, sin confundir un simple cambio de URL con eso.
        localStorage.setItem('nominapp_terminal_token', data.token);
        localStorage.setItem('nominapp_terminal_id', data.terminal.id);
        localStorage.setItem('nominapp_terminal_code', data.terminal.code);

        terminalCode = data.terminal.code;
        statusEl.textContent = 'Dispositivo vinculado correctamente.';
        statusEl.className = 'status status--success';
        btn.hidden = true;

        const standalone = isStandalone(
            window.navigator.standalone,
            window.matchMedia('(display-mode: standalone)').matches
        );

        if (standalone) {
            // Reaprovisión de un terminal ya instalado como PWA — sin necesidad de
            // volver a mostrar el flujo de instalación, continuar directo.
            window.location.href = '/terminal/' + terminalCode;
            return;
        }

        installSection.hidden = false;

        if (isIOS(window.navigator.userAgent, window.navigator.maxTouchPoints)) {
            btnInstallNow.hidden = true;
            installInstructionsIos.hidden = false;
        }
    } catch (error) {
        statusEl.textContent = 'Error de conexión. Intente nuevamente.';
        statusEl.className = 'status status--error';
        btn.disabled = false;
    }
});

btnInstallNow.addEventListener('click', () => triggerInstallPrompt());

btnContinue.addEventListener('click', () => {
    window.location.href = '/terminal/' + terminalCode;
});
