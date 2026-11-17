{{-- Calendario de feriados: 12 meses por año, con los feriados resaltados por tipo y los domingos marcados. --}}
@php
    $colors = [
        'national_fixed' => 'rgba(var(--primary-500), .25)',
        'national_moving' => 'rgba(var(--warning-500), .30)',
        'non_working' => 'rgba(var(--info-500), .25)',
    ];
@endphp
<div x-data="{ year: {{ $years[0] }} }" class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-2">
        @foreach ($years as $y)
            <button type="button" x-on:click="year = {{ $y }}"
                    x-bind:class="year === {{ $y }} ? 'font-semibold underline' : ''"
                    class="rounded-lg px-3 py-1 text-sm ring-1 ring-gray-950/10 dark:ring-white/20">{{ $y }}</button>
        @endforeach
        <span class="ms-auto flex flex-wrap gap-3 text-xs text-gray-600 dark:text-gray-300">
            <span><span style="background: {{ $colors['national_fixed'] }}" class="inline-block h-3 w-3 rounded-sm"></span> Fijo</span>
            <span><span style="background: {{ $colors['national_moving'] }}" class="inline-block h-3 w-3 rounded-sm"></span> Móvil</span>
            <span><span style="background: {{ $colors['non_working'] }}" class="inline-block h-3 w-3 rounded-sm"></span> No laborable</span>
            <span class="text-gray-400">Domingos en gris</span>
        </span>
    </div>

    @foreach ($years as $y)
        <div x-show="year === {{ $y }}" x-cloak style="display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px;">
            @for ($m = 1; $m <= 12; $m++)
                @php($first = \Carbon\Carbon::create($y, $m, 1))
                <div class="rounded-xl p-2 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <p class="mb-1 text-xs font-semibold capitalize text-gray-950 dark:text-white">{{ $first->locale('es')->monthName }}</p>
                    <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; font-size: 11px; text-align: center;">
                        @foreach (['L', 'M', 'M', 'J', 'V', 'S', 'D'] as $d)
                            <span class="text-gray-400">{{ $d }}</span>
                        @endforeach
                        @for ($i = 1; $i < $first->dayOfWeekIso; $i++)
                            <span></span>
                        @endfor
                        @for ($day = 1; $day <= $first->daysInMonth; $day++)
                            @php($date = $first->copy()->day($day))
                            @php($holiday = $holidays[$date->toDateString()] ?? null)
                            <span title="{{ $holiday?->name }}"
                                  style="border-radius: 4px; padding: 1px 0; @if ($holiday) background: {{ $colors[$holiday->type] ?? $colors['national_fixed'] }}; font-weight: 600; @elseif ($date->isSunday()) background: rgba(128,128,128,.15); @endif"
                                  class="text-gray-950 dark:text-white">{{ $day }}</span>
                        @endfor
                    </div>
                </div>
            @endfor
        </div>
    @endforeach
</div>
