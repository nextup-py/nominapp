{{-- Salud del sistema: estado de cada chequeo y errores repetidos de la última semana, agrupados y explicados. --}}
<x-filament-panels::page>
    @php
        $colors = ['ok' => 'success', 'warning' => 'warning', 'danger' => 'danger'];
        $labels = ['scheduler' => 'Programador', 'queue' => 'Cola', 'mail' => 'Correo', 'disk' => 'Disco', 'logs' => 'Logs'];
    @endphp

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
        @foreach ($this->checks() as $key => $check)
            <div wire:key="check-{{ $key }}" class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $labels[$key] }}</p>
                    <x-filament::badge :color="$colors[$check['status']]">{{ ['ok' => 'Bien', 'warning' => 'Atención', 'danger' => 'Crítico'][$check['status']] }}</x-filament::badge>
                </div>
                <p class="mt-2 text-sm font-semibold text-gray-950 dark:text-white">{{ $check['title'] }}</p>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $check['detail'] }}</p>
            </div>
        @endforeach
    </div>

    <x-filament::section heading="Errores de los últimos 7 días" description="Los errores repetidos se agrupan en una sola línea con su contador." icon="heroicon-o-exclamation-triangle">
        <x-slot name="headerEnd">
            <x-filament::button tag="a" :href="$this->logsUrl()" color="gray" size="sm" icon="heroicon-o-document-text">Abrir el visor de logs</x-filament::button>
        </x-slot>

        @forelse ($this->errors() as $error)
            <div class="border-b border-gray-100 py-3 last:border-0 dark:border-white/5" wire:key="error-{{ $loop->index }}">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge color="danger">{{ $error['count'] }} {{ $error['count'] === 1 ? 'vez' : 'veces' }}</x-filament::badge>
                    <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $error['explanation']['what'] ?? \Illuminate\Support\Str::limit($error['message'], 140) }}</span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Primera vez {{ $error['first_seen']->format('d/m H:i') }} · última {{ $error['last_seen']->format('d/m H:i') }}
                </p>
                @if ($error['explanation'])
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Qué hacer: {{ $error['explanation']['todo'] }}</p>
                    <p class="mt-1 break-all text-xs text-gray-400 dark:text-gray-500">{{ \Illuminate\Support\Str::limit($error['message'], 200) }}</p>
                @endif
            </div>
        @empty
            <p class="py-2 text-sm text-gray-500 dark:text-gray-400">No hubo errores en la última semana.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
