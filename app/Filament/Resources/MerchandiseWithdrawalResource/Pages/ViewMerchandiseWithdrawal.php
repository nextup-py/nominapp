<?php

namespace App\Filament\Resources\MerchandiseWithdrawalResource\Pages;

use App\Filament\Resources\MerchandiseWithdrawalResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/** Página de detalle de un retiro de mercadería. */
class ViewMerchandiseWithdrawal extends ViewRecord
{
    protected static string $resource = MerchandiseWithdrawalResource::class;

    /**
     * Acciones del encabezado según el estado del retiro.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Aprobar')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn () => $this->record->isPending() && auth()->user()->can('approve_merchandise_withdrawal'))
                ->requiresConfirmation()
                ->modalHeading('Aprobar Retiro')
                ->modalDescription(function () {
                    $days = $this->record->first_installment_days;
                    $firstDue = now()->addDays($days)->format('d/m/Y');
                    $count = $this->record->installments_count;

                    return "Se generarán {$count} cuota(s). La primera vencerá el {$firstDue} ({$days} días desde hoy).";
                })
                ->modalSubmitActionLabel('Sí, aprobar')
                ->action(function () {
                    $result = $this->record->approve(Auth::id());

                    if ($result['success']) {
                        Notification::make()
                            ->success()
                            ->title('Retiro Aprobado')
                            ->body($result['message'])
                            ->send();

                        $this->redirect($this->getUrl(['record' => $this->record]));
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Error')
                            ->body($result['message'])
                            ->send();
                    }
                }),

            Action::make('reject')
                ->label('Rechazar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->isPending() && auth()->user()->can('reject_merchandise_withdrawal'))
                ->requiresConfirmation()
                ->modalHeading('Rechazar Retiro')
                ->modalDescription('¿Está seguro de que desea rechazar esta solicitud? El retiro quedará en estado Rechazado.')
                ->modalSubmitActionLabel('Sí, rechazar')
                ->form([
                    Textarea::make('reason')
                        ->label('Motivo del rechazo')
                        ->placeholder('Ingrese el motivo...')
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    $result = $this->record->reject(Auth::id(), $data['reason'] ?? null);

                    if ($result['success']) {
                        Notification::make()
                            ->success()
                            ->title('Retiro Rechazado')
                            ->body($result['message'])
                            ->send();

                        $this->redirect($this->getUrl(['record' => $this->record]));
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Error')
                            ->body($result['message'])
                            ->send();
                    }
                }),

            Action::make('cancel')
                ->label('Cancelar')
                ->icon('heroicon-o-minus-circle')
                ->color('warning')
                ->visible(fn () => $this->record->isApproved() && $this->record->paid_installments_count === 0 && auth()->user()->can('cancel_merchandise_withdrawal'))
                ->requiresConfirmation()
                ->modalHeading('Cancelar Retiro')
                ->modalDescription(function () {
                    $pending = $this->record->pending_installments_count;
                    $paid = $this->record->paid_installments_count;
                    $base = "Se cancelarán {$pending} cuota(s) pendiente(s).";
                    $warning = $paid > 0
                        ? " ⚠ No se puede cancelar: {$paid} cuota(s) ya fueron descontadas en nómina."
                        : '';

                    return $base.$warning;
                })
                ->modalSubmitActionLabel('Sí, cancelar')
                ->form([
                    Textarea::make('reason')
                        ->label('Motivo de cancelación')
                        ->placeholder('Ingrese el motivo...')
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    $result = $this->record->cancel($data['reason'] ?? null);

                    if ($result['success']) {
                        Notification::make()
                            ->success()
                            ->title('Retiro Cancelado')
                            ->body($result['message'])
                            ->send();

                        $this->redirect($this->getUrl(['record' => $this->record]));
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Error')
                            ->body($result['message'])
                            ->send();
                    }
                }),

            Action::make('export_pdf')
                ->label('Descargar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => $this->record->isApproved() || $this->record->isPaid())
                ->url(fn () => route('merchandise-withdrawals.pdf', $this->record))
                ->openUrlInNewTab(),

            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->visible(fn () => $this->record->isPending()),
        ];
    }
}
