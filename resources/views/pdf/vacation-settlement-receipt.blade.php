<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo de Liquidacion de Vacaciones</title>
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

        .info-section {
            margin-bottom: 20px;
        }

        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 5px;
        }

        .info-label {
            display: table-cell;
            font-weight: bold;
            width: 180px;
        }

        .info-value {
            display: table-cell;
        }

        .info-inline {
            margin-bottom: 5px;
        }

        .info-inline .info-label {
            display: inline;
            width: auto;
        }

        .info-inline .info-value {
            display: inline;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 8px 6px;
            text-align: center;
            font-size: 9px;
        }

        th {
            font-weight: bold;
            background-color: #f5f5f5;
        }

        .text-left {
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .amount {
            font-family: 'Courier New', monospace;
            text-align: right;
        }

        .total-row td {
            font-weight: bold;
        }

        .period-section {
            margin: 15px 0;
            padding: 10px;
            border: 1px solid #000;
        }

        .period-title {
            font-weight: bold;
            margin-bottom: 8px;
        }

        .period-grid {
            display: table;
            width: 100%;
        }

        .period-item {
            display: table-cell;
            width: 33.33%;
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
            margin-top: 40px;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }

        .legal-note {
            margin-top: 20px;
            font-size: 9px;
            text-align: justify;
            padding: 10px;
            border: 1px solid #ccc;
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
    <div class="title">Recibo de Liquidacion de Vacaciones</div>
    <div class="subtitle">Art. 220 del Codigo Laboral</div>

    {{-- Informacion del Empleado --}}
    <div class="info-section">
        <div class="info-row">
            <span class="info-label">Empleado:</span>
            <span class="info-value">{{ strtoupper($vacation->employee->last_name) }},
                {{ strtoupper($vacation->employee->first_name) }}</span>
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
            <span class="info-label">Periodo correspondiente:</span>
            <span class="info-value">{{ $vacation->vacationBalance?->year - 1 ?? now()->year - 1 }} -
                {{ $vacation->vacationBalance?->year ?? now()->year }}</span>
        </div>
    </div>

    {{-- Periodo de Vacaciones --}}
    <div class="period-section">
        <div class="period-title">Periodo de Vacaciones</div>
        <div class="period-grid">
            <div class="period-item">
                <strong>Inicio:</strong> {{ $vacation->start_date->format('d/m/Y') }}
            </div>
            <div class="period-item">
                <strong>Fin:</strong> {{ $vacation->end_date->format('d/m/Y') }}
            </div>
            <div class="period-item">
                <strong>Dias habiles:</strong> {{ $vacation->business_days }} dias
            </div>
        </div>
    </div>

    {{-- Tabla de Liquidacion --}}
    <table>
        <thead>
            <tr>
                <th class="text-left">Concepto</th>
                <th>Cantidad</th>
                <th>Valor Unitario</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-left">Salario diario (Base: Gs.
                    {{ number_format($vacation->employee->base_salary ?? 0, 0, ',', '.') }} / 30)</td>
                <td>{{ $days }} dias</td>
                <td class="amount">Gs. {{ number_format($dailySalary, 0, ',', '.') }}</td>
                <td class="amount">Gs. {{ number_format($subTotal, 0, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td class="text-left" colspan="3">Total Haberes</td>
                <td class="amount">Gs. {{ number_format($totalSalary, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Tabla de Descuentos --}}
    <table>
        <thead>
            <tr>
                <th class="text-left">Descuentos</th>
                <th>Porcentaje</th>
                <th>Monto</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-left">Aporte IPS (Obrero)</td>
                <td>{{ number_format($ipsRate, 0) }}%</td>
                <td class="amount">Gs. {{ number_format($ipsDeduction, 0, ',', '.') }}</td>
            </tr>
            <tr class="total-row">
                <td class="text-left" colspan="2">Total Descuentos</td>
                <td class="amount">Gs. {{ number_format($totalDeductions, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Neto a Pagar --}}
    <table>
        <tbody>
            <tr class="total-row">
                <td class="text-left" style="width: 70%;">NETO A PAGAR</td>
                <td class="amount">Gs. {{ number_format($netAmount, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Nota Legal --}}
    <div class="legal-note">
        <strong>Nota:</strong> El presente recibo corresponde a la liquidacion de vacaciones anuales remuneradas
        conforme a lo establecido en el Articulo 220 del Codigo Laboral, el cual dispone que el trabajador
        tiene derecho a percibir su salario correspondiente al periodo de vacaciones antes del inicio de las mismas.
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
            <div class="signature-label">Empleador / RRHH</div>
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
