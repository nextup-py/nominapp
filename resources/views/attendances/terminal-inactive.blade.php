{{-- resources/views/attendances/terminal-inactive.blade.php --}}
<x-status-page
    variant="danger"
    title="Terminal fuera de servicio"
    :description="['Esta terminal no está disponible en este momento.', 'Por favor, comuníquese con el administrador.']"
    icon="<path stroke-linecap='round' stroke-linejoin='round' d='M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z' />"
>
    <x-slot:extra>{{ $terminal->name }} — {{ $terminal->branch?->name }}</x-slot:extra>
</x-status-page>
