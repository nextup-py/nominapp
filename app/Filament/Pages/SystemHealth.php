<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\UsesNavigationLayout;
use App\Services\LogErrorAnalyzer;
use App\Services\SystemHealthService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * «Salud del sistema» (solo Super Admin): estado del programador, la cola, el correo, el disco y los logs, más los
 * errores más repetidos de la última semana agrupados y explicados en lenguaje simple. Solo consulta.
 */
class SystemHealth extends Page
{
    use UsesNavigationLayout;

    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Salud del sistema';

    protected static ?string $title = 'Salud del sistema';

    protected static ?string $slug = 'salud-del-sistema';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.system-health';

    /** Acceso con el permiso `view_system_health` (el Super Admin lo tiene siempre; las páginas no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_system_health') ?? false;
    }

    /** Insignia roja si algún chequeo está en estado crítico. */
    public static function getNavigationBadge(): ?string
    {
        return app(SystemHealthService::class)->overall() === 'danger' ? '!' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * Resultado de cada chequeo.
     *
     * @return array<string, array{status: string, title: string, detail: string}>
     */
    public function checks(): array
    {
        return app(SystemHealthService::class)->checks();
    }

    /**
     * Errores de la última semana agrupados (los 15 más repetidos).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function errors(): Collection
    {
        return app(LogErrorAnalyzer::class)->groups(days: 7)->take(15);
    }

    /** Enlace al visor de logs completo. */
    public function logsUrl(): string
    {
        return LogViewer::getUrl();
    }
}
