<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reporte de Contratos — {{ $tab }}</title>
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

        .company-logo  { max-height: 40px; max-width: 120px; margin-bottom: 8px; }
        .company-name  { font-size: 14px; font-weight: bold; text-transform: uppercase; margin-bottom: 3px; }
        .company-info  { font-size: 9px; }

        .title    { text-align: center; font-size: 13px; font-weight: bold; text-transform: uppercase; margin: 20px 0 5px 0; }
        .subtitle { text-align: center; font-size: 10px; color: #444; margin-bottom: 20px; }

        .section-header {
            font-size: 11px; font-weight: bold; text-transform: uppercase;
            background-color: #e8e8e8; border: 1px solid #000;
            padding: 5px 8px; margin-top: 18px; margin-bottom: 0;
        }

        .subsection-header {
            font-size: 10px; font-weight: bold;
            background-color: #f5f5f5; border: 1px solid #ccc;
            border-top: none; padding: 4px 8px; margin-bottom: 0;
        }

        table { width: 100%; border-collapse: collapse; margin-bottom: 0; }

        th, td { border: 1px solid #000; padding: 4px 3px; font-size: 9px; }

        th {
            font-weight: bold; background-color: #f5f5f5;
            text-align: center; text-transform: uppercase;
        }

        td { text-align: center; }
        td.text-left { text-align: left; }

        tr.row-even { background-color: #fafafa; }

        .subtotal-row {
            display: table; width: 100%; border: 1px solid #000;
            border-top: none; padding: 4px 8px;
            background-color: #f0f0f0; font-size: 9px; margin-bottom: 4px;
        }

        .subtotal-row .st-item  { display: table-cell; width: 50%; }
        .subtotal-row .st-label { font-weight: bold; }

        .grand-total {
            margin-top: 16px; border: 2px solid #000;
            padding: 8px 12px; background-color: #f5f5f5;
        }

        .grand-total-title {
            font-weight: bold; font-size: 11px; text-transform: uppercase;
            margin-bottom: 6px; border-bottom: 1px solid #ccc; padding-bottom: 4px;
        }

        .grand-total-grid { display: table; width: 100%; }
        .grand-total-item { display: table-cell; width: 50%; padding: 3px 0; }
        .grand-total-label { font-weight: bold; }

        .empty-state { text-align: center; padding: 30px; color: #666; font-style: italic; }

        .footer {
            margin-top: 30px; text-align: center; font-size: 8px;
            border-top: 1px solid #ccc; padding-top: 8px; color: #666;
        }

        .badge-danger  { color: #b91c1c; font-weight: bold; }
        .badge-warning { color: #92400e; }
        .badge-ok      { color: #166534; }
        .badge-info    { color: #1e40af; }
        .badge-gray    { color: #374151; }
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

    {{-- Título del reporte --}}
    <div class="title">
        @switch($tab)
            @case('vencer')       Contratos por Vencer @break
            @case('prueba')       Períodos de Prueba en Curso @break
            @case('sin_contrato') Empleados Sin Contrato Activo @break
            @case('antiguedad')   Contratos por Antigüedad @break
            @case('suspendidos')  Contratos Suspendidos @break
            @case('activos')      Todos los Contratos Activos @break
            @case('rescindidos')  Contratos Rescindidos @break
        @endswitch
    </div>
    <div class="subtitle">
        Generado el {{ now()->format('d/m/Y H:i') }}
        @if ($tab === 'vencer' && $filters['days']) &nbsp;·&nbsp; Próximos {{ $filters['days'] }} días @endif
        @if ($tab === 'prueba' && $filters['days']) &nbsp;·&nbsp; Próximos {{ $filters['days'] }} días @endif
        @if ($tab === 'rescindidos' && $filters['period']) &nbsp;·&nbsp; Últimos {{ $filters['period'] }} meses @endif
        @if ($filters['startDateFrom'] && $filters['startDateUntil']) &nbsp;·&nbsp; Inicio: {{ \Carbon\Carbon::parse($filters['startDateFrom'])->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($filters['startDateUntil'])->format('d/m/Y') }}
        @elseif ($filters['startDateFrom']) &nbsp;·&nbsp; Inicio desde {{ \Carbon\Carbon::parse($filters['startDateFrom'])->format('d/m/Y') }}
        @elseif ($filters['startDateUntil']) &nbsp;·&nbsp; Inicio hasta {{ \Carbon\Carbon::parse($filters['startDateUntil'])->format('d/m/Y') }}
        @endif
        @if ($filters['endDateFrom'] && $filters['endDateUntil']) &nbsp;·&nbsp; Vence: {{ \Carbon\Carbon::parse($filters['endDateFrom'])->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($filters['endDateUntil'])->format('d/m/Y') }}
        @elseif ($filters['endDateFrom']) &nbsp;·&nbsp; Vence desde {{ \Carbon\Carbon::parse($filters['endDateFrom'])->format('d/m/Y') }}
        @elseif ($filters['endDateUntil']) &nbsp;·&nbsp; Vence hasta {{ \Carbon\Carbon::parse($filters['endDateUntil'])->format('d/m/Y') }}
        @endif
        @if ($filters['terminatedFrom'] && $filters['terminatedUntil']) &nbsp;·&nbsp; Rescisión: {{ \Carbon\Carbon::parse($filters['terminatedFrom'])->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($filters['terminatedUntil'])->format('d/m/Y') }}
        @elseif ($filters['terminatedFrom']) &nbsp;·&nbsp; Rescisión desde {{ \Carbon\Carbon::parse($filters['terminatedFrom'])->format('d/m/Y') }}
        @elseif ($filters['terminatedUntil']) &nbsp;·&nbsp; Rescisión hasta {{ \Carbon\Carbon::parse($filters['terminatedUntil'])->format('d/m/Y') }}
        @endif
        @foreach ($activeFilterLabels as $label)
            &nbsp;·&nbsp; {{ $label }}
        @endforeach
    </div>

    @php
        $showCol = array_flip($selectedColumns);

        /**
         * Genera el <thead> dinámico según columnas y tab.
         *
         * @param  array<string, int>  $showCol
         * @param  string  $tab
         */
        function contractThead(array $showCol, string $tab): void {
            $allLabels = [
                'employee_name'    => 'Empleado',
                'ci'               => 'CI',
                'company_name'     => 'Empresa',
                'branch_name'      => 'Sucursal',
                'position_name'    => 'Cargo',
                'contract_type'    => 'Tipo',
                'salary_type'      => 'Tipo Sal.',
                'salary'           => 'Salario',
                'start_date'       => 'Inicio',
                'end_date'         => 'Vencimiento',
                'terminated_at'    => 'Rescindido el',
                'trial_days'       => 'Días Prueba',
                'trial_end_date'   => 'Fin Prueba',
                'days_remaining'   => 'Días Rest.',
                'years_of_service' => 'Antigüedad',
                'employee_status'  => 'Estado',
            ];

            // Excluir columnas que no aplican al tab
            $tabExcludes = match ($tab) {
                'sin_contrato' => ['position_name', 'contract_type', 'salary_type', 'salary', 'start_date', 'end_date', 'terminated_at', 'trial_days', 'trial_end_date', 'days_remaining', 'years_of_service'],
                'prueba'       => ['salary_type', 'salary', 'end_date', 'terminated_at', 'years_of_service', 'employee_status'],
                'antiguedad'   => ['end_date', 'terminated_at', 'trial_days', 'trial_end_date', 'days_remaining', 'employee_status'],
                'suspendidos'  => ['terminated_at', 'trial_days', 'trial_end_date', 'days_remaining', 'years_of_service', 'employee_status'],
                'activos'      => ['terminated_at', 'trial_days', 'trial_end_date', 'days_remaining', 'years_of_service', 'employee_status'],
                'rescindidos'  => ['end_date', 'trial_days', 'trial_end_date', 'days_remaining', 'years_of_service', 'employee_status'],
                default        => ['terminated_at', 'trial_days', 'trial_end_date', 'years_of_service', 'salary_type', 'salary', 'employee_status'], // vencer
            };

            echo '<thead><tr>';
            foreach ($allLabels as $key => $label) {
                if (!isset($showCol[$key])) continue;
                if (in_array($key, $tabExcludes)) continue;
                $align = $key === 'employee_name' ? ' class="text-left"' : '';
                echo '<th' . $align . '>' . e($label) . '</th>';
            }
            echo '</tr></thead>';
        }

        /**
         * Renderiza las filas según el tab activo.
         *
         * @param  iterable  $rows
         * @param  array<string, int>  $showCol
         * @param  string  $tab
         */
        function contractRows(iterable $rows, array $showCol, string $tab): void {
            $i = 0;
            foreach ($rows as $r) {
                $even = $i % 2 === 1 ? 'row-even' : '';
                echo '<tr class="' . $even . '">';

                // Columnas comunes
                if (isset($showCol['employee_name'])) echo '<td class="text-left">' . strtoupper(e($r->last_name)) . ', ' . e($r->first_name) . '</td>';
                if (isset($showCol['ci']))             echo '<td>' . e($r->ci) . '</td>';
                if (isset($showCol['company_name']))   echo '<td>' . e($r->company_name ?? '—') . '</td>';
                if (isset($showCol['branch_name']))    echo '<td>' . e($r->branch_name) . '</td>';

                if ($tab === 'sin_contrato') {
                    if (isset($showCol['employee_status'])) {
                        $label = match ($r->employee_status) {
                            'active'    => 'Activo',
                            'inactive'  => 'Inactivo',
                            'suspended' => 'Suspendido',
                            'draft'     => 'Borrador',
                            default     => $r->employee_status ?? '—',
                        };
                        echo '<td>' . e($label) . '</td>';
                    }
                } else {
                    // Columnas de contrato
                    if (isset($showCol['position_name'])) echo '<td>' . e($r->position_name ?? '—') . '</td>';

                    if (isset($showCol['contract_type'])) {
                        echo '<td>' . e(\App\Models\Contract::getTypeLabel($r->type)) . '</td>';
                    }

                    if (in_array($tab, ['antiguedad', 'suspendidos', 'activos', 'rescindidos'])) {
                        if (isset($showCol['salary_type'])) {
                            echo '<td>' . e($r->salary_type === 'mensual' ? 'Mensual' : 'Jornal') . '</td>';
                        }
                        if (isset($showCol['salary'])) {
                            echo '<td>' . ($r->salary ? 'Gs. ' . number_format((float) $r->salary, 0, ',', '.') : '—') . '</td>';
                        }
                    }

                    if (isset($showCol['start_date']) && $r->start_date) {
                        echo '<td>' . \Carbon\Carbon::parse($r->start_date)->format('d/m/Y') . '</td>';
                    }

                    if ($tab === 'vencer' || $tab === 'activos' || $tab === 'suspendidos') {
                        if (isset($showCol['end_date'])) {
                            echo '<td>' . ($r->end_date ? \Carbon\Carbon::parse($r->end_date)->format('d/m/Y') : 'Indefinido') . '</td>';
                        }
                    }

                    if ($tab === 'rescindidos' && isset($showCol['terminated_at'])) {
                        echo '<td>' . ($r->terminated_at ? \Carbon\Carbon::parse($r->terminated_at)->format('d/m/Y') : '—') . '</td>';
                    }

                    if ($tab === 'prueba') {
                        if (isset($showCol['trial_days'])) echo '<td>' . e($r->trial_days) . ' días</td>';
                        if (isset($showCol['trial_end_date'])) {
                            $te = $r->trial_end_date ? \Carbon\Carbon::parse($r->trial_end_date)->format('d/m/Y') : '—';
                            echo '<td>' . $te . '</td>';
                        }
                        if (isset($showCol['days_remaining'])) {
                            $rem = (int) $r->days_until_trial_end;
                            $cls = $rem <= 15 ? 'badge-danger' : ($rem <= 30 ? 'badge-warning' : 'badge-ok');
                            echo '<td class="' . $cls . '">' . $rem . ' días</td>';
                        }
                    }

                    if ($tab === 'vencer' && isset($showCol['days_remaining'])) {
                        $rem = (int) $r->days_until_expiry;
                        $cls = $rem <= 15 ? 'badge-danger' : ($rem <= 30 ? 'badge-warning' : 'badge-ok');
                        echo '<td class="' . $cls . '">' . $rem . ' días</td>';
                    }

                    if ($tab === 'antiguedad' && isset($showCol['years_of_service'])) {
                        $years  = (int) ($r->years_of_service ?? 0);
                        $months = (int) ($r->months_of_service ?? 0);
                        if ($years >= 1) {
                            $rem   = $months - ($years * 12);
                            $label = $years . ' año' . ($years !== 1 ? 's' : '') . ($rem > 0 ? ', ' . $rem . ' mes' . ($rem !== 1 ? 'es' : '') : '');
                        } else {
                            $label = $months . ' mes' . ($months !== 1 ? 'es' : '');
                        }
                        $cls = $years >= 10 ? 'badge-ok' : ($years >= 5 ? 'badge-info' : ($years >= 1 ? 'badge-warning' : 'badge-gray'));
                        echo '<td class="' . $cls . '">' . e($label) . '</td>';
                    }
                }

                echo '</tr>';
                $i++;
            }
        }
    @endphp

    @if ($records->isEmpty())
        <div class="empty-state">No hay registros para los filtros seleccionados.</div>
    @else

        {{-- ──────────────────────────────────────────
             MODO FLAT
        ────────────────────────────────────────── --}}
        @if ($groupMode === 'flat')
            <table>
                @php contractThead($showCol, $tab) @endphp
                <tbody>
                    @php contractRows($records, $showCol, $tab) @endphp
                </tbody>
            </table>

        {{-- ──────────────────────────────────────────
             MODO BRANCH: agrupa por sucursal
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
                    <div class="st-item">
                        <span class="st-label">Registros:</span> {{ $rows->count() }}
                    </div>
                </div>
            @endforeach

        {{-- ──────────────────────────────────────────
             MODO COMPANY_BRANCH: agrupa por empresa → sucursal
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
                            <span class="st-label">{{ $branchName ?: 'Sin sucursal' }}:</span>
                            {{ $rows->count() }} registros
                        </div>
                    </div>
                @endforeach

                <div class="subtotal-row" style="background-color:#ddd; margin-bottom:12px;">
                    <div class="st-item">
                        <span class="st-label">Total {{ $compName }}:</span> {{ $compRows->count() }} registros
                    </div>
                </div>
            @endforeach
        @endif

        {{-- Gran total --}}
        <div class="grand-total">
            <div class="grand-total-title">Total General</div>
            <div class="grand-total-grid">
                <div class="grand-total-item">
                    <span class="grand-total-label">Total de registros:</span> {{ $totalRecords }}
                </div>
                @if ($avgDays !== null)
                    <div class="grand-total-item">
                        <span class="grand-total-label">
                            {{ $tab === 'prueba' ? 'Promedio días en prueba:' : 'Promedio días al vencimiento:' }}
                        </span>
                        {{ $avgDays }} días
                    </div>
                @endif
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
