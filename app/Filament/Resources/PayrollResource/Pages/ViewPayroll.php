<?php

namespace App\Filament\Resources\PayrollResource\Pages;

use App\Filament\Resources\PayrollResource;
use App\Models\Payroll;
use App\Services\PayrollService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewPayroll extends ViewRecord
{
    protected static string $resource = PayrollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_pdf')
                ->label('Descargar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->form([
                    Radio::make('mode')
                        ->label('Formato')
                        ->options([
                            'print' => 'Para imprimir — 2 copias en hoja horizontal (A4 landscape)',
                            'employee' => 'Para empleado — 1 copia en hoja vertical (A4 portrait)',
                        ])
                        ->default('print')
                        ->required(),
                ])
                ->modalHeading('Descargar Recibo PDF')
                ->modalSubmitActionLabel('Descargar')
                ->action(function (array $data) {
                    $url = route('payrolls.download', ['payroll' => $this->record, 'mode' => $data['mode']]);
                    $this->js("window.open('{$url}', '_blank')");
                }),

            Action::make('approve')
                ->label('Aprobar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aprobar Recibo')
                ->modalDescription(fn () => "¿Está seguro de aprobar el recibo de {$this->record->employee->full_name} por ".Payroll::formatCurrency($this->record->net_salary).'?')
                ->modalSubmitActionLabel('Sí, aprobar')
                ->action(function () {
                    $this->record->update([
                        'status' => 'approved',
                        'approved_by_id' => Auth::id(),
                        'approved_at' => now(),
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Recibo aprobado')
                        ->body("El recibo de {$this->record->employee->full_name} ha sido aprobado.")
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->status === 'draft' && auth()->user()->can('approve_payroll')),

            Action::make('mark_disbursed')
                ->label('Marcar Acreditado')
                ->icon('heroicon-o-building-library')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Marcar como Acreditado')
                ->modalDescription(fn () => "¿Confirma que el pago de {$this->record->employee->full_name} fue acreditado o entregado?")
                ->modalSubmitActionLabel('Sí, acreditar')
                ->action(function () {
                    $result = $this->record->markAsDisbursed(Auth::id());

                    Notification::make()
                        ->{$result['success'] ? 'success' : 'danger'}()
                        ->title($result['success'] ? 'Recibo acreditado' : 'Error')
                        ->body($result['message'])
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isApproved() && auth()->user()->can('disburse_payroll')),

            Action::make('mark_paid')
                ->label('Marcar Pagado')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Marcar como Pagado')
                ->modalDescription(fn () => "¿Confirma que el banco procesó el pago de {$this->record->employee->full_name}?")
                ->modalSubmitActionLabel('Sí, marcar como pagado')
                ->action(function () {
                    $this->record->update(['status' => 'paid']);

                    Notification::make()
                        ->success()
                        ->title('Recibo marcado como pagado')
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isDisbursed() && auth()->user()->can('mark_paid_payroll')),

            Action::make('revert_paid')
                ->label('Revertir Pago')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Revertir Pago')
                ->modalDescription(fn () => "¿Está seguro de revertir el pago del recibo de {$this->record->employee->full_name}? Volverá a estado Acreditado.")
                ->modalSubmitActionLabel('Sí, revertir')
                ->action(function () {
                    $this->record->update(['status' => 'disbursed']);

                    Notification::make()
                        ->success()
                        ->title('Pago revertido')
                        ->body('El recibo ha vuelto a estado Acreditado.')
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isPaid() && auth()->user()->can('revert_payroll')),

            Action::make('revert_to_approved')
                ->label('Revertir a Aprobado')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Revertir a Aprobado')
                ->modalDescription(fn () => "¿Está seguro de revertir el recibo de {$this->record->employee->full_name} a estado Aprobado?")
                ->modalSubmitActionLabel('Sí, revertir')
                ->action(function () {
                    $result = $this->record->revertToApproved();

                    Notification::make()
                        ->{$result['success'] ? 'success' : 'danger'}()
                        ->title($result['success'] ? 'Recibo revertido' : 'No se pudo revertir')
                        ->body($result['message'])
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isDisbursed() && $this->record->disbursement_batch_id === null && auth()->user()->can('revert_payroll')),

            Action::make('unapprove')
                ->label('Desaprobar')
                ->icon('heroicon-o-x-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Desaprobar Recibo')
                ->modalDescription(fn () => "¿Está seguro de desaprobar el recibo de {$this->record->employee->full_name}? Volverá a estado Borrador.")
                ->modalSubmitActionLabel('Sí, desaprobar')
                ->action(function () {
                    $this->record->update([
                        'status' => 'draft',
                        'approved_by_id' => null,
                        'approved_at' => null,
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Recibo desaprobado')
                        ->body('El recibo ha vuelto a estado Borrador.')
                        ->send();

                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isApproved() && auth()->user()->can('revert_payroll')),

            Action::make('regenerate')
                ->label('Regenerar')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Regenerar Recibo')
                ->modalDescription(fn () => 'Se recalcularán todos los ítems de este recibo. Esta acción reemplazará los valores actuales.')
                ->modalSubmitActionLabel('Sí, regenerar')
                ->action(function (PayrollService $payrollService) {
                    try {
                        $payrollService->regenerateForEmployee($this->record);

                        Notification::make()
                            ->success()
                            ->title('Recibo regenerado')
                            ->body('El recibo ha sido recalculado exitosamente.')
                            ->send();

                        $this->refreshFormData([
                            'base_salary',
                            'total_perceptions',
                            'total_deductions',
                            'gross_salary',
                            'net_salary',
                            'generated_at',
                            'pdf_path',
                        ]);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Error al regenerar')
                            ->body('Ocurrió un error al regenerar el recibo: '.$e->getMessage())
                            ->send();
                    }
                })
                ->visible(fn () => $this->record->status === 'draft' && auth()->user()->can('regenerate_payroll')),

            Action::make('edit_draft')
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->fillForm(fn () => [
                    'payment_method' => $this->record->payment_method,
                    'notes' => $this->record->notes,
                ])
                ->form([
                    Select::make('payment_method')
                        ->label('Método de pago')
                        ->options(Payroll::getPaymentMethodOptions())
                        ->native(false)
                        ->required(),

                    Textarea::make('notes')
                        ->label('Notas')
                        ->placeholder('Observaciones opcionales...')
                        ->rows(3),
                ])
                ->modalHeading('Editar Recibo')
                ->modalSubmitActionLabel('Guardar cambios')
                ->action(function (array $data) {
                    $this->record->update([
                        'payment_method' => $data['payment_method'],
                        'notes' => $data['notes'],
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Recibo actualizado')
                        ->send();

                    $this->refreshFormData(['payment_method', 'notes']);
                })
                ->visible(fn () => $this->record->status === 'draft'),
        ];
    }
}
