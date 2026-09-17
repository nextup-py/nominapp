<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Legajo — {{ $employee->full_name }}</title>
    <style>
        @page {
            size: A4;
            margin: 0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.5;
            padding: 15mm 20mm;
        }

        /* ── Encabezado empresa ── */
        .company-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #0d9488;
        }

        .company-logo {
            max-height: 40px;
            max-width: 120px;
            margin-bottom: 8px;
        }

        .company-name {
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .company-info {
            font-size: 9px;
        }

        /* ── Título del documento ── */
        .doc-title {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 12px 0 2px 0;
        }

        .doc-subtitle {
            text-align: center;
            font-size: 9px;
            color: #555;
            margin-bottom: 14px;
        }

        /* ── Secciones ── */
        .section-header {
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 6px;
            margin: 12px 0 6px 0;
            background-color: #e8e8e8;
            border-left: 3px solid #000;
        }

        /* ── Grid de campos ── */
        .grid {
            display: table;
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3px;
        }

        .grid-row {
            display: table-row;
        }

        .grid-cell {
            display: table-cell;
            width: 50%;
            padding: 2px 0;
            vertical-align: top;
        }

        .grid-cell.full {
            width: 100%;
        }

        .field-label {
            font-size: 8px;
            color: #555;
            display: block;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .field-value {
            font-size: 10px;
            font-weight: bold;
        }

        .field-value.normal {
            font-weight: normal;
        }

        /* ── Tabla de deducciones/percepciones ── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 3px;
            font-size: 9px;
        }

        .data-table th {
            background-color: #f0f0f0;
            border: 1px solid #ccc;
            padding: 3px 5px;
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
        }

        .data-table td {
            border: 1px solid #ccc;
            padding: 3px 5px;
            vertical-align: top;
        }

        .data-table tr:nth-child(even) td {
            background-color: #fafafa;
        }

        .empty-note {
            color: #777;
            font-style: italic;
            font-size: 9px;
            margin: 3px 0 6px 0;
        }

        /* ── Firma ── */
        .signature-section {
            margin-top: 30px;
            display: table;
            width: 100%;
            page-break-inside: avoid;
        }

        .signature-item {
            display: table-cell;
            width: 50%;
            text-align: center;
            padding: 0 25px;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-bottom: 4px;
            padding-top: 4px;
        }

        .signature-label {
            font-size: 10px;
            font-weight: bold;
        }

        .signature-sublabel {
            font-size: 9px;
        }

        /* ── Footer ── */
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #ccc;
            padding-top: 6px;
            color: #555;
        }
    </style>
</head>

<body>

    {{-- Encabezado de la Empresa --}}
    <div class="company-header">
        @if ($companyLogo && file_exists($companyLogo))
            <img src="{{ $companyLogo }}" alt="Logo" class="company-logo">
        @endif
        <div class="company-name">{{ $companyName }}</div>
        <div class="company-info">
            @if ($companyRuc) RUC: {{ $companyRuc }} @endif
            @if ($companyRuc && $employerNumber) &nbsp;|&nbsp; @endif
            @if ($employerNumber) N.° Patronal: {{ $employerNumber }} @endif
        </div>
        @if ($companyAddress)
            <div class="company-info">{{ $companyAddress }}{{ $city ? ', ' . $city : '' }}</div>
        @endif
        @if ($companyPhone || $companyEmail)
            <div class="company-info">
                @if ($companyPhone) Tel: {{ $companyPhone }} @endif
                @if ($companyPhone && $companyEmail) &nbsp;|&nbsp; @endif
                @if ($companyEmail) {{ $companyEmail }} @endif
            </div>
        @endif
    </div>

    {{-- Título --}}
    <div class="doc-title">Legajo del Empleado</div>
    <div class="doc-subtitle">Generado el {{ now()->isoFormat('DD [de] MMMM [de] YYYY, HH:mm') }}</div>

    {{-- ──────────────────────────────────────────── --}}
    {{-- SECCIÓN: Datos Personales --}}
    {{-- ──────────────────────────────────────────── --}}
    <div class="section-header">Datos Personales</div>

    <div class="grid">
        <div class="grid-row">
            <div class="grid-cell">
                <span class="field-label">Apellido y Nombre</span>
                <span class="field-value">{{ $employee->full_name }}</span>
            </div>
            <div class="grid-cell">
                <span class="field-label">Cédula de Identidad</span>
                <span class="field-value">{{ $employee->ci }}</span>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="grid-row">
            <div class="grid-cell">
                <span class="field-label">Fecha de Nacimiento</span>
                <span class="field-value normal">
                    {{ $employee->birth_date ? $employee->birth_date->format('d/m/Y') . ' (' . $employee->birth_date->age . ' años)' : '—' }}
                </span>
            </div>
            <div class="grid-cell">
                <span class="field-label">Género</span>
                <span class="field-value normal">{{ $employee->gender_label ?? '—' }}</span>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="grid-row">
            <div class="grid-cell">
                <span class="field-label">Teléfono</span>
                <span class="field-value normal">{{ $employee->phone ?? '—' }}</span>
            </div>
            <div class="grid-cell">
                <span class="field-label">Correo Electrónico</span>
                <span class="field-value normal">{{ $employee->email ?? '—' }}</span>
            </div>
        </div>
    </div>

    {{-- ──────────────────────────────────────────── --}}
    {{-- SECCIÓN: Datos Laborales --}}
    {{-- ──────────────────────────────────────────── --}}
    <div class="section-header">Datos Laborales</div>

    <div class="grid">
        <div class="grid-row">
            <div class="grid-cell">
                <span class="field-label">Estado</span>
                <span class="field-value normal">{{ $employee->status_label }}</span>
            </div>
            <div class="grid-cell">
                <span class="field-label">Fecha de Ingreso</span>
                <span class="field-value normal">
                    {{ $employee->hire_date ? $employee->hire_date->format('d/m/Y') : '—' }}
                </span>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="grid-row">
            <div class="grid-cell">
                <span class="field-label">Empresa</span>
                <span class="field-value normal">{{ $employee->branch?->company?->name ?? '—' }}</span>
            </div>
            <div class="grid-cell">
                <span class="field-label">Sucursal</span>
                <span class="field-value normal">{{ $employee->branch?->name ?? '—' }}</span>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="grid-row">
            <div class="grid-cell">
                <span class="field-label">Departamento</span>
                <span class="field-value normal">{{ $contract?->position?->department?->name ?? '—' }}</span>
            </div>
            <div class="grid-cell">
                <span class="field-label">Cargo / Posición</span>
                <span class="field-value normal">{{ $contract?->position?->name ?? '—' }}</span>
            </div>
        </div>
    </div>

    {{-- ──────────────────────────────────────────── --}}
    {{-- SECCIÓN: Contrato Activo --}}
    {{-- ──────────────────────────────────────────── --}}
    <div class="section-header">Contrato Activo</div>

    @if ($contract)
        <div class="grid">
            <div class="grid-row">
                <div class="grid-cell">
                    <span class="field-label">Tipo de Contrato</span>
                    <span class="field-value normal">{{ \App\Models\Contract::getTypeOptions()[$contract->type] ?? $contract->type }}</span>
                </div>
                <div class="grid-cell">
                    <span class="field-label">Modalidad de Trabajo</span>
                    <span class="field-value normal">{{ \App\Models\Contract::getWorkModalityOptions()[$contract->work_modality] ?? $contract->work_modality ?? '—' }}</span>
                </div>
            </div>
        </div>

        <div class="grid">
            <div class="grid-row">
                <div class="grid-cell">
                    <span class="field-label">Tipo de Salario</span>
                    <span class="field-value normal">{{ $contract->salary_type === 'jornal' ? 'Jornalero' : 'Mensual' }}</span>
                </div>
                <div class="grid-cell">
                    <span class="field-label">{{ $contract->salary_type === 'jornal' ? 'Tarifa Diaria' : 'Salario Base' }}</span>
                    <span class="field-value">Gs. {{ number_format((int) $contract->salary, 0, '', '.') }}</span>
                </div>
            </div>
        </div>

        <div class="grid">
            <div class="grid-row">
                <div class="grid-cell">
                    <span class="field-label">Tipo de Nómina</span>
                    <span class="field-value normal">{{ \App\Models\Employee::getPayrollTypeOptions()[$contract->payroll_type] ?? $contract->payroll_type ?? '—' }}</span>
                </div>
                <div class="grid-cell">
                    <span class="field-label">Método de Pago</span>
                    <span class="field-value normal">{{ \App\Models\Employee::getPaymentMethodOptions()[$contract->payment_method] ?? $contract->payment_method ?? '—' }}</span>
                </div>
            </div>
        </div>

        <div class="grid">
            <div class="grid-row">
                <div class="grid-cell">
                    <span class="field-label">Inicio del Contrato</span>
                    <span class="field-value normal">{{ $contract->start_date?->format('d/m/Y') ?? '—' }}</span>
                </div>
                <div class="grid-cell">
                    <span class="field-label">Fin del Contrato</span>
                    <span class="field-value normal">{{ $contract->end_date ? $contract->end_date->format('d/m/Y') : 'Indefinido' }}</span>
                </div>
            </div>
        </div>
    @else
        <p class="empty-note">Sin contrato activo registrado.</p>
    @endif

    {{-- ──────────────────────────────────────────── --}}
    {{-- SECCIÓN: Deducciones Activas --}}
    {{-- ──────────────────────────────────────────── --}}
    <div class="section-header">Deducciones Activas</div>

    @php $deductions = $employee->activeEmployeeDeductions; @endphp

    @if ($deductions->isNotEmpty())
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:35%">Deducción</th>
                    <th style="width:18%">Monto</th>
                    <th style="width:15%">Inicio</th>
                    <th style="width:15%">Vencimiento</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($deductions as $ed)
                    <tr>
                        <td>{{ $ed->deduction?->name ?? '—' }}</td>
                        <td>
                            @if ($ed->custom_amount)
                                Gs. {{ number_format((int) $ed->custom_amount, 0, '', '.') }}
                            @else
                                Por defecto
                            @endif
                        </td>
                        <td>{{ $ed->start_date ? \Carbon\Carbon::parse($ed->start_date)->format('d/m/Y') : '—' }}</td>
                        <td>{{ $ed->end_date ? \Carbon\Carbon::parse($ed->end_date)->format('d/m/Y') : 'Indefinido' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="empty-note">Sin deducciones activas.</p>
    @endif

    {{-- ──────────────────────────────────────────── --}}
    {{-- SECCIÓN: Percepciones Activas --}}
    {{-- ──────────────────────────────────────────── --}}
    <div class="section-header">Percepciones Activas</div>

    @php $perceptions = $employee->activeEmployeePerceptions; @endphp

    @if ($perceptions->isNotEmpty())
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:35%">Percepción</th>
                    <th style="width:18%">Monto</th>
                    <th style="width:15%">Inicio</th>
                    <th style="width:15%">Vencimiento</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($perceptions as $ep)
                    <tr>
                        <td>{{ $ep->perception?->name ?? '—' }}</td>
                        <td>
                            @if ($ep->custom_amount)
                                Gs. {{ number_format((int) $ep->custom_amount, 0, '', '.') }}
                            @else
                                Por defecto
                            @endif
                        </td>
                        <td>{{ $ep->start_date ? \Carbon\Carbon::parse($ep->start_date)->format('d/m/Y') : '—' }}</td>
                        <td>{{ $ep->end_date ? \Carbon\Carbon::parse($ep->end_date)->format('d/m/Y') : 'Indefinido' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="empty-note">Sin percepciones activas.</p>
    @endif

    {{-- ──────────────────────────────────────────── --}}
    {{-- Firmas --}}
    {{-- ──────────────────────────────────────────── --}}
    <div class="signature-section">
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Trabajador</div>
            <div class="signature-sublabel">{{ $employee->full_name }}</div>
            <div class="signature-sublabel">C.I. N.°: {{ $employee->ci }}</div>
        </div>
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Empleador o Representante Legal</div>
            <div class="signature-sublabel">{{ $companyName }}</div>
            <div class="signature-sublabel">Firma y Sello</div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="footer">
        Documento generado el {{ now()->format('d/m/Y H:i') }}
        @if ($city) &nbsp;|&nbsp; {{ $city }}, Paraguay @endif
        &nbsp;|&nbsp; Empleado #{{ $employee->id }}
    </div>

</body>

</html>
