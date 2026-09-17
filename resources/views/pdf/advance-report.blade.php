<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reporte de Adelantos</title>
    <style>
        @page {
            size: A4 {{ $orientation }};
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
            color: #444;
            margin-bottom: 20px;
        }

        .section-title {
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            padding: 5px 0;
            margin-bottom: 8px;
            border-bottom: 1px solid #000;
        }

        /* Encabezado de empresa en modo agrupado */
        .section-header {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            background-color: #e8e8e8;
            border: 1px solid #000;
            padding: 5px 8px;
            margin-top: 18px;
            margin-bottom: 0;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .summary-table td {
            padding: 5px 8px;
            border: 1px solid #ccc;
            font-size: 10px;
        }

        .summary-table .label {
            font-weight: bold;
            background: #f5f5f5;
            width: 40%;
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }

        table.data-table th {
            background: #f0f0f0;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            padding: 5px 6px;
            border: 1px solid #ccc;
            text-align: left;
        }

        table.data-table td {
            padding: 4px 6px;
            border: 1px solid #ccc;
            font-size: 9px;
            vertical-align: top;
        }

        table.data-table tr:nth-child(even) td {
            background: #fafafa;
        }

        .amount {
            text-align: right;
            white-space: nowrap;
        }

        .status-badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: bold;
        }

        .status-pending   { background: #fef3cd; color: #856404; }
        .status-approved  { background: #cfe2ff; color: #084298; }
        .status-disbursed { background: #cfe2ff; color: #052c65; }
        .status-paid      { background: #d1e7dd; color: #0a3622; }
        .status-rejected  { background: #f8d7da; color: #58151c; }
        .status-cancelled { background: #e2e3e5; color: #383d41; }

        .subtotal-row {
            display: table;
            width: 100%;
            border: 1px solid #ccc;
            border-top: none;
            padding: 4px 8px;
            background-color: #f0f0f0;
            font-size: 9px;
            margin-bottom: 4px;
        }

        .subtotal-row .st-item {
            display: table-cell;
            width: 50%;
        }

        .subtotal-row .st-label {
            font-weight: bold;
        }

        .grand-total {
            margin-top: 16px;
            border: 2px solid #000;
            padding: 8px 12px;
            background-color: #f5f5f5;
            page-break-inside: avoid;
            page-break-before: auto;
        }

        .grand-total-title {
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
            margin-bottom: 6px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 4px;
        }

        .grand-total-grid {
            display: table;
            width: 100%;
        }

        .grand-total-item {
            display: table-cell;
            width: 20%;
            padding: 3px 0;
        }

        .grand-total-label {
            font-weight: bold;
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: #666;
            font-style: italic;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #ccc;
            padding-top: 8px;
            color: #666;
        }
    </style>
</head>

<body>

    {{-- Encabezado de empresa (solo cuando hay una empresa identificada) --}}
    @if($showCompanyHeader)
    <div class="company-header">
        @if($companyLogo)
            <div><img src="{{ $companyLogo }}" class="company-logo" alt="Logo"></div>
        @endif
        <div class="company-name">{{ $companyName }}</div>
        <div class="company-info">
            @if($companyRuc) RUC: {{ $companyRuc }} @if($companyAddress) &nbsp;|&nbsp; @endif @endif
            @if($companyAddress) {{ $companyAddress }} @if($city), {{ $city }} @endif @endif
        </div>
        @if($companyPhone || $companyEmail)
        <div class="company-info">
            @if($companyPhone) Tel: {{ $companyPhone }} @endif
            @if($companyPhone && $companyEmail) &nbsp;|&nbsp; @endif
            @if($companyEmail) {{ $companyEmail }} @endif
        </div>
        @endif
    </div>
    @endif

    {{-- Título del documento --}}
    <div class="title">Reporte de Adelantos de Salario</div>
    <div class="subtitle">
        Período: {{ $fromFormatted }} al {{ $toFormatted }}
        @if($status) &nbsp;·&nbsp; Estado: {{ \App\Models\Advance::getStatusLabel($status) }} @endif
        @if($paymentMethod) &nbsp;·&nbsp; Método: {{ \App\Models\Advance::getPaymentMethodLabel($paymentMethod) }} @endif
    </div>

    {{-- Resumen --}}
    <div class="section-title">Resumen</div>
    <table class="summary-table">
        <tr>
            <td class="label">Total de adelantos</td>
            <td>{{ $advances->count() }}</td>
        </tr>
        <tr>
            <td class="label">Empleados involucrados</td>
            <td>{{ $totalEmployees }}</td>
        </tr>
        <tr>
            <td class="label">Monto total</td>
            <td><strong>Gs. {{ number_format((float) $totalAmount, 0, ',', '.') }}</strong></td>
        </tr>
        @if($amountTransfer > 0)
        <tr>
            <td class="label">Total acreditación</td>
            <td>Gs. {{ number_format((float) $amountTransfer, 0, ',', '.') }}</td>
        </tr>
        @endif
        @if($amountCash > 0)
        <tr>
            <td class="label">Total efectivo</td>
            <td>Gs. {{ number_format((float) $amountCash, 0, ',', '.') }}</td>
        </tr>
        @endif
        @foreach(\App\Models\Advance::getStatusOptions() as $key => $label)
            @if(($countByStatus[$key] ?? 0) > 0)
            <tr>
                <td class="label">{{ $label }}</td>
                <td>{{ $countByStatus[$key] }} adelanto(s) &nbsp;·&nbsp; Gs. {{ number_format((float) $advances->where('status', $key)->sum('amount'), 0, ',', '.') }}</td>
            </tr>
            @endif
        @endforeach
    </table>

    {{-- Detalle --}}
    @if($advances->isEmpty())
        <div class="empty-state">No hay adelantos para el período seleccionado.</div>
    @else

        @php
            $showCol  = array_flip($selectedColumns);
            $allLabels = $columnLabels;
            $headers  = array_values(array_intersect_key($allLabels, $showCol));

            /**
             * Renderiza filas de tabla para una colección de adelantos con columnas seleccionables.
             *
             * @param  iterable  $rows
             * @param  array<string, int>  $showCol
             */
            function advanceRows($rows, $showCol) {
                $i = 0;
                foreach ($rows as $a) {
                    $even = $i % 2 === 1 ? 'background:#fafafa;' : '';
                    echo '<tr style="' . $even . '">';
                    if (isset($showCol['employee_name']))    echo '<td>' . e(strtoupper($a->last_name)) . ', ' . e($a->first_name) . '</td>';
                    if (isset($showCol['ci']))               echo '<td>' . e($a->ci) . '</td>';
                    if (isset($showCol['company_name']))     echo '<td>' . e($a->company_name ?? '—') . '</td>';
                    if (isset($showCol['branch_name']))      echo '<td>' . e($a->branch_name) . '</td>';
                    if (isset($showCol['amount']))           echo '<td style="text-align:right;white-space:nowrap;">Gs. ' . number_format((float) $a->amount, 0, ',', '.') . '</td>';
                    if (isset($showCol['payment_method']))   echo '<td>' . e(\App\Models\Advance::getPaymentMethodLabel($a->payment_method)) . '</td>';
                    if (isset($showCol['status']))           echo '<td><span class="status-badge status-' . $a->status . '">' . \App\Models\Advance::getStatusLabel($a->status) . '</span></td>';
                    if (isset($showCol['created_at']))       echo '<td>' . \Carbon\Carbon::parse($a->created_at)->format('d/m/Y') . '</td>';
                    if (isset($showCol['approved_at']))      echo '<td>' . ($a->approved_at ? \Carbon\Carbon::parse($a->approved_at)->format('d/m/Y') : '—') . '</td>';
                    if (isset($showCol['approved_by_name'])) echo '<td>' . e($a->approved_by_name ?? '—') . '</td>';
                    if (isset($showCol['notes']))            echo '<td>' . e($a->notes ?? '—') . '</td>';
                    echo '</tr>';
                    $i++;
                }
            }

            $theadHtml = '<thead><tr>';
            foreach ($headers as $label) {
                $theadHtml .= '<th>' . e($label) . '</th>';
            }
            $theadHtml .= '</tr></thead>';
        @endphp

        {{-- ─── MODO FLAT: empresa filtrada o una sola empresa ─── --}}
        @if($groupMode === 'flat')
            <div class="section-title">Detalle</div>
            <table class="data-table">
                {!! $theadHtml !!}
                <tbody>
                    @php advanceRows($advances, $showCol) @endphp
                </tbody>
            </table>

        {{-- ─── MODO COMPANY: múltiples empresas sin filtro ─── --}}
        @elseif($groupMode === 'company')
            @foreach($groups as $companyGroupName => $rows)
                <div class="section-header">{{ $companyGroupName ?: 'Sin empresa' }}</div>
                <table class="data-table">
                    {!! $theadHtml !!}
                    <tbody>
                        @php advanceRows($rows, $showCol) @endphp
                    </tbody>
                </table>
                @php
                    $rowTransfer = $rows->where('payment_method', 'transfer')->sum('amount');
                    $rowCash     = $rows->where('payment_method', 'cash')->sum('amount');
                @endphp
                <div class="subtotal-row">
                    <div class="st-item">
                        <span class="st-label">Empleados:</span> {{ $rows->unique('ci')->count() }}
                        &nbsp;·&nbsp;
                        <span class="st-label">Adelantos:</span> {{ $rows->count() }}
                    </div>
                    <div class="st-item">
                        <span class="st-label">Total:</span> Gs. {{ number_format((float) $rows->sum('amount'), 0, ',', '.') }}
                        @if($rowTransfer > 0)
                            &nbsp;·&nbsp; <span class="st-label">Acred.:</span> Gs. {{ number_format((float) $rowTransfer, 0, ',', '.') }}
                        @endif
                        @if($rowCash > 0)
                            &nbsp;·&nbsp; <span class="st-label">Efect.:</span> Gs. {{ number_format((float) $rowCash, 0, ',', '.') }}
                        @endif
                    </div>
                </div>
            @endforeach
        @endif

        {{-- Gran total --}}
        <div class="grand-total">
            <div class="grand-total-title">Total General</div>
            <div class="grand-total-grid">
                <div class="grand-total-item">
                    <span class="grand-total-label">Total adelantos:</span> {{ $advances->count() }}
                </div>
                <div class="grand-total-item">
                    <span class="grand-total-label">Empleados:</span> {{ $totalEmployees }}
                </div>
                <div class="grand-total-item">
                    <span class="grand-total-label">Monto total:</span>
                    Gs. {{ number_format((float) $totalAmount, 0, ',', '.') }}
                </div>
                <div class="grand-total-item">
                    <span class="grand-total-label">Acreditación:</span>
                    Gs. {{ number_format((float) $amountTransfer, 0, ',', '.') }}
                </div>
                <div class="grand-total-item">
                    <span class="grand-total-label">Efectivo:</span>
                    Gs. {{ number_format((float) $amountCash, 0, ',', '.') }}
                </div>
            </div>
        </div>

    @endif

    {{-- Pie de página --}}
    <div class="footer">
        Generado el {{ now()->format('d/m/Y H:i') }}
        @if($city) &nbsp;·&nbsp; {{ $city }}, Paraguay @endif
    </div>

</body>
</html>
