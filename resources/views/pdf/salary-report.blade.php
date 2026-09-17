<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reporte de Salarios</title>
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
            font-size: 9px;
            line-height: 1.4;
            padding: 12mm 15mm;
        }

        .company-header {
            text-align: center;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 2px solid #0d9488;
        }

        .company-logo {
            max-height: 36px;
            max-width: 110px;
            margin-bottom: 6px;
        }

        .company-name {
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .company-info {
            font-size: 8px;
        }

        .title {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 14px 0 3px 0;
        }

        .subtitle {
            text-align: center;
            font-size: 9px;
            margin-bottom: 14px;
        }

        .section-title {
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            padding: 4px 0;
            margin-bottom: 6px;
            border-bottom: 1px solid #000;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }

        thead th {
            background-color: #f0f0f0;
            font-weight: bold;
            font-size: 8px;
            text-align: center;
            padding: 4px 3px;
            border: 1px solid #ccc;
        }

        tbody td {
            padding: 3px 3px;
            border: 1px solid #ddd;
            font-size: 8px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .row-total {
            background-color: #f5f5f5;
            font-weight: bold;
        }

        .badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 7.5px;
        }

        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-info    { background-color: #dbeafe; color: #1e40af; }
        .badge-gray    { background-color: #f3f4f6; color: #374151; }

        .summary {
            display: table;
            width: 100%;
            margin-bottom: 16px;
        }

        .summary-item {
            display: table-cell;
            text-align: center;
            padding: 6px 8px;
            border: 1px solid #ddd;
            background: #fafafa;
        }

        .summary-value {
            font-size: 11px;
            font-weight: bold;
        }

        .summary-label {
            font-size: 7.5px;
            color: #555;
            margin-top: 2px;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 7.5px;
            border-top: 1px solid #ccc;
            padding-top: 8px;
        }
    </style>
</head>

<body>

    {{-- Encabezado de empresa --}}
    @if($companyName)
    <div class="company-header">
        @if($companyLogo)
            <div><img src="{{ $companyLogo }}" class="company-logo" alt="Logo"></div>
        @endif
        <div class="company-name">{{ $companyName }}</div>
        <div class="company-info">
            @if($companyRuc) RUC: {{ $companyRuc }} @endif
            @if($companyAddress) &nbsp;|&nbsp; {{ $companyAddress }} @endif
        </div>
    </div>
    @endif

    {{-- Título --}}
    <div class="title">Reporte de Salarios</div>
    <div class="subtitle">
        @if($period)
            Planilla: {{ $period->name }}
            ({{ $period->start_date->format('d/m/Y') }} — {{ $period->end_date->format('d/m/Y') }})
        @else
            Todas las planillas
        @endif
        &nbsp;|&nbsp; Generado el {{ now()->format('d/m/Y H:i') }}
    </div>

    {{-- Filtros aplicados --}}
    @if(count($appliedFilters) > 0)
    <div style="background: #f8f8f8; border: 1px solid #ddd; padding: 5px 10px; font-size: 8px; margin-bottom: 14px;">
        <strong>Filtros aplicados:</strong>
        @foreach($appliedFilters as $label => $value)
            {{ $label }}: <strong>{{ $value }}</strong>
            @if(! $loop->last) &nbsp;|&nbsp; @endif
        @endforeach
    </div>
    @endif

    {{-- Resumen --}}
    <div class="summary">
        <div class="summary-item">
            <div class="summary-value">{{ $totalEmployees }}</div>
            <div class="summary-label">Empleados</div>
        </div>
        <div class="summary-item">
            <div class="summary-value">Gs. {{ number_format($totalBaseSalary, 0, ',', '.') }}</div>
            <div class="summary-label">Total Salario Base</div>
        </div>
        <div class="summary-item">
            <div class="summary-value">Gs. {{ number_format($totalPerceptions, 0, ',', '.') }}</div>
            <div class="summary-label">Total Percepciones</div>
        </div>
        <div class="summary-item">
            <div class="summary-value">Gs. {{ number_format($totalDeductions, 0, ',', '.') }}</div>
            <div class="summary-label">Total Deducciones</div>
        </div>
        <div class="summary-item">
            <div class="summary-value">Gs. {{ number_format($totalNet, 0, ',', '.') }}</div>
            <div class="summary-label">Total Neto a Pagar</div>
        </div>
    </div>

    {{-- Tabla principal --}}
    @php
        $showCol     = array_flip($selectedColumns);
        $colCount    = count($selectedColumns);

        $monetaryFields = ['base_salary', 'total_perceptions', 'ips_amount', 'loan_amount',
                           'judicial_amount', 'voluntary_amount', 'total_deductions', 'net_salary'];
        $monetaryTotals = [
            'base_salary'       => $totalBaseSalary,
            'total_perceptions' => $totalPerceptions,
            'ips_amount'        => $totalIps,
            'loan_amount'       => $totalLoans,
            'judicial_amount'   => $totalJudicial,
            'voluntary_amount'  => $totalVoluntary,
            'total_deductions'  => $totalDeductions,
            'net_salary'        => $totalNet,
        ];
        $nonMonetaryCols = ['employee_name', 'ci', 'branch_name', 'position_name', 'payment_method', 'status'];
        $leadingSpanCount = count(array_intersect(array_keys(array_filter($showCol, fn($v, $k) => in_array($k, ['employee_name','ci','branch_name','position_name']), ARRAY_FILTER_USE_BOTH)), $selectedColumns));
    @endphp
    <table>
        <thead>
            <tr>
                @if(isset($showCol['employee_name'])) <th style="width:14%">Empleado</th> @endif
                @if(isset($showCol['ci']))             <th style="width:6%">CI</th> @endif
                @if(isset($showCol['branch_name']))    <th style="width:7%">Sucursal</th> @endif
                @if(isset($showCol['position_name']))  <th style="width:9%">Cargo</th> @endif
                @if(isset($showCol['base_salary']))    <th style="width:8%">Salario Base</th> @endif
                @if(isset($showCol['total_perceptions'])) <th style="width:7%">+Percepciones</th> @endif
                @if(isset($showCol['ips_amount']))     <th style="width:7%">IPS</th> @endif
                @if(isset($showCol['loan_amount']))    <th style="width:8%">Desc. por Deuda</th> @endif
                @if(isset($showCol['judicial_amount'])) <th style="width:6%">Judiciales</th> @endif
                @if(isset($showCol['voluntary_amount'])) <th style="width:6%">Voluntarias</th> @endif
                @if(isset($showCol['total_deductions'])) <th style="width:8%">-Deducciones</th> @endif
                @if(isset($showCol['net_salary']))     <th style="width:8%">Neto a Pagar</th> @endif
                @if(isset($showCol['payment_method'])) <th style="width:6%">Método</th> @endif
                @if(isset($showCol['status']))         <th style="width:6%">Estado</th> @endif
            </tr>
        </thead>
        <tbody>
            @forelse($payrolls as $p)
            <tr>
                @if(isset($showCol['employee_name'])) <td>{{ $p->last_name }}, {{ $p->first_name }}</td> @endif
                @if(isset($showCol['ci']))             <td class="text-center">{{ $p->ci }}</td> @endif
                @if(isset($showCol['branch_name']))    <td class="text-center">{{ $p->branch_name }}</td> @endif
                @if(isset($showCol['position_name']))  <td>{{ $p->position_name ?? '—' }}</td> @endif
                @if(isset($showCol['base_salary']))    <td class="text-right">{{ number_format($p->base_salary, 0, ',', '.') }}</td> @endif
                @if(isset($showCol['total_perceptions'])) <td class="text-right">{{ number_format($p->total_perceptions, 0, ',', '.') }}</td> @endif
                @if(isset($showCol['ips_amount']))     <td class="text-right">{{ $p->ips_amount > 0 ? number_format($p->ips_amount, 0, ',', '.') : '—' }}</td> @endif
                @if(isset($showCol['loan_amount']))    <td class="text-right">{{ $p->loan_amount > 0 ? number_format($p->loan_amount, 0, ',', '.') : '—' }}</td> @endif
                @if(isset($showCol['judicial_amount'])) <td class="text-right">{{ $p->judicial_amount > 0 ? number_format($p->judicial_amount, 0, ',', '.') : '—' }}</td> @endif
                @if(isset($showCol['voluntary_amount'])) <td class="text-right">{{ $p->voluntary_amount > 0 ? number_format($p->voluntary_amount, 0, ',', '.') : '—' }}</td> @endif
                @if(isset($showCol['total_deductions'])) <td class="text-right">{{ number_format($p->total_deductions, 0, ',', '.') }}</td> @endif
                @if(isset($showCol['net_salary']))     <td class="text-right" style="font-weight:bold">{{ number_format($p->net_salary, 0, ',', '.') }}</td> @endif
                @if(isset($showCol['payment_method']))
                <td class="text-center">
                    @php $pm = $p->payment_method; @endphp
                    <span class="badge {{ $pm === 'cash' ? 'badge-warning' : 'badge-info' }}">
                        {{ $pm === 'cash' ? 'Efectivo' : 'Transferencia' }}
                    </span>
                </td>
                @endif
                @if(isset($showCol['status']))
                <td class="text-center">
                    @php
                        $statusClass = match($p->status) {
                            'paid'      => 'badge-success',
                            'disbursed' => 'badge-info',
                            'approved'  => 'badge-warning',
                            default     => 'badge-gray',
                        };
                        $statusLabel = \App\Models\Payroll::getStatusLabels()[$p->status] ?? $p->status;
                    @endphp
                    <span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
                </td>
                @endif
            </tr>
            @empty
            <tr>
                <td colspan="{{ $colCount }}" class="text-center" style="padding:10px; color:#888;">
                    Sin registros para los filtros seleccionados.
                </td>
            </tr>
            @endforelse

            {{-- Fila de totales (solo columnas monetarias seleccionadas) --}}
            @if($payrolls->isNotEmpty())
            <tr class="row-total">
                @php
                    // Calcular cuántas columnas no-monetarias van al inicio (para el colspan del label TOTALES)
                    $leadingNonMonetary = 0;
                    foreach (['employee_name','ci','branch_name','position_name'] as $f) {
                        if (isset($showCol[$f])) { $leadingNonMonetary++; }
                    }
                    if ($leadingNonMonetary === 0) { $leadingNonMonetary = 1; } // al menos 1
                @endphp
                <td colspan="{{ $leadingNonMonetary }}" style="text-align:right; padding-right:6px;">TOTALES</td>
                @foreach(['base_salary','total_perceptions','ips_amount','loan_amount','judicial_amount','voluntary_amount','total_deductions','net_salary'] as $mf)
                    @if(isset($showCol[$mf]))
                        <td class="text-right" @if($mf === 'net_salary') style="font-weight:bold" @endif>
                            {{ number_format($monetaryTotals[$mf], 0, ',', '.') }}
                        </td>
                    @endif
                @endforeach
                @php
                    $trailingNonMonetary = 0;
                    foreach (['payment_method','status'] as $f) {
                        if (isset($showCol[$f])) { $trailingNonMonetary++; }
                    }
                @endphp
                @if($trailingNonMonetary > 0)
                    <td colspan="{{ $trailingNonMonetary }}"></td>
                @endif
            </tr>
            @endif
        </tbody>
    </table>

    {{-- Sub-tablas (percepciones, deducciones, método de pago) con toggle --}}
    @php
        $showPerceptions   = in_array('perceptions', $showSubtables);
        $showDeductions    = in_array('deductions', $showSubtables);
        $showPaymentMethods = in_array('payment_methods', $showSubtables);
        $hasSubtables = $showPerceptions || $showDeductions || $showPaymentMethods;
    @endphp
    @if($hasSubtables && ($perceptionSummary->isNotEmpty() || $deductionSummary->isNotEmpty() || $paymentMethodSummary->isNotEmpty()))
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px;">
        <tr>
            {{-- Percepciones --}}
            @if($showPerceptions)
            <td style="width: {{ ($showDeductions && $showPaymentMethods) ? '38%' : ($showDeductions || $showPaymentMethods ? '50%' : '100%') }}; vertical-align: top; padding-right: 10px;">
                <div class="section-title">Desglose de Percepciones</div>
                <table style="margin-bottom: 0;">
                    <thead>
                        <tr>
                            <th style="text-align: left; width: 58%;">Concepto</th>
                            <th style="width: 18%;">Empleados</th>
                            <th style="width: 24%;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($perceptionSummary as $item)
                        <tr>
                            <td>{{ $item->description }}</td>
                            <td class="text-center">{{ $item->employees_count }}</td>
                            <td class="text-right">{{ number_format($item->total_amount, 0, ',', '.') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="text-center" style="color:#888;">Sin percepciones</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            @endif

            {{-- Deducciones --}}
            @if($showDeductions)
            <td style="width: {{ ($showPerceptions && $showPaymentMethods) ? '38%' : ($showPerceptions || $showPaymentMethods ? '50%' : '100%') }}; vertical-align: top; padding: 0 10px; border-left: 1px solid #ddd;">
                <div class="section-title">Desglose de Deducciones</div>
                <table style="margin-bottom: 0;">
                    <thead>
                        <tr>
                            <th style="text-align: left; width: 58%;">Concepto</th>
                            <th style="width: 18%;">Empleados</th>
                            <th style="width: 24%;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($deductionSummary as $item)
                        <tr>
                            <td>{{ $item->description }}</td>
                            <td class="text-center">{{ $item->employees_count }}</td>
                            <td class="text-right">{{ number_format($item->total_amount, 0, ',', '.') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="text-center" style="color:#888;">Sin deducciones</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            @endif

            {{-- Método de pago --}}
            @if($showPaymentMethods)
            <td style="width: {{ ($showPerceptions && $showDeductions) ? '24%' : ($showPerceptions || $showDeductions ? '50%' : '100%') }}; vertical-align: top; padding-left: 10px; border-left: 1px solid #ddd;">
                <div class="section-title">Por Método de Pago</div>
                <table style="margin-bottom: 0;">
                    <thead>
                        <tr>
                            <th style="text-align: left; width: 40%;">Método</th>
                            <th style="width: 20%;">Empl.</th>
                            <th style="width: 40%;">Neto a Pagar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($paymentMethodSummary as $item)
                        <tr>
                            <td>{{ $item['label'] }}</td>
                            <td class="text-center">{{ $item['count'] }}</td>
                            <td class="text-right">{{ number_format($item['total_net'], 0, ',', '.') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="text-center" style="color:#888;">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            @endif
        </tr>
    </table>
    @endif

    <div class="footer">
        Reporte generado el {{ now()->format('d/m/Y \a \l\a\s H:i') }} · {{ config('app.name') }}
    </div>

</body>
</html>
