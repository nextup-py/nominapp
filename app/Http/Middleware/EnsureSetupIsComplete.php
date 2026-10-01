<?php

namespace App\Http\Middleware;

use App\Filament\Pages\SetupWizardPage;
use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza al admin de una instalación nueva a completar el asistente de
 * configuración inicial (SetupWizardPage) antes de usar el resto del panel.
 * Solo bloquea mientras general.setup_completed sea false — las
 * instalaciones existentes migran ese flag en true de forma retroactiva
 * (ver InstallationDetector) y nunca ven este redirect.
 */
class EnsureSetupIsComplete
{
    /**
     * Rutas siempre accesibles aunque el setup no esté completo, para no
     * generar un loop de redirección: la propia página del wizard (y su
     * ciclo de vida Livewire), y logout.
     */
    private const ALLOWED_PATH_PATTERNS = [
        'setup-inicial*',
        'logout',
        'livewire/update',
        'livewire/upload-file',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        try {
            $setupCompleted = app(GeneralSettings::class)->setup_completed;
        } catch (\Throwable $e) {
            // Fail-open: si el settings store todavía no tiene el campo
            // (ventana de deploy antes de correr la migración), nunca se
            // debe tumbar el panel en producción.
            report($e);

            return $next($request);
        }

        if ($setupCompleted || $request->is(self::ALLOWED_PATH_PATTERNS)) {
            return $next($request);
        }

        return redirect()->to(SetupWizardPage::getUrl());
    }
}
