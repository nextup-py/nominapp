<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Organigrama - {{ $company->name }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 15mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 9px;
            line-height: 1.3;
            color: #333;
            padding: 10px;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #0d9488;
        }

        .company-logo {
            max-height: 30px;
            max-width: 80px;
            margin-bottom: 6px;
        }

        .header h1 {
            font-size: 16px;
            font-weight: bold;
            margin: 6px 0;
            color: #0d9488;
        }

        .header p {
            font-size: 11px;
            color: #666;
        }

        .org-content {
            padding: 0 20px;
        }

        .company-root {
            text-align: center;
            margin-bottom: 8px;
        }

        .company-box {
            display: inline-block;
            background: #0d9488;
            color: white;
            padding: 10px 30px;
            font-size: 12px;
            font-weight: bold;
        }

        /* Linea conectora vertical */
        .connector {
            text-align: center;
            padding: 6px 0;
        }

        .connector-vertical {
            display: inline-block;
            width: 2px;
            height: 15px;
            background: #0d9488;
        }

        /* Linea conectora horizontal */
        .connector-horizontal {
            border-top: 2px solid #0d9488;
            margin: 0 auto 6px auto;
            width: 80%;
        }

        .level-section {
            margin-bottom: 15px;
            page-break-inside: avoid;
        }

        .level-label {
            text-align: center;
            font-size: 8px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        /* Tabla para centrar las tarjetas */
        .cards-table {
            width: 100%;
            border-collapse: collapse;
        }

        .cards-table td {
            padding: 4px 8px;
            vertical-align: top;
            text-align: center;
        }

        .position-card.is-vacant {
            border-style: dashed;
            background: #f5f5f5;
        }

        .filter-summary {
            font-size: 9px;
            color: #555;
            margin-top: 3px;
        }

        .position-card {
            display: inline-block;
            text-align: left;
            border: 1px solid #ddd;
            background: white;
            min-width: 140px;
            max-width: 180px;
        }

        .position-header {
            background: #0d9488;
            color: white;
            padding: 6px 10px;
            font-weight: bold;
            font-size: 9px;
        }

        .position-dept {
            background: #fef3c7;
            color: #92400e;
            padding: 3px 10px;
            font-size: 8px;
        }

        .position-body {
            padding: 8px 10px;
            background: #fafafa;
        }

        .employee-name {
            font-size: 8px;
            color: #333;
            padding: 3px 0;
            border-bottom: 1px dotted #ddd;
        }

        .employee-name:last-child {
            border-bottom: none;
        }

        .no-employees {
            font-size: 8px;
            color: #999;
            font-style: italic;
        }

        .unassigned-section {
            margin-top: 25px;
            padding-top: 15px;
            border-top: 1px dashed #ccc;
        }

        .unassigned-title {
            font-size: 9px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 10px;
            text-align: center;
            letter-spacing: 0.5px;
        }

        .unassigned-list {
            text-align: center;
        }

        .unassigned-employee {
            display: inline-block;
            background: #f5f5f5;
            padding: 4px 10px;
            margin: 3px;
            font-size: 8px;
            border: 1px solid #ddd;
        }

        .footer {
            position: fixed;
            bottom: 8mm;
            left: 15mm;
            right: 15mm;
            text-align: center;
            font-size: 8px;
            color: #999;
            border-top: 1px solid #eee;
            padding-top: 5px;
        }

        .dept-section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }

        .dept-header {
            background: #0d9488;
            color: white;
            padding: 5px 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
            font-size: 11px;
        }
    </style>
</head>

<body>
    <div class="header">
        @if ($companyLogo)
            <img src="{{ $companyLogo }}" alt="Logo" class="company-logo"><br>
        @endif
        <p>{{ $company->name }}</p>
        <h1>ORGANIGRAMA</h1>
        @if (!empty($filterSummary))
            <p class="filter-summary">{{ $filterSummary }}</p>
        @endif
    </div>

    @if (count($orgData['tree']) > 0 || count($orgData['unassigned']) > 0)
        <div class="org-content">
            @php
                // Aplanar cargos de un departamento por niveles para el PDF
                if (!function_exists('flattenByLevelPdf')) {
                    function flattenByLevelPdf($nodes, $level = 0, &$levels = [])
                    {
                        if (!isset($levels[$level])) {
                            $levels[$level] = [];
                        }
                        foreach ($nodes as $node) {
                            $levels[$level][] = $node;
                            if (!empty($node['children'])) {
                                flattenByLevelPdf($node['children'], $level + 1, $levels);
                            }
                        }
                        return $levels;
                    }
                }
            @endphp

            @foreach ($orgData['tree'] as $department)
                <div class="dept-section">
                    <div class="dept-header">{{ $department['name'] }}</div>

                    @php
                        $levels = [];
                        flattenByLevelPdf($department['positions'], 0, $levels);
                    @endphp

                    @foreach ($levels as $levelNum => $positions)
                        <div class="level-section">
                            @if (count($positions) > 1)
                                <div class="connector-horizontal"></div>
                            @endif

                            <table class="cards-table">
                                <tr>
                                    @foreach ($positions as $position)
                                        <td>
                                            <div class="position-card {{ $position['vacant'] ? 'is-vacant' : '' }}">
                                                <div class="position-header">{{ $position['name'] }}{{ $position['vacant'] ? ' (Vacante)' : '' }}</div>
                                                <div class="position-body">
                                                    @if (count($position['employees']) > 0)
                                                        @foreach ($position['employees'] as $employee)
                                                            <div class="employee-name">{{ $employee['name'] }}{{ $employee['suspended'] ? ' (Suspendido)' : '' }}</div>
                                                        @endforeach
                                                    @elseif ($position['vacant'])
                                                        <div class="no-employees">Sin ocupante</div>
                                                    @else
                                                        <div class="no-employees">Sin empleados en esta vista</div>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                    @endforeach
                                </tr>
                            </table>

                            @if ($levelNum < count($levels) - 1)
                                <div class="connector">
                                    <span class="connector-vertical"></span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        @if (count($orgData['unassigned']) > 0)
            <div class="unassigned-section">
                <div class="unassigned-title">Empleados sin cargo asignado</div>
                <div class="unassigned-list">
                    @foreach ($orgData['unassigned'] as $employee)
                        <span class="unassigned-employee">{{ $employee['name'] }}{{ $employee['suspended'] ? ' (Suspendido)' : '' }}</span>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div class="empty-state">
            <p>No hay cargos ni empleados que coincidan con lo solicitado.</p>
        </div>
    @endif

    <div class="footer">
        Generado el {{ now()->format('d/m/Y H:i') }} | {{ $company->name }} - RUC: {{ $company->ruc }}
    </div>
</body>

</html>
