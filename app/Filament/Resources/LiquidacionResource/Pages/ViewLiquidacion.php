<?php

namespace App\Filament\Resources\LiquidacionResource\Pages;

use App\Filament\Resources\LiquidacionResource;
use App\Models\Liquidacion;
use App\Services\LiquidacionPDFGenerator;
use App\Services\LiquidacionService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewLiquidacion extends ViewRecord
{
    protected static string $resource = LiquidacionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calculate')
                ->label('Calcular Liquidación')
                ->icon('heroicon-o-calculator')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Calcular Liquidación')
                ->modalDescription(
                    fn () => "¿Calcular la liquidación de {$this->record->employee->full_name}? ".
                        'Tipo: '.Liquidacion::getTerminationTypeLabel($this->record->termination_type)
                )
                ->action(function (LiquidacionService $service) {
                    LiquidacionResource::performCalculation($this->record, $service, 'Liquidación calculada');
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isDraft() && auth()->user()->can('calculate_liquidacion')),

            Action::make('recalculate')
                ->label('Recalcular')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Recalcular Liquidación')
                ->modalDescription(
                    fn () => "¿Recalcular la liquidación de {$this->record->employee->full_name}? ".
                        'Se eliminarán todos los conceptos actuales y se recalculará desde cero. '.
                        'Los cambios manuales en los items se perderán.'
                )
                ->action(function (LiquidacionService $service) {
                    LiquidacionResource::performCalculation($this->record, $service, 'Liquidación recalculada');
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isCalculated() && auth()->user()->can('calculate_liquidacion')),

            Action::make('close')
                ->label('Cerrar y Desactivar Empleado')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cerrar Liquidación')
                ->modalDescription(
                    fn () => "¿Cerrar la liquidación de {$this->record->employee->full_name}? ".
                        'El empleado será marcado como INACTIVO y los préstamos pendientes serán cancelados.'
                )
                ->action(function (LiquidacionService $service) {
                    LiquidacionResource::performClose($this->record, $service);
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isCalculated() && auth()->user()->can('close_liquidacion')),

            Action::make('regenerate_pdf')
                ->label('Regenerar PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('warning')
                ->action(function (LiquidacionPDFGenerator $generator) {
                    $pdfPath = $generator->generate($this->record);
                    $this->record->update(['pdf_path' => $pdfPath]);

                    Notification::make()
                        ->success()
                        ->title('PDF regenerado')
                        ->body('El PDF fue actualizado con los datos actuales.')
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->pdf_path === null && ! $this->record->isDraft()),

            Action::make('download_pdf')
                ->label('Descargar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn () => route('liquidaciones.download', $this->record))
                ->openUrlInNewTab()
                ->visible(fn () => $this->record->pdf_path !== null),

            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->visible(fn () => ! $this->record->isClosed()),
        ];
    }
}
