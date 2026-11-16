@php
    $location    = $getRecord()->location;
    $lat         = $location['lat'] ?? null;
    $lng         = $location['lng'] ?? null;
    $hasLocation = $lat && $lng;
@endphp

@if ($hasLocation)
    {{-- Mapa embebido --}}
    <div class="rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700">
        <iframe
            width="100%"
            height="220"
            frameborder="0"
            style="border:0; display:block;"
            referrerpolicy="no-referrer-when-downgrade"
            src="https://www.google.com/maps/embed/v1/place?key={{ config('services.google.maps_key') }}&q={{ $lat }},{{ $lng }}&zoom=16"
            allowfullscreen>
        </iframe>
    </div>

    {{-- Link a Google Maps --}}
    <a href="https://www.google.com/maps?q={{ $lat }},{{ $lng }}" target="_blank"
        class="inline-flex items-center gap-1.5 mt-2 text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">
        Ver en Google Maps
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
        </svg>
    </a>

    {{-- Control de ubicación: distancia al punto de trabajo y decisión de RR.HH. --}}
    @php
        $record = $getRecord();
        $rule = $record->location_distance_m !== null || $record->location_flag ? \App\Services\LocationRadiusService::resolve($record->employee) : null;
    @endphp
    @if ($record->location_distance_m !== null || $record->location_flag)
        <div class="mt-3 flex flex-col gap-1 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium text-gray-950 dark:text-white">Control de ubicación</span>
                @if ($record->location_flag)
                    <x-filament::badge color="warning">{{ \App\Services\LocationRadiusService::getFlagLabels()[$record->location_flag] ?? $record->location_flag }}</x-filament::badge>
                @else
                    <x-filament::badge color="success">Dentro del radio</x-filament::badge>
                @endif
                @if ($record->location_review)
                    <x-filament::badge :color="\App\Services\LocationRadiusService::getReviewColors()[$record->location_review] ?? 'gray'">{{ \App\Services\LocationRadiusService::getReviewLabels()[$record->location_review] ?? $record->location_review }}</x-filament::badge>
                @endif
            </div>
            @if ($record->location_distance_m !== null)
                <p class="text-gray-600 dark:text-gray-300">
                    A <strong>{{ number_format($record->location_distance_m, 0, ',', '.') }} m</strong> del punto de trabajo
                    @if ($rule) (radio permitido: {{ number_format($rule['radius'], 0, ',', '.') }} m) @endif
                </p>
            @endif
            @if ($rule)
                <a href="https://www.google.com/maps?q={{ $rule['lat'] }},{{ $rule['lng'] }}" target="_blank" class="text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">
                    Ver el punto de trabajo ({{ $rule['source'] === 'employee' ? 'del empleado' : 'de la sucursal' }})
                </a>
            @endif
            @if ($record->location_reviewed_at)
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Revisada el {{ $record->location_reviewed_at->format('d/m/Y H:i') }}@if ($record->location_reviewed_by_id) por {{ \App\Models\User::find($record->location_reviewed_by_id)?->name ?? 'un usuario' }}@endif
                </p>
            @endif
        </div>
    @endif
@else
    {{-- Sin ubicación --}}
    <span class="text-xs text-gray-400 dark:text-gray-500 italic">Sin datos de ubicación</span>
@endif
