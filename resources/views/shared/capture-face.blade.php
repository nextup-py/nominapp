@php
    $isEnrollment = $mode === 'enrollment';

    // Resolver nombre del empleado con fallback
    $employeeName = '';
    if (!empty($employee->name)) {
        $employeeName = trim($employee->name);
    } else {
        $firstName = trim($employee->first_name ?? '');
        $lastName = trim($employee->last_name ?? '');
        $employeeName = trim($firstName . ' ' . $lastName);
    }
    if (empty($employeeName)) {
        $employeeName = 'Empleado #' . $employee->id;
    }

    // Resolver número de documento (distintos nombres de campo)
    $documentNumber =
        $employee->document_number ?? ($employee->document ?? ($employee->dni ?? ($employee->ci ?? null)));
@endphp
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isEnrollment ? 'Registro Facial' : 'Capturar Rostro' }} - {{ config('app.name', 'RRHH') }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-favicon-links />
    <link rel="preconnect" href="https://unpkg.com">
    @vite($css)
</head>

<body data-mode="{{ $mode }}"
    @if (!$isEnrollment) data-has-face="{{ $employee->has_face ? 'true' : 'false' }}"
        data-face-date="{{ $faceDate?->format('d/m/Y H:i') }}" @endif>
    <x-employee.capture-loading />

    <div class="container">
        <header class="app-header">
            <div class="app-header-brand">
                <span class="app-mode-badge">{{ $isEnrollment ? 'Auto-Registro' : 'Captura Facial' }}</span>
                <span class="app-title">Registro Facial</span>
            </div>
            <div class="app-employee-info" aria-label="Empleado">
                <span class="app-employee-name">{{ $employeeName }}</span>
                @if ($documentNumber)
                    <span class="app-employee-ci">CI: {{ $documentNumber }}</span>
                @endif
            </div>
        </header>

        <main class="grid" role="main">
            <!-- Paso 1: Captura del rostro -->
            <section class="card capture-section" aria-labelledby="capture-heading">
                <h2 id="capture-heading">
                    <span class="step-badge" aria-hidden="true">1</span> Captura del Rostro
                </h2>

                <div class="video-container">
                    <div class="video-wrap" role="img" aria-label="Vista previa de la cámara">
                        <video id="video" autoplay playsinline muted
                            aria-label="Vista previa de la cámara para captura facial"></video>
                        <canvas id="overlay" aria-hidden="true"></canvas>
                        <div class="video-blur-overlay" aria-hidden="true"></div>
                        <div class="face-guide" aria-hidden="true">
                            <div class="face-guide-oval"></div>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="controls row">
                        <button id="btnStart" type="button" class="btn-gray" aria-label="Iniciar cámara">
                            <span aria-hidden="true"><svg class="btn-icon" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path
                                        d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z" />
                                    <circle cx="12" cy="13" r="4" />
                                </svg></span>
                            Iniciar Cámara
                        </button>
                        <button id="btnCapture" type="button" class="btn-blue" disabled aria-label="Capturar rostro">
                            <span aria-hidden="true"><svg class="btn-icon" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10" />
                                    <circle cx="12" cy="12" r="3" />
                                    <line x1="12" y1="2" x2="12" y2="6" />
                                    <line x1="12" y1="18" x2="12" y2="22" />
                                    <line x1="2" y1="12" x2="6" y2="12" />
                                    <line x1="18" y1="12" x2="22" y2="12" />
                                </svg></span>
                            Capturar Rostro
                        </button>
                    </div>

                    <div class="status-area">
                        <div id="status" class="status" role="status" aria-live="polite" aria-atomic="true">
                            Presiona "Iniciar Cámara" para comenzar
                        </div>
                    </div>

                    <div id="captureProgress" class="capture-progress hidden" aria-hidden="true">
                        <span class="capture-dot"></span>
                        <span class="capture-dot"></span>
                        <span class="capture-dot"></span>
                        <span class="capture-dot"></span>
                        <span class="capture-dot"></span>
                        <span class="capture-dot"></span>
                        <span class="capture-dot"></span>
                    </div>
                </div>
            </section>

            <!-- Paso 2: Confirmación y guardado -->
            <section class="card confirmation-section" aria-labelledby="save-heading">
                <h2 id="save-heading">
                    <span class="step-badge" aria-hidden="true">2</span>
                    Confirmar y Guardar
                </h2>

                <div class="card-body">
                    <div class="descriptor-status">
                        <div class="desc-row">
                            <span class="desc-label">Rostro</span>
                            <span id="descValue" class="desc-value">No capturado</span>
                        </div>
                        <div class="desc-row hidden" id="descRowSamples">
                            <span class="desc-label">Muestras</span>
                            <span id="descSamplesValue" class="desc-value">—</span>
                        </div>
                        <div class="desc-row hidden" id="descRowQuality">
                            <span class="desc-label">Calidad</span>
                            <span id="descQualityValue" class="desc-value">—</span>
                        </div>
                        <div class="desc-row hidden" id="descRowTime">
                            <span class="desc-label" id="descTimeLabel">Hora</span>
                            <span id="descTimeValue" class="desc-value">—</span>
                        </div>
                    </div>

                    <form id="saveForm" method="POST" action="{{ $formAction }}" novalidate>
                        @csrf
                        <input type="hidden" name="face_descriptor" id="faceDescriptor" required>

                        <div class="form-actions row">
                            <button type="submit" id="btnSave" class="btn-green" disabled
                                @if ($isEnrollment) aria-describedby="save-help" @endif>
                                <span aria-hidden="true"><svg class="btn-icon" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round">
                                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                                        <polyline points="17 21 17 13 7 13 7 21" />
                                        <polyline points="7 3 7 8 15 8" />
                                    </svg></span>
                                Guardar
                            </button>
                            <button type="button" id="btnCancel" class="btn-red" onclick="handleCancel()"
                                aria-label="Cancelar y regresar">
                                <span aria-hidden="true"><svg class="btn-icon" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                                        <line x1="18" y1="6" x2="6" y2="18" />
                                        <line x1="6" y1="6" x2="18" y2="18" />
                                    </svg></span>
                                Cancelar
                            </button>
                        </div>

                        @if ($isEnrollment)
                            <small id="save-help" class="help-text">
                                El administrador revisará y aprobará tu registro antes de que puedas marcar asistencia.
                            </small>
                        @endif

                        @if ($isEnrollment)
                            <div class="expiry-warning" role="note">
                                <span class="expiry-dot" aria-hidden="true"></span>
                                Enlace válido hasta el {{ $enrollment->expires_at->format('d/m/Y H:i') }}
                            </div>
                        @endif
                    </form>
                </div>
            </section>
        </main>
    </div>

    <!-- Modal de confirmación -->
    <div id="confirmationModal" class="modal" style="display: none;" role="dialog" aria-modal="true"
        aria-labelledby="modal-title" aria-describedby="modal-description" tabindex="-1">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <header class="modal-header">
                <div class="modal-success-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M9 12 L11 14 L15 10" pathLength="1" />
                    </svg>
                </div>
            </header>
            <div class="modal-body">
                <p id="modal-description">El rostro fue registrado correctamente.</p>
                @if ($isEnrollment)
                    <p>El administrador revisará y aprobará su registro antes de que pueda marcar asistencia.</p>
                @endif
            </div>
            <footer class="modal-footer">
                <button id="closeModal" type="button" class="btn-primary">Entendido</button>
            </footer>
        </div>
    </div>

    <script defer src="https://unpkg.com/face-api.js@0.22.2/dist/face-api.min.js" crossorigin="anonymous"
        referrerpolicy="no-referrer" onerror="handleScriptError()"></script>
    @vite($js)

    <script>
        function handleCancel() {
            const msg = @json(
                $isEnrollment
                    ? '¿Está seguro de que desea cancelar el registro?'
                    : '¿Está seguro de que desea cancelar la captura? Se perderá el progreso actual.');
            if (confirm(msg)) window.history.back();
        }

        function handleScriptError() {
            document.getElementById('status').textContent =
                'Error: No se pudo cargar la biblioteca de reconocimiento facial.';
        }
    </script>
</body>

</html>
