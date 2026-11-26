{{-- Tabla compacta de los días de un horario: un renglón por día con entrada, salida, descansos y horas netas. --}}
@php
    $days = $getRecord()->days->sortBy('day_of_week');
    $border = '1px solid rgba(128, 128, 128, .25)';
    $muted = 'opacity: .6;';
@endphp

<div style="overflow-x: auto;">
    <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
        <thead>
            <tr style="text-align: left; {{ $muted }}">
                <th style="padding: .5rem .75rem; border-bottom: {{ $border }}; font-weight: 600;">Día</th>
                <th style="padding: .5rem .75rem; border-bottom: {{ $border }}; font-weight: 600;">Entrada</th>
                <th style="padding: .5rem .75rem; border-bottom: {{ $border }}; font-weight: 600;">Salida</th>
                <th style="padding: .5rem .75rem; border-bottom: {{ $border }}; font-weight: 600;">Descansos</th>
                <th style="padding: .5rem .75rem; border-bottom: {{ $border }}; font-weight: 600; text-align: right;">Horas netas</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($days as $day)
                <tr wire:key="schedule-day-{{ $day->id }}" @if (! $day->is_active) style="{{ $muted }}" @endif>
                    <td style="padding: .5rem .75rem; border-bottom: {{ $border }}; font-weight: 500;">
                        {{ $day->day_of_week_name }}
                    </td>
                    @if ($day->is_active)
                        <td style="padding: .5rem .75rem; border-bottom: {{ $border }};">
                            {{ \Illuminate\Support\Carbon::parse($day->start_time)->format('H:i') }}
                        </td>
                        <td style="padding: .5rem .75rem; border-bottom: {{ $border }};">
                            {{ \Illuminate\Support\Carbon::parse($day->end_time)->format('H:i') }}
                            @if ($day->crossesMidnight())
                                <span style="margin-left: .25rem; font-size: .75rem; opacity: .7;">(día siguiente)</span>
                            @endif
                        </td>
                        <td style="padding: .5rem .75rem; border-bottom: {{ $border }};">
                            @forelse ($day->breaks as $break)
                                <div>{{ $break->name }} {{ \Illuminate\Support\Carbon::parse($break->start_time)->format('H:i') }}–{{ \Illuminate\Support\Carbon::parse($break->end_time)->format('H:i') }}</div>
                            @empty
                                <span style="{{ $muted }}">Sin descanso</span>
                            @endforelse
                        </td>
                        <td style="padding: .5rem .75rem; border-bottom: {{ $border }}; text-align: right; white-space: nowrap;">
                            {{ rtrim(rtrim(number_format($day->scheduled_hours, 2, ',', '.'), '0'), ',') }} h
                        </td>
                    @else
                        <td colspan="4" style="padding: .5rem .75rem; border-bottom: {{ $border }};">Libre</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
