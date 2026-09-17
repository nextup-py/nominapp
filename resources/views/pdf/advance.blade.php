<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Comprobante de Adelanto #{{ $advance->id }}</title>
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
            font-size: 10px;
            line-height: 1.5;
            padding: 15mm 20mm;
        }

        .company-header {
            text-align: center;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid #0d9488;
        }

        .company-logo {
            max-height: 35px;
            max-width: 100px;
            margin-bottom: 4px;
        }

        .company-name {
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .company-info {
            font-size: 9px;
        }

        .title {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 12px 0 3px 0;
        }

        .subtitle {
            text-align: center;
            font-size: 9px;
            margin-bottom: 10px;
        }

        .highlight-box {
            margin: 10px 0;
            padding: 10px;
            border: 2px solid #000;
            text-align: center;
        }

        .highlight-label {
            font-size: 9px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .highlight-amount {
            font-size: 20px;
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }

        .section {
            margin-bottom: 10px;
        }

        .section-title {
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            padding: 3px 0;
            margin-bottom: 5px;
            border-bottom: 1px solid #000;
        }

        table.info-table {
            width: 100%;
            border-collapse: collapse;
        }

        table.info-table th {
            text-align: left;
            font-weight: bold;
            width: 160px;
            padding: 4px 8px;
            border: 1px solid #000;
        }

        table.info-table td {
            padding: 4px 8px;
            border: 1px solid #000;
        }

        .info-box {
            margin: 8px 0;
            padding: 6px 10px;
            border: 1px solid #ccc;
            font-size: 9px;
        }

        table.signature-table {
            width: 100%;
            margin-top: 40px;
        }

        table.signature-table td {
            width: 50%;
            text-align: center;
            padding: 0 25px;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-bottom: 5px;
            padding-top: 45px;
        }

        .signature-label {
            font-size: 9px;
            font-weight: bold;
        }

        .signature-sublabel {
            font-size: 8px;
        }

        .footer {
            margin-top: 15px;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #ccc;
            padding-top: 5px;
        }
    </style>
</head>

<body>

    {{-- Encabezado de la empresa --}}
    <div class="company-header">
        @if ($companyLogo)
            <img src="{{ $companyLogo }}" alt="Logo" class="company-logo">
        @endif
        <div class="company-name">{{ $companyName }}</div>
        <div class="company-info">
            @if ($companyRuc)
                RUC: {{ $companyRuc }}
            @endif
            @if ($companyRuc && $employerNumber)
                |
            @endif
            @if ($employerNumber)
                Nro. Patronal: {{ $employerNumber }}
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

    {{-- Título --}}
    <div class="title">Adelanto de Salario</div>
    <div class="subtitle">Documento #{{ $advance->id }}</div>

    {{-- Monto destacado --}}
    <div class="highlight-box">
        <div class="highlight-label">Monto del Adelanto</div>
        <div class="highlight-amount">Gs. {{ number_format($advance->amount, 0, ',', '.') }}</div>
    </div>

    {{-- Información del empleado --}}
    <div class="section">
        <div class="section-title">Información del Empleado</div>
        <table class="info-table">
            <tr>
                <th>Nombre Completo:</th>
                <td>{{ $advance->employee->full_name }}</td>
            </tr>
            <tr>
                <th>Cédula de Identidad:</th>
                <td>{{ $advance->employee->ci }}</td>
            </tr>
            <tr>
                <th>Cargo:</th>
                <td>{{ $advance->employee->activeContract?->position?->name ?? 'N/A' }}</td>
            </tr>
            <tr>
                <th>Departamento:</th>
                <td>{{ $advance->employee->activeContract?->position?->department?->name ?? 'N/A' }}</td>
            </tr>
        </table>
    </div>

    {{-- Datos del adelanto --}}
    <div class="section">
        <div class="section-title">Datos del Adelanto</div>
        <table class="info-table">
            <tr>
                <th>Método de pago:</th>
                <td>{{ \App\Models\Advance::getPaymentMethodLabel($advance->payment_method) }}</td>
            </tr>
            <tr>
                <th>Aprobado el:</th>
                <td>{{ $advance->approved_at?->format('d/m/Y H:i') ?? 'N/A' }}</td>
            </tr>
            <tr>
                <th>Aprobado por:</th>
                <td>{{ $advance->approvedBy?->name ?? 'N/A' }}</td>
            </tr>
        </table>
    </div>

    {{-- Nota legal --}}
    <div class="info-box">
        El presente comprobante acredita el adelanto de salario solicitado por el empleado. El monto indicado
        será descontado automáticamente en la liquidación de nómina del período correspondiente.
    </div>

    {{-- Firmas --}}
    <table class="signature-table">
        <tr>
            <td>
                <div class="signature-line"></div>
                <div class="signature-label">Empleado</div>
                <div class="signature-sublabel">{{ $advance->employee->full_name }}</div>
                <div class="signature-sublabel">CI: {{ $advance->employee->ci }}</div>
            </td>
            <td>
                <div class="signature-line"></div>
                <div class="signature-label">Recursos Humanos</div>
                <div class="signature-sublabel">Firma y Sello</div>
            </td>
        </tr>
    </table>

    {{-- Footer --}}
    <div class="footer">
        Documento generado el {{ now()->format('d/m/Y H:i') }}
        @if ($city)
            | {{ $city }}, Paraguay
        @endif
    </div>

</body>

</html>
