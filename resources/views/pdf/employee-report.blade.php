<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reporte de Empleados</title>
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
            padding: 10mm 12mm;
        }

        .company-header {
            text-align: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #0d9488;
        }

        .company-logo {
            max-height: 35px;
            max-width: 100px;
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
            margin: 16px 0 4px 0;
        }

        .subtitle {
            text-align: center;
            font-size: 9px;
            color: #444;
            margin-bottom: 16px;
        }

        .section-title {
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
            padding: 4px 0;
            margin-bottom: 6px;
            border-bottom: 1px solid #000;
        }

        .section-header {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            background-color: #e8e8e8;
            border: 1px solid #000;
            padding: 4px 8px;
            margin-top: 14px;
            margin-bottom: 0;
        }

        .summary-line {
            font-size: 9px;
            margin-bottom: 16px;
            padding: 5px 8px;
            border: 1px solid #ccc;
            background: #f5f5f5;
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
            padding: 3px 4px;
            border: 1px solid #ccc;
            text-align: left;
        }

        table.data-table td {
            padding: 2px 4px;
            border: 1px solid #ccc;
            font-size: 10px;
            vertical-align: middle;
        }

        table.data-table tr:nth-child(even) td {
            background: #fafafa;
        }

        .subtotal-row {
            display: table;
            width: 100%;
            border: 1px solid #ccc;
            border-top: none;
            padding: 4px 8px;
            background-color: #f0f0f0;
            font-size: 8px;
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
            margin-top: 14px;
            border: 2px solid #000;
            padding: 5px 10px;
            background-color: #f5f5f5;
            font-size: 9px;
            page-break-inside: avoid;
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
            margin-top: 24px;
            text-align: center;
            font-size: 7px;
            border-top: 1px solid #ccc;
            padding-top: 6px;
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
    <div class="title">Reporte de Empleados</div>
    @php
        $statusLabel = $status === 'sin_contrato' ? 'Sin contrato' : ($statusOptions[$status] ?? $status);
        $subtitleParts = array_filter([
            $gender ? ($genderOptions[$gender] ?? $gender) : null,
            $birthMonth ? 'Cumpleaños en '.($monthOptions[$birthMonth] ?? $birthMonth) : null,
            $status ? 'Estado: '.$statusLabel : null,
            $contractType ? ($contractTypes[$contractType] ?? $contractType) : null,
            $paymentMethod ? ($paymentMethods[$paymentMethod] ?? $paymentMethod) : null,
            ($registeredFrom && $registeredUntil) ? 'Registro: '.\Carbon\Carbon::parse($registeredFrom)->format('d/m/Y').' — '.\Carbon\Carbon::parse($registeredUntil)->format('d/m/Y') : null,
            (!$registeredUntil && $registeredFrom) ? 'Registro desde: '.\Carbon\Carbon::parse($registeredFrom)->format('d/m/Y') : null,
            (!$registeredFrom && $registeredUntil) ? 'Registro hasta: '.\Carbon\Carbon::parse($registeredUntil)->format('d/m/Y') : null,
            ($endDateFrom && $endDateUntil) ? 'Baja: '.\Carbon\Carbon::parse($endDateFrom)->format('d/m/Y').' — '.\Carbon\Carbon::parse($endDateUntil)->format('d/m/Y') : null,
            (!$endDateUntil && $endDateFrom) ? 'Baja desde: '.\Carbon\Carbon::parse($endDateFrom)->format('d/m/Y') : null,
            (!$endDateFrom && $endDateUntil) ? 'Baja hasta: '.\Carbon\Carbon::parse($endDateUntil)->format('d/m/Y') : null,
        ]);
    @endphp
    @if(count($subtitleParts))
    <div class="subtitle">{{ implode(' · ', $subtitleParts) }}</div>
    @endif

    {{-- Resumen --}}
    <div class="section-title">Resumen</div>
    <div class="summary-line">
        <strong>{{ $totalCount }} empleados</strong>
        @foreach($genderOptions as $key => $label)
            @if(($byGender[$key] ?? 0) > 0)
                &nbsp;·&nbsp; {{ $label }}: {{ $byGender[$key] }}
            @endif
        @endforeach
        @foreach($statusOptions as $key => $label)
            @if(($byStatus[$key] ?? 0) > 0)
                &nbsp;·&nbsp; {{ $label }}: {{ $byStatus[$key] }}
            @endif
        @endforeach
        @if($avgYears !== null)
            &nbsp;·&nbsp; Antigüedad prom.: {{ number_format($avgYears, 1) }} años
        @endif
    </div>

    {{-- Detalle --}}
    @if($employees->isEmpty())
        <div class="empty-state">No hay empleados para los filtros seleccionados.</div>
    @else

        @php
            $showCol = array_flip($selectedColumns);

            /**
             * Renderiza las filas de la tabla de empleados para las columnas seleccionadas.
             */
            function employeeRows($rows, $showCol, $genderOptions, $statusOptions, $contractTypes, $paymentMethods) {
                $contractTypesShort = [
                    'indefinido'       => 'Indefinido',
                    'plazo_fijo'       => 'Plazo Fijo',
                    'obra_determinada' => 'Obra',
                    'aprendizaje'      => 'Aprendizaje',
                    'pasantia'         => 'Pasantía',
                ];
                $paymentMethodsShort = [
                    'debit' => 'Débito',
                    'cash'  => 'Efectivo',
                    'check' => 'Cheque',
                ];
                $i = 0;
                foreach ($rows as $e) {
                    $even        = $i % 2 === 1 ? 'background:#fafafa;' : '';
                    $birthDate   = $e->birth_date ? \Carbon\Carbon::parse($e->birth_date) : null;
                    $hireDate    = $e->hire_date  ? \Carbon\Carbon::parse($e->hire_date)  : null;
                    $salaryFmt   = $e->salary ? number_format((float) $e->salary, 0, ',', '.') : '—';

                    if ($hireDate && $e->status === 'active') {
                        $years       = (int) $hireDate->diffInYears(now());
                        $totalMonths = (int) $hireDate->diffInMonths(now());
                        $days        = (int) $hireDate->diffInDays(now());
                        if ($years >= 1) {
                            $remainderMonths = $totalMonths - ($years * 12);
                            $antiguedadFmt   = $years . ' año' . ($years !== 1 ? 's' : '');
                            if ($remainderMonths > 0) {
                                $antiguedadFmt .= ' ' . $remainderMonths . ' mes' . ($remainderMonths !== 1 ? 'es' : '');
                            }
                        } elseif ($totalMonths >= 1) {
                            $antiguedadFmt = $totalMonths . ' mes' . ($totalMonths !== 1 ? 'es' : '');
                        } else {
                            $antiguedadFmt = $days . ' día' . ($days !== 1 ? 's' : '');
                        }
                    } else {
                        $antiguedadFmt = '—';
                    }

                    echo '<tr style="' . $even . '">';
                    echo '<td style="text-align:center;">' . ($i + 1) . '</td>';
                    if (isset($showCol['employee_name'])) echo '<td>' . e(strtoupper($e->last_name)) . ', ' . e($e->first_name) . '</td>';
                    if (isset($showCol['ci']))             echo '<td>' . e($e->ci) . '</td>';
                    if (isset($showCol['gender']))         { $g = $genderOptions[$e->gender] ?? ''; echo '<td style="text-align:center;">' . e($g ? mb_strtoupper(mb_substr($g, 0, 1)) : '—') . '</td>'; }
                    if (isset($showCol['age']))            echo '<td style="text-align:center;">' . ($birthDate ? $birthDate->age : '—') . '</td>';
                    if (isset($showCol['birthday']))       echo '<td style="text-align:center;">' . ($birthDate ? $birthDate->format('d/m') : '—') . '</td>';
                    if (isset($showCol['hire_date']))      echo '<td>' . ($hireDate ? $hireDate->format('d/m/Y') : '—') . '</td>';
                    if (isset($showCol['years_of_service'])) echo '<td style="text-align:center;">' . $antiguedadFmt . '</td>';
                    if (isset($showCol['registered_at'])) { $regDate = $e->created_at ? \Carbon\Carbon::parse($e->created_at) : null; echo '<td>' . ($regDate ? $regDate->format('d/m/Y') : '—') . '</td>'; }
                    if (isset($showCol['end_date']))      { $endDate = $e->last_end_date ? \Carbon\Carbon::parse($e->last_end_date) : null; echo '<td>' . ($endDate ? $endDate->format('d/m/Y') : '—') . '</td>'; }
                    if (isset($showCol['salary']))         echo '<td style="text-align:right;">' . $salaryFmt . '</td>';
                    if (isset($showCol['contract_type']))  echo '<td>' . e($contractTypesShort[$e->contract_type] ?? $contractTypes[$e->contract_type] ?? '—') . '</td>';
                    if (isset($showCol['payment_method'])) echo '<td>' . e($paymentMethodsShort[$e->payment_method] ?? $paymentMethods[$e->payment_method] ?? '—') . '</td>';
                    if (isset($showCol['position_name'])) echo '<td>' . e($e->position_name ?? '—') . '</td>';
                    if (isset($showCol['department_name'])) echo '<td>' . e($e->department_name ?? '—') . '</td>';
                    if (isset($showCol['branch_name']))    echo '<td>' . e($e->branch_name) . '</td>';
                    if (isset($showCol['company_name']))   echo '<td>' . e($e->company_name) . '</td>';
                    if (isset($showCol['status']))         echo '<td>' . e($statusOptions[$e->status] ?? $e->status) . '</td>';
                    if (isset($showCol['phone']))          echo '<td>' . e($e->phone ?? '—') . '</td>';
                    echo '</tr>';
                    $i++;
                }
            }
        @endphp

        @php
            $allLabels = $columnLabels;
            $headers = array_intersect_key($allLabels, $showCol);
        @endphp

        @if($groupMode === 'flat')
            <div class="section-title">Detalle</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:22px;text-align:center;">#</th>
                        @foreach($headers as $key => $label)
                            <th>{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php employeeRows($employees, $showCol, $genderOptions, $statusOptions, $contractTypes, $paymentMethods) @endphp
                </tbody>
            </table>

        @elseif($groupMode === 'company')
            @foreach($groups as $companyGroupName => $rows)
                <div class="section-header">{{ $companyGroupName ?: 'Sin empresa' }}</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:22px;text-align:center;">#</th>
                            @foreach($headers as $key => $label)
                                <th>{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php employeeRows($rows, $showCol, $genderOptions, $statusOptions, $contractTypes, $paymentMethods) @endphp
                    </tbody>
                </table>
                <div class="subtotal-row">
                    <div class="st-item">
                        <span class="st-label">Empleados:</span> {{ $rows->count() }}
                    </div>
                    <div class="st-item">
                        @php $groupAvg = $rows->whereNotNull('years_of_service')->avg('years_of_service'); @endphp
                        @if($groupAvg !== null)
                            <span class="st-label">Antigüedad promedio:</span> {{ number_format($groupAvg, 1) }} años
                        @endif
                    </div>
                </div>
            @endforeach
        @endif

        {{-- Gran total --}}
        <div class="grand-total">
            <span class="grand-total-label">Total general:</span> {{ $totalCount }} empleados
        </div>

    @endif

    {{-- Pie de página --}}
    <div class="footer">
        Generado el {{ now()->format('d/m/Y H:i') }}
        @if($city) &nbsp;·&nbsp; {{ $city }}, Paraguay @endif
    </div>

</body>
</html>
