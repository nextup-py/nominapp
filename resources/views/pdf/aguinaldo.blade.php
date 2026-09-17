<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo de Aguinaldo #{{ $aguinaldo->id }}</title>
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

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 6px;
            font-size: 9px;
        }

        th {
            font-weight: bold;
            background-color: #f5f5f5;
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .amount {
            font-family: 'Courier New', monospace;
            text-align: right;
        }

        .summary-section {
            margin: 15px 0;
            padding: 12px;
            border: 1px solid #000;
        }

        .summary-title {
            font-weight: bold;
            margin-bottom: 10px;
            text-transform: uppercase;
            font-size: 10px;
        }

        .summary-row {
            padding: 4px 0;
        }

        .summary-label {
            font-weight: bold;
            width: 200px;
            display: inline-block;
        }

        .total-row {
            border-top: 2px solid #000;
            padding-top: 10px;
            margin-top: 10px;
        }

        .total-label {
            font-size: 13px;
            font-weight: bold;
        }

        .total-value {
            font-size: 13px;
            font-weight: bold;
        }

        .legal-note {
            margin-top: 15px;
            font-size: 9px;
            text-align: justify;
            padding: 10px;
            border: 1px solid #ccc;
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
            margin-top: 40px;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }

        .highlight {
            background-color: #e8f5e9;
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
    <div class="title">Recibo de Aguinaldo</div>
    <div class="subtitle">Correspondiente al Ejercicio Fiscal {{ $aguinaldo->period->year }}</div>

    {{-- Informacion del Empleado --}}
    <div class="section">
        <div class="section-title">Informacion del Empleado</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Nombre Completo:</div>
                <div class="info-value">{{ $aguinaldo->employee->full_name }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Cedula de Identidad:</div>
                <div class="info-value">{{ $aguinaldo->employee->ci }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Cargo:</div>
                <div class="info-value">{{ $aguinaldo->employee->activeContract?->position?->name ?? 'N/A' }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Departamento:</div>
                <div class="info-value">{{ $aguinaldo->employee->activeContract?->position?->department?->name ?? 'N/A' }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Meses Trabajados:</div>
                <div class="info-value">{{ number_format($aguinaldo->months_worked, 0) }} meses en {{ $aguinaldo->period->year }}</div>
            </div>
        </div>
    </div>

    {{-- Desglose Mensual --}}
    <div class="section">
        <div class="section-title">Desglose de Ingresos por Mes</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 20%;">Mes</th>
                    <th style="width: 20%;" class="text-right">Salario Base</th>
                    <th style="width: 20%;" class="text-right">Percepciones</th>
                    <th style="width: 20%;" class="text-right">Horas Extras</th>
                    <th style="width: 20%;" class="text-right">Total Mes</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($aguinaldo->items as $item)
                    <tr>
                        <td>{{ $item->month }}</td>
                        <td class="amount">{{ $item->formatted_base_salary }}</td>
                        <td class="amount">{{ $item->formatted_perceptions }}</td>
                        <td class="amount">{{ $item->formatted_extra_hours }}</td>
                        <td class="amount">{{ $item->formatted_total }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="highlight">
                    <th>TOTAL ANUAL</th>
                    <th class="amount">{{ \App\Models\Aguinaldo::formatCurrency($aguinaldo->items->sum('base_salary')) }}</th>
                    <th class="amount">{{ \App\Models\Aguinaldo::formatCurrency($aguinaldo->items->sum('perceptions')) }}</th>
                    <th class="amount">{{ \App\Models\Aguinaldo::formatCurrency($aguinaldo->items->sum('extra_hours')) }}</th>
                    <th class="amount">{{ $aguinaldo->formatted_total_earned }}</th>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Resumen de Liquidacion --}}
    <div class="summary-section">
        <div class="summary-title">Calculo del Aguinaldo</div>
        <div class="summary-row">
            <span class="summary-label">Total Devengado en {{ $aguinaldo->period->year }}:</span>
            {{ $aguinaldo->formatted_total_earned }}
        </div>
        <div class="summary-row">
            <span class="summary-label">Meses Trabajados:</span>
            {{ number_format($aguinaldo->months_worked, 0) }} meses
        </div>
        <div class="summary-row">
            <span class="summary-label">Calculo (Total / 12):</span>
            {{ $aguinaldo->formatted_total_earned }} / 12
        </div>
        <div class="summary-row total-row">
            <span class="summary-label total-label">AGUINALDO A PAGAR:</span>
            <strong class="total-value">{{ $aguinaldo->formatted_aguinaldo_amount }}</strong>
        </div>
    </div>

    {{-- Nota Legal --}}
    <div class="legal-note">
        <strong>Nota:</strong> El aguinaldo corresponde a la doceava parte (1/12) de las remuneraciones
        devengadas durante el ano, conforme a lo establecido en el Codigo del Trabajo de la Republica
        del Paraguay. Este pago no esta sujeto a descuentos de IPS segun la legislacion vigente.
    </div>

    {{-- Firmas --}}
    <div class="signature-section">
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Empleado</div>
            <div class="signature-sublabel">{{ $aguinaldo->employee->full_name }}</div>
            <div class="signature-sublabel">CI: {{ $aguinaldo->employee->ci }}</div>
        </div>
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Recursos Humanos</div>
            <div class="signature-sublabel">Firma y Sello</div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="footer">
        Documento generado el {{ now()->format('d/m/Y H:i') }} | Recibo de Aguinaldo #{{ $aguinaldo->id }}
        @if ($city)
            | {{ $city }}, Paraguay
        @endif
    </div>
</body>

</html>
