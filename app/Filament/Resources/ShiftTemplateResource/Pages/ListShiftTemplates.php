<?php

namespace App\Filament\Resources\ShiftTemplateResource\Pages;

use App\Exports\ShiftTemplatesExport;
use App\Filament\Resources\ShiftTemplateResource;
use App\Models\ShiftTemplate;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/** Página de listado de turnos con pestañas por estado. */
class ListShiftTemplates extends ListRecords
{
    protected static string $resource = ShiftTemplateResource::class;

    /** @var array{all: int, active: int, inactive: int}|null Conteos de las pestañas, calculados una sola vez por ciclo. */
    protected ?array $shiftCounts = null;

    /** @var array<int, array{patterns: int, overrides: int}>|null Uso de cada turno, calculado una vez por ciclo. */
    protected ?array $usageMap = null;

    /**
     * Uso de cada turno en patrones y turnos puntuales (lo lee la columna «Usado en»).
     *
     * @return array<int, array{patterns: int, overrides: int}>
     */
    public function usageMap(): array
    {
        return $this->usageMap ??= ShiftTemplate::usageMap();
    }

    /**
     * Cuenta turnos por pestaña en una sola query.
     *
     * @return array{all: int, active: int, inactive: int}
     */
    protected function getShiftCounts(): array
    {
        if ($this->shiftCounts === null) {
            $row = ShiftTemplate::query()
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('COALESCE(SUM(shift_templates.is_active = 1), 0) AS active')
                ->selectRaw('COALESCE(SUM(shift_templates.is_active = 0), 0) AS inactive')
                ->toBase()
                ->first();

            $this->shiftCounts = [
                'all' => (int) $row->total,
                'active' => (int) $row->active,
                'inactive' => (int) $row->inactive,
            ];
        }

        return $this->shiftCounts;
    }

    /**
     * Pestañas por estado; abre en Activos.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = $this->getShiftCounts();

        return [
            'active' => Tab::make('Activos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('shift_templates.is_active', true))
                ->badge($counts['active'])
                ->badgeColor('success')
                ->icon('heroicon-o-check-circle'),

            'inactive' => Tab::make('Inactivos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('shift_templates.is_active', false))
                ->badge($counts['inactive'])
                ->badgeColor('danger')
                ->icon('heroicon-o-no-symbol'),

            'all' => Tab::make('Todos')
                ->badge($counts['all'])
                ->badgeColor('gray')
                ->icon('heroicon-o-table-cells'),
        ];
    }

    /** Pestaña inicial: los turnos activos. */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->authorize(fn (): bool => (bool) auth()->user()?->can('view_any_shift_template'))
                ->label('Exportar')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('¿Exportar Turnos a Excel?')
                ->modalDescription(fn () => 'Se exportarán los '.$this->getFilteredTableQuery()->count().' turnos del listado actual (según pestaña, filtros y búsqueda).')
                ->modalSubmitActionLabel('Sí, exportar')
                ->action(function () {
                    $ids = $this->getFilteredTableQuery()->reorder()->select('shift_templates.id')->pluck('id')->all();

                    Notification::make()->success()->title('Exportación lista')->body('El listado de turnos se está descargando.')->send();

                    return Excel::download(new ShiftTemplatesExport($ids), 'turnos_'.now()->format('Y_m_d_H_i_s').'.xlsx');
                }),

            CreateAction::make()->label('Nuevo Turno')->icon('heroicon-o-plus'),
        ];
    }
}
