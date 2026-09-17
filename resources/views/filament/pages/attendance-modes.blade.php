<x-filament-panels::page>

    <div class="space-y-6">

        {{-- Descripción general --}}
        <x-filament::section>
            <x-slot name="heading">¿Cómo marcan los empleados?</x-slot>
            <x-slot name="description">
                El sistema dispone de dos modos de marcación facial. Compartí la URL o el código QR
                correspondiente con cada empleado o instalalo en el dispositivo del kiosco.
            </x-slot>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

                {{-- ── Modo Móvil ── --}}
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm">

                    {{-- Encabezado --}}
                    <div class="flex items-center gap-3 bg-green-600 px-5 py-4">
                        <x-filament::icon icon="heroicon-o-device-phone-mobile" class="h-7 w-7 text-white" />
                        <div>
                            <p class="text-base font-bold text-white">Modo Móvil</p>
                            <p class="text-xs text-green-100">Dispositivo personal del empleado</p>
                        </div>
                    </div>

                    <div class="p-6 space-y-6 bg-white dark:bg-gray-900">

                        {{-- URL + copiar --}}
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
                                URL de acceso
                            </p>
                            <div class="flex items-center gap-2">
                                <a
                                    href="{{ $mobileUrl }}"
                                    target="_blank"
                                    class="flex-1 truncate rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-2 font-mono text-sm text-green-600 dark:text-green-400 hover:underline"
                                >
                                    {{ $mobileUrl }}
                                </a>
                                <button
                                    type="button"
                                    onclick="navigator.clipboard.writeText('{{ $mobileUrl }}').then(() => { this.textContent = '✓'; setTimeout(() => this.textContent = 'Copiar', 1500) })"
                                    class="shrink-0 rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition"
                                >
                                    Copiar
                                </button>
                            </div>
                        </div>

                        {{-- QR --}}
                        <div class="flex flex-col items-center gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Código QR
                            </p>
                            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white p-3 inline-block">
                                {!! $mobileQr !!}
                            </div>
                            <p class="text-xs text-gray-400">Apuntá la cámara para abrir directamente</p>
                        </div>

                        {{-- Requisitos --}}
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">
                                Requisitos del dispositivo
                            </p>
                            <ul class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                                <li class="flex items-center gap-2">
                                    <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Cámara frontal
                                </li>
                                <li class="flex items-center gap-2">
                                    <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    GPS / ubicación activada
                                </li>
                                <li class="flex items-center gap-2">
                                    <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Acceso a internet
                                </li>
                                <li class="flex items-center gap-2">
                                    <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Rostro registrado previamente
                                </li>
                            </ul>
                        </div>

                        {{-- Cuándo usarlo --}}
                        <div class="rounded-lg bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-800 px-4 py-3 text-sm text-green-800 dark:text-green-300">
                            <strong>Cuándo usarlo:</strong> empleados remotos o en campo que marcan
                            desde su propio dispositivo. La ubicación GPS se registra automáticamente.
                        </div>

                    </div>
                </div>

                {{-- ── Modo Terminal / Kiosco ── --}}
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm">

                    {{-- Encabezado --}}
                    <div class="flex items-center gap-3 bg-primary-600 px-5 py-4">
                        <x-filament::icon icon="heroicon-o-computer-desktop" class="h-7 w-7 text-white" />
                        <div>
                            <p class="text-base font-bold text-white">Modo Terminal / Kiosco</p>
                            <p class="text-xs text-primary-100">Dispositivo compartido en la sucursal</p>
                        </div>
                    </div>

                    <div class="p-6 space-y-6 bg-white dark:bg-gray-900">

                        {{-- Terminales registradas --}}
                        @if ($terminals->isEmpty())
                            <div class="rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 px-5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                <p class="font-medium mb-1">Sin terminales registradas</p>
                                <p>Registrá una terminal en <strong>Asistencias → Terminales</strong> para obtener su URL y código QR.</p>
                            </div>
                        @else
                            {{-- Una card por terminal --}}
                            <div class="space-y-5">
                                @foreach ($terminals as $terminal)
                                    <div class="rounded-lg border border-primary-100 dark:border-primary-900 bg-primary-50/40 dark:bg-primary-950/20 p-4 space-y-4">

                                        {{-- Nombre y sucursal --}}
                                        <div>
                                            <p class="font-semibold text-sm text-gray-800 dark:text-gray-200">{{ $terminal['name'] }}</p>
                                            @if ($terminal['branch'])
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $terminal['branch'] }}</p>
                                            @endif
                                        </div>

                                        {{-- URL + copiar --}}
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
                                                URL de acceso
                                            </p>
                                            <div class="flex items-center gap-2">
                                                <a
                                                    href="{{ $terminal['url'] }}"
                                                    target="_blank"
                                                    class="flex-1 truncate rounded-lg bg-gray-100 dark:bg-gray-800 px-3 py-2 font-mono text-sm text-primary-600 dark:text-primary-400 hover:underline"
                                                >
                                                    {{ $terminal['url'] }}
                                                </a>
                                                <button
                                                    type="button"
                                                    onclick="navigator.clipboard.writeText('{{ $terminal['url'] }}').then(() => { this.textContent = '✓'; setTimeout(() => this.textContent = 'Copiar', 1500) })"
                                                    class="shrink-0 rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition"
                                                >
                                                    Copiar
                                                </button>
                                            </div>
                                        </div>

                                        {{-- QR --}}
                                        <div class="flex flex-col items-center gap-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                                Código QR
                                            </p>
                                            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white p-3 inline-block">
                                                {!! $terminal['qr'] !!}
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Requisitos --}}
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">
                                Requisitos del dispositivo
                            </p>
                            <ul class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                                <li class="flex items-center gap-2">
                                    <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Cámara frontal
                                </li>
                                <li class="flex items-center gap-2">
                                    <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Acceso a internet
                                </li>
                                <li class="flex items-center gap-2">
                                    <svg class="h-4 w-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Rostros de empleados registrados previamente
                                </li>
                                <li class="flex items-center gap-2">
                                    <svg class="h-4 w-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span class="text-gray-400">GPS no requerido — usa coordenadas de la sucursal</span>
                                </li>
                            </ul>
                        </div>

                        {{-- Cuándo usarlo --}}
                        <div class="rounded-lg bg-primary-50 dark:bg-primary-950/30 border border-primary-200 dark:border-primary-800 px-4 py-3 text-sm text-primary-800 dark:text-primary-300">
                            <strong>Cuándo usarlo:</strong> tablet o PC fija en la entrada de la
                            sucursal. Todos los empleados del lugar marcan en el mismo dispositivo.
                            La ubicación se toma automáticamente de las coordenadas configuradas
                            en la sucursal.
                        </div>

                    </div>
                </div>

            </div>
        </x-filament::section>

        {{-- Nota sobre enrolamiento --}}
        <x-filament::section>
            <x-slot name="heading">Antes de marcar: registro facial</x-slot>
            <x-slot name="description">
                Ambos modos requieren que el empleado tenga su rostro registrado en el sistema.
            </x-slot>

            <div class="flex items-start gap-4 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 px-5 py-4">
                <svg class="h-6 w-6 text-amber-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div class="text-sm text-amber-800 dark:text-amber-300 space-y-1">
                    <p>
                        <strong>El empleado debe estar enrolado</strong> para que el sistema lo reconozca.
                        El enrolamiento se realiza una sola vez desde el panel de administración,
                        en el perfil del empleado.
                    </p>
                    <p>
                        Los descriptores faciales tienen una validez configurada en
                        <strong>Configuración General → Expiración de enrolamiento</strong>.
                        Pasado ese tiempo, el empleado deberá reenrolarse.
                    </p>
                </div>
            </div>
        </x-filament::section>

    </div>

</x-filament-panels::page>
