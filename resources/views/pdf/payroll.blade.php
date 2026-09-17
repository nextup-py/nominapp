<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Recibo de Salario #{{ $payroll->id }}</title>
    <style>
        @page {
            size: A4 {{ $mode === 'print' ? 'landscape' : 'portrait' }};
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
            line-height: 1.2;
        }

        .copy-label {
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
            text-align: right;
            color: #555;
            margin-bottom: 4px;
            letter-spacing: 1px;
        }

        .company-header {
            text-align: center;
            margin-bottom: 5px;
            padding-bottom: 5px;
            border-bottom: 2px solid #0d9488;
        }

        .company-logo {
            max-height: 28px;
            max-width: 90px;
            margin-bottom: 3px;
        }

        .company-name {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .company-info {
            font-size: 7px;
        }

        .title {
            text-align: center;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            margin: 6px 0 2px 0;
        }

        .subtitle {
            text-align: center;
            font-size: 7px;
            margin-bottom: 5px;
        }

        .section {
            margin-bottom: {{ $mode === 'print' ? '8px' : '6px' }};
        }

        .section-title {
            font-weight: bold;
            font-size: 7px;
            text-transform: uppercase;
            padding: 3px 0;
            margin-bottom: 3px;
            border-bottom: 1px solid #000;
        }

        table.info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        table.info-table th {
            text-align: left;
            font-weight: bold;
            width: {{ $mode === 'print' ? '120px' : '130px' }};
            padding: {{ $mode === 'print' ? '4px 6px' : '3px 6px' }};
            border-bottom: 1px solid #eee;
            font-size: 9px;
        }

        table.info-table td {
            padding: {{ $mode === 'print' ? '4px 6px' : '3px 6px' }};
            border-bottom: 1px solid #eee;
            font-size: 9px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 4px 0;
        }

        th,
        td {
            border-bottom: 1px solid #eee;
            padding: {{ $mode === 'print' ? '4px 5px' : '3px 5px' }};
            font-size: {{ $mode === 'print' ? '8px' : '7px' }};
        }

        th {
            font-weight: bold;
            background-color: #f5f5f5;
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .amount {
            font-family: 'Courier New', monospace;
            text-align: right;
        }

        .summary-section {
            margin: {{ $mode === 'print' ? '8px' : '6px' }} 0;
            padding: {{ $mode === 'print' ? '8px' : '6px' }} 0;
        }

        .summary-title {
            font-weight: bold;
            margin-bottom: 4px;
            text-transform: uppercase;
            font-size: 7px;
        }

        .summary-grid {
            display: table;
            width: 100%;
        }

        .summary-row {
            display: table-row;
        }

        .summary-item {
            display: table-cell;
            padding: 2px 0;
        }

        .summary-label {
            font-weight: bold;
            width: {{ $mode === 'print' ? '150px' : '180px' }};
            display: inline-block;
        }

        .total-row {
            border-top: 1px solid #000;
            padding-top: 4px;
            margin-top: 4px;
        }

        .total-label {
            font-size: 10px;
            font-weight: bold;
        }

        .total-value {
            font-size: 10px;
            font-weight: bold;
        }

        .legal-note {
            margin-top: 4px;
            font-size: 6px;
            text-align: justify;
            padding: 4px 0;
        }

        table.signature-table {
            width: 100%;
            margin-top: {{ $mode === 'print' ? '55px' : '18px' }};
            border-collapse: collapse;
        }

        table.signature-table td {
            width: 50%;
            text-align: center;
            padding: 0 20px;
            border: none;
            font-size: 8px;
        }

        .signature-line {
            border-top: 1px solid #000;
            margin-bottom: 4px;
            padding-top: {{ $mode === 'print' ? '18px' : '22px' }};
        }

        .signature-label {
            font-size: 7px;
            font-weight: bold;
        }

        .signature-sublabel {
            font-size: 6px;
        }

        .footer {
            margin-top: 5px;
            text-align: center;
            font-size: 6px;
            border-top: 1px solid #ccc;
            padding-top: 3px;
        }
    </style>
</head>

<body>
    @php
        $freqLabels = ['monthly' => 'Mensual', 'biweekly' => 'Quincenal', 'weekly' => 'Semanal'];
        $perceptionTypeLabels = ['salary' => 'Salariales', 'viaticos' => 'Viáticos', 'subsidy' => 'Subsidios', 'other' => 'Otros'];
        $deductionTypeLabels  = ['legal' => 'Legales', 'judicial' => 'Judiciales', 'voluntary' => 'Voluntarias'];
        $perceptions = $payroll->items->where('type', 'perception');
        $deductions  = $payroll->items->where('type', 'deduction');
        $isDayLaborer = $payroll->employee->employment_type === 'day_laborer';
        $perceptionGroups = $perceptions->groupBy('perception_type');
        $groupOrder = ['salary', 'other', 'viaticos', 'subsidy', null];
        $sortedGroups = collect($groupOrder)
            ->filter(fn($k) => $perceptionGroups->has($k))
            ->mapWithKeys(fn($k) => [$k => $perceptionGroups->get($k)]);
        $perceptionGroups->each(function($items, $key) use (&$sortedGroups, $groupOrder) {
            if (!in_array($key, $groupOrder, true)) {
                $sortedGroups->put($key, $items);
            }
        });
        $showGroups = $sortedGroups->count() > 1;
        $deductionGroups = $deductions->groupBy('deduction_type');
        $deductionGroupOrder = ['legal', 'judicial', 'voluntary', null];
        $sortedDeductionGroups = collect($deductionGroupOrder)
            ->filter(fn($k) => $deductionGroups->has($k))
            ->mapWithKeys(fn($k) => [$k => $deductionGroups->get($k)]);
        $deductionGroups->each(function($items, $key) use (&$sortedDeductionGroups, $deductionGroupOrder) {
            if (!in_array($key, $deductionGroupOrder, true)) {
                $sortedDeductionGroups->put($key, $items);
            }
        });
        $showDeductionGroups = $sortedDeductionGroups->count() > 1;
    @endphp

    @if ($mode === 'print')
        {{-- Landscape: COPIA EMPLEADO | línea de corte vertical | COPIA EMPRESA --}}
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                @foreach (['COPIA EMPLEADO', 'COPIA EMPRESA'] as $copyLabel)
                    <td style="width: 50%; vertical-align: top; padding: 9mm 11mm 7mm 11mm; {{ ! $loop->last ? 'border-right: 1px dashed #888;' : '' }}">
                        @include('pdf._payroll-copy')
                    </td>
                @endforeach
            </tr>
        </table>
    @else
        {{-- Portrait: copia única para el empleado --}}
        @php $copyLabel = 'COPIA EMPLEADO'; @endphp
        <div style="padding: 12mm 16mm 8mm 16mm;">
            @include('pdf._payroll-copy')
        </div>
    @endif
</body>

</html>
