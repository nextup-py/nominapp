{{-- Perfil del usuario: formulario (foto y contraseña), permisos, sesiones abiertas y últimos ingresos. --}}
<x-filament-panels::page>
    <x-filament-panels::form id="form" wire:submit="save">
        {{ $this->form }}

        <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
    </x-filament-panels::form>

    {{-- Permisos agrupados por módulo, solo lectura --}}
    <x-filament::section heading="Mis permisos" icon="heroicon-o-shield-check" :collapsed="true" :collapsible="true">
        @if ($this->isSuperAdmin())
            <p class="text-sm text-gray-600 dark:text-gray-300">Tenés acceso total al sistema (Super Admin).</p>
        @else
            @forelse ($this->permissionGroups() as $group => $labels)
                <div class="mb-3">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $group }}</p>
                    <p class="text-xs text-gray-600 dark:text-gray-300">{{ implode(' · ', $labels) }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Tu rol no tiene permisos asignados.</p>
            @endforelse
        @endif
    </x-filament::section>

    {{-- Sesiones abiertas --}}
    <x-filament::section heading="Sesiones abiertas" icon="heroicon-o-computer-desktop">
        <x-slot name="headerEnd">
            <x-filament::button color="gray" size="sm" wire:click="closeOtherSessions" wire:confirm="¿Cerrar las sesiones abiertas en otros dispositivos?">
                Cerrar las demás sesiones
            </x-filament::button>
        </x-slot>

        <ul class="flex flex-col gap-2 text-sm">
            @forelse ($this->sessions() as $session)
                <li class="flex flex-wrap items-center justify-between gap-2" wire:key="session-{{ $session['id'] }}">
                    <span class="text-gray-950 dark:text-white">{{ $session['browser'] ?? 'Navegador' }} en {{ $session['os'] ?? 'dispositivo desconocido' }} · {{ $session['ip'] ?? 'IP desconocida' }}</span>
                    <span class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                        @if ($session['current'])
                            <x-filament::badge color="success">Esta sesión</x-filament::badge>
                        @endif
                        {{ $session['last_activity']->diffForHumans() }}
                    </span>
                </li>
            @empty
                <li class="text-gray-500 dark:text-gray-400">No hay información de sesiones.</li>
            @endforelse
        </ul>
    </x-filament::section>

    {{-- Últimos ingresos --}}
    <x-filament::section heading="Últimos ingresos" icon="heroicon-o-clock">
        <ul class="flex flex-col gap-2 text-sm">
            @forelse ($this->logins() as $login)
                <li class="flex flex-wrap items-center justify-between gap-2" wire:key="login-{{ $login->id }}">
                    <span class="text-gray-950 dark:text-white">{{ $login->logged_in_at->format('d/m/Y H:i') }} · {{ $login->ip_address ?? 'IP desconocida' }}</span>
                    @if ($login->is_new_ip)
                        <x-filament::badge color="warning">IP nueva</x-filament::badge>
                    @endif
                </li>
            @empty
                <li class="text-gray-500 dark:text-gray-400">Todavía no hay ingresos registrados.</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
