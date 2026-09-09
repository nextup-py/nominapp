<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configurar terminal — {{ $terminal->name }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-favicon-links />
    @vite('resources/css/attendances/terminal-setup.css')
    <x-theme-vars />
</head>

<body>
    <div class="container">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
            </svg>
        </div>
        <h1>Configurar terminal</h1>
        <p>Este dispositivo se vinculará como el terminal de marcación de la sucursal indicada, habilitando la marcación sin conexión.</p>
        <div class="terminal-meta">{{ $terminal->name }} — {{ $terminal->branch?->name }}</div>

        <button type="button" id="btnClaim">Vincular este dispositivo</button>
        <div id="status" class="status" role="status" aria-live="polite"></div>
    </div>

    <script>
        const btn = document.getElementById('btnClaim');
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

                statusEl.textContent = 'Dispositivo vinculado correctamente. Redirigiendo...';
                statusEl.className = 'status status--success';

                setTimeout(() => {
                    window.location.href = '/terminal/' + data.terminal.code;
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
