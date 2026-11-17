{{-- Mi equipo: foto de hoy de las personas a cargo del usuario (solo consulta). Se refresca con polling. --}}
<x-filament-panels::page>
    @if (! $linked)
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Tu usuario no está vinculado a una ficha de empleado, por eso no se puede calcular tu equipo.
                Pedile a RR.HH. o a un administrador que la vincule.
            </p>
        </x-filament::section>
    @elseif (! $hasTeam)
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Todavía no hay personas a tu cargo. Aparecerán cuando RR.HH. te asigne como superior directo («Reporta a»)
                o, si tenés acceso a tu sucursal, cuando haya personal activo en ella.
            </p>
        </x-filament::section>
    @else
        <div class="flex flex-col gap-6" wire:poll.{{ $pollSeconds }}s.visible>
            <div class="flex flex-wrap items-end gap-3">
                <x-filament::input.wrapper class="w-56">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Buscar nombre o CI" />
                </x-filament::input.wrapper>
                <span class="ms-auto text-xs text-gray-500 dark:text-gray-400">
                    Actualizado {{ $snapshot['now']->format('H:i:s') }} · cada {{ $pollSeconds }} s
                </span>
            </div>

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
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-9">
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
                {{ $snapshot['total'] }} personas en tu equipo
                @if ($state) · filtrando por <strong>{{ $states[$state]['label'] ?? ($state === 'late' ? 'Con retraso' : 'Presentes ahora') }}</strong>
                    (<button type="button" class="underline" wire:click="toggleState('{{ $state }}')">quitar filtro</button>)
                @endif
            </p>

            <x-filament::section heading="Tu equipo hoy" :compact="true">
                @if (count($snapshot['rows']) === 0)
                    <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No hay personas para mostrar con este filtro.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-start text-sm">
                            <thead class="text-xs text-gray-500 dark:text-gray-400">
                                <tr>
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
                                            <span class="font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</span>
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
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
