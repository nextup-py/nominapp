<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vincular dispositivo — Marcación de asistencia</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <x-favicon-links />
    @vite('resources/js/attendances/device-link.js')
    @vite('resources/css/attendances/device-link.css')
    <x-theme-vars />
</head>

<body>
    <header class="device-link-header">
        <x-theme-toggle-button />
    </header>

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

        {{-- Branding de la empresa del empleado recién identificado — oculto
        hasta la vinculación exitosa, poblado por device-link.js con los
        campos company_name/company_logo de la respuesta de claim(). --}}
        <div id="linkSuccessBranding" class="link-success-branding hidden">
            <img id="linkSuccessLogo" class="link-success-logo hidden" alt="">
            <span id="linkSuccessCompanyName" class="link-success-company-name"></span>
        </div>

        <p class="hint">Solo se puede vincular un dispositivo a la vez. Si vinculás uno nuevo, el anterior deja de funcionar automáticamente.</p>
    </div>
</body>

</html>
