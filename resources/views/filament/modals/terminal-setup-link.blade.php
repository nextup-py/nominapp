{{-- Modal con el enlace/QR de configuración de un solo uso para provisionar el terminal offline --}}
<div class="space-y-4">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Abra este enlace <strong>una sola vez, con el dispositivo conectado a internet</strong>, durante la instalación
        física del terminal. No puede reutilizarse.
    </p>

    <p class="text-sm text-amber-700 dark:text-amber-400">
        Este enlace se muestra <strong>solo ahora</strong>: si cierra esta ventana no se puede volver a ver (por
        seguridad, el sistema no lo guarda). Genere uno nuevo si lo necesita de nuevo.
    </p>

    <p class="text-sm text-gray-600 dark:text-gray-300">
        Vence: <strong>{{ $expiresAt->translatedFormat('l d/m/Y H:i') }}</strong> ({{ $expiresAt->diffForHumans() }})
    </p>

    <div class="flex justify-center">
        <div style="display:inline-block;background:#fff;padding:12px;border-radius:8px;border:1px solid #e5e7eb">
            {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(200)->generate($url) !!}
        </div>
    </div>

    <div x-data="{ copied: false }" class="flex items-center gap-2 rounded-lg bg-gray-50 dark:bg-gray-800 p-3">
        <span class="flex-1 text-xs break-all font-mono text-gray-700 dark:text-gray-300">{{ $url }}</span>
        <button
            type="button"
            x-on:click="navigator.clipboard.writeText(@js($url)); copied = true; setTimeout(() => copied = false, 2000)"
            class="shrink-0 rounded-md bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 px-2 py-1 text-xs font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-600"
        >
            <span x-show="!copied">Copiar</span>
            <span x-show="copied" x-cloak>¡Copiado!</span>
        </button>
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Si el terminal ya tiene un dispositivo vinculado, este enlace lo reemplaza al vincularse — el acceso del
        dispositivo anterior queda inválido de inmediato.
    </p>
</div>
