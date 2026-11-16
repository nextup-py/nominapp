{{-- Monitor de asistencia en vivo: contadores tocables, alertas, lista por empleado y feed de marcaciones. Se refresca con polling. --}}
<x-filament-panels::page>
    @if ($kiosk)
        {{-- Modo pantalla grande: sin menú ni barra superior, letra más grande. --}}
        <style>
            .fi-sidebar, .fi-topbar, .fi-header { display: none !important; }
            .fi-main { max-width: 100% !important; }
            .live-monitor { font-size: 1.125rem; }
        </style>
    @endif

    <div class="live-monitor flex flex-col gap-6" wire:poll.{{ $pollSeconds }}s.visible
         x-data="{ fullscreen: false, toggleFullscreen() { if (document.fullscreenElement) { document.exitFullscreen(); } else { document.documentElement.requestFullscreen?.(); } } }"
         x-on:fullscreenchange.window="fullscreen = !! document.fullscreenElement">

        {{-- Filtros y modo pantalla --}}
        <div class="flex flex-wrap items-end gap-3">
            @if ($showCompanyFilter)
                <x-filament::input.wrapper class="w-48">
                    <x-filament::input.select wire:model.live="companyId">
                        <option value="">Todas las empresas</option>
                        @foreach ($companies as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            @endif

            <x-filament::input.wrapper class="w-48">
                <x-filament::input.select wire:model.live="branchId">
                    <option value="">Todas las sucursales</option>
                    @foreach ($branches as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            <x-filament::input.wrapper class="w-48">
                <x-filament::input.select wire:model.live="departmentId">
                    <option value="">Todos los departamentos</option>
                    @foreach ($departments as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>

            <x-filament::input.wrapper class="w-56">
                <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Buscar nombre o CI" />
            </x-filament::input.wrapper>

            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <x-filament::input.checkbox wire:model.live="onlyScheduled" />
                Solo quienes tienen turno hoy
            </label>

            <div class="ms-auto flex items-center gap-3">
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    Actualizado {{ $snapshot['now']->format('H:i:s') }} · cada {{ $pollSeconds }} s
                </span>
                <x-filament::button color="gray" size="sm" icon="heroicon-o-tv" wire:click="$toggle('kiosk')">
                    {{ $kiosk ? 'Salir de pantalla grande' : 'Pantalla grande' }}
                </x-filament::button>
                <x-filament::button color="gray" size="sm" icon="heroicon-o-arrows-pointing-out" x-on:click="toggleFullscreen()">
                    Pantalla completa
                </x-filament::button>
            </div>
        </div>

        {{-- Contadores: cada tarjeta filtra la lista por ese estado --}}
        @php
            $cards = [
                ['key' => 'present', 'label' => 'Presentes ahora', 'color' => 'success'],
                ['key' => 'late', 'label' => 'Con retraso', 'color' => 'warning'],
                ['key' => 'missing', 'label' => 'Sin marcar', 'color' => 'danger'],
                ['key' => 'absent', 'label' => 'Ausentes', 'color' => 'danger'],
                ['key' => 'on_break', 'label' => 'En descanso', 'color' => 'info'],
                ['key' => 'upcoming', 'label' => 'Aún no inician', 'color' => 'gray'],
                ['key' => 'finished', 'label' => 'Terminaron', 'color' => 'gray'],
                ['key' => 'on_leave', 'label' => 'De permiso', 'color' => 'warning'],
            ];
        @endphp
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
            @foreach ($cards as $card)
                @php($count = $snapshot['counts'][$card['key']] ?? 0)
                <button type="button" wire:click="toggleState('{{ $card['key'] }}')" wire:key="card-{{ $card['key'] }}"
                        class="rounded-xl bg-white p-4 text-start shadow-sm ring-1 ring-gray-950/5 transition hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10 {{ $state === $card['key'] ? 'ring-2 !ring-primary-500' : '' }}">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                    <p class="mt-1 text-3xl font-semibold {{ $count > 0 && in_array($card['color'], ['danger', 'warning']) ? 'text-'.$card['color'].'-600 dark:text-'.$card['color'].'-400' : 'text-gray-950 dark:text-white' }}">{{ $count }}</p>
                </button>
            @endforeach
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ $snapshot['total'] }} empleados activos en el alcance
            @if ($state) · filtrando por <strong>{{ $states[$state]['label'] ?? ($state === 'late' ? 'Con retraso' : 'Presentes ahora') }}</strong>
                (<button type="button" class="underline" wire:click="toggleState('{{ $state }}')">quitar filtro</button>)
            @endif
        </p>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            {{-- Lista en vivo --}}
            <div class="xl:col-span-2">
                <x-filament::section heading="Empleados hoy" :compact="true">
                    @if (count($snapshot['rows']) === 0)
                        <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No hay empleados para mostrar con estos filtros.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-start text-sm">
                                <thead class="text-xs text-gray-500 dark:text-gray-400">
                                    <tr class="text-start">
                                        <th class="py-2 pe-3 text-start font-medium">Empleado</th>
                                        <th class="px-3 text-start font-medium">Estado</th>
                                        <th class="px-3 text-start font-medium">Horario</th>
                                        <th class="px-3 text-start font-medium">Última marcación</th>
                                        <th class="ps-3 text-start font-medium">Detalle</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach ($snapshot['rows'] as $row)
                                        @php($meta = $states[$row['state']])
                                        <tr wire:key="row-{{ $row['id'] }}">
                                            <td class="py-2 pe-3">
                                                <a href="{{ \App\Filament\Pages\EmployeeMonthSheet::getUrl(['employee' => $row['id']]) }}" class="font-medium text-gray-950 hover:underline dark:text-white">{{ $row['name'] }}</a>
                                                @if ($row['branch'])
                                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['branch'] }}</div>
                                                @endif
                                            </td>
                                            <td class="px-3">
                                                <x-filament::badge :color="$meta['color']" :icon="$meta['icon']">{{ $meta['label'] }}</x-filament::badge>
                                                @if ($row['late_minutes'] > 0 && $row['state'] !== 'missing')
                                                    <x-filament::badge color="warning" class="mt-1">Retraso {{ $row['late_minutes'] }} min</x-filament::badge>
                                                @endif
                                            </td>
                                            <td class="px-3 text-gray-600 dark:text-gray-300">
                                                {{ $row['expected_in'] ? $row['expected_in'].' – '.($row['expected_out'] ?? '?') : '—' }}
                                            </td>
                                            <td class="px-3 text-gray-600 dark:text-gray-300">
                                                {{ $row['last_event'] ? $row['last_event'].' · '.$row['last_event_at'] : '—' }}
                                            </td>
                                            <td class="ps-3 text-gray-600 dark:text-gray-300">{{ $row['detail'] ?? '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($snapshot['truncated'])
                            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Se muestran los primeros {{ \App\Services\LiveAttendanceMonitor::MAX_ROWS }}; acotá con los filtros.</p>
                        @endif
                    @endif
                </x-filament::section>
            </div>

            <div class="flex flex-col gap-6">
                {{-- Alertas del momento --}}
                <x-filament::section heading="Alertas del momento" :compact="true">
                    @php($hasAlerts = count($snapshot['alerts']) > 0 || $snapshot['offline_terminals'] > 0)
                    @if (! $hasAlerts)
                        <p class="py-2 text-sm text-gray-500 dark:text-gray-400">Sin alertas por ahora.</p>
                    @endif
                    <ul class="flex flex-col gap-2">
                        @if ($snapshot['offline_terminals'] > 0)
                            <li class="text-sm">
                                <x-filament::badge color="danger" icon="heroicon-o-computer-desktop">
                                    {{ $snapshot['offline_terminals'] }} {{ $snapshot['offline_terminals'] === 1 ? 'terminal sin conexión' : 'terminales sin conexión' }}
                                </x-filament::badge>
                                @if ($terminalsUrl)
                                    <a href="{{ $terminalsUrl }}" class="ms-1 text-xs underline">ver</a>
                                @endif
                            </li>
                        @endif
                        @foreach ($snapshot['alerts'] as $alert)
                            <li class="text-sm" wire:key="alert-{{ $loop->index }}">
                                <x-filament::badge :color="$alert['color']">{{ ['missing' => 'Sin marcar', 'long_break' => 'Descanso largo', 'outside_radius' => 'Fuera de radio'][$alert['type']] ?? $alert['type'] }}</x-filament::badge>
                                <span class="font-medium text-gray-950 dark:text-white">{{ $alert['employee'] }}</span>
                                <span class="text-gray-600 dark:text-gray-300">— {{ $alert['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($inboxUrl)
                        <a href="{{ $inboxUrl }}" class="mt-3 inline-block text-xs underline">Ver pendientes por resolver</a>
                    @endif
                </x-filament::section>

                {{-- Feed de marcaciones --}}
                <x-filament::section heading="Últimas marcaciones" :compact="true">
                    @if (count($snapshot['feed']) === 0)
                        <p class="py-2 text-sm text-gray-500 dark:text-gray-400">Todavía no hay marcaciones hoy.</p>
                    @else
                        <ul class="flex flex-col gap-2">
                            @foreach ($snapshot['feed'] as $event)
                                <li class="flex items-center justify-between gap-2 text-sm" wire:key="feed-{{ $event['id'] }}">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $event['name'] }}</span>
                                    <span class="flex items-center gap-2">
                                        <x-filament::badge :color="match ($event['event_type']) { 'check_in' => 'success', 'check_out' => 'gray', default => 'info' }">{{ $event['type'] }}</x-filament::badge>
                                        <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $event['time'] }}</span>
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
