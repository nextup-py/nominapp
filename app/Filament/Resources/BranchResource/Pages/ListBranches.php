<?php

namespace App\Filament\Resources\BranchResource\Pages;

use App\Exports\BranchesExport;
use App\Filament\Resources\BranchResource;
use App\Models\Branch;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/** Página de listado de sucursales con pestañas y exportación a Excel. */
class ListBranches extends ListRecords
{
    protected static string $resource = BranchResource::class;

    /** @var array<string, int>|null Caché de conteos por pestaña para evitar N+1. */
    protected ?array $branchCounts = null;

    /**
     * Calcula y cachea los conteos de sucursales por pestaña.
     *
     * @return array<string, int>
     */
    protected function getBranchCounts(): array
    {
        if ($this->branchCounts === null) {
            $this->branchCounts = [
                'all' => Branch::count(),
                'with_employees' => Branch::has('employees')->count(),
                'without_employees' => Branch::doesntHave('employees')->count(),
            ];
        }

        return $this->branchCounts;
    }

    /**
     * Retorna las acciones del encabezado: exportar a Excel y crear sucursal.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->label('Exportar')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('¿Exportar Sucursales a Excel?')
                ->modalDescription(fn () => 'Se exportarán las '.$this->getFilteredTableQuery()->count().' sucursales del listado actual (según pestaña, filtros y búsqueda).')
                ->modalSubmitActionLabel('Sí, exportar')
                ->action(function () {
                    $ids = $this->getFilteredTableQuery()->reorder()->select('branches.id')->pluck('id')->all();

                    Notification::make()
                        ->success()
                        ->title('Exportación lista')
                        ->body('El listado de sucursales se está descargando.')
                        ->send();

                    return Excel::download(
                        new BranchesExport(null, $ids),
                        'sucursales_'.now()->format('Y_m_d_H_i_s').'.xlsx'
                    );
                }),

            CreateAction::make()
                ->label('Nueva Sucursal')
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * Retorna las pestañas de filtrado: todas, con empleados y sin empleados.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = $this->getBranchCounts();

        return [
            'all' => Tab::make('Todas')
                ->badge($counts['all'])
                ->badgeColor('gray')
                ->icon('heroicon-o-building-office-2'),

            'with_employees' => Tab::make('Con empleados')
                ->modifyQueryUsing(fn (Builder $query) => $query->has('employees'))
                ->badge($counts['with_employees'])
                ->badgeColor('success')
                ->icon('heroicon-o-users'),

            'without_employees' => Tab::make('Sin empleados')
                ->modifyQueryUsing(fn (Builder $query) => $query->doesntHave('employees'))
                ->badge($counts['without_employees'])
                ->badgeColor('warning')
                ->icon('heroicon-o-exclamation-triangle'),
        ];
    }

    /**
     * Pestaña activa por defecto al entrar al listado.
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
