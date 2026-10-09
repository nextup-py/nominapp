<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organigrama - {{ $company->name }}</title>
    <x-favicon-links />
    <meta name="color-scheme" content="light dark">
    @vite(['resources/css/org-chart/org-chart.css', 'resources/js/org-chart/org-chart.js'])
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
                            <span class="stat stat--warning">{{ $orgData['stats']['vacancies'] }} {{ $orgData['stats']['vacancies'] === 1 ? 'vacante' : 'vacantes' }}</span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="header-actions">
                <x-theme-toggle-button />
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
