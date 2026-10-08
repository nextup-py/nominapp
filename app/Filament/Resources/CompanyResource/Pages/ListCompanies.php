<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Exports\CompaniesExport;
use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    /** @var array<string, int>|null Conteos de las pestañas, calculados una sola vez por ciclo. */
    protected ?array $companyCounts = null;

    /**
     * Cuenta empresas por pestaña en una sola query.
     *
     * @return array{all: int, active: int, inactive: int, pending: int}
     */
    protected function getCompanyCounts(): array
    {
        if ($this->companyCounts === null) {
            $pending = implode(' + ', Company::pendingDataExpressions());

            $row = Company::query()
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw('COALESCE(SUM(companies.is_active = 1), 0) AS active')
                ->selectRaw('COALESCE(SUM(companies.is_active = 0), 0) AS inactive')
                ->selectRaw("COALESCE(SUM(({$pending}) > 0), 0) AS pending")
                ->toBase()
                ->first();

            $this->companyCounts = [
                'all' => (int) $row->total,
                'active' => (int) $row->active,
                'inactive' => (int) $row->inactive,
                'pending' => (int) $row->pending,
            ];
        }

        return $this->companyCounts;
    }

    /**
     * Pestañas del listado: todas, activas, inactivas y con datos pendientes.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = $this->getCompanyCounts();

        return [
            'all' => Tab::make('Todas')
                ->icon('heroicon-o-building-office-2')
                ->badge($counts['all']),
            'active' => Tab::make('Activas')
                ->icon('heroicon-o-check-circle')
                ->badge($counts['active'])
                ->modifyQueryUsing(fn (Builder $query) => $query->where('companies.is_active', true)),
            'inactive' => Tab::make('Inactivas')
                ->icon('heroicon-o-no-symbol')
                ->badge($counts['inactive'])
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('companies.is_active', false)),
            'pending' => Tab::make('Con datos pendientes')
                ->icon('heroicon-o-exclamation-triangle')
                ->badge($counts['pending'])
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->havingPendingData()),
        ];
    }

    /**
     * Define las acciones que se mostrarán en el encabezado de la página de listado de empresas.
     * La exportación respeta pestaña, filtros y búsqueda activos, y permite elegir columnas.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->label('Exportar')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->modalHeading('Exportar Empresas a Excel')
                ->modalDescription(fn () => 'Se exportarán las '.$this->getFilteredTableQuery()->count().' empresas del listado actual (según pestaña, filtros y búsqueda).')
                ->modalSubmitActionLabel('Sí, exportar')
                ->form([
                    CheckboxList::make('columns')
                        ->label('Columnas')
                        ->options(CompaniesExport::availableColumns())
                        ->default(CompaniesExport::defaultColumns())
                        ->columns(3)
                        ->bulkToggleable()
                        ->required(),
                ])
                ->action(function (array $data) {
                    $ids = $this->getFilteredTableQuery()->reorder()->select('companies.id')->pluck('id')->all();

                    Notification::make()
                        ->success()
                        ->title('Exportación lista')
                        ->body('El listado de empresas se está descargando.')
                        ->send();

                    return Excel::download(
                        new CompaniesExport($data['columns'], $ids),
                        'empresas_'.now()->format('Y_m_d_H_i_s').'.xlsx'
                    );
                }),

            CreateAction::make()
                ->label('Nueva Empresa')
                ->icon('heroicon-o-plus'),
        ];
    }
}
