{{-- Tabla de salud de terminales con semáforo (estilos inline y variables de Filament: el panel solo trae las clases ya compiladas) --}}
@php
    $levelLabels = ['danger' => 'Con problemas', 'warning' => 'Con alertas', 'success' => 'Sin alertas'];
    $levelColors = ['danger' => 'danger', 'warning' => 'warning', 'success' => 'success'];
    $cell = 'padding: .5rem .75rem; vertical-align: top; text-align: left;';
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Salud de los terminales" description="Semáforo por terminal: gana la peor señal. Disponibilidad y conflictos de los últimos 7 días.">
        @if ($rows->isEmpty())
            <p style="color: rgb(var(--gray-500));">No hay terminales activos.</p>
        @else
            <div style="display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem;">
                @foreach ($counts as $level => $total)
                    <x-filament::badge :color="$levelColors[$level]">{{ $levelLabels[$level] }}: {{ $total }}</x-filament::badge>
                @endforeach
            </div>

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid rgba(var(--gray-500), .3);">
                            <th style="{{ $cell }}">Estado</th>
                            <th style="{{ $cell }}">Terminal</th>
                            <th style="{{ $cell }}">Conectividad</th>
                            <th style="{{ $cell }}">Disponibilidad</th>
                            <th style="{{ $cell }}">Conflictos</th>
                            <th style="{{ $cell }}">Identificación</th>
                            <th style="{{ $cell }}">Responsable</th>
                            <th style="{{ $cell }}">Alertas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($terminal = $row['terminal'])
                            <tr wire:key="health-board-{{ $terminal->id }}" style="border-bottom: 1px solid rgba(var(--gray-500), .15);">
                                <td style="{{ $cell }}">
                                    <x-filament::badge :color="$levelColors[$row['level']]">{{ $levelLabels[$row['level']] }}</x-filament::badge>
                                </td>
                                <td style="{{ $cell }}">
                                    <a href="{{ \App\Filament\Widgets\TerminalHealthBoardWidget::terminalUrl($terminal) }}" style="font-weight: 600; color: rgb(var(--primary-600));">{{ $terminal->name }}</a>
                                    <div style="color: rgb(var(--gray-500)); font-size: .75rem;">{{ $terminal->branch?->name ?? 'Sin sucursal' }}</div>
                                </td>
                                <td style="{{ $cell }}">
                                    {{ \App\Models\Terminal::getConnectivityStatusLabels()[$terminal->connectivity_status] ?? $terminal->connectivity_status }}
                                    <div style="color: rgb(var(--gray-500)); font-size: .75rem;">
                                        {{ $terminal->last_heartbeat_at ? 'Último latido '.$terminal->last_heartbeat_at->diffForHumans() : 'Sin latidos' }}
                                    </div>
                                </td>
                                <td style="{{ $cell }}">{{ $row['availability'] !== null ? number_format($row['availability'], 1, ',', '.').'%' : 'Sin datos' }}</td>
                                <td style="{{ $cell }}">{{ $row['conflicts'] }}</td>
                                <td style="{{ $cell }}">{{ $row['identification_ms'] !== null ? number_format($row['identification_ms'] / 1000, 1, ',', '.').' s' : 'Sin datos' }}</td>
                                <td style="{{ $cell }}">{{ $terminal->responsibleContact() ?? 'Sin responsable' }}</td>
                                <td style="{{ $cell }}">{{ $row['reasons'] === [] ? 'Sin alertas' : implode(' · ', $row['reasons']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
