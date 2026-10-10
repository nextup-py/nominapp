{{-- Bandeja "Por resolver": una tarjeta por tipo de pendiente con su contador y acceso a la lista filtrada. --}}
<x-filament-panels::page>
    @php($total = array_sum(array_column($sections, 'count')))

    @if ($total === 0)
        <x-filament::section>
            <div class="flex flex-col items-center gap-2 py-8 text-center">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-10 w-10 text-success-500" />
                <p class="text-base font-semibold text-gray-950 dark:text-white">Todo al día</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">No hay jornadas, aprobaciones, ausencias ni fallos de marcación pendientes.</p>
            </div>
        </x-filament::section>
    @endif

    <div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($sections as $section)
            <x-filament::section wire:key="inbox-{{ $section['key'] }}">
                <div class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <x-filament::icon :icon="$section['icon']" class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $section['label'] }}</h3>
                        </div>
                        <x-filament::badge :color="$section['count'] > 0 ? $section['color'] : 'success'" size="lg">
                            {{ $section['count'] }}
                        </x-filament::badge>
                    </div>

                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $section['description'] }}</p>

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($section['oldest'])
                            Lo más antiguo: {{ $section['oldest']->format('d/m/Y') }}
                        @else
                            Sin pendientes
                        @endif
                    </p>

                    <div>
                        <x-filament::button tag="a" :href="$section['url']" :color="$section['count'] > 0 ? 'primary' : 'gray'" size="sm">
                            {{ $section['count'] > 0 ? 'Resolver' : 'Ver lista' }}
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
