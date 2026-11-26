<?php

namespace App\Filament\Resources\ScheduleResource\Pages;

use App\Exports\SchedulesExport;
use App\Filament\Resources\ScheduleResource;
use App\Models\Schedule;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/** Página de listado de horarios con pestañas por estado y exportación a Excel. */
class ListSchedules extends ListRecords
{
    protected static string $resource = ScheduleResource::class;

    /** @var array{all: int, active: int, inactive: int}|null Conteos de las pestañas, calculados una sola vez por ciclo. */
    protected ?array $scheduleCounts = null;

    /**
     * Cuenta horarios por pestaña en una sola query.
     *
     * @return array{all: int, active: int, inactive: int}
     */
    protected function getScheduleCounts(): array
    {
        if ($this->scheduleCounts === null) {
            $row = Schedule::query()
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('COALESCE(SUM(schedules.is_active = 1), 0) AS active')
                ->selectRaw('COALESCE(SUM(schedules.is_active = 0), 0) AS inactive')
                ->toBase()
                ->first();

            $this->scheduleCounts = [
                'all' => (int) $row->total,
                'active' => (int) $row->active,
                'inactive' => (int) $row->inactive,
            ];
        }

        return $this->scheduleCounts;
    }

    /**
     * Pestañas por estado; abre en Activos.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = $this->getScheduleCounts();

        return [
            'active' => Tab::make('Activos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('schedules.is_active', true))
                ->badge($counts['active'])
                ->badgeColor('success')
                ->icon('heroicon-o-check-circle'),

            'inactive' => Tab::make('Inactivos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('schedules.is_active', false))
                ->badge($counts['inactive'])
                ->badgeColor('danger')
                ->icon('heroicon-o-no-symbol'),

            'all' => Tab::make('Todos')
                ->badge($counts['all'])
                ->badgeColor('gray')
                ->icon('heroicon-o-clock'),
        ];
    }

    /** Pestaña inicial: los horarios activos. */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    /**
     * Define las acciones del encabezado: exportar a Excel y crear nuevo horario.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->authorize(fn (): bool => (bool) auth()->user()?->can('view_any_schedule'))
                ->label('Exportar')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('¿Exportar Horarios a Excel?')
                ->modalDescription(fn () => 'Se exportarán los '.$this->getFilteredTableQuery()->count().' horarios del listado actual (según pestaña, filtros y búsqueda).')
                ->modalSubmitActionLabel('Sí, exportar')
                ->action(function () {
                    $ids = $this->getFilteredTableQuery()->reorder()->select('schedules.id')->pluck('id')->all();

                    Notification::make()
                        ->success()
                        ->title('Exportación lista')
                        ->body('El listado de horarios se está descargando.')
                        ->send();

                    return Excel::download(
                        new SchedulesExport($ids),
                        'horarios_'.now()->format('Y_m_d_H_i_s').'.xlsx'
                    );
                }),

            CreateAction::make()
                ->label('Nuevo Horario')
                ->icon('heroicon-o-plus'),
        ];
    }
}
