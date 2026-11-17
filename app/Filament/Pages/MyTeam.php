<?php

namespace App\Filament\Pages;

use App\Services\LiveAttendanceMonitor;
use App\Services\TeamScopeService;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * «Mi equipo»: estado de hoy de las personas a cargo del usuario (quienes le reportan y, con `view_branch_team`,
 * toda su sucursal). Reutiliza la foto del Monitor en vivo acotada a ese alcance. Solo consulta: no aprueba ni
 * resuelve nada (eso sigue en RR.HH.).
 */
class MyTeam extends Page
{
    protected static ?string $navigationLabel = 'Mi equipo';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Mi equipo';

    protected static ?string $slug = 'mi-equipo';

    protected static string $view = 'filament.pages.my-team';

    /** Segundos entre refrescos. */
    public const POLL_SECONDS = 30;

    #[Url(as: 'buscar')]
    public string $search = '';

    /** Estado elegido tocando una tarjeta (vacío = todos). */
    #[Url(as: 'estado')]
    public ?string $state = null;

    /** Quien tiene alguno de los permisos de equipo. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && app(TeamScopeService::class)->canView($user);
    }

    /** Alterna el filtro de estado (tocar la misma tarjeta otra vez lo quita). */
    public function toggleState(string $state): void
    {
        $this->state = $this->state === $state ? null : $state;
    }

    /**
     * Datos de la vista: foto de hoy del equipo y avisos si no hay ficha vinculada o equipo.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $scope = app(TeamScopeService::class);
        $user = auth()->user();
        $linked = $scope->hasLinkedEmployee($user);
        $ids = $scope->employeeIds($user);

        return [
            'linked' => $linked,
            'hasTeam' => $ids !== [],
            'snapshot' => $ids === [] ? null : LiveAttendanceMonitor::snapshot([
                'employee_ids' => $ids,
                'search' => $this->search,
                'state' => $this->state,
            ]),
            'states' => LiveAttendanceMonitor::states(),
            'pollSeconds' => self::POLL_SECONDS,
        ];
    }
}
