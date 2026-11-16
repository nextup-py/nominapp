<?php

namespace App\Filament\Resources\TerminalResource\Pages;

use App\Exports\TerminalReliabilityExport;
use App\Filament\Resources\TerminalResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

/** Lista de terminales de marcación. */
class ListTerminals extends ListRecords
{
    protected static string $resource = TerminalResource::class;

    /**
     * Acciones del encabezado de la página.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_reliability')
                ->label('Exportar confiabilidad')
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->modalHeading('Exportar confiabilidad de los terminales')
                ->modalDescription('Una fila por terminal con su disponibilidad, cortes, marcaciones, conflictos e identificación en el período elegido. Respeta los filtros del listado.')
                ->modalSubmitActionLabel('Exportar a Excel')
                ->form([
                    DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y')->closeOnDateSelection()->default(now()->subDays(29))->required()->maxDate(now()),
                    DatePicker::make('until')->label('Hasta')->native(false)->displayFormat('d/m/Y')->closeOnDateSelection()->default(now())->required()->maxDate(now())->afterOrEqual('from'),
                ])
                ->action(function (array $data) {
                    $ids = $this->getFilteredTableQuery()->reorder()->pluck('terminals.id')->all();

                    Notification::make()->success()->title('Exportación generada')->body('Se descarga el Excel con la confiabilidad de los terminales.')->send();

                    return Excel::download(
                        new TerminalReliabilityExport(Carbon::parse($data['from']), Carbon::parse($data['until']), $ids),
                        'confiabilidad_terminales_'.now()->format('Y_m_d_H_i_s').'.xlsx',
                    );
                }),

            CreateAction::make()
                ->label('Nueva Terminal')
                ->icon('heroicon-o-plus'),
        ];
    }
}
