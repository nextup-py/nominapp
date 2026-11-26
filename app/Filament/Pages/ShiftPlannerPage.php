<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\ScheduleResource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Position;
use App\Models\RotationPattern;
use Filament\Pages\Page;

/**
 * Planificador visual de turnos rotativos.
 *
 * Renderiza una aplicación Vue 3 que muestra un grid interactivo
 * de empleados × días, con soporte de drag & drop y overrides puntuales.
 */
class ShiftPlannerPage extends Page
{
    use UsesNavigationLayout;

    /** Acceso restringido por permiso (las páginas de Filament no consultan la policy por sí solas). */
    public static function canAccess(): bool
    {
        return ScheduleResource::canViewAny();
    }

    protected static ?string $navigationGroup = 'Horarios y turnos';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Planificador de Turnos';

    protected static ?string $title = 'Planificador de Turnos';

    protected static ?string $slug = 'planificador';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.shift-planner';

    /**
     * Datos inyectados en la vista para inicializar el componente Vue.
     *
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $companies = Company::active()->orderBy('name')->get(['id', 'name']);

        $branches = Branch::orderBy('name')
            ->get(['id', 'name', 'company_id']);

        return [
            'initData' => [
                'companies' => $companies,
                'branches' => $branches,
                'patterns' => RotationPattern::active()->orderBy('name')->get(['id', 'name', 'company_id']),
                'positions' => Position::active()->orderBy('name')->get(['id', 'name']),
                'csrf' => csrf_token(),
                'canEdit' => (bool) auth()->user()?->can('update_schedule'),
                'routes' => [
                    'data' => route('shift-planner.data'),
                    'shifts' => route('shift-planner.shifts'),
                    'overrideStore' => route('shift-planner.override.store'),
                    'overrideDestroy' => route('shift-planner.override.destroy'),
                    'export' => route('shift-planner.export'),
                    'copyWeek' => route('shift-planner.copy-week'),
                ],
            ],
        ];
    }
}
