{{-- Ciclo del patrón de rotación: una fila por día con su turno, horario y color. Estilos inline: el panel no compila clases Tailwind propias. --}}
@php
    $days = $getRecord()->cycleDays();
@endphp

<div style="overflow-x: auto;">
    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
        <thead>
            <tr style="text-align: left; border-bottom: 1px solid rgba(var(--gray-500), .3);">
                <th style="padding: .5rem;">Día</th>
                <th style="padding: .5rem;">Turno</th>
                <th style="padding: .5rem;">Horario</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($days as $day)
                <tr style="border-bottom: 1px solid rgba(var(--gray-500), .15);">
                    <td style="padding: .5rem; white-space: nowrap;">Día {{ $day['day'] }}</td>
                    <td style="padding: .5rem;">
                        <span style="display: inline-block; width: .75rem; height: .75rem; border-radius: 9999px; margin-right: .4rem; background: {{ $day['color'] ?? '#6B7280' }};"></span>{{ $day['name'] }}
                    </td>
                    <td style="padding: .5rem; opacity: .8;">{{ $day['summary'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3" style="padding: .75rem; opacity: .7;">El patrón no tiene días cargados.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
