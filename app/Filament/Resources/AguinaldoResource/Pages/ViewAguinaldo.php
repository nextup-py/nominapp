<?php

namespace App\Filament\Resources\AguinaldoResource\Pages;

use App\Filament\Resources\AguinaldoPeriodResource;
use App\Filament\Resources\AguinaldoResource;
use App\Models\Aguinaldo;
use App\Services\AguinaldoService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAguinaldo extends ViewRecord
{
    protected static string $resource = AguinaldoResource::class;

    /**
     * Define las acciones del encabezado para la página de vista detallada de un aguinaldo.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_period')
                ->label('Ver Período')
                ->icon('heroicon-o-arrow-left')
                ->color('primary')
                ->url(fn () => AguinaldoPeriodResource::getUrl('view', ['record' => $this->record->aguinaldo_period_id])),

            Action::make('mark_paid')
                ->label('Marcar Pagado')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Marcar como Pagado')
                ->modalDescription(fn () => "¿Confirmar pago del aguinaldo de {$this->record->employee->full_name} por ".Aguinaldo::formatCurrency($this->record->aguinaldo_amount).'?')
                ->action(function () {
                    $this->record->markAsPaid();

                    Notification::make()
                        ->success()
                        ->title('Aguinaldo marcado como pagado')
                        ->send();

                    $this->refreshFormData(['status', 'paid_at']);
                })
                ->visible(fn () => $this->record->isPending() && $this->record->period->isProcessing() && auth()->user()->can('mark_paid_aguinaldo')),

            Action::make('unmark_paid')
                ->label('Marcar Pendiente')
                ->icon('heroicon-o-x-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('¿Marcar como Pendiente?')
                ->modalDescription(fn () => "Se revertirá el pago del aguinaldo de {$this->record->employee->full_name} por ".Aguinaldo::formatCurrency($this->record->aguinaldo_amount).' y volverá a estado Pendiente.')
                ->modalSubmitActionLabel('Sí, marcar como pendiente')
                ->action(function () {
                    $this->record->markAsPending();

                    Notification::make()
                        ->warning()
                        ->title('Pago revertido')
                        ->body("El aguinaldo de {$this->record->employee->full_name} volvió a estado Pendiente.")
                        ->send();

                    $this->refreshFormData(['status', 'paid_at']);
                })
                ->visible(fn () => $this->record->isPaid() && $this->record->period->isProcessing() && auth()->user()->can('mark_paid_aguinaldo')),

            Action::make('regenerate')
                ->label('Regenerar')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Regenerar Aguinaldo')
                ->modalDescription('Se recalcularán todos los montos basándose en las nóminas actuales del año. El PDF también se regenerará.')
                ->action(function (AguinaldoService $aguinaldoService) {
                    try {
                        $aguinaldoService->regenerateForEmployee($this->record);

                        Notification::make()
                            ->success()
                            ->title('Aguinaldo regenerado')
                            ->body('El aguinaldo ha sido recalculado exitosamente.')
                            ->send();

                        $this->refreshFormData(['total_earned', 'months_worked', 'aguinaldo_amount', 'generated_at', 'status', 'paid_at']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Error al regenerar')
                            ->body($e->getMessage())
                            ->send();
                    }
                })
                ->visible(fn () => $this->record->period->isProcessing()),

            Action::make('download_pdf')
                ->label('Descargar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn () => route('aguinaldos.download', $this->record))
                ->openUrlInNewTab()
                ->visible(fn () => (bool) $this->record->pdf_path),
        ];
    }
}
