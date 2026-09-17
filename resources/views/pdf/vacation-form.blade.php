<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comunicacion de Vacaciones</title>
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

        .content {
            text-align: justify;
            line-height: 1.8;
            margin: 15px 0;
        }

        .content p {
            text-indent: 40px;
        }

        .period-section {
            margin: 15px 0;
            padding: 12px;
            border: 1px solid #000;
        }

        .period-title {
            font-weight: bold;
            margin-bottom: 10px;
            text-transform: uppercase;
            font-size: 10px;
        }

        .period-grid {
            display: table;
            width: 100%;
        }

        .period-row {
            display: table-row;
        }

        .period-item {
            display: table-cell;
            padding: 3px 0;
        }

        .period-label {
            font-weight: bold;
            width: 140px;
            display: inline-block;
        }

        .reason-section {
            margin: 15px 0;
        }

        .reason-box {
            border: 1px solid #000;
            padding: 10px;
            min-height: 50px;
        }

        .note-section {
            margin: 20px 0;
            padding: 12px;
            border: 1px solid #000;
        }

        .note-title {
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
            width: 33.33%;
            text-align: center;
            padding: 0 15px;
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
    <div class="title">Comunicacion de Vacaciones</div>
    <div class="subtitle">Art. 218 del Codigo Laboral</div>

    {{-- Informacion del Empleado --}}
    <div class="section">
        <div class="section-title">Informacion del Empleado</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Nombre Completo:</div>
                <div class="info-value">{{ $vacation->employee->full_name }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Cedula de Identidad:</div>
                <div class="info-value">{{ $vacation->employee->ci }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Cargo:</div>
                <div class="info-value">{{ $vacation->employee->position->name ?? 'N/A' }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Departamento:</div>
                <div class="info-value">{{ $vacation->employee->position->department->name ?? 'N/A' }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Antiguedad:</div>
                <div class="info-value">
                    @php
                        $hireDate = $vacation->employee->hire_date;
                        $years = $hireDate ? (int) $hireDate->diffInYears(now()) : 0;
                        $months = $hireDate ? (int) $hireDate->copy()->addYears($years)->diffInMonths(now()) : 0;
                        $parts = [];
                        if ($years > 0) $parts[] = $years . ' ' . ($years === 1 ? 'año' : 'años');
                        if ($months > 0) $parts[] = $months . ' ' . ($months === 1 ? 'mes' : 'meses');
                        $seniority = !empty($parts) ? implode(' y ', $parts) : 'Menos de 1 mes';
                    @endphp
                    {{ $seniority }}
                </div>
            </div>
        </div>
    </div>

    {{-- Comunicacion Legal --}}
    <div class="content">
        <p>
            En cumplimiento a lo dispuesto en el Articulo 218 del Codigo Laboral vigente, se le comunica
            que debera hacer uso de sus vacaciones anuales remuneradas correspondientes al periodo
            <strong>{{ $vacation->vacationBalance?->year - 1 ?? now()->year - 1 }} -
                {{ $vacation->vacationBalance?->year ?? now()->year }}</strong>,
            por un total de <strong>{{ $vacation->business_days ?? $vacation->total_days }} dias habiles</strong>,
            conforme al siguiente detalle:
        </p>
    </div>

    {{-- Periodo de Vacaciones --}}
    <div class="period-section">
        <div class="period-title">Periodo de Vacaciones</div>
        <div class="period-grid">
            <div class="period-row">
                <div class="period-item">
                    <span class="period-label">Fecha de Inicio:</span>
                    {{ $vacation->start_date->format('d/m/Y') }}
                    ({{ ucfirst($vacation->start_date->locale('es')->dayName) }})
                </div>
            </div>
            <div class="period-row">
                <div class="period-item">
                    <span class="period-label">Fecha de Fin:</span>
                    {{ $vacation->end_date->format('d/m/Y') }}
                    ({{ ucfirst($vacation->end_date->locale('es')->dayName) }})
                </div>
            </div>
            <div class="period-row">
                <div class="period-item">
                    <span class="period-label">Dias Habiles:</span>
                    {{ $vacation->business_days ?? $vacation->total_days }} dias
                </div>
            </div>
            <div class="period-row">
                <div class="period-item">
                    <span class="period-label">Fecha de Reintegro:</span>
                    {{ $returnDate->format('d/m/Y') }} ({{ ucfirst($returnDate->locale('es')->dayName) }})
                </div>
            </div>
        </div>
    </div>

    {{-- Nota Importante --}}
    <div class="note-section">
        <div class="note-title">Nota Importante:</div>
        <p>
            El empleado debera reintegrarse a sus labores el dia <strong>{{ $returnDate->format('d/m/Y') }}</strong>.
            En caso de no presentarse sin justificacion, se considerara como ausencia injustificada conforme
            a lo establecido en el Codigo Laboral vigente.
        </p>
    </div>

    {{-- Texto de conformidad --}}
    <div class="content">
        <p>
            En prueba de conformidad y como constancia de haber sido notificado de lo anterior,
            firmo el presente documento.
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
            <div class="signature-label">Superior Inmediato</div>
            <div class="signature-sublabel">Firma</div>
        </div>
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Recursos Humanos</div>
            <div class="signature-sublabel">Firma y Sello</div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="footer">
        Documento generado el {{ now()->format('d/m/Y H:i') }} | Solicitud #{{ $vacation->id }}
        @if ($city)
            | {{ $city }}, Paraguay
        @endif
    </div>
</body>

</html>
