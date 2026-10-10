<?php

namespace App\Filament\Pages;

use App\Services\AttendanceInboxService;
use Filament\Pages\Page;

/**
 * Bandeja "Por resolver" de asistencia: una vista única con lo pendiente (jornadas sin salida,
 * tardanzas y extras por aprobar, ausencias y fallos de marcación) y un acceso directo a cada lista
 * ya filtrada, donde están las acciones de resolución. No es un Resource: reúne varios módulos y
 * cada sección respeta el permiso de su propio recurso.
 */
class AttendanceInbox extends Page
{
    protected static ?string $navigationLabel = 'Por resolver';

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Por resolver';

    protected static ?string $slug = 'por-resolver';

    protected static string $view = 'filament.pages.attendance-inbox';

    /** Visible si el usuario puede ver al menos una de las secciones. */
    public static function canAccess(): bool
    {
        return AttendanceInboxService::sections() !== [];
    }

    /** Total de pendientes entre las secciones visibles. */
    public static function getNavigationBadge(): ?string
    {
        $total = AttendanceInboxService::totalPending();

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pendientes de asistencia';
    }

    /**
     * Secciones con sus contadores para la vista.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['sections' => AttendanceInboxService::sections()];
    }
}
