{{-- Preferencias de notificaciones del usuario: avisos, canales, frecuencia y hora del resumen. --}}
<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" icon="heroicon-o-check">
                Guardar
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
