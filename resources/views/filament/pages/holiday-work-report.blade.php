{{-- Trabajo en feriado y domingo: filtros, totales y detalle por jornada, con la estimación del recargo. --}}
<x-filament-panels::page>
    @php($rows = $this->rows())
    @php($totals = \App\Services\HolidayWorkReportService::totals($rows))

    <div class="flex flex-wrap items-end gap-3">
        <label class="text-xs text-gray-600 dark:text-gray-300">Desde
            <x-filament::input.wrapper class="w-40"><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
        </label>
        <label class="text-xs text-gray-600 dark:text-gray-300">Hasta
            <x-filament::input.wrapper class="w-40"><x-filament::input type="date" wire:model.live="until" /></x-filament::input.wrapper>
        </label>
        <x-filament::input.wrapper class="w-52">
            <x-filament::input.select wire:model.live="kind">
                @foreach (\App\Services\HolidayWorkReportService::kindOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <x-filament::input.wrapper class="w-52">
            <x-filament::input.select wire:model.live="branchId">
                <option value="">Todas las sucursales</option>
                @foreach ($this->branches() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach ([
            ['Jornadas', $totals['days']],
            ['Empleados', $totals['employees']],
            ['Horas trabajadas', number_format($totals['worked'], 1, ',', '.')],
            ['Horas extra registradas', number_format($totals['extra'], 1, ',', '.')],
            ['Horas del turno sin recargo', number_format($totals['normal'], 1, ',', '.')],
            ['Recargo estimado (Gs.)', number_format($totals['premium'], 0, ',', '.')],
        ] as [$label, $value])
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        El recargo estimado es horas del turno × valor hora × (multiplicador de feriado − 1). Hoy el turno normal en feriado o domingo se paga como un día común: este reporte no cambia ningún pago y sirve para consultarlo con el asesor legal y el contador.
        @if ($totals['without_rate'] > 0) {{ $totals['without_rate'] }} jornada(s) sin contrato mensual vigente ese día no tienen estimación. @endif
        @if ($totals['days'] >= \App\Services\HolidayWorkReportService::MAX_ROWS) Se muestran las primeras {{ \App\Services\HolidayWorkReportService::MAX_ROWS }}: acotá el período. @endif
    </p>

    <x-filament::section :compact="true">
        <div class="overflow-x-auto">
            <table class="w-full text-start text-sm">
                <thead class="text-xs text-gray-500 dark:text-gray-400">
                    <tr>
                        @foreach (['Fecha', 'Tipo', 'Empleado', 'Sucursal', 'Trabajadas', 'Extra', 'Turno', 'Valor hora', 'Recargo estimado'] as $h)
                            <th class="px-2 py-2 text-start font-medium">{{ $h }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($rows as $row)
                        <tr wire:key="row-{{ $loop->index }}">
                            <td class="px-2 py-1.5">{{ $row['date']->format('d/m/Y') }}</td>
                            <td class="px-2 py-1.5">{{ $row['kind'] }}</td>
                            <td class="px-2 py-1.5">{{ $row['employee'] }}</td>
                            <td class="px-2 py-1.5">{{ $row['branch'] }}</td>
                            <td class="px-2 py-1.5">{{ number_format($row['worked'], 2, ',', '.') }}</td>
                            <td class="px-2 py-1.5">{{ number_format($row['extra'], 2, ',', '.') }}@unless ($row['extra_approved'])<span class="text-gray-400"> (sin aprobar)</span>@endunless</td>
                            <td class="px-2 py-1.5">{{ number_format($row['normal'], 2, ',', '.') }}</td>
                            <td class="px-2 py-1.5">{{ $row['hourly'] !== null ? number_format($row['hourly'], 0, ',', '.') : '—' }}</td>
                            <td class="px-2 py-1.5">{{ $row['premium'] !== null ? number_format($row['premium'], 0, ',', '.') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-2 py-6 text-center text-gray-500 dark:text-gray-400">No hay jornadas con marcación en feriado o domingo en este período.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
