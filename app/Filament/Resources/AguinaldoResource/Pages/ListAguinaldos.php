<?php

namespace App\Filament\Resources\AguinaldoResource\Pages;

use App\Exports\AguinaldosExport;
use App\Filament\Resources\AguinaldoResource;
use App\Models\Aguinaldo;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListAguinaldos extends ListRecords
{
    protected static string $resource = AguinaldoResource::class;

    /**
     * Define las acciones del encabezado para la página de listado de aguinaldos.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->label('Exportar')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => auth()->user()->can('export_aguinaldo'))
                ->requiresConfirmation()
                ->modalHeading('¿Exportar aguinaldos a Excel?')
                ->modalDescription('Se exportarán todos los aguinaldos según el filtro de estado activo.')
                ->modalSubmitActionLabel('Sí, exportar')
                ->action(function () {
                    Notification::make()
                        ->success()
                        ->title('Exportación lista')
                        ->body('La planilla de aguinaldos se está descargando.')
                        ->send();

                    $status = match ($this->activeTab) {
                        'pending' => 'pending',
                        'paid' => 'paid',
                        default => null,
                    };

                    return Excel::download(
                        new AguinaldosExport(status: $status),
                        'aguinaldos_'.now()->format('Y_m_d_H_i_s').'.xlsx'
                    );
                }),
        ];
    }

    /**
     * Define las pestañas para filtrar los aguinaldos por estado, mostrando el conteo de cada estado.
     */
    public function getTabs(): array
    {
        $counts = Aguinaldo::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $total = array_sum($counts);

        return [
            'all' => Tab::make('Todos')
                ->badge($total ?: null),

            'pending' => Tab::make('Pendientes')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'pending'))
                ->badge($counts['pending'] ?? null)
                ->badgeColor('warning'),

            'paid' => Tab::make('Pagados')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'paid'))
                ->badge($counts['paid'] ?? null)
                ->badgeColor('success'),
        ];
    }

    /**
     * Define la pestaña activa por defecto al cargar la página de listado de aguinaldos.
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }
}
