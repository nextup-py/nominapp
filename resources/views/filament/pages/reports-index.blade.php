{{-- Índice de reportes: tarjetas por tema; solo se muestran los que el usuario puede abrir. --}}
<x-filament-panels::page>
    @forelse ($this->sections() as $topic => $reports)
        <x-filament::section :heading="$topic" :compact="true">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($reports as $report)
                    <a href="{{ $report['url'] }}" wire:key="report-{{ $loop->parent->index }}-{{ $loop->index }}"
                       class="flex items-start gap-3 rounded-xl p-4 ring-1 ring-gray-950/5 transition hover:ring-primary-500 dark:ring-white/10">
                        @if ($report['icon'])
                            <x-filament::icon :icon="$report['icon']" class="mt-0.5 h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400" />
                        @endif
                        <span>
                            <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $report['label'] }}</span>
                            <span class="block text-xs text-gray-600 dark:text-gray-300">{{ $report['description'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </x-filament::section>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">No tenés acceso a ningún reporte.</p>
    @endforelse
</x-filament-panels::page>
