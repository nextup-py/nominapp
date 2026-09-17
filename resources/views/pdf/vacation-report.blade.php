<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Informe de Vacaciones</title>
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

        /* Sección de empresa (nivel 1) */
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

        /* Sub-sección de mes (nivel 2) */
        .subsection-header {
            font-size: 10px;
            font-weight: bold;
            background-color: #f5f5f5;
            border: 1px solid #ccc;
            border-top: none;
            padding: 4px 8px;
            margin-bottom: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }

        th, td {
            border: 1px solid #000;
            padding: 4px 3px;
            font-size: 9px;
        }

        th {
            font-weight: bold;
            background-color: #f5f5f5;
            text-align: center;
            text-transform: uppercase;
        }

        td {
            text-align: center;
        }

        td.text-left {
            text-align: left;
        }

        tr.row-even {
            background-color: #fafafa;
        }

        /* Subtotales de sección/subsección */
        .subtotal-row {
            display: table;
            width: 100%;
            border: 1px solid #000;
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

        /* Gran total al final */
        .grand-total {
            margin-top: 16px;
            border: 2px solid #000;
            padding: 8px 12px;
            background-color: #f5f5f5;
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
            width: 50%;
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

    {{-- Encabezado: cuando hay una sola empresa (por filtro o porque solo existe una) --}}
    @if ($showCompanyHeader)
        <div class="company-header">
            @if ($companyLogo)
                <img src="{{ $companyLogo }}" alt="Logo" class="company-logo"><br>
            @endif
            <div class="company-name">{{ $companyName }}</div>
            <div class="company-info">
                @if ($companyRuc) RUC: {{ $companyRuc }} @endif
                @if ($companyRuc && $employerNumber) | @endif
                @if ($employerNumber) Nro. Patronal: {{ $employerNumber }} @endif
            </div>
            @if ($companyAddress)
                <div class="company-info">{{ $companyAddress }}</div>
            @endif
            @if ($companyPhone || $companyEmail)
                <div class="company-info">
                    @if ($companyPhone) Tel: {{ $companyPhone }} @endif
                    @if ($companyPhone && $companyEmail) | @endif
                    @if ($companyEmail) {{ $companyEmail }} @endif
                </div>
            @endif
        </div>
    @endif

    {{-- Título del documento --}}
    <div class="title">Informe de Vacaciones</div>
    <div class="subtitle">
        @if ($monthName) {{ $monthName }} @endif {{ $year }}
        &nbsp;·&nbsp; {{ \App\Models\Vacation::getStatusLabel($status) }}
    </div>

    @php
        $showCol = array_flip($selectedColumns);
        $allLabels = $columnLabels;
        $headers = array_values(array_intersect_key($allLabels, $showCol));

        /**
         * Renderiza las filas de vacaciones para las columnas seleccionadas.
         *
         * @param  iterable  $rows
         * @param  array<string, int>  $showCol  Claves de columnas activas (array_flip de $selectedColumns)
         */
        function vacationRows($rows, $showCol) {
            $i = 0;
            foreach ($rows as $v) {
                $even   = $i % 2 === 1 ? 'row-even' : '';
                $amount = ($v->payment_amount !== null && $v->payment_amount > 0)
                    ? 'Gs. ' . number_format((float) $v->payment_amount, 0, ',', '.')
                    : '—';
                $startDate  = $v->start_date  ? \Carbon\Carbon::parse($v->start_date)->format('d/m/Y')  : '—';
                $endDate    = $v->end_date    ? \Carbon\Carbon::parse($v->end_date)->format('d/m/Y')    : '—';
                $returnDate = $v->return_date ? \Carbon\Carbon::parse($v->return_date)->format('d/m/Y') : '—';

                echo '<tr class="' . $even . '">';
                if (isset($showCol['employee_name'])) echo '<td class="text-left">' . strtoupper(e($v->last_name)) . ', ' . e($v->first_name) . '</td>';
                if (isset($showCol['ci']))             echo '<td>' . e($v->ci) . '</td>';
                if (isset($showCol['company_name']))   echo '<td>' . e($v->company_name ?? '—') . '</td>';
                if (isset($showCol['branch_name']))    echo '<td>' . e($v->branch_name) . '</td>';
                if (isset($showCol['start_date']))     echo '<td>' . $startDate . '</td>';
                if (isset($showCol['end_date']))       echo '<td>' . $endDate . '</td>';
                if (isset($showCol['return_date']))    echo '<td>' . $returnDate . '</td>';
                if (isset($showCol['business_days']))  echo '<td>' . e($v->business_days) . '</td>';
                if (isset($showCol['payment_amount'])) echo '<td>' . $amount . '</td>';
                if (isset($showCol['payment_method'])) echo '<td>' . e(\App\Models\Vacation::getPaymentMethodLabel($v->payment_method ?? 'immediate')) . '</td>';
                if (isset($showCol['status']))         echo '<td>' . e(\App\Models\Vacation::getStatusLabel($v->status)) . '</td>';
                echo '</tr>';
                $i++;
            }
        }

        /** Formatea un monto en Guaraníes o retorna '—' si es nulo/cero. */
        function fmtGs($amount): string {
            return ($amount !== null && $amount > 0)
                ? 'Gs. ' . number_format((float) $amount, 0, ',', '.')
                : '—';
        }
    @endphp

    @if ($vacations->isEmpty())
        <div class="empty-state">No hay registros de vacaciones para el período seleccionado.</div>
    @else

        {{-- Tabla de encabezados dinámica (reutilizable via @include parcial no disponible en DomPDF,
             así que la construimos inline y la pasamos a los modos de agrupación) --}}
        @php
            $theadHtml = '<thead><tr>';
            foreach ($headers as $label) {
                $theadHtml .= '<th>' . e($label) . '</th>';
            }
            $theadHtml .= '</tr></thead>';
        @endphp

        {{-- ──────────────────────────────────────────
             MODO FLAT: empresa + mes seleccionados
        ────────────────────────────────────────── --}}
        @if ($groupMode === 'flat')
            <table>
                {!! $theadHtml !!}
                <tbody>
                    @php vacationRows($vacations, $showCol) @endphp
                </tbody>
            </table>

        {{-- ──────────────────────────────────────────
             MODO COMPANY: solo mes seleccionado
             → Agrupa por empresa
        ────────────────────────────────────────── --}}
        @elseif ($groupMode === 'company')
            @foreach ($groups as $compName => $rows)
                <div class="section-header">{{ $compName ?: 'Sin empresa' }}</div>
                <table>
                    {!! $theadHtml !!}
                    <tbody>
                        @php vacationRows($rows, $showCol) @endphp
                    </tbody>
                </table>
                <div class="subtotal-row">
                    <div class="st-item"><span class="st-label">Empleados:</span> {{ $rows->unique('ci')->count() }} &nbsp;·&nbsp; <span class="st-label">Días hábiles:</span> {{ $rows->sum('business_days') }}</div>
                    <div class="st-item"><span class="st-label">Monto total:</span> {{ fmtGs($rows->sum('payment_amount')) }}</div>
                </div>
            @endforeach

        {{-- ──────────────────────────────────────────
             MODO MONTH: solo empresa seleccionada
             → Agrupa por mes
        ────────────────────────────────────────── --}}
        @elseif ($groupMode === 'month')
            @foreach ($groups as $monthNum => $rows)
                <div class="section-header">{{ $months[$monthNum] ?? $monthNum }} {{ $year }}</div>
                <table>
                    {!! $theadHtml !!}
                    <tbody>
                        @php vacationRows($rows, $showCol) @endphp
                    </tbody>
                </table>
                <div class="subtotal-row">
                    <div class="st-item"><span class="st-label">Empleados:</span> {{ $rows->unique('ci')->count() }} &nbsp;·&nbsp; <span class="st-label">Días hábiles:</span> {{ $rows->sum('business_days') }}</div>
                    <div class="st-item"><span class="st-label">Monto total:</span> {{ fmtGs($rows->sum('payment_amount')) }}</div>
                </div>
            @endforeach

        {{-- ──────────────────────────────────────────
             MODO COMPANY_MONTH: sin filtros
             → Agrupa por empresa → por mes
        ────────────────────────────────────────── --}}
        @elseif ($groupMode === 'company_month')
            @foreach ($groups as $compName => $monthGroups)
                @php $compRows = $monthGroups->flatten() @endphp
                <div class="section-header">{{ $compName ?: 'Sin empresa' }}</div>

                @foreach ($monthGroups as $monthNum => $rows)
                    <div class="subsection-header">{{ $months[$monthNum] ?? $monthNum }} {{ $year }}</div>
                    <table>
                        {!! $theadHtml !!}
                        <tbody>
                            @php vacationRows($rows, $showCol) @endphp
                        </tbody>
                    </table>
                    <div class="subtotal-row">
                        <div class="st-item"><span class="st-label">Empleados en {{ $months[$monthNum] ?? '' }}:</span> {{ $rows->unique('ci')->count() }} &nbsp;·&nbsp; <span class="st-label">Días hábiles:</span> {{ $rows->sum('business_days') }}</div>
                        <div class="st-item"><span class="st-label">Monto total:</span> {{ fmtGs($rows->sum('payment_amount')) }}</div>
                    </div>
                @endforeach

                {{-- Subtotal empresa --}}
                <div class="subtotal-row" style="background-color:#ddd; margin-bottom:12px;">
                    <div class="st-item"><span class="st-label">Total {{ $compName }}:</span> {{ $compRows->unique('ci')->count() }} empleados &nbsp;·&nbsp; {{ $compRows->sum('business_days') }} días</div>
                    <div class="st-item"><span class="st-label">Monto total:</span> {{ fmtGs($compRows->sum('payment_amount')) }}</div>
                </div>
            @endforeach
        @endif

        {{-- Gran total (siempre visible) --}}
        <div class="grand-total">
            <div class="grand-total-title">Total General</div>
            <div class="grand-total-grid">
                <div class="grand-total-item">
                    <span class="grand-total-label">Total empleados:</span> {{ $totalEmployees }}
                </div>
                <div class="grand-total-item">
                    <span class="grand-total-label">Total días hábiles:</span> {{ $totalBusinessDays }}
                </div>
                <div class="grand-total-item">
                    <span class="grand-total-label">Monto total:</span> {{ fmtGs($totalPaymentAmount) }}
                </div>
            </div>
        </div>

    @endif

    {{-- Footer --}}
    <div class="footer">
        Generado el {{ now()->format('d/m/Y H:i') }}
        @if ($city) | {{ $city }}, Paraguay @endif
    </div>

</body>
</html>
