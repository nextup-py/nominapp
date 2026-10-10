{{-- Ficha mensual de asistencia: encabezado del empleado, totales del mes y cuadrícula de días. Solo consulta. --}}
@php
    $totals = $sheet['totals'];
    $weekdays = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

    $totalGroups = [
        'Días' => [
            ['Presente', $totals['present_days'], 'success'],
            ['Ausente', $totals['absent_days'], 'danger'],
            ['Ausencia justificada', $totals['absent_justified_days'], 'info'],
            ['Permiso', $totals['leave_days'], 'info'],
            ['Vacaciones', $totals['vacation_days'], 'info'],
            ['Feriado / franco', $totals['holiday_days'] + $totals['day_off_days'], 'gray'],
        ],
        'Horas' => [
            ['Trabajadas', number_format($totals['hours_worked'], 2, ',', '.').' hrs', 'gray'],
            ['Esperadas', number_format($totals['hours_expected'], 2, ',', '.').' hrs', 'gray'],
            ['Extras', number_format($totals['extra_hours'], 2, ',', '.').' hrs', 'warning'],
            ['Extras aprobadas', number_format($totals['extra_hours_approved'], 2, ',', '.').' hrs', 'success'],
        ],
        'Tardanzas' => [
            ['Días con tardanza', $totals['late_days'], 'warning'],
            ['Minutos', $totals['late_minutes'], 'warning'],
            ['Minutos aprobados', $totals['late_minutes_approved'], 'success'],
        ],
        'Pendientes de resolver' => [
            ['Sin salida', $totals['pending_missing_check_out'], 'danger'],
            ['Tardanzas por aprobar', $totals['pending_tardiness'], 'warning'],
            ['Extras por aprobar', $totals['pending_overtime'], 'warning'],
        ],
    ];
@endphp

<x-filament-panels::page>
    {{-- Encabezado: empleado y navegación entre meses --}}
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem;">
            <div>
                <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ $employee->full_name }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    CI {{ $employee->ci }}
                    @if ($employee->branch) · {{ $employee->branch->name }} @endif
                    @if ($employee->activeContract?->position) · {{ $employee->activeContract->position->name }} @endif
                </p>
            </div>

            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.5rem;">
                <x-filament::button tag="a" :href="$previousUrl" color="gray" size="sm" icon="heroicon-m-chevron-left">Anterior</x-filament::button>
                <span class="text-base font-semibold text-gray-950 dark:text-white" style="min-width:9rem;text-align:center;">
                    {{ ucfirst($sheet['month']->translatedFormat('F Y')) }}
                </span>
                <x-filament::button tag="a" :href="$nextUrl" color="gray" size="sm" icon="heroicon-m-chevron-right" icon-position="after">Siguiente</x-filament::button>
                @unless ($isCurrentMonth)
                    <x-filament::button tag="a" :href="$currentUrl" color="primary" size="sm">Mes actual</x-filament::button>
                @endunless
                <x-filament::button tag="a" :href="$backUrl" color="gray" size="sm" outlined>Volver a Asistencias</x-filament::button>
            </div>
        </div>
    </x-filament::section>

    {{-- Totales del mes --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:1rem;">
        @foreach ($totalGroups as $title => $items)
            <x-filament::section :heading="$title" compact wire:key="totals-{{ $loop->index }}">
                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    @foreach ($items as [$label, $value, $color])
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                            <span class="text-sm text-gray-600 dark:text-gray-300">{{ $label }}</span>
                            <x-filament::badge :color="$color">{{ $value }}</x-filament::badge>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>

    {{-- Cuadrícula de días: lunes a domingo; cada día enlaza a su jornada --}}
    <x-filament::section heading="Días del mes" description="Clic en un día para abrir su jornada, donde se corrigen las marcaciones y se aprueban las alertas.">
        <div style="overflow-x:auto;">
            <div style="min-width:46rem;">
                <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:0.5rem;margin-bottom:0.5rem;">
                    @foreach ($weekdays as $weekday)
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400" style="text-align:center;">{{ $weekday }}</p>
                    @endforeach
                </div>

                <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:0.5rem;">
                    @for ($i = 0; $i < $sheet['leading_blanks']; $i++)
                        <div></div>
                    @endfor

                    @foreach ($sheet['days'] as $day)
                        @php
                            $color = $day['color'];
                            $tint = $day['state'] === 'upcoming'
                                ? 'transparent'
                                : "rgba(var(--{$color}-500), 0.10)";
                            $border = $day['is_today']
                                ? 'rgb(var(--primary-500))'
                                : ($day['state'] === 'upcoming' ? 'rgba(var(--gray-500), 0.2)' : "rgba(var(--{$color}-500), 0.35)");
                            $tag = $day['record_id'] ? 'a' : 'div';
                            $href = $day['record_id'] ? \App\Filament\Resources\AttendanceDayResource::getUrl('view', ['record' => $day['record_id']]) : null;
                        @endphp

                        <{{ $tag }}
                            @if ($href) href="{{ $href }}" @endif
                            wire:key="day-{{ $day['date']->format('Ymd') }}"
                            style="display:flex;flex-direction:column;gap:0.25rem;min-height:6.5rem;padding:0.5rem;border-radius:0.5rem;background:{{ $tint }};border:{{ $day['is_today'] ? '2px' : '1px' }} solid {{ $border }};text-decoration:none;"
                        >
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $day['date']->day }}</span>
                                @if ($day['label'] !== '')
                                    <x-filament::badge :color="$color" size="sm">{{ $day['label'] }}</x-filament::badge>
                                @endif
                            </div>

                            @if ($day['in'] || $day['out'])
                                <p class="text-xs text-gray-700 dark:text-gray-200">{{ $day['in'] ?? '—' }} – {{ $day['out'] ?? '—' }}</p>
                            @endif

                            @if ($day['hours'] !== null && $day['hours'] > 0)
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($day['hours'], 2, ',', '.') }} hrs</p>
                            @endif

                            @if ($day['late_minutes'] > 0 || $day['extra_hours'] > 0)
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    @if ($day['late_minutes'] > 0) {{ $day['late_minutes'] }} min tarde @endif
                                    @if ($day['late_minutes'] > 0 && $day['extra_hours'] > 0) · @endif
                                    @if ($day['extra_hours'] > 0) +{{ number_format($day['extra_hours'], 2, ',', '.') }} extra @endif
                                </p>
                            @endif

                            @foreach ($day['alerts'] as $alert)
                                <x-filament::badge :color="$alert === 'Sin salida' ? 'danger' : 'warning'" size="sm">
                                    {{ ['Tardanza por aprobar' => 'Aprobar tardanza', 'Extras por aprobar' => 'Aprobar extras'][$alert] ?? $alert }}
                                </x-filament::badge>
                            @endforeach
                        </{{ $tag }}>
                    @endforeach
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
