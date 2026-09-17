<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reporte de Retiro de Mercaderías</title>
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

        /* Tabla principal de retiros */
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

        /* Tabla de cuotas anidada dentro de cada retiro */
        .installments-wrapper {
            border: 1px solid #ccc;
            border-top: none;
            background: #f9f9f9;
            padding: 6px 10px;
            margin-bottom: 10px;
        }

        .installments-label {
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            color: #555;
            margin-bottom: 4px;
        }

        table.installments-table {
            width: 100%;
            border-collapse: collapse;
        }

        table.installments-table th {
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            background: #ececec;
            border: 1px solid #ddd;
            padding: 3px 5px;
            text-align: left;
        }

        table.installments-table td {
            font-size: 8px;
            border: 1px solid #ddd;
            padding: 2px 5px;
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
        .status-paid      { background: #d1e7dd; color: #0a3622; }
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
            width: 25%;
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

    {{-- Encabezado de empresa --}}
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
    <div class="title">Reporte de Retiro de Mercaderías</div>
    <div class="subtitle">
        Período: {{ $fromFormatted }} al {{ $toFormatted }}
        @if($status) &nbsp;·&nbsp; Estado: {{ \App\Models\MerchandiseWithdrawal::getStatusLabel($status) }} @endif
    </div>

    {{-- Resumen --}}
    <div class="section-title">Resumen</div>
    <table class="summary-table">
        <tr>
            <td class="label">Total de retiros</td>
            <td>{{ $withdrawals->count() }}</td>
        </tr>
        <tr>
            <td class="label">Empleados involucrados</td>
            <td>{{ $totalEmployees }}</td>
        </tr>
        <tr>
            <td class="label">Monto total otorgado</td>
            <td><strong>Gs. {{ number_format((float) $totalAmount, 0, ',', '.') }}</strong></td>
        </tr>
        <tr>
            <td class="label">Saldo pendiente total</td>
            <td><strong>Gs. {{ number_format((float) $totalPending, 0, ',', '.') }}</strong></td>
        </tr>
        @foreach(\App\Models\MerchandiseWithdrawal::getStatusOptions() as $key => $label)
            @if(($countByStatus[$key] ?? 0) > 0)
            <tr>
                <td class="label">{{ $label }}</td>
                <td>{{ $countByStatus[$key] }} retiro(s)</td>
            </tr>
            @endif
        @endforeach
    </table>

    {{-- Detalle --}}
    @if($withdrawals->isEmpty())
        <div class="empty-state">No hay retiros de mercadería para el período seleccionado.</div>
    @else

        @php
            $showCol  = array_flip($selectedColumns);
            $colCount = count($selectedColumns);

            $allLabels = $columnLabels;
            $headers   = array_values(array_intersect_key($allLabels, $showCol));

            $theadHtml = '<thead><tr>';
            foreach ($headers as $label) {
                $theadHtml .= '<th>' . e($label) . '</th>';
            }
            $theadHtml .= '</tr></thead>';

            /**
             * Renderiza filas y cuotas anidadas para una colección de retiros con columnas seleccionables.
             *
             * @param  \Illuminate\Support\Collection  $rows
             * @param  \Illuminate\Support\Collection  $installmentsByWithdrawal
             * @param  array<string, int>  $showCol  Claves de columnas activas
             * @param  int  $colCount  Número de columnas seleccionadas (para colspan)
             */
            function withdrawalRows($rows, $installmentsByWithdrawal, $showCol, $colCount) {
                $installmentLabels = ['pending' => 'Pendiente', 'paid' => 'Pagada', 'cancelled' => 'Cancelada'];
                foreach ($rows as $w) {
                    echo '<tr>';
                    if (isset($showCol['employee_name']))      echo '<td>' . e(strtoupper($w->last_name)) . ', ' . e($w->first_name) . '</td>';
                    if (isset($showCol['ci']))                 echo '<td>' . e($w->ci) . '</td>';
                    if (isset($showCol['company_name']))       echo '<td>' . e($w->company_name ?? '—') . '</td>';
                    if (isset($showCol['branch_name']))        echo '<td>' . e($w->branch_name) . '</td>';
                    if (isset($showCol['total_amount']))       echo '<td style="text-align:right;white-space:nowrap;">Gs. ' . number_format((float) $w->total_amount, 0, ',', '.') . '</td>';
                    if (isset($showCol['installments_count'])) echo '<td style="text-align:center;">' . $w->paid_installments_count . '/' . $w->installments_count . '</td>';
                    if (isset($showCol['installment_amount'])) echo '<td style="text-align:right;white-space:nowrap;">Gs. ' . number_format((float) $w->installment_amount, 0, ',', '.') . '</td>';
                    if (isset($showCol['outstanding_balance'])) echo '<td style="text-align:right;white-space:nowrap;">Gs. ' . number_format((float) $w->outstanding_balance, 0, ',', '.') . '</td>';
                    if (isset($showCol['status']))             echo '<td><span class="status-badge status-' . $w->status . '">' . \App\Models\MerchandiseWithdrawal::getStatusLabel($w->status) . '</span></td>';
                    if (isset($showCol['approved_at']))        echo '<td>' . ($w->approved_at ? \Carbon\Carbon::parse($w->approved_at)->format('d/m/Y') : '—') . '</td>';
                    if (isset($showCol['approved_by_name']))   echo '<td>' . e($w->approved_by_name ?? '—') . '</td>';
                    if (isset($showCol['notes']))              echo '<td>' . e($w->notes ?? '—') . '</td>';
                    echo '</tr>';

                    // Cuotas anidadas (siempre visibles)
                    $installments = $installmentsByWithdrawal[$w->id] ?? collect();
                    if ($installments->isNotEmpty()) {
                        echo '<tr><td colspan="' . $colCount . '" style="padding:0;">';
                        echo '<div class="installments-wrapper">';
                        echo '<div class="installments-label">Cuotas</div>';
                        echo '<table class="installments-table">';
                        echo '<thead><tr><th>N°</th><th>Monto (Gs.)</th><th>Vencimiento</th><th>Estado</th><th>Pagado el</th></tr></thead>';
                        echo '<tbody>';
                        foreach ($installments as $inst) {
                            echo '<tr>';
                            echo '<td>' . $inst->installment_number . '/' . $w->installments_count . '</td>';
                            echo '<td style="text-align:right;">Gs. ' . number_format((float) $inst->amount, 0, ',', '.') . '</td>';
                            echo '<td>' . ($inst->due_date ? \Carbon\Carbon::parse($inst->due_date)->format('d/m/Y') : '—') . '</td>';
                            echo '<td>' . ($installmentLabels[$inst->status] ?? $inst->status) . '</td>';
                            echo '<td>' . ($inst->paid_at ? \Carbon\Carbon::parse($inst->paid_at)->format('d/m/Y') : '—') . '</td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table></div></td></tr>';
                    }
                }
            }
        @endphp

        @if($groupMode === 'flat')
            <div class="section-title">Detalle</div>
            <table class="data-table">
                {!! $theadHtml !!}
                <tbody>
                    @php withdrawalRows($withdrawals, $installmentsByWithdrawal, $showCol, $colCount) @endphp
                </tbody>
            </table>

        @elseif($groupMode === 'company')
            @foreach($groups as $companyGroupName => $rows)
                <div class="section-header">{{ $companyGroupName ?: 'Sin empresa' }}</div>
                <table class="data-table">
                    {!! $theadHtml !!}
                    <tbody>
                        @php withdrawalRows($rows, $installmentsByWithdrawal, $showCol, $colCount) @endphp
                    </tbody>
                </table>
                <div class="subtotal-row">
                    <div class="st-item">
                        <span class="st-label">Empleados:</span> {{ $rows->unique('ci')->count() }}
                        &nbsp;·&nbsp;
                        <span class="st-label">Retiros:</span> {{ $rows->count() }}
                    </div>
                    <div class="st-item">
                        <span class="st-label">Total otorgado:</span> Gs. {{ number_format((float) $rows->sum('total_amount'), 0, ',', '.') }}
                        &nbsp;·&nbsp;
                        <span class="st-label">Saldo pend.:</span> Gs. {{ number_format((float) $rows->sum('outstanding_balance'), 0, ',', '.') }}
                    </div>
                </div>
            @endforeach
        @endif

        {{-- Gran total --}}
        <div class="grand-total">
            <div class="grand-total-title">Total General</div>
            <div class="grand-total-grid">
                <div class="grand-total-item">
                    <span class="grand-total-label">Total retiros:</span> {{ $withdrawals->count() }}
                </div>
                <div class="grand-total-item">
                    <span class="grand-total-label">Empleados:</span> {{ $totalEmployees }}
                </div>
                <div class="grand-total-item">
                    <span class="grand-total-label">Monto total:</span>
                    Gs. {{ number_format((float) $totalAmount, 0, ',', '.') }}
                </div>
                <div class="grand-total-item">
                    <span class="grand-total-label">Saldo pendiente:</span>
                    Gs. {{ number_format((float) $totalPending, 0, ',', '.') }}
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
