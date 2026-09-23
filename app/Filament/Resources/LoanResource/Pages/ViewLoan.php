<?php

namespace App\Filament\Resources\LoanResource\Pages;

use App\Filament\Resources\LoanResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewLoan extends ViewRecord
{
    protected static string $resource = LoanResource::class;

    /**
     * Define las acciones del encabezado de la página de detalle del préstamo.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('activate')
                ->label('Aprobar')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn () => $this->record->isPending() && auth()->user()->can('approve_loan'))
                ->requiresConfirmation()
                ->modalHeading('Aprobar Préstamo')
                ->modalDescription(function () {
                    $days = $this->record->first_installment_days;
                    $firstDue = now()->addDays($days)->format('d/m/Y');

                    return "Se generarán {$this->record->installments_count} cuotas. La primera vencerá el {$firstDue} ({$days} días desde hoy).";
                })
                ->modalSubmitActionLabel('Sí, aprobar')
                ->action(function () {
                    $result = $this->record->activate(Auth::id());

                    if ($result['success']) {
                        Notification::make()
                            ->success()
                            ->title('Préstamo Aprobado')
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
                ->color('warning')
                ->visible(fn () => $this->record->isPending() && auth()->user()->can('reject_loan'))
                ->requiresConfirmation()
                ->modalHeading('Rechazar Préstamo')
                ->modalDescription(fn () => 'Se rechazará la solicitud de préstamo de '.number_format((float) $this->record->amount, 0, ',', '.').' Gs. para '.$this->record->employee->full_name.'. El préstamo quedará en estado Rechazado.')
                ->modalSubmitActionLabel('Sí, rechazar')
                ->form([
                    Textarea::make('reason')
                        ->label('Motivo del rechazo')
                        ->placeholder('Ingrese el motivo...')
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    $result = $this->record->reject($data['reason'] ?? null);

                    if ($result['success']) {
                        Notification::make()
                            ->warning()
                            ->title('Préstamo Rechazado')
                            ->body($result['message'])
                            ->send();

                        $this->refreshFormData(['status', 'notes']);
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Error')
                            ->body($result['message'])
                            ->send();
                    }
                }),

            Action::make('mark_disbursed')
                ->label('Marcar como Entregado')
                ->icon('heroicon-o-banknotes')
                ->color('primary')
                ->visible(fn () => $this->record->isApproved() && auth()->user()->can('disburse_loan'))
                ->requiresConfirmation()
                ->modalHeading('Marcar Préstamo como Entregado')
                ->modalDescription(fn () => 'Se registrará que el dinero del préstamo de '.number_format((float) $this->record->amount, 0, ',', '.').' Gs. fue entregado a '.$this->record->employee->full_name.'. Las cuotas comenzarán a descontarse en la próxima nómina.')
                ->modalSubmitActionLabel('Sí, marcar como entregado')
                ->action(function () {
                    $result = $this->record->disburse(Auth::id());

                    if ($result['success']) {
                        Notification::make()
                            ->success()
                            ->title('Préstamo Marcado como Entregado')
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
                ->color('danger')
                ->visible(fn () => ($this->record->isPending() || $this->record->isApproved()) && auth()->user()->can('cancel_loan'))
                ->requiresConfirmation()
                ->modalHeading('Cancelar Préstamo')
                ->modalDescription(fn () => $this->record->isApproved()
                    ? '¿Está seguro de que desea cancelar el préstamo de '.number_format((float) $this->record->amount, 0, ',', '.').' Gs.? Se cancelarán '.$this->record->pending_installments_count.' cuota(s) pendiente(s).'
                    : '¿Está seguro de que desea cancelar el préstamo de '.number_format((float) $this->record->amount, 0, ',', '.').' Gs.?'
                )
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
                            ->title('Préstamo Cancelado')
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
                ->visible(fn () => $this->record->isApproved() || $this->record->isDisbursed() || $this->record->isPaid())
                ->url(fn () => route('loans.pdf', $this->record))
                ->openUrlInNewTab(),

            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->visible(fn () => $this->record->isPending()),
        ];
    }
}
