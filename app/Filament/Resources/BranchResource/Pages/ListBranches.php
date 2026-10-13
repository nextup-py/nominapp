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

/** Página de listado de sucursales con pestañas por estado y exportación a Excel. */
class ListBranches extends ListRecords
{
    protected static string $resource = BranchResource::class;

    /** @var array<string, int>|null Conteos de las pestañas, calculados una sola vez por ciclo. */
    protected ?array $branchCounts = null;

    /**
     * Cuenta sucursales por pestaña en una sola query.
     *
     * @return array{all: int, active: int, inactive: int, pending: int}
     */
    protected function getBranchCounts(): array
    {
        if ($this->branchCounts === null) {
            $pending = implode(' + ', Branch::pendingDataExpressions());

            $row = Branch::query()
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('COALESCE(SUM(branches.is_active = 1), 0) AS active')
                ->selectRaw('COALESCE(SUM(branches.is_active = 0), 0) AS inactive')
                ->selectRaw("COALESCE(SUM(({$pending}) > 0), 0) AS pending")
                ->toBase()
                ->first();

            $this->branchCounts = [
                'all' => (int) $row->total,
                'active' => (int) $row->active,
                'inactive' => (int) $row->inactive,
                'pending' => (int) $row->pending,
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
     * Retorna las pestañas del listado: todas, activas, inactivas y con datos pendientes.
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
                ->icon('heroicon-o-building-storefront'),

            'active' => Tab::make('Activas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('branches.is_active', true))
                ->badge($counts['active'])
                ->badgeColor('success')
                ->icon('heroicon-o-check-circle'),

            'inactive' => Tab::make('Inactivas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('branches.is_active', false))
                ->badge($counts['inactive'])
                ->badgeColor('danger')
                ->icon('heroicon-o-no-symbol'),

            'pending' => Tab::make('Con datos pendientes')
                ->modifyQueryUsing(fn (Builder $query) => $query->havingPendingData())
                ->badge($counts['pending'])
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
