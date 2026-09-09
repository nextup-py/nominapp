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

        <div id="installSection" class="install-section" hidden>
            <p>Instalá esta app en la pantalla de inicio del dispositivo para que funcione como terminal fijo.</p>
            <p id="installInstructionsIos" class="install-instructions-ios" hidden>En iOS: tocá el botón Compartir y elegí "Agregar a pantalla de inicio".</p>
            <button type="button" id="btnInstallNow" hidden>Instalar ahora</button>
            <button type="button" id="btnContinue" class="btn-secondary">Continuar al terminal</button>
        </div>
    </div>

    @vite('resources/js/attendances/terminal-setup.js')
</body>

</html>
