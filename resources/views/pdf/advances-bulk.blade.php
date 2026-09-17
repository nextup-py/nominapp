<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Adelantos de Salario</title>
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
            font-size: 9px;
            line-height: 1.4;
        }

        .page {
            width: 100%;
            page-break-after: always;
            page-break-inside: avoid;
        }

        .page:last-child {
            page-break-after: avoid;
        }

        .advance-block {
            padding: 5mm 14mm 5mm 14mm;
            min-height: 130mm;
        }

        .advance-block-first {
            padding-top: 10mm;
        }

        .company-header {
            text-align: center;
            margin-bottom: 4px;
            padding-bottom: 4px;
            border-bottom: 2px solid #0d9488;
        }

        .company-logo {
            max-height: 28px;
            max-width: 80px;
            margin-bottom: 3px;
        }

        .company-name {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 1px;
        }

        .company-info {
            font-size: 8px;
        }

        .doc-title {
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 4px 0 1px 0;
        }

        .doc-subtitle {
            text-align: center;
            font-size: 8px;
            margin-bottom: 3px;
        }

        .highlight-box {
            margin: 3px 0;
            padding: 3px 5px;
            border: 2px solid #000;
            text-align: center;
        }

        .highlight-label {
            font-size: 8px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .highlight-amount {
            font-size: 16px;
            font-weight: bold;
            font-family: 'Courier New', monospace;
        }

        .section-title {
            font-weight: bold;
            font-size: 8px;
            text-transform: uppercase;
            padding: 2px 0;
            margin-bottom: 3px;
            border-bottom: 1px solid #000;
        }

        table.info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }

        table.info-table th {
            text-align: left;
            font-weight: bold;
            width: 140px;
            padding: 2px 5px;
            border: 1px solid #000;
            font-size: 8px;
        }

        table.info-table td {
            padding: 2px 5px;
            border: 1px solid #000;
            font-size: 8px;
        }

        .info-box {
            margin: 3px 0;
            padding: 3px 6px;
            border: 1px solid #ccc;
            font-size: 8px;
        }

        table.signature-table {
            width: 100%;
            margin-top: 40px;
        }

        table.signature-table td {
            width: 50%;
            text-align: center;
            padding: 0 20px;
        }

        .signature-line {
            border-top: 1px solid #000;
            padding-top: 12px;
        }

        .signature-label {
            font-size: 8px;
            font-weight: bold;
        }

        .signature-sublabel {
            font-size: 7px;
        }

        .footer {
            margin-top: 5px;
            text-align: center;
            font-size: 7px;
            border-top: 1px solid #ccc;
            padding-top: 3px;
        }
    </style>
</head>

<body>

    @foreach ($advances->chunk(2) as $pageAdvances)
        <div class="page">
            @foreach ($pageAdvances as $loopIndex => $advance)

                @if (!$loop->first)
                    <div style="border-top: 2px dashed #999; margin: 3mm 14mm;"></div>
                @endif

                <div class="advance-block {{ $loop->first ? 'advance-block-first' : '' }}">

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
                    <div class="doc-title">Adelanto de Salario</div>
                    <div class="doc-subtitle">Documento #{{ $advance->id }}</div>

                    {{-- Monto destacado --}}
                    <div class="highlight-box">
                        <div class="highlight-label">Monto del Adelanto</div>
                        <div class="highlight-amount">Gs. {{ number_format($advance->amount, 0, ',', '.') }}</div>
                    </div>

                    {{-- Información del empleado --}}
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

                </div>

            @endforeach
        </div>
    @endforeach

</body>

</html>
