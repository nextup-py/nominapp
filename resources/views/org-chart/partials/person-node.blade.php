{{-- Persona del organigrama por personas: tarjeta con su cargo y los subordinados directos debajo --}}
<li>
    <div class="position-node">
        <div class="position-header">{{ $person['position'] ?? 'Sin cargo' }}</div>
        <div class="position-employees">
            <div class="employee-item">
                @if ($person['photo'])
                    <img src="{{ $person['photo'] }}" alt="{{ $person['name'] }}" class="employee-photo">
                @else
                    <div class="employee-photo-placeholder">{{ strtoupper(substr($person['name'], 0, 1)) }}</div>
                @endif
                <div class="employee-info">
                    <span class="employee-name">{{ $person['name'] }}
                        @if ($person['suspended'])
                            <span class="badge-suspended">Suspendido</span>
                        @endif
                    </span>
                    <span class="employee-meta">
                        @if ($person['since'])
                            Desde {{ $person['since'] }}
                        @endif
                        @if ($showBranch && $person['branch'])
                            {{ $person['since'] ? '·' : '' }} {{ $person['branch'] }}
                        @endif
                    </span>
                </div>
            </div>
        </div>
    </div>

    @if (count($person['children']) > 0)
        <ul>
            @foreach ($person['children'] as $child)
                @include('org-chart.partials.person-node', ['person' => $child])
            @endforeach
        </ul>
    @endif
</li>
