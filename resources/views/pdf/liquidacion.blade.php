<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liquidacion #{{ $liquidacion->id }}</title>
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
            font-size: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }

        th, td {
            border: 1px solid #000;
            padding: 5px 6px;
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

        .net-amount-box {
            margin: 15px 0;
            padding: 12px;
            border: 2px solid #000;
            text-align: center;
        }

        .net-amount-label {
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .net-amount-value {
            font-size: 16px;
            font-weight: bold;
        }

        .legal-note {
            margin-top: 15px;
            font-size: 8px;
            text-align: justify;
            padding: 8px;
            border: 1px solid #ccc;
        }

        .signature-section {
            margin-top: 40px;
            display: table;
            width: 100%;
        }

        .signature-item {
            display: table-cell;
            width: 50%;
            text-align: center;
            padding: 0 15px;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-bottom: 5px;
            padding-top: 5px;
        }

        .signature-label {
            font-size: 9px;
            font-weight: bold;
        }

        .signature-sublabel {
            font-size: 8px;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }

        .highlight {
            background-color: #e8f5e9;
        }

        .highlight-danger {
            background-color: #ffebee;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: 9px;
            font-weight: bold;
            border: 1px solid #000;
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
    <div class="title">Liquidacion y Finiquito Laboral</div>
    <div class="subtitle">Conforme al Codigo del Trabajo de la Republica del Paraguay</div>

    {{-- Informacion del Empleado --}}
    <div class="section">
        <div class="section-title">Datos del Empleado</div>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Nombre:</div>
                <div class="info-value" style="text-transform: uppercase;">{{ $liquidacion->employee?->full_name }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Cédula:</div>
                <div class="info-value">{{ $liquidacion->employee?->ci }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Puesto:</div>
                <div class="info-value">{{ $liquidacion->employee?->activeContract?->position?->name ?? 'N/A' }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Período trabajado:</div>
                <div class="info-value">Del {{ $liquidacion->hire_date->format('d/m/Y') }} al {{ $liquidacion->termination_date->format('d/m/Y') }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Tipo de desvinculación:</div>
                <div class="info-value">{{ \App\Models\Liquidacion::getTerminationTypeLabel($liquidacion->termination_type) }}</div>
            </div>
            @if ($liquidacion->termination_reason)
                <div class="info-row">
                    <div class="info-label">Motivo:</div>
                    <div class="info-value">{{ $liquidacion->termination_reason }}</div>
                </div>
            @endif
            <div class="info-row">
                <div class="info-label">{{ $liquidacion->salary_type === 'jornal' ? 'Jornal Diario:' : 'Salario Base:' }}</div>
                <div class="info-value">
                    @if ($liquidacion->salary_type === 'jornal')
                        {{ \App\Models\Liquidacion::formatCurrency($liquidacion->daily_salary) }}/jornal
                        (Base mensual ref.: {{ \App\Models\Liquidacion::formatCurrency($liquidacion->base_salary) }})
                    @else
                        {{ \App\Models\Liquidacion::formatCurrency($liquidacion->base_salary) }}/mes
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Tabla de Haberes --}}
    <div class="section">
        <div class="section-title">Haberes</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 50%;">Concepto</th>
                    <th style="width: 30%;">Detalle</th>
                    <th style="width: 20%;" class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($liquidacion->items->where('type', 'haber') as $item)
                    @php
                        $meta = $item->metadata ?? [];
                        $isJornal = $liquidacion->salary_type === 'jornal';
                        $unit = $isJornal ? 'jornal(es)' : 'días';
                        $rateLabel = $isJornal ? '/jornal' : '/día';
                        $detalle = match ($item->category) {
                            'preaviso', 'vacaciones', 'salario_pendiente' =>
                                ($meta['days'] ?? '-') . ' ' . $unit . ' × ' .
                                (isset($meta['daily_salary'])
                                    ? \App\Models\Liquidacion::formatCurrency($meta['daily_salary']) . $rateLabel
                                    : '-'),
                            'indemnizacion' =>
                                ($meta['years'] ?? 0) . ' año(s)' .
                                (($meta['months_fraction'] ?? 0) > 0
                                    ? ' + ' . $meta['months_fraction'] . ' mes(es)'
                                    : '') .
                                (isset($meta['daily_avg'])
                                    ? ' | ' . \App\Models\Liquidacion::formatCurrency($meta['daily_avg']) . ($isJornal ? '/jornal prom.' : '/día prom.')
                                    : ''),
                            'aguinaldo' =>
                                ($meta['months_in_year'] ?? '-') . ' meses del año ' . ($meta['year'] ?? ''),
                            'indemnizacion_estabilidad' =>
                                'Igual a indemnización base (Art. 95 CLT — más de 10 años)',
                            default => '-',
                        };
                    @endphp
                    <tr>
                        <td>{{ $item->description }}</td>
                        <td>{{ $detalle }}</td>
                        <td class="amount">{{ \App\Models\Liquidacion::formatCurrency($item->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="highlight">
                    <th colspan="2">TOTAL HABERES</th>
                    <th class="amount">{{ \App\Models\Liquidacion::formatCurrency($liquidacion->total_haberes) }}</th>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Tabla de Descuentos --}}
    <div class="section">
        <div class="section-title">Descuentos</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 50%;">Concepto</th>
                    <th style="width: 30%;">Detalle</th>
                    <th style="width: 20%;" class="text-right">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($liquidacion->items->where('type', 'deduction') as $item)
                    @php
                        $meta = $item->metadata ?? [];
                        $detalle = match ($item->category) {
                            'ips' =>
                                'Base: ' . \App\Models\Liquidacion::formatCurrency($meta['base'] ?? 0) .
                                ' × ' . ($meta['rate'] ?? 9) . '%',
                            'loan' => 'Saldo pendiente de préstamos',
                            'ausencias' =>
                                ($meta['days'] ?? '-') . ' día(s) × ' .
                                (isset($meta['daily_salary'])
                                    ? \App\Models\Liquidacion::formatCurrency($meta['daily_salary']) . '/día'
                                    : '-'),
                            default => '-',
                        };
                    @endphp
                    <tr>
                        <td>{{ $item->description }}</td>
                        <td>{{ $detalle }}</td>
                        <td class="amount">{{ \App\Models\Liquidacion::formatCurrency($item->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="highlight-danger">
                    <th colspan="2">TOTAL DESCUENTOS</th>
                    <th class="amount">{{ \App\Models\Liquidacion::formatCurrency($liquidacion->total_deductions) }}</th>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Monto Neto a Pagar --}}
    <div class="net-amount-box">
        <div class="net-amount-label">Neto a Pagar</div>
        <div class="net-amount-value">{{ \App\Models\Liquidacion::formatCurrency($liquidacion->net_amount) }}</div>
    </div>

    {{-- Nota Legal --}}
    <div class="legal-note">
        <strong>Nota Legal:</strong> La presente liquidacion se realiza conforme a lo establecido en los
        Articulos 78 al 100 del Codigo del Trabajo de la Republica del Paraguay (Ley 213/93).
        El preaviso e indemnizacion no estan sujetos a descuento de IPS. El trabajador declara
        haber recibido conforme el monto total indicado, sin tener nada mas que reclamar a la empresa
        por ningun concepto derivado de la relacion laboral.
    </div>

    {{-- Firmas --}}
    <div class="signature-section">
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Empleado</div>
            <div class="signature-sublabel">{{ $liquidacion->employee?->full_name }}</div>
            <div class="signature-sublabel">CI: {{ $liquidacion->employee?->ci }}</div>
        </div>
        <div class="signature-item">
            <div class="signature-line"></div>
            <div class="signature-label">Empleador / RRHH</div>
            <div class="signature-sublabel">Firma y Sello</div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="footer">
        Documento generado el {{ now()->format('d/m/Y H:i') }} | Liquidacion #{{ $liquidacion->id }}
        @if ($city)
            | {{ $city }}, Paraguay
        @endif
    </div>
</body>

</html>
