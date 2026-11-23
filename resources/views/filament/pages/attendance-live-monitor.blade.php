{{-- Monitor de asistencia en vivo: tarjetas tocables en una fila, aviso de terminales caídos, lista por empleado (plana o agrupada por sucursal), resumen por sucursal con el estado de sus terminales, alertas y feed de marcaciones. Se refresca con polling. Los estilos son propios (clases lm-*): el panel solo trae las utilidades de Tailwind que Filament ya usa, así que las de grilla no existen aquí. --}}
<x-filament-panels::page>
    @if ($kiosk)
        {{-- Modo pantalla grande: sin menú ni barra superior. --}}
        <style>
            .fi-sidebar, .fi-topbar, .fi-header { display: none !important; }
            .fi-main { max-width: 100% !important; }
        </style>
    @endif

    {{-- Estilos del monitor, con las variables de color de Filament para que sigan el tema claro y oscuro. --}}
    <style>
        .live-monitor { --lm-bg: #fff; --lm-line: rgba(var(--gray-950), .08); --lm-muted: rgb(var(--gray-500)); --lm-text: rgb(var(--gray-950)); --lm-danger: rgb(var(--danger-600)); --lm-warning: rgb(var(--warning-600)); --lm-success: rgb(var(--success-600)); --lm-primary: rgb(var(--primary-500)); display: flex; flex-direction: column; gap: 1rem; }
        .dark .live-monitor { --lm-bg: rgb(var(--gray-900)); --lm-line: rgba(255, 255, 255, .1); --lm-muted: rgb(var(--gray-400)); --lm-text: #fff; --lm-danger: rgb(var(--danger-400)); --lm-warning: rgb(var(--warning-400)); --lm-success: rgb(var(--success-400)); }
        .live-monitor .lm-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: .75rem; }
        .live-monitor .lm-filters .lm-right { margin-inline-start: auto; display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
        .live-monitor .lm-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(6.5rem, 1fr)); gap: .5rem; }
        .live-monitor .lm-card { text-align: start; padding: .5rem .75rem; border-radius: .75rem; background: var(--lm-bg); box-shadow: 0 0 0 1px var(--lm-line); cursor: pointer; transition: box-shadow .15s; }
        .live-monitor .lm-card:hover { box-shadow: 0 0 0 1px var(--lm-primary); }
        .live-monitor .lm-card.is-active { box-shadow: 0 0 0 2px var(--lm-primary); }
        .live-monitor .lm-label { font-size: .75rem; line-height: 1rem; color: var(--lm-muted); }
        .live-monitor .lm-num { font-size: 1.5rem; line-height: 2rem; font-weight: 600; color: var(--lm-text); }
        .live-monitor .lm-num.is-danger { color: var(--lm-danger); }
        .live-monitor .lm-num.is-warning { color: var(--lm-warning); }
        .live-monitor .lm-cols { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; align-items: start; }
        @media (min-width: 1280px) { .live-monitor .lm-cols { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); } }
        .live-monitor .lm-stack { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
        .live-monitor .lm-banner { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .625rem .875rem; border-radius: .75rem; background: rgba(var(--danger-500), .1); box-shadow: 0 0 0 1px rgba(var(--danger-500), .35); color: var(--lm-danger); font-size: .875rem; }
        .live-monitor .lm-banner a { text-decoration: underline; }
        .live-monitor .lm-table { width: 100%; border-collapse: collapse; font-size: .875rem; text-align: start; }
        .live-monitor .lm-table th { padding: .375rem .5rem; font-size: .75rem; font-weight: 500; color: var(--lm-muted); text-align: start; }
        .live-monitor .lm-table td { padding: .375rem .5rem; border-top: 1px solid var(--lm-line); vertical-align: top; }
        .live-monitor .lm-table .lm-group td { background: rgba(var(--gray-500), .08); font-weight: 600; }
        .live-monitor .lm-table .lm-num-cell { text-align: center; font-variant-numeric: tabular-nums; }
        .live-monitor .lm-table .is-danger { color: var(--lm-danger); font-weight: 600; }
        .live-monitor .lm-muted { color: var(--lm-muted); font-size: .75rem; }
        .live-monitor .lm-chip { display: inline-flex; align-items: center; gap: .25rem; margin-inline-end: .5rem; font-size: .75rem; white-space: nowrap; }
        .live-monitor .lm-dot { display: inline-block; width: .5rem; height: .5rem; border-radius: 9999px; background: var(--lm-muted); }
        .live-monitor .lm-dot.is-success { background: var(--lm-success); }
        .live-monitor .lm-dot.is-danger { background: var(--lm-danger); }
        .live-monitor .lm-views { display: flex; gap: .5rem; margin-bottom: .75rem; }
        .live-monitor.is-kiosk { font-size: 1.125rem; }
        .live-monitor.is-kiosk .lm-label { font-size: 1rem; line-height: 1.25rem; }
        .live-monitor.is-kiosk .lm-num { font-size: 2.5rem; line-height: 2.75rem; }
        .live-monitor.is-kiosk .lm-table, .live-monitor.is-kiosk .lm-banner { font-size: 1.125rem; }
    </style>

    @php
        $problemTerminals = collect($snapshot['terminals'])->filter(fn ($t) => in_array($t['status'], ['stale', 'unlinked', 'never_connected'], true));
        $terminalDot = fn (string $status) => match ($status) {
            'online' => 'is-success',
            'off_hours' => '',
            default => 'is-danger',
        };
    @endphp

    <div class="live-monitor {{ $kiosk ? 'is-kiosk' : '' }}" wire:poll.{{ $pollSeconds }}s.visible
         x-data="{ fullscreen: false, toggleFullscreen() { if (document.fullscreenElement) { document.exitFullscreen(); } else { document.documentElement.requestFullscreen?.(); } } }"
         x-on:fullscreenchange.window="fullscreen = !! document.fullscreenElement">

        {{-- Filtros y modo pantalla (en pantalla grande solo queda la hora y el botón para salir) --}}
        <div class="lm-filters">
            @unless ($kiosk)
                @if ($showCompanyFilter)
                    <x-filament::input.wrapper style="width: 12rem">
                        <x-filament::input.select wire:model.live="companyId">
                            <option value="">Todas las empresas</option>
                            @foreach ($companies as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif

                <x-filament::input.wrapper style="width: 12rem">
                    <x-filament::input.select wire:model.live="branchId">
                        <option value="">Todas las sucursales</option>
                        @foreach ($branches as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <x-filament::input.wrapper style="width: 12rem">
                    <x-filament::input.select wire:model.live="departmentId">
                        <option value="">Todos los departamentos</option>
                        @foreach ($departments as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <x-filament::input.wrapper style="width: 14rem">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Buscar nombre o CI" />
                </x-filament::input.wrapper>

                <label class="lm-muted" style="display: flex; align-items: center; gap: .5rem; font-size: .875rem;">
                    <x-filament::input.checkbox wire:model.live="onlyScheduled" />
                    Solo quienes tienen turno hoy
                </label>
            @endunless

            <div class="lm-right">
                <span class="lm-muted">Actualizado {{ $snapshot['now']->format('H:i:s') }} · cada {{ $pollSeconds }} s</span>
                <x-filament::button color="gray" size="sm" icon="heroicon-o-tv" wire:click="$toggle('kiosk')">
                    {{ $kiosk ? 'Salir de pantalla grande' : 'Pantalla grande' }}
                </x-filament::button>
                <x-filament::button color="gray" size="sm" icon="heroicon-o-arrows-pointing-out" x-on:click="toggleFullscreen()">
                    Pantalla completa
                </x-filament::button>
            </div>
        </div>

        {{-- Aviso: un terminal caído no registra marcaciones, así que lo «sin marcar» de esa sucursal puede no ser real --}}
        @if ($problemTerminals->isNotEmpty())
            <div class="lm-banner" role="alert">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" style="width: 1.25rem; height: 1.25rem;" />
                <span>
                    <strong>{{ $problemTerminals->count() }} {{ $problemTerminals->count() === 1 ? 'terminal sin conexión o sin vincular' : 'terminales sin conexión o sin vincular' }}:</strong>
                    {{ $problemTerminals->map(fn ($t) => $t['name'].' ('.($t['branch'] ?? 'sin sucursal').')')->implode(' · ') }}.
                    Las faltas de esas sucursales pueden no ser reales: un equipo caído no registra marcaciones.
                </span>
                @if ($terminalsUrl)
                    <a href="{{ $terminalsUrl }}">Ver terminales</a>
                @endif
            </div>
        @endif

        {{-- Contadores en una fila: cada tarjeta filtra la lista por ese estado --}}
        @php
            $cards = [
                ['key' => 'present', 'label' => 'Presentes ahora', 'color' => 'success'],
                ['key' => 'late', 'label' => 'Con retraso', 'color' => 'warning'],
                ['key' => 'missing', 'label' => 'Sin marcar', 'color' => 'danger'],
                ['key' => 'absent', 'label' => 'Ausentes', 'color' => 'danger'],
                ['key' => 'no_exit', 'label' => 'Sin salida', 'color' => 'warning'],
                ['key' => 'on_break', 'label' => 'En descanso', 'color' => 'info'],
                ['key' => 'upcoming', 'label' => 'Aún no inician', 'color' => 'gray'],
                ['key' => 'finished', 'label' => 'Terminaron', 'color' => 'gray'],
                ['key' => 'on_leave', 'label' => 'De permiso', 'color' => 'warning'],
            ];
        @endphp
        <div class="lm-cards">
            @foreach ($cards as $card)
                @php
                    $count = $snapshot['counts'][$card['key']] ?? 0;
                @endphp
                <button type="button" wire:click="toggleState('{{ $card['key'] }}')" wire:key="card-{{ $card['key'] }}"
                        class="lm-card {{ $state === $card['key'] ? 'is-active' : '' }}">
                    <p class="lm-label">{{ $card['label'] }}</p>
                    <p class="lm-num {{ $count > 0 && in_array($card['color'], ['danger', 'warning'], true) ? 'is-'.$card['color'] : '' }}">{{ $count }}</p>
                </button>
            @endforeach
        </div>

        <p class="lm-muted">
            {{ $snapshot['total'] }} empleados activos en el alcance
            @if ($state) · filtrando por <strong>{{ $states[$state]['label'] ?? ($state === 'late' ? 'Con retraso' : 'Presentes ahora') }}</strong>
                (<button type="button" style="text-decoration: underline;" wire:click="toggleState('{{ $state }}')">quitar filtro</button>)
            @endif
        </p>

        <div class="lm-cols">
            {{-- Lista en vivo, plana o agrupada por sucursal --}}
            <div class="lm-stack">
                <x-filament::section heading="Empleados hoy" :compact="true">
                    <div class="lm-views">
                        <x-filament::button size="xs" :color="$grouped ? 'gray' : 'primary'" :outlined="$grouped" wire:click="$set('grouped', false)">Lista plana</x-filament::button>
                        <x-filament::button size="xs" :color="$grouped ? 'primary' : 'gray'" :outlined="! $grouped" wire:click="$set('grouped', true)">Agrupar por sucursal</x-filament::button>
                    </div>

                    @if (count($snapshot['rows']) === 0)
                        <p class="lm-muted" style="padding: 1.5rem 0; text-align: center; font-size: .875rem;">No hay empleados para mostrar con estos filtros.</p>
                    @else
                        @php
                            $groups = $grouped
                                ? collect($snapshot['rows'])->groupBy(fn ($r) => $r['branch'] ?? 'Sin sucursal')->sortKeys()
                                : collect(['' => collect($snapshot['rows'])]);
                        @endphp
                        <div style="overflow-x: auto;">
                            <table class="lm-table">
                                <thead>
                                    <tr>
                                        <th>Empleado</th>
                                        <th>Estado</th>
                                        <th>Horario</th>
                                        <th>Última marcación</th>
                                        <th>Detalle</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($groups as $branchName => $groupRows)
                                        @if ($grouped)
                                            <tr class="lm-group" wire:key="group-{{ $branchName }}">
                                                <td colspan="5">
                                                    {{ $branchName }}
                                                    <span class="lm-muted">· {{ $groupRows->count() }} {{ $groupRows->count() === 1 ? 'empleado' : 'empleados' }}</span>
                                                </td>
                                            </tr>
                                        @endif
                                        @foreach ($groupRows as $row)
                                            @php
                                                $meta = $states[$row['state']];
                                            @endphp
                                            <tr wire:key="row-{{ $row['id'] }}">
                                                <td>
                                                    <a href="{{ \App\Filament\Pages\EmployeeMonthSheet::getUrl(['employee' => $row['id']]) }}" class="font-medium text-gray-950 hover:underline dark:text-white">{{ $row['name'] }}</a>
                                                    @if ($row['branch'] && ! $grouped)
                                                        <div class="lm-muted">{{ $row['branch'] }}</div>
                                                    @endif
                                                </td>
                                                <td>
                                                    <x-filament::badge :color="$meta['color']" :icon="$meta['icon']">{{ $meta['label'] }}</x-filament::badge>
                                                    @if ($row['late_minutes'] > 0 && $row['state'] !== 'missing')
                                                        <x-filament::badge color="warning" style="margin-top: .25rem;">Retraso {{ $row['late_minutes'] }} min</x-filament::badge>
                                                    @endif
                                                </td>
                                                <td class="lm-muted" style="font-size: .875rem;">
                                                    {{ $row['expected_in'] ? $row['expected_in'].' – '.($row['expected_out'] ?? '?') : '—' }}
                                                </td>
                                                <td class="lm-muted" style="font-size: .875rem;">
                                                    {{ $row['last_event'] ? $row['last_event'].' · '.$row['last_event_at'] : '—' }}
                                                </td>
                                                <td class="lm-muted" style="font-size: .875rem;">{{ $row['detail'] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($snapshot['truncated'])
                            <p class="lm-muted" style="margin-top: .75rem;">Se muestran los primeros {{ \App\Services\LiveAttendanceMonitor::MAX_ROWS }}; acotá con los filtros.</p>
                        @endif
                    @endif
                </x-filament::section>
            </div>

            <div class="lm-stack">
                {{-- Resumen por sucursal, con el estado de sus terminales --}}
                <x-filament::section heading="Por sucursal" :compact="true">
                    @if (count($snapshot['branches']) === 0)
                        <p class="lm-muted" style="font-size: .875rem;">Sin sucursales para mostrar.</p>
                    @else
                        <div style="overflow-x: auto;">
                            <table class="lm-table">
                                <thead>
                                    <tr>
                                        <th>Sucursal</th>
                                        <th class="lm-num-cell">Presentes</th>
                                        <th class="lm-num-cell">Sin marcar</th>
                                        <th class="lm-num-cell">Ausentes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($snapshot['branches'] as $branch)
                                        <tr wire:key="branch-{{ $branch['branch'] }}">
                                            <td>
                                                <span class="font-medium text-gray-950 dark:text-white">{{ $branch['branch'] }}</span>
                                                <div class="lm-muted">
                                                    {{ $branch['last_event_at'] ? 'Última marcación '.$branch['last_event_at'] : 'Sin marcaciones hoy' }}
                                                </div>
                                                @foreach ($branch['terminals'] as $terminal)
                                                    <span class="lm-chip" title="{{ $terminal['name'] }}{{ $terminal['heartbeat'] ? ' · último latido '.$terminal['heartbeat'] : '' }}">
                                                        <span class="lm-dot {{ $terminalDot($terminal['status']) }}"></span>{{ $terminal['label'] }}
                                                    </span>
                                                @endforeach
                                            </td>
                                            <td class="lm-num-cell">{{ $branch['present'] }}/{{ $branch['total'] }}</td>
                                            <td class="lm-num-cell {{ $branch['missing'] > 0 ? 'is-danger' : '' }}">{{ $branch['missing'] }}</td>
                                            <td class="lm-num-cell {{ $branch['absent'] > 0 ? 'is-danger' : '' }}">{{ $branch['absent'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>

                {{-- Alertas del momento --}}
                <x-filament::section heading="Alertas del momento" :compact="true">
                    @if (count($snapshot['alerts']) === 0)
                        <p class="lm-muted" style="font-size: .875rem;">Sin alertas por ahora.</p>
                    @else
                        <ul style="display: flex; flex-direction: column; gap: .5rem;">
                            @foreach ($snapshot['alerts'] as $alert)
                                <li style="font-size: .875rem;" wire:key="alert-{{ $loop->index }}">
                                    <x-filament::badge :color="$alert['color']">{{ ['missing' => 'Sin marcar', 'no_exit' => 'Sin salida', 'long_break' => 'Descanso largo', 'outside_radius' => 'Fuera de radio'][$alert['type']] ?? $alert['type'] }}</x-filament::badge>
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $alert['employee'] }}</span>
                                    <span class="lm-muted" style="font-size: .875rem;">— {{ $alert['text'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($inboxUrl)
                        <a href="{{ $inboxUrl }}" style="display: inline-block; margin-top: .75rem; text-decoration: underline;" class="lm-muted">Ver pendientes por resolver</a>
                    @endif
                </x-filament::section>

                {{-- Feed de marcaciones --}}
                <x-filament::section heading="Últimas marcaciones" :compact="true">
                    @if (count($snapshot['feed']) === 0)
                        <p class="lm-muted" style="font-size: .875rem;">Todavía no hay marcaciones hoy.</p>
                    @else
                        <ul style="display: flex; flex-direction: column; gap: .5rem;">
                            @foreach ($snapshot['feed'] as $event)
                                <li style="display: flex; align-items: center; justify-content: space-between; gap: .5rem; font-size: .875rem;" wire:key="feed-{{ $event['id'] }}">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $event['name'] }}</span>
                                    <span style="display: flex; align-items: center; gap: .5rem;">
                                        <x-filament::badge :color="match ($event['event_type']) { 'check_in' => 'success', 'check_out' => 'gray', default => 'info' }">{{ $event['type'] }}</x-filament::badge>
                                        <span class="lm-muted" style="font-variant-numeric: tabular-nums;">{{ $event['time'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-filament::section>
            </div>
        </div>
    </div>
</x-filament-panels::page>
