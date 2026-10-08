<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Actions\CompanyStatusAction;
use App\Filament\Resources\CompanyResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    /**
     * Define las acciones que estarán disponibles en la vista de detalles de la empresa.
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),

            Action::make('orgChart')
                ->label('Organigrama')
                ->icon('heroicon-o-rectangle-group')
                ->color('info')
                ->url(fn () => route('org-chart.show', $this->record))
                ->openUrlInNewTab(),

            Action::make('orgChartPdf')
                ->label('Organigrama en PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn () => route('org-chart.pdf', $this->record))
                ->openUrlInNewTab(),

            CompanyStatusAction::make(Action::class)->record($this->record),
        ];
    }
}
