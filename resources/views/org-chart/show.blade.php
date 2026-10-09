<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organigrama - {{ $company->name }}</title>
    <x-favicon-links />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            min-height: 100vh;
            padding: 24px;
            color: #374151;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
        }

        .header {
            background: white;
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .header-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .company-logo {
            max-height: 48px;
            max-width: 120px;
        }

        .header h1 {
            font-size: 20px;
            font-weight: 600;
            color: #111827;
            margin: 0;
        }

        .header p {
            color: #6b7280;
            font-size: 14px;
            margin-top: 2px;
        }

        .header-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }

        .btn-primary {
            background: #14b8a6;
            color: white;
        }

        .btn-primary:hover {
            background: #0d9488;
        }

        .btn-secondary {
            background: white;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        .btn-secondary:hover {
            background: #f9fafb;
            border-color: #9ca3af;
        }

        .org-chart {
            background: white;
            border-radius: 8px;
            padding: 32px;
            border: 1px solid #e5e7eb;
            overflow-x: auto;
            min-width: fit-content;
        }

        .stats {
            display: flex;
            gap: 12px;
            margin-top: 8px;
        }

        .stat {
            background: #fef3c7;
            color: #92400e;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }

        /* Estilos del arbol */
        .tree {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .tree ul {
            padding-top: 24px;
            position: relative;
            display: flex;
            justify-content: center;
        }

        .tree li {
            float: left;
            text-align: center;
            list-style-type: none;
            position: relative;
            padding: 24px 12px 0 12px;
        }

        /* Lineas del arbol */
        .tree li::before,
        .tree li::after {
            content: '';
            position: absolute;
            top: 0;
            right: 50%;
            border-top: 1px solid #d1d5db;
            width: 50%;
            height: 24px;
        }

        .tree li::after {
            right: auto;
            left: 50%;
            border-left: 1px solid #d1d5db;
        }

        .tree li:only-child::after,
        .tree li:only-child::before {
            display: none;
        }

        .tree li:only-child {
            padding-top: 0;
        }

        .tree li:first-child::before,
        .tree li:last-child::after {
            border: 0 none;
        }

        .tree li:last-child::before {
            border-right: 1px solid #d1d5db;
            border-radius: 0 4px 0 0;
        }

        .tree li:first-child::after {
            border-radius: 4px 0 0 0;
        }

        .tree ul ul::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            border-left: 1px solid #d1d5db;
            width: 0;
            height: 24px;
        }

        /* Nodo del cargo */
        .position-node {
            display: inline-block;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: white;
            min-width: 180px;
            max-width: 240px;
            text-align: left;
            transition: all 0.15s;
        }

        .position-node:hover {
            border-color: #14b8a6;
            box-shadow: 0 2px 8px rgba(20, 184, 166, 0.12);
        }

        .position-header {
            background: #14b8a6;
            color: white;
            padding: 8px 12px;
            border-radius: 5px 5px 0 0;
            font-weight: 500;
            font-size: 12px;
        }

        .position-department {
            background: #f9fafb;
            color: #6b7280;
            padding: 4px 12px;
            font-size: 10px;
            border-bottom: 1px solid #e5e7eb;
        }

        .position-employees {
            padding: 8px;
        }

        .employee-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px;
            border-radius: 4px;
        }

        .employee-item:hover {
            background: #f9fafb;
        }

        .employee-photo {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid #e5e7eb;
        }

        .employee-photo-placeholder {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #0d9488;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 500;
            font-size: 11px;
        }

        .employee-name {
            font-size: 12px;
            font-weight: 500;
            color: #374151;
        }

        .no-employees {
            color: #9ca3af;
            font-size: 11px;
            font-style: italic;
            padding: 8px;
            text-align: center;
        }

        /* Vacantes, suspendidos y datos del empleado */
        .position-node.is-vacant {
            border-style: dashed;
            background: #f9fafb;
            opacity: 0.85;
        }

        .position-node.is-vacant .position-header {
            background: #9ca3af;
        }

        .badge-vacant,
        .badge-suspended {
            display: inline-block;
            margin-left: 4px;
            padding: 1px 6px;
            border-radius: 8px;
            font-size: 9px;
            font-weight: 600;
            vertical-align: middle;
        }

        .badge-vacant {
            background: #f3f4f6;
            color: #4b5563;
        }

        .badge-suspended {
            background: #fee2e2;
            color: #b91c1c;
        }

        .employee-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .employee-meta {
            font-size: 10px;
            color: #6b7280;
        }

        /* Filtros */
        .filters {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 12px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }

        .filters label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 11px;
            font-weight: 500;
            color: #4b5563;
        }

        .filters select,
        .filters input[type="search"] {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 12px;
            min-width: 170px;
            background: white;
        }

        .filters .check {
            flex-direction: row;
            align-items: center;
            gap: 6px;
            padding-bottom: 7px;
        }

        .filters-actions {
            display: flex;
            gap: 8px;
            padding-bottom: 1px;
        }

        .filters-actions button {
            cursor: pointer;
            font-family: inherit;
        }

        /* Nodo de departamento */
        .department-node {
            display: inline-block;
            border: 2px solid #0d9488;
            border-radius: 6px;
            min-width: 160px;
            max-width: 220px;
            text-align: center;
        }

        .department-header {
            background: #0d9488;
            color: white;
            padding: 8px 16px;
            border-radius: 4px 4px 0 0;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Nodo raiz (empresa) */
        .company-node {
            display: inline-block;
            background: #0d9488;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 24px;
        }

        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: #6b7280;
        }

        .empty-state svg {
            width: 48px;
            height: 48px;
            margin-bottom: 12px;
            opacity: 0.4;
        }

        .empty-state h3 {
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 4px;
            color: #374151;
        }

        .empty-state p {
            font-size: 13px;
        }

        /* Seccion de empleados sin asignar */
        .unassigned-section {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px dashed #d1d5db;
        }

        .unassigned-title {
            color: #9ca3af;
            font-size: 12px;
            margin-bottom: 12px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .unassigned-employees {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
        }

        .unassigned-employee {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #f9fafb;
            padding: 6px 10px;
            border-radius: 4px;
            border: 1px solid #e5e7eb;
            font-size: 12px;
        }

        /* Responsive - Tablets */
        @media (max-width: 768px) {
            body {
                padding: 16px;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
                padding: 16px;
            }

            .header-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
                width: 100%;
            }

            .company-logo {
                max-height: 40px;
            }

            .header h1 {
                font-size: 18px;
            }

            .header-actions {
                width: 100%;
                justify-content: stretch;
            }

            .header-actions .btn {
                flex: 1;
                justify-content: center;
            }

            .org-chart {
                padding: 20px;
                border-radius: 6px;
            }

            .position-node {
                min-width: 160px;
                max-width: 200px;
            }

            .tree li {
                padding: 20px 8px 0 8px;
            }

            .tree ul {
                padding-top: 20px;
            }

            .tree li::before,
            .tree li::after {
                height: 20px;
            }

            .tree ul ul::before {
                height: 20px;
            }

            .company-node {
                padding: 10px 20px;
                font-size: 13px;
                margin-bottom: 20px;
            }
        }

        /* Responsive - Mobile */
        @media (max-width: 480px) {
            body {
                padding: 12px;
            }

            .header {
                padding: 12px;
            }

            .header h1 {
                font-size: 16px;
            }

            .header p {
                font-size: 13px;
            }

            .stats {
                flex-wrap: wrap;
                gap: 8px;
            }

            .header-actions {
                flex-direction: column;
            }

            .header-actions .btn {
                width: 100%;
            }

            .org-chart {
                padding: 16px;
                margin: 0 -12px;
                border-radius: 0;
                border-left: none;
                border-right: none;
            }

            .position-node {
                min-width: 140px;
                max-width: 180px;
            }

            .position-header {
                padding: 6px 10px;
                font-size: 11px;
            }

            .position-department {
                padding: 3px 10px;
                font-size: 9px;
            }

            .position-employees {
                padding: 6px;
            }

            .employee-item {
                padding: 4px;
                gap: 6px;
            }

            .employee-photo,
            .employee-photo-placeholder {
                width: 24px;
                height: 24px;
                font-size: 10px;
            }

            .employee-name {
                font-size: 11px;
            }

            .company-node {
                padding: 8px 16px;
                font-size: 12px;
                margin-bottom: 16px;
            }

            .tree li {
                padding: 16px 6px 0 6px;
            }

            .tree ul {
                padding-top: 16px;
            }

            .tree li::before,
            .tree li::after {
                height: 16px;
            }

            .tree ul ul::before {
                height: 16px;
            }

            .unassigned-section {
                margin-top: 24px;
                padding-top: 16px;
            }

            .unassigned-employee {
                padding: 4px 8px;
                font-size: 11px;
            }

            .empty-state {
                padding: 32px 16px;
            }

            .empty-state svg {
                width: 40px;
                height: 40px;
            }

            .empty-state h3 {
                font-size: 14px;
            }

            .empty-state p {
                font-size: 12px;
            }
        }

        /* Indicador de scroll horizontal */
        .scroll-hint {
            display: none;
            text-align: center;
            padding: 8px;
            color: #9ca3af;
            font-size: 11px;
            background: #f9fafb;
            border-radius: 4px;
            margin-bottom: 12px;
        }

        @media (max-width: 768px) {
            .scroll-hint {
                display: block;
            }
        }

        @media print {
            body {
                background: white;
                padding: 0;
            }

            .header-actions,
            .filters {
                display: none;
            }

            .org-chart {
                border: none;
            }

            .position-node:hover {
                box-shadow: none;
            }

            .scroll-hint {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <div class="header-info">
                @if ($company->logo)
                    <img src="{{ asset('storage/' . $company->logo) }}" alt="Logo" class="company-logo">
                @endif
                <div>
                    <h1>Organigrama</h1>
                    <p>{{ $company->name }}</p>
                    <div class="stats">
                        <span class="stat">{{ $orgData['stats']['departments'] }} {{ $orgData['stats']['departments'] === 1 ? 'departamento' : 'departamentos' }}</span>
                        <span class="stat">{{ $orgData['stats']['positions'] }} {{ $orgData['stats']['positions'] === 1 ? 'cargo' : 'cargos' }}</span>
                        <span class="stat">{{ $orgData['stats']['employees'] }} {{ $orgData['stats']['employees'] === 1 ? 'empleado' : 'empleados' }}</span>
                        @if ($orgData['stats']['vacancies'] > 0)
                            <span class="stat">{{ $orgData['stats']['vacancies'] }} {{ $orgData['stats']['vacancies'] === 1 ? 'vacante' : 'vacantes' }}</span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="header-actions">
                <a href="{{ route('org-chart.pdf', ['company' => $company] + $pdfParams) }}" class="btn btn-primary" target="_blank">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                    </svg>
                    Exportar PDF
                </a>
                <a href="{{ url()->previous() }}" class="btn btn-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12" />
                        <polyline points="12 19 5 12 12 5" />
                    </svg>
                    Volver
                </a>
            </div>
        </div>

        {{-- Filtros: se aplican en el servidor por parámetros de la URL, así el PDF respeta los mismos --}}
        <form method="GET" action="{{ route('org-chart.show', $company) }}" class="filters">
            <label>
                Buscar
                <input type="search" name="q" value="{{ $filters['search'] }}" maxlength="100"
                    placeholder="Empleado o cargo">
            </label>
            @if ($branches->count() > 1)
                <label>
                    Sucursal
                    <select name="branch">
                        <option value="">Todas</option>
                        @foreach ($branches as $id => $name)
                            <option value="{{ $id }}" @selected($filters['branch'] === $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label>
                Departamento
                <select name="department">
                    <option value="">Todos</option>
                    @foreach ($departments as $id => $name)
                        <option value="{{ $id }}" @selected($filters['department'] === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="check">
                <input type="hidden" name="vacancies" value="0">
                <input type="checkbox" name="vacancies" value="1" @checked($filters['vacancies'])>
                Mostrar vacantes
            </label>
            <div class="filters-actions">
                <button type="submit" class="btn btn-primary">Aplicar</button>
                <a href="{{ route('org-chart.show', $company) }}" class="btn btn-secondary">Limpiar</a>
            </div>
        </form>

        <div class="org-chart">
            @if (count($orgData['tree']) > 0 || count($orgData['unassigned']) > 0)
                <div class="scroll-hint">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" style="display: inline; vertical-align: middle;">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                    Desliza horizontalmente para ver todo el organigrama
                </div>
                @if (count($orgData['tree']) > 0)
                    <div class="tree">
                        <ul>
                            @foreach ($orgData['tree'] as $department)
                                @include('org-chart.partials.department-node', ['department' => $department])
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (count($orgData['unassigned']) > 0)
                    <div class="unassigned-section">
                        <div class="unassigned-title">Empleados sin cargo asignado</div>
                        <div class="unassigned-employees">
                            @foreach ($orgData['unassigned'] as $employee)
                                <div class="unassigned-employee">
                                    @if ($employee['photo'])
                                        <img src="{{ $employee['photo'] }}" alt="{{ $employee['name'] }}"
                                            class="employee-photo">
                                    @else
                                        <div class="employee-photo-placeholder">
                                            {{ strtoupper(substr($employee['name'], 0, 1)) }}
                                        </div>
                                    @endif
                                    <span class="employee-name">{{ $employee['name'] }}
                                        @if ($employee['suspended'])
                                            <span class="badge-suspended">Suspendido</span>
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @else
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                    <h3>No hay resultados</h3>
                    <p>
                        @if ($filters['search'] || $filters['branch'] || $filters['department'])
                            Ningún cargo ni empleado coincide con los filtros aplicados.
                        @else
                            Esta empresa aún no tiene empleados activos ni cargos registrados.
                        @endif
                    </p>
                </div>
            @endif
        </div>
    </div>
</body>

</html>
