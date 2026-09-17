<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitud de Usufructo de Vacaciones</title>
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
            margin: 25px 0;
            text-decoration: underline;
        }

        .date-location {
            text-align: right;
            margin-bottom: 25px;
            font-size: 11px;
        }

        .recipient {
            margin-bottom: 5px;
        }

        .recipient-company {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .content {
            text-align: justify;
            margin: 30px 0;
            line-height: 1.8;
            font-size: 11px;
        }

        .content p {
            text-indent: 40px;
        }

        .info-section {
            margin: 25px 0;
            padding: 15px;
            border: 1px solid #000;
        }

        .info-title {
            font-weight: bold;
            margin-bottom: 10px;
            text-transform: uppercase;
            font-size: 10px;
        }

        .info-grid {
            display: table;
            width: 100%;
        }

        .info-row {
            display: table-row;
        }

        .info-label {
            display: table-cell;
            font-weight: bold;
            width: 180px;
            padding: 3px 0;
        }

        .info-value {
            display: table-cell;
            padding: 3px 0;
        }

        .signature-section {
            margin-top: 60px;
            display: table;
            width: 100%;
        }

        .signature-item {
            display: table-cell;
            width: 50%;
            text-align: center;
            padding: 0 30px;
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
            margin-top: 50px;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
    </style>
</head>

<body>
    @php
        $returnDate = $vacation->return_date ?? $vacation->end_date->addDay();
    @endphp

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
            @if ($employerNumber)
                | Nro. Patronal: {{ $employerNumber }}
            @endif
        </div>
        @if ($companyAddress)
            <div class="company-info">{{ $companyAddress }}</div>
        @endif
        @if ($companyPhone || $companyEmail)
            <div class="company-info">
                @if ($companyPhone)
                    Tel: {{ $companyPhone }}
                @endif
                @if ($companyPhone && $companyEmail)
                    |
                @endif
                @if ($companyEmail)
                    {{ $companyEmail }}
                @endif
            </div>
        @endif
    </div>

    {{-- Titulo --}}
    <div class="title">Solicitud de Usufructo de Vacaciones</div>

    {{-- Fecha y Lugar --}}
    <div class="date-location">
        {{ $city }}, {{ $returnDate->format('d') }} de {{ $returnDate->locale('es')->monthName }} de {{ $returnDate->year }}
    </div>

    {{-- Destinatario --}}
    <div class="recipient">Senores</div>
    <div class="recipient-company">{{ $companyName }}</div>
    <div class="recipient">Presente.-</div>

    {{-- Contenido --}}
    <div class="content">
        <p>
            Por medio de la presente, notifico a ustedes que en cumplimiento de la Legislacion Laboral vigente,
            he USUFRUCTUADO mis vacaciones anuales remuneradas correspondientes al periodo
            <strong>{{ $vacation->vacationBalance?->year - 1 ?? now()->year - 1 }} - {{ $vacation->vacationBalance?->year ?? now()->year }}</strong>,
            conforme al siguiente detalle:
        </p>
    </div>

    {{-- Informacion del Periodo --}}
    <div class="info-section">
        <div class="info-title">Detalle del Periodo de Vacaciones</div>
        <div class="info-grid">
            <div class="info-row">
                <span class="info-label">Empleado:</span>
                <span class="info-value">{{ $vacation->employee->full_name }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Cedula de Identidad:</span>
                <span class="info-value">{{ $vacation->employee->ci }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Cargo:</span>
                <span class="info-value">{{ $vacation->employee->position?->name ?? 'N/A' }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Fecha de Inicio:</span>
                <span class="info-value">{{ $vacation->start_date->format('d/m/Y') }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Fecha de Fin:</span>
                <span class="info-value">{{ $vacation->end_date->format('d/m/Y') }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Dias habiles disfrutados:</span>
                <span class="info-value">{{ $vacation->business_days ?? $vacation->total_days }} dias</span>
            </div>
            <div class="info-row">
                <span class="info-label">Fecha de Reintegro:</span>
                <span class="info-value">{{ $returnDate->format('d/m/Y') }}</span>
            </div>
        </div>
    </div>

    {{-- Texto de cierre --}}
    <div class="content">
        <p>
            Declaro haber retomado mis labores habituales en fecha <strong>{{ $returnDate->format('d/m/Y') }}</strong>,
            dando por concluido el periodo de vacaciones correspondiente.
        </p>
    </div>

    {{-- Firmas --}}
    <div class="signature-section">
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Empleado</div>
            <div class="signature-sublabel">{{ $vacation->employee->full_name }}</div>
            <div class="signature-sublabel">CI: {{ $vacation->employee->ci }}</div>
        </div>
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Recursos Humanos</div>
            <div class="signature-sublabel">Firma y Sello</div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="footer">
        Documento generado el {{ now()->format('d/m/Y H:i') }}
        @if ($city)
            | {{ $city }}, Paraguay
        @endif
    </div>
</body>

</html>
