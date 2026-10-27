/**
 * =============================================================================
 * VINCULACIÓN DE DISPOSITIVO — AVISO DE RE-VINCULACIÓN
 * =============================================================================
 *
 * @fileoverview device-link.blade.php es HTML+JS plano sin build step, salvo
 *               por este módulo: chequea si el propio navegador ya tiene un
 *               dispositivo vinculado (IndexedDB) antes de dejar completar el
 *               formulario de nuevo. Sin esto, volver a esta URL desde un
 *               dispositivo ya vinculado y reenviar el mismo CI+fecha genera
 *               una re-vinculación real (revoca el token actual, dispara
 *               MobileDeviceRelinkedNotification a los admins por campanita +
 *               email) sin que el empleado se entere de que está pasando —
 *               indistinguible para RRHH de un intento real de secuestro del
 *               dispositivo.
 *
 * @fileoverview (continúa el fileoverview anterior) También maneja el envío
 *               del formulario de vinculación (antes vivía como <script>
 *               inline en device-link.blade.php) y la obtención del modelo
 *               de dispositivo vía Client Hints.
 */

import { getMeta, getOwnEmployee, migrateTokenFromLocalStorage } from './mobile-offline/db.js';
import { initThemeToggle } from '../shared/theme-toggle.js';

/**
 * Modelo real del dispositivo, solo disponible vía Client Hints en
 * navegadores Chromium sobre Android — null en iOS/Safari/Firefox/desktop,
 * donde el servidor cae al parseo del User-Agent (DeviceHintsParser).
 * @param {Navigator} [nav] - inyectable para tests; navigator en producción
 * @returns {Promise<string|null>}
 */
export async function getClientHintModel(nav = navigator) {
    if (!nav.userAgentData?.getHighEntropyValues) return null;
    try {
        const hints = await nav.userAgentData.getHighEntropyValues(['model']);
        return hints.model || null;
    } catch {
        return null;
    }
}

/**
 * Envía CI + fecha de nacimiento al backend y persiste el token en éxito.
 * @param {{ci: string, birth_date: string, accept_privacy_version?: number}} formValues
 * @param {string} endpoint - URL a la que hacer POST (window.location.pathname sin barra final)
 * @param {string} csrfToken
 * @param {{getItem: Function, setItem: Function}} [storage] - inyectable para tests; localStorage en producción
 * @returns {Promise<{ok: boolean, message?: string, employee?: object, privacy?: object}>} `privacy` viene cuando la empresa
 *          exige aceptar su aviso de privacidad antes de vincular (se reenvía con `accept_privacy_version`).
 */
export async function handleLinkSubmit(formValues, endpoint, csrfToken, storage = localStorage) {
    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({
                ci: formValues.ci,
                birth_date: formValues.birth_date,
                device_model_hint: await getClientHintModel(),
                accept_privacy_version: formValues.accept_privacy_version,
            }),
        });
        const data = await response.json();

        if (!data.ok && data.code === 'privacy_consent_required' && data.privacy) {
            return { ok: false, message: data.message, privacy: data.privacy };
        }

        if (!data.ok) {
            return { ok: false, message: data.message || 'No se pudo vincular el dispositivo.' };
        }

        // Almacenamiento provisorio del token — en la fase de sincronización
        // offline (mobile-offline/) este valor pasa a vivir en su propio store.
        storage.setItem('nominapp_mobile_token', data.token);
        storage.setItem('nominapp_mobile_employee_id', String(data.employee.id));

        return { ok: true, employee: data.employee };
    } catch {
        return { ok: false, message: 'Error de conexión. Intente nuevamente.' };
    }
}

/** Engancha el submit del formulario de vinculación al DOM real. */
function initLinkForm() {
    const form = document.getElementById('linkForm');
    const btn = document.getElementById('btnLink');
    const btnLabel = btn?.querySelector('.btn-label');
    const btnSpinner = btn?.querySelector('.btn-spinner');
    const statusEl = document.getElementById('status');
    if (!form || !btn || !statusEl) return;

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const successCard = document.getElementById('linkSuccessCard');
    const successMessage = document.getElementById('linkSuccessMessage');
    const brandingEl = document.getElementById('linkSuccessBranding');
    const brandingLogo = document.getElementById('linkSuccessLogo');
    const brandingName = document.getElementById('linkSuccessCompanyName');

    const consentBox = document.getElementById('privacyConsent');
    const consentText = document.getElementById('privacyConsentText');
    const consentLink = document.getElementById('privacyConsentLink');
    const consentCheck = document.getElementById('privacyConsentCheck');
    let consentVersion = null;

    /** Muestra el aviso de privacidad de la empresa y exige tildarlo antes de reenviar. */
    const showConsent = (privacy) => {
        consentVersion = privacy.version;
        if (consentText) consentText.textContent = privacy.text || '';
        consentText?.classList.toggle('hidden', !privacy.text);
        if (consentLink) {
            consentLink.href = privacy.url || '#';
            consentLink.classList.toggle('hidden', !privacy.url);
        }
        if (consentCheck) consentCheck.checked = false;
        consentBox?.classList.remove('hidden');
    };

    const setLoading = (isLoading) => {
        btn.disabled = isLoading;
        btnSpinner?.classList.toggle('hidden', !isLoading);
        if (btnLabel) {
            btnLabel.textContent = isLoading ? 'Vinculando...' : 'Vincular este dispositivo';
        }
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        statusEl.classList.add('hidden');

        if (consentVersion !== null && !consentCheck?.checked) {
            statusEl.textContent = 'Para vincular su celular acepte el aviso de privacidad.';
            statusEl.className = 'status alert-box alert-box-error';
            return;
        }

        setLoading(true);

        const endpoint = window.location.pathname.replace(/\/$/, '');
        const result = await handleLinkSubmit(
            {
                ci: document.getElementById('ci').value.trim(),
                birth_date: document.getElementById('birth_date').value,
                accept_privacy_version: consentVersion ?? undefined,
            },
            endpoint,
            csrf,
        );

        if (!result.ok && result.privacy) {
            showConsent(result.privacy);
            statusEl.textContent = result.message;
            statusEl.className = 'status alert-box alert-box-error';
            setLoading(false);
            return;
        }

        if (!result.ok) {
            statusEl.textContent = result.message;
            statusEl.className = 'status alert-box alert-box-error';
            setLoading(false);
            return;
        }

        if (result.employee.company_logo && brandingLogo && brandingEl) {
            brandingLogo.src = result.employee.company_logo;
            brandingLogo.classList.remove('hidden');
        }
        if (result.employee.company_name && brandingName && brandingEl) {
            brandingName.textContent = result.employee.company_name;
        }
        if (result.employee.company_name || result.employee.company_logo) {
            brandingEl?.classList.remove('hidden');
        }

        if (successMessage) {
            successMessage.textContent = `Dispositivo vinculado. ¡Hola, ${result.employee.first_name}! Redirigiendo...`;
        }
        form.classList.add('hidden');
        successCard?.classList.remove('hidden');

        setTimeout(() => {
            window.location.href = '/marcar';
        }, 1200);
    });
}

// Guardado tras `typeof document !== 'undefined'` para que este módulo pueda
// importarse en Vitest (entorno Node, sin jsdom) sin lanzar ReferenceError al
// evaluar el top-level — el comportamiento en el navegador real es idéntico,
// ya que `document` siempre existe ahí.
if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', async () => {
        initThemeToggle('device-link-theme');

        // Por si quedó un token sin migrar de una vinculación interrumpida (ej. el
        // empleado cerró la pestaña antes de que /marcar corriera la migración).
        await migrateTokenFromLocalStorage();

        const token = await getMeta('api_token');
        if (token) {
            const warning = document.getElementById('alreadyLinkedWarning');
            const form = document.getElementById('linkForm');
            const nameEl = document.getElementById('alreadyLinkedName');
            const btnContinue = document.getElementById('btnContinueAnyway');
            const btnCancel = document.getElementById('btnCancelRelink');

            if (warning && form) {
                if (nameEl) {
                    const employee = await getOwnEmployee();
                    const fullName = [employee?.first_name, employee?.last_name].filter(Boolean).join(' ');
                    nameEl.textContent = fullName || 'este empleado';
                }

                form.classList.add('hidden');
                warning.classList.remove('hidden');

                btnContinue?.addEventListener('click', () => {
                    warning.classList.add('hidden');
                    form.classList.remove('hidden');
                });

                btnCancel?.addEventListener('click', () => {
                    window.location.href = '/marcar';
                });
            }
        }

        initLinkForm();
    });
}
