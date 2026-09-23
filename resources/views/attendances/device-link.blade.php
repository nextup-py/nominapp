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
</head>

<body>
    <header class="app-header">
        <div class="app-header-brand">
            <span class="app-mode-badge">Vincular dispositivo</span>
        </div>
        <div class="app-header-right">
            <x-theme-toggle-button />
        </div>
    </header>

    <main class="page-wrapper">
        <div class="main-grid">
            <div class="card">
                <div class="card-body">
                    <div class="link-hero">
                        <div class="link-hero-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75A2.25 2.25 0 0015.75 1.5H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
                            </svg>
                        </div>
                        <h1 class="link-hero-title">Vincular este dispositivo</h1>
                        <p class="link-hero-subtitle">Ingresá tu CI y fecha de nacimiento para vincular este dispositivo. Vas a poder marcar tu asistencia aunque no tengas conexión a internet.</p>
                    </div>

                    {{-- Solo se muestra si device-link.js detecta un token ya vinculado en este
                    navegador (IndexedDB) — evita re-vinculaciones accidentales que disparan una
                    alerta de seguridad real (MobileDeviceRelinkedNotification) a los admins. --}}
                    <div id="alreadyLinkedWarning" class="alert-box alert-box-warning hidden">
                        <div class="alert-box-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                                <line x1="12" y1="9" x2="12" y2="13"/>
                                <line x1="12" y1="17" x2="12.01" y2="17"/>
                            </svg>
                        </div>
                        <div class="alert-box-content">
                            <p class="alert-box-title">Este dispositivo ya está vinculado a <span id="alreadyLinkedName"></span></p>
                            <p class="alert-box-message">Si continuás y vinculás uno nuevo, el dispositivo actual va a dejar de funcionar y se le va a avisar a RRHH.</p>
                            <div class="already-linked-actions">
                                <button type="button" id="btnCancelRelink" class="btn btn-primary btn-full">Ya tengo un dispositivo — volver a marcar</button>
                                <button type="button" id="btnContinueAnyway" class="btn btn-outline-warning btn-full">Continuar y vincular este de todos modos</button>
                            </div>
                        </div>
                    </div>

                    <form id="linkForm" class="link-form">
                        <div class="form-field">
                            <label for="ci">CI</label>
                            <div class="input-icon-wrap">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <rect x="2" y="4" width="20" height="16" rx="2.5"/>
                                    <circle cx="9" cy="10.5" r="2"/>
                                    <path stroke-linecap="round" d="M6.5 16c.5-1.6 1.9-2.5 3.5-2.5s3 .9 3.5 2.5"/>
                                    <path stroke-linecap="round" d="M15.5 9.5h4M15.5 13h4"/>
                                </svg>
                                <input type="text" id="ci" name="ci" inputmode="numeric" autocomplete="off" placeholder="Ej: 1234567" required>
                            </div>
                        </div>

                        <div class="form-field">
                            <label for="birth_date">Fecha de nacimiento</label>
                            <div class="input-icon-wrap">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <rect x="3" y="4.5" width="18" height="16" rx="2.5"/>
                                    <line x1="3" y1="9.5" x2="21" y2="9.5"/>
                                    <line x1="8" y1="2.5" x2="8" y2="6.5"/>
                                    <line x1="16" y1="2.5" x2="16" y2="6.5"/>
                                </svg>
                                <input type="date" id="birth_date" name="birth_date" required>
                            </div>
                        </div>

                        <button type="submit" id="btnLink" class="btn btn-primary btn-full btn-lg">
                            <svg class="btn-spinner hidden" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                <circle cx="12" cy="12" r="10" stroke-opacity=".25"/>
                                <path stroke-linecap="round" d="M22 12a10 10 0 0 0-10-10"/>
                            </svg>
                            <span class="btn-label">Vincular este dispositivo</span>
                        </button>
                    </form>

                    <div id="status" class="status hidden" role="status" aria-live="polite"></div>

                    {{-- Tarjeta de éxito — reemplaza al formulario tras una vinculación correcta.
                    linkSuccessBranding/Logo/CompanyName se completan con los datos de la
                    respuesta de claim() (company_name/company_logo del empleado). --}}
                    <div id="linkSuccessCard" class="link-success-card hidden">
                        <div class="link-success-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        </div>
                        <p id="linkSuccessMessage" class="link-success-message"></p>
                        <div id="linkSuccessBranding" class="link-success-branding hidden">
                            <img id="linkSuccessLogo" class="link-success-logo hidden" alt="">
                            <span id="linkSuccessCompanyName" class="link-success-company-name"></span>
                        </div>
                    </div>
                </div>
            </div>

            <p class="hint">Solo se puede vincular un dispositivo a la vez. Si vinculás uno nuevo, el anterior deja de funcionar automáticamente.</p>
        </div>
    </main>

    <x-branding-footer />
</body>

</html>
