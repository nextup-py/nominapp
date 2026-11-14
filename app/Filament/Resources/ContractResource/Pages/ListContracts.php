<?php

namespace App\Filament\Resources\ContractResource\Pages;

use App\Filament\Pages\ContractReport;
use App\Filament\Resources\ContractResource;
use App\Models\Contract;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Página de listado de contratos con tabs por estado y acceso al reporte.
 */
class ListContracts extends ListRecords
{
    protected static string $resource = ContractResource::class;

    /** @var array<string, int>|null Conteos de contratos por estado, calculados en una sola query. */
    protected ?array $contractCounts = null;

    /**
     * Retorna los conteos de contratos agrupados por estado.
     * Ejecuta una sola query GROUP BY y cachea el resultado.
     *
     * @return array<string, int>
     */
    protected function getContractCounts(): array
    {
        if ($this->contractCounts === null) {
            $counts = Contract::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray();

            $alertDays = app(GeneralSettings::class)->contract_alert_days;

            $this->contractCounts = [
                'all'          => array_sum($counts),
                'expiring'     => Contract::expiringSoon($alertDays)->count(),
                'draft'        => $counts['draft'] ?? 0,
                'active'       => $counts['active'] ?? 0,
                'suspended'    => $counts['suspended'] ?? 0,
                'expired'      => $counts['expired'] ?? 0,
                'terminated'   => $counts['terminated'] ?? 0,
                'renewed'      => $counts['renewed'] ?? 0,
            ];
        }

        return $this->contractCounts;
    }

    /**
     * Define los tabs de filtrado por estado del contrato.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = $this->getContractCounts();

        return [
            'all' => Tab::make('Todos')
                ->badge($counts['all'])
                ->badgeColor('gray'),

            'expiring' => Tab::make('Por vencer')
                ->badge($counts['expiring'] ?: null)
                ->badgeColor('warning')
                ->icon('heroicon-o-clock')
                ->modifyQueryUsing(fn (Builder $query) => $query->expiringSoon(app(GeneralSettings::class)->contract_alert_days)),

            'draft' => Tab::make('Borrador')
                ->badge($counts['draft'])
                ->badgeColor(Contract::getStatusColor('draft'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft')),

            'active' => Tab::make('Vigentes')
                ->badge($counts['active'])
                ->badgeColor(Contract::getStatusColor('active'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'active')),

            'suspended' => Tab::make('Suspendidos')
                ->badge($counts['suspended'])
                ->badgeColor(Contract::getStatusColor('suspended'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'suspended')),

            'expired' => Tab::make('Vencidos')
                ->badge($counts['expired'])
                ->badgeColor(Contract::getStatusColor('expired'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'expired')),

            'terminated' => Tab::make('Terminados')
                ->badge($counts['terminated'])
                ->badgeColor(Contract::getStatusColor('terminated'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'terminated')),

            'renewed' => Tab::make('Renovados')
                ->badge($counts['renewed'])
                ->badgeColor(Contract::getStatusColor('renewed'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'renewed')),
        ];
    }

    /**
     * Define las acciones del encabezado: ver reporte y crear nuevo contrato.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('ver_reporte')
                ->label('Ver Reporte')
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->url(ContractReport::getUrl()),

            CreateAction::make()
                ->label('Nuevo Contrato')
                ->icon('heroicon-o-plus'),
        ];
    }
}
