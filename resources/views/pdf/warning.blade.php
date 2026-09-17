<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Amonestación #{{ $warning->id }}</title>
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

        .title {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 20px 0 5px 0;
        }

        .subtitle {
            text-align: center;
            font-size: 10px;
            margin-bottom: 20px;
        }

        .section {
            margin-bottom: 15px;
        }

        .section-title {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            padding: 5px 0;
            margin-bottom: 8px;
            border-bottom: 1px solid #000;
        }

        .info-grid {
            display: table;
            width: 100%;
            border-collapse: collapse;
        }

        .info-row {
            display: table-row;
        }

        .info-label {
            display: table-cell;
            font-weight: bold;
            width: 180px;
            padding: 5px 8px;
            border: 1px solid #000;
        }

        .info-value {
            display: table-cell;
            padding: 5px 8px;
            border: 1px solid #000;
        }

        .type-badge {
            display: inline-block;
            padding: 4px 12px;
            border: 2px solid #000;
            font-weight: bold;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .description-box {
            margin: 15px 0;
            padding: 12px;
            border: 1px solid #000;
        }

        .description-title {
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 10px;
            text-transform: uppercase;
        }

        .description-text {
            line-height: 1.8;
        }

        .notes-box {
            margin: 15px 0;
            padding: 10px 12px;
            border: 1px solid #ccc;
            font-size: 10px;
        }

        .notes-title {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .signature-section {
            margin-top: 50px;
            display: table;
            width: 100%;
        }

        .signature-item {
            display: table-cell;
            width: 50%;
            text-align: center;
            padding: 0 25px;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-bottom: 5px;
            padding-top: 5px;
        }

        .signature-label {
            font-size: 10px;
            font-weight: bold;
        }

        .signature-sublabel {
            font-size: 9px;
        }

        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
    </style>
</head>

<body>
    {{-- Encabezado de la Empresa --}}
    <div class="company-header">
        @if ($companyLogo)
            <img src="{{ $companyLogo }}" alt="Logo" class="company-logo">
        @endif
        <div class="company-name">{{ $companyName }}</div>
        <div class="company-info">
            @if ($companyRuc)
                RUC: {{ $companyRuc }}
            @endif
        </div>
        @if ($companyAddress)
            <div class="company-info">{{ $companyAddress }}</div>
        @endif
        @if ($companyPhone || $companyEmail)
            <div class="company-info">
                @if ($companyPhone)Tel: {{ $companyPhone }}@endif
                @if ($companyPhone && $companyEmail) | @endif
                @if ($companyEmail){{ $companyEmail }}@endif
            </div>
        @endif
    </div>

    {{-- Título del documento --}}
    <div class="title">Amonestación Laboral</div>
    <div class="subtitle">
        Nro. {{ str_pad($warning->id, 6, '0', STR_PAD_LEFT) }} &mdash;
        {{ $warning->issued_at->format('d/m/Y') }}
    </div>

    {{-- Tipo de amonestación --}}
    <div style="text-align: center; margin-bottom: 20px;">
        <span class="type-badge">{{ \App\Models\Warning::getTypeLabel($warning->type) }}</span>
    </div>

    {{-- Datos del Empleado --}}
    <div class="section">
        <div class="section-title">Datos del Empleado</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Apellido y Nombre</div>
                <div class="info-value">{{ $warning->employee->full_name }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Cédula de Identidad</div>
                <div class="info-value">{{ number_format($warning->employee->ci, 0, ',', '.') }}</div>
            </div>
            @if ($warning->employee->activeContract?->position)
                <div class="info-row">
                    <div class="info-label">Cargo</div>
                    <div class="info-value">{{ $warning->employee->activeContract->position->name }}</div>
                </div>
            @endif
            @if ($warning->employee->activeContract?->position?->department)
                <div class="info-row">
                    <div class="info-label">Departamento</div>
                    <div class="info-value">{{ $warning->employee->activeContract->position->department->name }}</div>
                </div>
            @endif
        </div>
    </div>

    {{-- Detalle de la Amonestación --}}
    <div class="section">
        <div class="section-title">Detalle de la Amonestación</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Motivo</div>
                <div class="info-value">{{ \App\Models\Warning::getReasonLabel($warning->reason) }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Emitida por</div>
                <div class="info-value">{{ $warning->issuedBy->name }}</div>
            </div>
        </div>
    </div>

    {{-- Descripción del Hecho --}}
    <div class="description-box">
        <div class="description-title">Descripción del Hecho</div>
        <div class="description-text">{{ $warning->description }}</div>
    </div>

    {{-- Observaciones (si las hay) --}}
    @if ($warning->notes)
        <div class="notes-box">
            <div class="notes-title">Observaciones</div>
            {{ $warning->notes }}
        </div>
    @endif

    {{-- Firmas --}}
    <div class="signature-section">
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">{{ $warning->issuedBy->name }}</div>
            <div class="signature-sublabel">Recursos Humanos</div>
        </div>
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">{{ $warning->employee->full_name }}</div>
            <div class="signature-sublabel">CI: {{ number_format($warning->employee->ci, 0, ',', '.') }}</div>
            <div class="signature-sublabel">Empleado</div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="footer">
        Documento generado el {{ now()->format('d/m/Y H:i') }}
        @if ($city) &mdash; {{ $city }} @endif
    </div>
</body>

</html>
