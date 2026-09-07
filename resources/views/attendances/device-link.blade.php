<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vincular dispositivo — Marcación de asistencia</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-favicon-links />
    @vite('resources/js/attendances/device-link.js')
    @vite('resources/css/attendances/device-link.css')
</head>

<body>
    <div class="container">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75A2.25 2.25 0 0015.75 1.5H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
            </svg>
        </div>
        <h1>Vincular este dispositivo</h1>
        <p>Ingresá tu CI y fecha de nacimiento para vincular este dispositivo. Vas a poder marcar tu asistencia aunque no tengas conexión a internet.</p>

        {{-- Solo se muestra si device-link.js detecta un token ya vinculado en este
        navegador (IndexedDB) — evita re-vinculaciones accidentales que disparan una
        alerta de seguridad real (MobileDeviceRelinkedNotification) a los admins. --}}
        <div id="alreadyLinkedWarning" class="already-linked-warning hidden">
            <p><strong>Este dispositivo ya está vinculado</strong> a <span id="alreadyLinkedName"></span>.</p>
            <p>Si continuás y vinculás uno nuevo, el dispositivo actual va a dejar de funcionar y se le va a avisar a RRHH.</p>
            <div class="already-linked-actions">
                <button type="button" id="btnCancelRelink" class="btn-cancel-relink">Ya tengo un dispositivo — volver a marcar</button>
                <button type="button" id="btnContinueAnyway" class="btn-continue-anyway">Continuar y vincular este de todos modos</button>
            </div>
        </div>

        <form id="linkForm">
            <label for="ci">CI</label>
            <input type="text" id="ci" name="ci" inputmode="numeric" autocomplete="off" required>

            <label for="birth_date">Fecha de nacimiento</label>
            <input type="date" id="birth_date" name="birth_date" required>

            <button type="submit" id="btnLink">Vincular este dispositivo</button>
        </form>
        <div id="status" class="status" role="status" aria-live="polite"></div>

        <p class="hint">Solo se puede vincular un dispositivo a la vez. Si vinculás uno nuevo, el anterior deja de funcionar automáticamente.</p>
    </div>

    <script>
        const form = document.getElementById('linkForm');
        const btn = document.getElementById('btnLink');
        const statusEl = document.getElementById('status');
        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        // Modelo real del dispositivo, solo disponible vía Client Hints en navegadores
        // Chromium sobre Android (Chrome, Edge...) — null en iOS/Safari/Firefox/desktop,
        // donde el servidor cae al parseo del User-Agent (ver DeviceHintsParser). Sirve
        // solo para prellenar marca/modelo como sugerencia editable en el panel.
        async function getClientHintModel() {
            if (!navigator.userAgentData?.getHighEntropyValues) return null;
            try {
                const hints = await navigator.userAgentData.getHighEntropyValues(['model']);
                return hints.model || null;
            } catch {
                return null;
            }
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            btn.disabled = true;
            statusEl.textContent = 'Vinculando...';
            statusEl.className = 'status';

            try {
                const response = await fetch(window.location.pathname.replace(/\/$/, ''), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({
                        ci: document.getElementById('ci').value.trim(),
                        birth_date: document.getElementById('birth_date').value,
                        device_model_hint: await getClientHintModel(),
                    }),
                });
                const data = await response.json();

                if (!data.ok) {
                    statusEl.textContent = data.message || 'No se pudo vincular el dispositivo.';
                    statusEl.className = 'status status--error';
                    btn.disabled = false;
                    return;
                }

                // Almacenamiento provisorio del token — en la fase de sincronización offline
                // (IndexedDB, módulo mobile-offline/) este valor pasa a vivir en su propio store,
                // mismo patrón que usó terminal-setup.blade.php para el terminal.
                localStorage.setItem('nominapp_mobile_token', data.token);
                localStorage.setItem('nominapp_mobile_employee_id', String(data.employee.id));

                statusEl.textContent = `Dispositivo vinculado. ¡Hola, ${data.employee.first_name}! Redirigiendo...`;
                statusEl.className = 'status status--success';

                setTimeout(() => {
                    window.location.href = '{{ route('mark.show') }}';
                }, 1200);
            } catch (error) {
                statusEl.textContent = 'Error de conexión. Intente nuevamente.';
                statusEl.className = 'status status--error';
                btn.disabled = false;
            }
        });
    </script>
</body>

</html>
