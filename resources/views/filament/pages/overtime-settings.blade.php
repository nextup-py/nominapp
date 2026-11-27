{{-- Configuración de horas extra: un solo formulario que guarda ajustes de nómina y de asistencia. --}}
<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament-panels::form.actions :actions="$this->getFormActions()" />
    </form>
</x-filament-panels::page>
