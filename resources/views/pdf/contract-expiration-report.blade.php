<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>{{ $tab === 'prueba' ? 'Períodos de Prueba' : 'Contratos por Vencer' }}</title>
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

        /* Sub-sección de sucursal (nivel 2) */
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

        .badge-danger  { color: #b91c1c; font-weight: bold; }
        .badge-warning { color: #92400e; }
        .badge-ok      { color: #166534; }
    </style>
</head>

<body>

    {{-- Encabezado de empresa --}}
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

    {{-- Título --}}
    <div class="title">
        {{ $tab === 'prueba' ? 'Períodos de Prueba en Curso' : 'Contratos por Vencer' }}
    </div>
    <div class="subtitle">
        Contratos activos
        @if ($days) &nbsp;·&nbsp; Próximos {{ $days }} días @endif
        &nbsp;·&nbsp; Generado el {{ now()->format('d/m/Y') }}
    </div>

    @php
        $showCol = array_flip($selectedColumns);

        /**
         * Renderiza las filas de la tabla con columnas seleccionables y por tab.
         *
         * @param  iterable  $rows
         * @param  array<string, int>  $showCol
         * @param  string  $tab  'contratos' | 'prueba'
         */
        function contractRows(iterable $rows, array $showCol, string $tab): void {
            $i = 0;
            foreach ($rows as $c) {
                $even = $i % 2 === 1 ? 'row-even' : '';
                echo '<tr class="' . $even . '">';
                if (isset($showCol['employee_name'])) echo '<td class="text-left">' . strtoupper(e($c->last_name)) . ', ' . e($c->first_name) . '</td>';
                if (isset($showCol['ci']))             echo '<td>' . e($c->ci) . '</td>';
                if (isset($showCol['company_name']))   echo '<td>' . e($c->company_name ?? '—') . '</td>';
                if (isset($showCol['branch_name']))    echo '<td>' . e($c->branch_name) . '</td>';
                if (isset($showCol['position_name'])) echo '<td>' . e($c->position_name ?? '—') . '</td>';
                if (isset($showCol['contract_type'])) echo '<td>' . e(\App\Models\Contract::getTypeLabel($c->type)) . '</td>';
                if (isset($showCol['start_date']))     echo '<td>' . \Carbon\Carbon::parse($c->start_date)->format('d/m/Y') . '</td>';

                if ($tab === 'prueba') {
                    if (isset($showCol['trial_days'])) echo '<td>' . e($c->trial_days) . ' días</td>';
                    if (isset($showCol['trial_end_date'])) {
                        $trialEnd = $c->trial_end_date ? \Carbon\Carbon::parse($c->trial_end_date)->format('d/m/Y') : '—';
                        echo '<td>' . $trialEnd . '</td>';
                    }
                    if (isset($showCol['days_remaining'])) {
                        $rem = (int) $c->days_until_trial_end;
                        $cls = $rem <= 15 ? 'badge-danger' : ($rem <= 30 ? 'badge-warning' : 'badge-ok');
                        echo '<td class="' . $cls . '">' . $rem . ' días</td>';
                    }
                } else {
                    if (isset($showCol['end_date']))      echo '<td>' . \Carbon\Carbon::parse($c->end_date)->format('d/m/Y') . '</td>';
                    if (isset($showCol['days_remaining'])) {
                        $rem = (int) $c->days_until_expiry;
                        $cls = $rem <= 15 ? 'badge-danger' : ($rem <= 30 ? 'badge-warning' : 'badge-ok');
                        echo '<td class="' . $cls . '">' . $rem . ' días</td>';
                    }
                }
                echo '</tr>';
                $i++;
            }
        }

        /**
         * Genera el thead dinámico según columnas seleccionadas y tab activo.
         *
         * @param  array<string, int>  $showCol
         * @param  string  $tab  'contratos' | 'prueba'
         */
        function contractThead(array $showCol, string $tab): void {
            $allLabels = [
                'employee_name'  => 'Empleado',
                'ci'             => 'CI',
                'company_name'   => 'Empresa',
                'branch_name'    => 'Sucursal',
                'position_name'  => 'Cargo',
                'contract_type'  => 'Tipo Contrato',
                'start_date'     => 'Inicio',
                'end_date'       => 'Vencimiento',
                'trial_days'     => 'Días Prueba',
                'trial_end_date' => 'Fin Prueba',
                'days_remaining' => 'Días Rest.',
            ];
            echo '<thead><tr>';
            foreach ($allLabels as $key => $label) {
                if (!isset($showCol[$key])) continue;
                if ($key === 'end_date'      && $tab === 'prueba')    continue;
                if ($key === 'trial_days'    && $tab !== 'prueba')    continue;
                if ($key === 'trial_end_date' && $tab !== 'prueba')   continue;
                $align = $key === 'employee_name' ? ' class="text-left"' : '';
                echo '<th' . $align . '>' . e($label) . '</th>';
            }
            echo '</tr></thead>';
        }
    @endphp

    @if ($contracts->isEmpty())
        <div class="empty-state">No hay registros para los filtros seleccionados.</div>
    @else

        {{-- ──────────────────────────────────────────
             MODO FLAT: empresa + sucursal seleccionados
        ────────────────────────────────────────── --}}
        @if ($groupMode === 'flat')
            <table>
                @php contractThead($showCol, $tab) @endphp
                <tbody>
                    @php contractRows($contracts, $showCol, $tab) @endphp
                </tbody>
            </table>

        {{-- ──────────────────────────────────────────
             MODO BRANCH: empresa seleccionada, sin sucursal
             → Agrupa por sucursal
        ────────────────────────────────────────── --}}
        @elseif ($groupMode === 'branch')
            @foreach ($groups as $branchName => $rows)
                <div class="section-header">{{ $branchName ?: 'Sin sucursal' }}</div>
                <table>
                    @php contractThead($showCol, $tab) @endphp
                    <tbody>
                        @php contractRows($rows, $showCol, $tab) @endphp
                    </tbody>
                </table>
                <div class="subtotal-row">
                    <div class="st-item"><span class="st-label">Contratos:</span> {{ $rows->count() }}</div>
                    <div class="st-item">
                        <span class="st-label">Promedio días restantes:</span>
                        {{ $rows->count() > 0 ? round($rows->avg($daysField)) : 0 }} días
                    </div>
                </div>
            @endforeach

        {{-- ──────────────────────────────────────────
             MODO COMPANY_BRANCH: sin filtros
             → Agrupa por empresa → por sucursal
        ────────────────────────────────────────── --}}
        @elseif ($groupMode === 'company_branch')
            @foreach ($groups as $compName => $branchGroups)
                @php $compRows = $branchGroups->flatten() @endphp
                <div class="section-header">{{ $compName ?: 'Sin empresa' }}</div>

                @foreach ($branchGroups as $branchName => $rows)
                    <div class="subsection-header">{{ $branchName ?: 'Sin sucursal' }}</div>
                    <table>
                        @php contractThead($showCol, $tab) @endphp
                        <tbody>
                            @php contractRows($rows, $showCol, $tab) @endphp
                        </tbody>
                    </table>
                    <div class="subtotal-row">
                        <div class="st-item">
                            <span class="st-label">Contratos en {{ $branchName ?: 'Sin sucursal' }}:</span>
                            {{ $rows->count() }}
                        </div>
                        <div class="st-item">
                            <span class="st-label">Promedio días restantes:</span>
                            {{ $rows->count() > 0 ? round($rows->avg($daysField)) : 0 }} días
                        </div>
                    </div>
                @endforeach

                {{-- Subtotal empresa --}}
                <div class="subtotal-row" style="background-color:#ddd; margin-bottom:12px;">
                    <div class="st-item">
                        <span class="st-label">Total {{ $compName }}:</span> {{ $compRows->count() }} contratos
                    </div>
                    <div class="st-item">
                        <span class="st-label">Promedio días restantes:</span>
                        {{ $compRows->count() > 0 ? round($compRows->avg($daysField)) : 0 }} días
                    </div>
                </div>
            @endforeach
        @endif

        {{-- Gran total --}}
        <div class="grand-total">
            <div class="grand-total-title">Total General</div>
            <div class="grand-total-grid">
                <div class="grand-total-item">
                    <span class="grand-total-label">Total de contratos:</span> {{ $totalContracts }}
                </div>
                <div class="grand-total-item">
                    <span class="grand-total-label">
                        {{ $tab === 'prueba' ? 'Promedio días en prueba:' : 'Promedio días al vencimiento:' }}
                    </span>
                    {{ $avgDays }} días
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
