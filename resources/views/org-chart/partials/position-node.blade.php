{{-- Cargo del organigrama: empleados (con insignia de suspendido, ingreso y sucursal) o etiqueta de vacante --}}
<li>
    <div class="position-node {{ $position['vacant'] ? 'is-vacant' : '' }}">
        <div class="position-header">
            {{ $position['name'] }}
            @if ($position['vacant'])
                <span class="badge-vacant">Vacante</span>
            @endif
        </div>
        <div class="position-employees">
            @if (count($position['employees']) > 0)
                @foreach ($position['employees'] as $employee)
                    <div class="employee-item">
                        @if ($employee['photo'])
                            <img src="{{ $employee['photo'] }}" alt="{{ $employee['name'] }}" class="employee-photo">
                        @else
                            <div class="employee-photo-placeholder">
                                {{ strtoupper(substr($employee['name'], 0, 1)) }}
                            </div>
                        @endif
                        <div class="employee-info">
                            <span class="employee-name">{{ $employee['name'] }}
                                @if ($employee['suspended'])
                                    <span class="badge-suspended">Suspendido</span>
                                @endif
                            </span>
                            <span class="employee-meta">
                                @if ($employee['since'])
                                    Desde {{ $employee['since'] }}
                                @endif
                                @if ($showBranch && $employee['branch'])
                                    {{ $employee['since'] ? '·' : '' }} {{ $employee['branch'] }}
                                @endif
                            </span>
                        </div>
                    </div>
                @endforeach
            @elseif ($position['vacant'])
                <div class="no-employees">Sin ocupante</div>
            @else
                <div class="no-employees">Sin empleados en esta vista</div>
            @endif
        </div>
    </div>

    @if (count($position['children']) > 0)
        <ul>
            @foreach ($position['children'] as $child)
                @include('org-chart.partials.position-node', ['position' => $child])
            @endforeach
        </ul>
    @endif
</li>
