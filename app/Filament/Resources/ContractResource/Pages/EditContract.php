<?php

namespace App\Filament\Resources\ContractResource\Pages;

use App\Filament\Resources\ContractResource;
use App\Models\Contract;
use App\Models\EmployeeBankAccount;
use App\Services\ContractService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    /**
     * Define las acciones disponibles en el encabezado de la página de edición.
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('Ver')
                ->icon('heroicon-o-eye')
                ->color('gray'),

            // --- Acciones de documento ---

            Action::make('generate_pdf')
                ->label('Generar PDF')
                ->icon('heroicon-o-printer')
                ->color('info')
                ->url(fn (Contract $record) => route('contracts.pdf', $record))
                ->openUrlInNewTab(),

            Action::make('upload_signed')
                ->label(fn (Contract $record) => $record->document_path ? 'Reemplazar Firmado' : 'Subir Firmado')
                ->icon(fn (Contract $record) => $record->document_path ? 'heroicon-o-arrow-path' : 'heroicon-o-arrow-up-tray')
                ->color(fn (Contract $record) => $record->document_path ? 'warning' : 'success')
                ->visible(fn (Contract $record) => $record->status === 'active')
                ->form([
                    FileUpload::make('document_path')
                        ->label('Contrato Firmado (PDF)')
                        ->disk('public')
                        ->directory('contracts')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(10240)
                        ->required()
                        ->helperText('Suba el documento escaneado del contrato firmado. Solo PDF, máximo 10 MB.'),
                ])
                ->modalHeading(fn (Contract $record) => $record->document_path ? 'Reemplazar Documento Firmado' : 'Subir Contrato Firmado')
                ->modalDescription(fn (Contract $record) => $record->document_path
                    ? 'El documento actual será reemplazado por el nuevo archivo.'
                    : 'Suba el contrato escaneado con las firmas correspondientes.')
                ->action(function (Contract $record, array $data) {
                    if ($record->document_path && Storage::disk('public')->exists($record->document_path)) {
                        Storage::disk('public')->delete($record->document_path);
                    }

                    $record->update(['document_path' => $data['document_path']]);

                    Notification::make()
                        ->title('Documento subido')
                        ->body('El contrato firmado se ha guardado correctamente.')
                        ->success()
                        ->send();
                }),

            Action::make('download_signed')
                ->label('Descargar Firmado')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (Contract $record) => (bool) $record->document_path)
                ->action(fn (Contract $record) => response()->download(
                    Storage::disk('public')->path($record->document_path),
                    "contrato_firmado_{$record->employee->ci}_{$record->start_date->format('Y_m_d')}.pdf"
                )),

            // --- Acciones de gestión ---

            Action::make('activate')
                ->label('Activar Contrato')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Activar contrato')
                ->modalDescription('¿Confirmás que querés activar este contrato? Pasará a estado Vigente.')
                ->modalSubmitActionLabel('Sí, activar')
                ->visible(fn (Contract $record) => $record->status === 'draft' && auth()->user()->can('activate_contract'))
                ->action(function (Contract $record) {
                    $record->update(['status' => 'active']);
                    Notification::make()->success()->title('Contrato activado')->send();
                }),

            Action::make('suspend')
                ->label('Suspender')
                ->icon('heroicon-o-pause-circle')
                ->color('warning')
                ->visible(fn (Contract $record) => $record->status === 'active' && auth()->user()->can('suspend_contract'))
                ->requiresConfirmation()
                ->modalHeading('Suspender contrato')
                ->modalDescription(fn (Contract $record) => "Se suspenderá el contrato de {$record->employee->full_name}. Sus percepciones y deducciones serán desactivadas temporalmente.")
                ->modalSubmitActionLabel('Sí, suspender')
                ->action(function (Contract $record) {
                    $record->update(['status' => 'suspended']);
                    Notification::make()->success()->title('Contrato suspendido')->send();
                }),

            Action::make('reactivate')
                ->label('Reactivar')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn (Contract $record) => $record->status === 'suspended' && auth()->user()->can('reactivate_contract'))
                ->requiresConfirmation()
                ->modalHeading('Reactivar contrato')
                ->modalDescription(fn (Contract $record) => "Se reactivará el contrato de {$record->employee->full_name}. Sus percepciones y deducciones serán restauradas.")
                ->modalSubmitActionLabel('Sí, reactivar')
                ->action(function (Contract $record) {
                    $record->update(['status' => 'active']);
                    Notification::make()->success()->title('Contrato reactivado')->send();
                }),

            Action::make('renew')
                ->label('Renovar')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn (Contract $record) => $record->status === 'active' && $record->type !== 'indefinido' && auth()->user()->can('renew_contract'))
                ->requiresConfirmation()
                ->modalHeading('Renovar Contrato')
                ->modalDescription(function (Contract $record) {
                    $msg = "Se creará un nuevo contrato para {$record->employee->full_name} y el contrato actual pasará a estado 'Renovado'.";
                    if ($record->wouldBecomeIndefiniteOnRenewal()) {
                        $msg .= "\n\n⚠ Art. 53 CLT: Este contrato a plazo fijo ya fue renovado anteriormente. Al renovar nuevamente, el nuevo contrato será automáticamente de tipo INDEFINIDO.";
                    }

                    return $msg;
                })
                ->modalSubmitActionLabel('Sí, renovar')
                ->form([
                    DatePicker::make('start_date')
                        ->label('Fecha de Inicio')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required()
                        ->default(fn (Contract $record) => $record->end_date ?? now())
                        ->closeOnDateSelection(),

                    DatePicker::make('end_date')
                        ->label('Fecha de Finalización')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->closeOnDateSelection()
                        ->visible(fn (Contract $record) => ! $record->wouldBecomeIndefiniteOnRenewal())
                        ->required(fn (Contract $record) => ! $record->wouldBecomeIndefiniteOnRenewal())
                        ->helperText(fn (Contract $record) => $record->type === 'plazo_fijo' ? 'Art. 53 CLT: Máximo 1 año' : null),

                    TextInput::make('salary')
                        ->label(fn (Contract $record) => $record->salary_type === 'jornal' ? 'Jornal Diario' : 'Salario Mensual')
                        ->numeric()
                        ->required()
                        ->prefix('Gs.')
                        ->suffix(fn (Contract $record) => $record->salary_type === 'jornal' ? '/día' : '/mes')
                        ->default(fn (Contract $record) => $record->salary),

                    Textarea::make('notes')
                        ->label('Notas')
                        ->placeholder('Notas sobre la renovación...')
                        ->rows(2),
                ])
                ->action(function (Contract $record, array $data) {
                    $oldType = $record->type;
                    $newContract = ContractService::renew($record, $data);

                    $typeMsg = $newContract->type === 'indefinido' && $oldType !== 'indefinido'
                        ? ' (convertido a INDEFINIDO por Art. 53 CLT)'
                        : '';

                    Notification::make()
                        ->title('Contrato Renovado')
                        ->body("Se creó un nuevo contrato{$typeMsg} para {$record->employee->full_name}.")
                        ->success()
                        ->send();

                    return redirect(ContractResource::getUrl('view', ['record' => $newContract]));
                }),

            Action::make('terminate')
                ->label('Terminar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Contract $record) => $record->status === 'active' && auth()->user()->can('terminate_contract'))
                ->requiresConfirmation()
                ->modalHeading('Terminar Contrato')
                ->modalDescription(fn (Contract $record) => "¿Está seguro de que desea terminar el contrato de {$record->employee->full_name}?")
                ->modalSubmitActionLabel('Sí, terminar')
                ->form([
                    Textarea::make('termination_notes')
                        ->label('Motivo de terminación')
                        ->placeholder('Ingrese el motivo...')
                        ->rows(3),
                ])
                ->action(function (Contract $record, array $data) {
                    ContractService::terminate($record, $data['termination_notes'] ?? null);

                    Notification::make()
                        ->title('Contrato Terminado')
                        ->body("El contrato de {$record->employee->full_name} ha sido terminado.")
                        ->warning()
                        ->persistent()
                        ->actions([
                            NotificationAction::make('create_liquidacion')
                                ->label('Crear Liquidación')
                                ->url(route('filament.admin.resources.liquidaciones.create', [
                                    'employee_id' => $record->employee_id,
                                ]))
                                ->button(),
                        ])
                        ->send();
                }),

            DeleteAction::make()
                ->label('Eliminar borrador')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (Contract $record) => $record->status === 'draft')
                ->modalHeading('¿Eliminar contrato?')
                ->modalSubmitActionLabel('Sí, eliminar')
                ->successRedirectUrl(ContractResource::getUrl('index')),
        ];
    }

    /**
     * Crea la cuenta bancaria del empleado si se ingresó una al editar el contrato con pago por débito.
     */
    protected function afterSave(): void
    {
        if (
            ($this->data['payment_method'] ?? null) === 'debit' &&
            filled($this->data['ba_bank'] ?? null)
        ) {
            EmployeeBankAccount::firstOrCreate(
                ['employee_id' => $this->record->employee_id, 'status' => 'active'],
                [
                    'bank' => $this->data['ba_bank'],
                    'account_type' => $this->data['ba_account_type'],
                    'account_number' => $this->data['ba_account_number'],
                    'holder_name' => $this->data['ba_holder_name'],
                    'holder_ci' => $this->data['ba_holder_ci'],
                    'is_primary' => true,
                ]
            );
        }
    }

    /**
     * Redirige a la vista de detalle del contrato tras guardar.
     */
    protected function getRedirectUrl(): string
    {
        return ContractResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * Retorna la notificación de éxito al guardar el contrato.
     */
    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Contrato actualizado')
            ->body('Los datos del contrato fueron guardados correctamente.');
    }
}
