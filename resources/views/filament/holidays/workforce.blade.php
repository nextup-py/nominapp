{{-- Empleados que trabajaron (fecha pasada) o tienen turno (fecha futura) en un feriado, por sucursal. --}}
<div class="flex flex-col gap-3 text-sm">
    <p class="text-gray-600 dark:text-gray-300">
        {{ $data['mode'] === 'worked' ? 'Empleados con marcación ese día' : 'Empleados con turno ese día según su horario' }}:
        <strong class="text-gray-950 dark:text-white">{{ $data['employees']->count() }}</strong>
    </p>

    @forelse ($data['employees']->groupBy('branch') as $branch => $rows)
        <div wire:key="branch-{{ $loop->index }}">
            <p class="font-medium text-gray-950 dark:text-white">{{ $branch }} <span class="text-gray-500">({{ $rows->count() }})</span></p>
            <ul class="ms-4 list-disc text-xs text-gray-600 dark:text-gray-300">
                @foreach ($rows as $row)
                    <li>{{ $row['name'] }} — {{ $row['detail'] }}</li>
                @endforeach
            </ul>
        </div>
    @empty
        <p class="text-gray-500 dark:text-gray-400">Nadie con {{ $data['mode'] === 'worked' ? 'marcación' : 'turno' }} ese día.</p>
    @endforelse
</div>
