<?php

namespace App\Filament\Resources\AdvanceResource\Pages;

use App\Filament\Resources\AdvanceResource;
use App\Models\Advance;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewAdvance extends ViewRecord
{
    protected static string $resource = AdvanceResource::class;

    /**
     * Define las acciones del encabezado de la página de detalle.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Aprobar')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn () => $this->record->isPending() && auth()->user()->can('approve_advance'))
                ->modalHeading('Aprobar Adelanto')
                ->modalDescription(fn () => 'Se aprobará el adelanto de '.number_format((float) $this->record->amount, 0, ',', '.').' Gs. para '.$this->record->employee->full_name.'. Se descontará automáticamente en la próxima liquidación de nómina.')
                ->modalSubmitActionLabel('Sí, aprobar')
                ->form([
                    Select::make('payment_method')
                        ->label('Método de pago')
                        ->options(Advance::getPaymentMethodOptions())
                        ->default(fn () => $this->record->payment_method)
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data) {
                    $result = $this->record->approve(Auth::id(), $data['payment_method']);

                    if ($result['success']) {
                        Notification::make()
                            ->success()
                            ->title('Adelanto Aprobado')
                            ->body($result['message'])
                            ->send();

                        $this->record->refresh();
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
                ->visible(fn () => $this->record->isPending() && auth()->user()->can('reject_advance'))
                ->requiresConfirmation()
                ->modalHeading('Rechazar Adelanto')
                ->modalDescription(fn () => 'Se rechazará el adelanto de '.number_format((float) $this->record->amount, 0, ',', '.').' Gs. para '.$this->record->employee->full_name.'. El adelanto quedará en estado Rechazado.')
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
                            ->title('Adelanto Rechazado')
                            ->body($result['message'])
                            ->send();

                        $this->record->refresh();
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
                ->visible(fn () => ($this->record->isPending() || $this->record->isApproved()) && auth()->user()->can('cancel_advance'))
                ->requiresConfirmation()
                ->modalHeading('Cancelar Adelanto')
                ->modalDescription(fn () => '¿Está seguro de que desea cancelar el adelanto de '.number_format((float) $this->record->amount, 0, ',', '.').' Gs.?')
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
                            ->title('Adelanto Cancelado')
                            ->send();

                        $this->record->refresh();
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Error')
                            ->body($result['message'])
                            ->send();
                    }
                }),

            Action::make('revert_to_pending')
                ->label('Desaprobar')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn () => $this->record->isApproved() && $this->record->disbursement_batch_id === null && auth()->user()->can('revert_advance'))
                ->requiresConfirmation()
                ->modalHeading('Desaprobar adelanto')
                ->modalDescription(fn () => 'El adelanto de '.number_format((float) $this->record->amount, 0, ',', '.').' Gs. volverá a estado Pendiente y podrá editarse. Deberá aprobarse nuevamente antes de ser entregado.')
                ->modalSubmitActionLabel('Sí, desaprobar')
                ->action(function () {
                    $result = $this->record->revertToPending();

                    if ($result['success']) {
                        Notification::make()
                            ->warning()
                            ->title('Adelanto desaprobado')
                            ->body($result['message'])
                            ->send();

                        $this->record->refresh();
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
                ->visible(fn () => $this->record->isApproved() && auth()->user()->can('disburse_advance'))
                ->modalHeading('Marcar Adelanto como Entregado')
                ->modalDescription(fn () => 'Se confirmará que el adelanto de '.number_format((float) $this->record->amount, 0, ',', '.').' Gs. fue entregado a '.$this->record->employee->full_name.'. Se descontará en la próxima liquidación de nómina.')
                ->modalSubmitActionLabel('Sí, marcar como entregado')
                ->form([
                    DateTimePicker::make('disbursed_at')
                        ->label('Fecha y hora de Entrega')
                        ->required()
                        ->native(false)
                        ->default(now())
                        ->displayFormat('d/m/Y H:i'),

                    FileUpload::make('transfer_receipt_path')
                        ->label('Comprobante')
                        ->disk('public')
                        ->directory('advances/receipts')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(5120)
                        ->required(fn () => $this->record->payment_method === 'transfer')
                        ->getUploadedFileNameForStorageUsing(function ($file): string {
                            $ext = $file->getClientOriginalExtension();

                            return 'comprobante_adelanto_'.$this->record->id.'_'.now()->format('Y-m-d_H-i-s').'.'.$ext;
                        })
                        ->helperText(fn () => ($this->record->payment_method === 'transfer'
                            ? 'Obligatorio para acreditación bancaria. '
                            : 'Opcional. ')
                            .'Formatos aceptados: PDF, JPG, PNG, WEBP. Tamaño máximo: 5 MB.'),
                ])
                ->action(function (array $data) {
                    $result = $this->record->markAsDisbursed(
                        $data['disbursed_at'],
                        Auth::id(),
                        $data['transfer_receipt_path'] ?? null,
                    );

                    if ($result['success']) {
                        Notification::make()
                            ->success()
                            ->title('Adelanto Entregado')
                            ->body($result['message'])
                            ->send();

                        $this->record->refresh();
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Error')
                            ->body($result['message'])
                            ->send();
                    }
                }),

            Action::make('revert_to_approved')
                ->label('Revertir a Aprobado')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn () => $this->record->isDisbursed() && $this->record->payroll_id === null && auth()->user()->can('revert_advance'))
                ->requiresConfirmation()
                ->modalHeading('Revertir Adelanto a Aprobado')
                ->modalDescription(fn () => 'El adelanto de '.number_format((float) $this->record->amount, 0, ',', '.').' Gs. para '.$this->record->employee->full_name.' volverá al estado Aprobado.')
                ->modalSubmitActionLabel('Sí, revertir')
                ->action(function () {
                    $result = $this->record->revertToApproved();

                    if ($result['success']) {
                        Notification::make()
                            ->warning()
                            ->title('Adelanto Revertido')
                            ->body($result['message'])
                            ->send();

                        $this->record->refresh();
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
                ->url(fn () => route('advances.pdf', $this->record))
                ->openUrlInNewTab(),

            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->visible(fn () => $this->record->isPending()),
        ];
    }
}
