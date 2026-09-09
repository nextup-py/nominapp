<?php

namespace App\Http\Controllers;

use App\Models\Terminal;
use App\Settings\GeneralSettings;
use App\Support\ThemeResolver;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\JsonResponse;

class AttendanceFaceMarkController extends Controller
{
    /** Muestra la página de marcación facial */
    public function show(): ViewContract
    {
        return view('attendances.mark', ['title' => 'Marcación Facial']);
    }

    /** Muestra la página de marcación en modo terminal/kiosco (legacy — URL sin código) */
    public function terminal(): ViewContract
    {
        return view('attendances.terminal', ['title' => 'Terminal de Marcación']);
    }

    /**
     * Muestra la terminal de marcación identificada por su código único.
     * Actualiza last_seen_at en cada carga. Si está inactiva, muestra pantalla de fuera de servicio.
     *
     * @param  string  $code  Código único de 8 caracteres de la terminal
     */
    public function terminalByCode(string $code): ViewContract
    {
        $terminal = Terminal::with('branch.company')->where('code', $code)->first();

        if (! $terminal) {
            abort(404);
        }

        if ($terminal->isInactive()) {
            return view('attendances.terminal-inactive', [
                'title' => 'Terminal fuera de servicio',
                'terminal' => $terminal,
            ]);
        }

        $terminal->update(['last_seen_at' => now()]);

        // Sucursal — empresa en el título de pestaña: ayuda a diferenciar terminales de
        // distintas sucursales cuando un admin monitorea varios a la vez. Sin
        // sucursal/empresa cargada (dato incompleto), cae al nombre del propio terminal.
        $title = collect([$terminal->branch?->name, $terminal->branch?->company?->name])
            ->filter()
            ->implode(' — ');

        return view('attendances.terminal', [
            'title' => $title !== '' ? $title : "Terminal — {$terminal->name}",
            'terminal' => $terminal,
        ]);
    }

    /** Manifest de PWA para el modo Marcación (dispositivo personal del empleado). */
    public function markManifest(): JsonResponse
    {
        return $this->buildManifest(
            name: 'Nominapp Marcación',
            shortName: 'Marcación',
            startUrl: '/marcar',
            scope: '/marcar',
        );
    }

    /**
     * Manifest de PWA para el modo Terminal, específico de la sucursal.
     *
     * @param  string  $code  Código único de 8 caracteres de la terminal
     */
    public function terminalManifest(string $code): JsonResponse
    {
        $terminal = Terminal::where('code', $code)->first();

        if (! $terminal) {
            abort(404);
        }

        return $this->buildManifest(
            name: 'Nominapp Terminal',
            shortName: 'Terminal',
            startUrl: "/terminal/{$terminal->code}",
            scope: '/terminal/',
        );
    }

    /**
     * Construye el JSON del manifest, resolviendo el color primario configurado
     * en Settings una sola vez por request (cae al default de ThemeResolver si
     * el settings store no está disponible — mismo criterio defensivo que
     * <x-theme-vars />: una lectura fallida no debe romper una ruta pública activa).
     * Mismo set de íconos para ambos modos — sin variantes por modo.
     */
    private function buildManifest(string $name, string $shortName, string $startUrl, string $scope): JsonResponse
    {
        try {
            $colorKey = app(GeneralSettings::class)->primary_color;
        } catch (\Throwable) {
            $colorKey = ThemeResolver::DEFAULT_COLOR;
        }

        $theme = ThemeResolver::primaryColorCss($colorKey);

        return response()->json([
            'name' => $name,
            'short_name' => $shortName,
            'start_url' => $startUrl,
            'scope' => $scope,
            'display' => 'standalone',
            'theme_color' => $theme['hex'][600],
            'background_color' => $theme['hex'][50],
            'icons' => [
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => asset('icons/icon-192-maskable.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => asset('icons/icon-512-maskable.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
