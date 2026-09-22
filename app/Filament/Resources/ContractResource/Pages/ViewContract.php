<?php

namespace App\Filament\Resources\ContractResource\Pages;

use App\Filament\Resources\ContractResource;
use App\Services\ContractService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

/**
 * Página de vista de detalle de un contrato laboral.
 *
 * Muestra la información completa del contrato e incluye acciones de dominio:
 * generación de PDF, carga/descarga del firmado, renovación y terminación.
 */
class ViewContract extends ViewRecord
{
    protected static string $resource = ContractResource::class;

    /**
     * Define las acciones disponibles en el encabezado de la página de detalle.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('activate')
                ->label('Activar Contrato')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Activar contrato')
                ->modalDescription('¿Confirmás que querés activar este contrato? Pasará a estado Vigente.')
                ->modalSubmitActionLabel('Sí, activar')
                ->visible(fn () => $this->record->status === 'draft' && auth()->user()->can('activate_contract'))
                ->action(function () {
                    $this->record->update(['status' => 'active']);
                    Notification::make()->success()->title('Contrato activado')->send();
                    $this->refreshFormData(['status']);
                }),

            Action::make('generate_pdf')
                ->label('Generar PDF')
                ->icon('heroicon-o-printer')
                ->color('info')
                ->url(fn () => route('contracts.pdf', $this->record))
                ->openUrlInNewTab(),

            Action::make('upload_signed')
                ->label(fn () => $this->record->document_path ? 'Reemplazar Firmado' : 'Subir Firmado')
                ->icon(fn () => $this->record->document_path ? 'heroicon-o-arrow-path' : 'heroicon-o-arrow-up-tray')
                ->color(fn () => $this->record->document_path ? 'warning' : 'gray')
                ->visible(fn () => $this->record->status === 'active')
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
                ->modalHeading(fn () => $this->record->document_path ? 'Reemplazar Documento Firmado' : 'Subir Contrato Firmado')
                ->modalDescription(fn () => $this->record->document_path
                    ? 'El documento actual será reemplazado por el nuevo archivo.'
                    : 'Suba el contrato escaneado con las firmas correspondientes.')
                ->modalSubmitActionLabel('Subir documento')
                ->action(function (array $data) {
                    if ($this->record->document_path && Storage::disk('public')->exists($this->record->document_path)) {
                        Storage::disk('public')->delete($this->record->document_path);
                    }

                    $this->record->update(['document_path' => $data['document_path']]);

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
                ->visible(fn () => (bool) $this->record->document_path)
                ->action(fn () => response()->download(
                    Storage::disk('public')->path($this->record->document_path),
                    "contrato_firmado_{$this->record->employee->ci}_{$this->record->start_date->format('Y_m_d')}.pdf"
                )),

            // --- Acciones de gestión ---

            Action::make('renew')
                ->label('Renovar')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn () => $this->record->status === 'active' && $this->record->type !== 'indefinido' && auth()->user()->can('renew_contract'))
                ->requiresConfirmation()
                ->modalHeading('Renovar Contrato')
                ->modalDescription(function () {
                    $msg = "Se creará un nuevo contrato para {$this->record->employee->full_name} y el contrato actual pasará a estado 'Renovado'.";
                    if ($this->record->wouldBecomeIndefiniteOnRenewal()) {
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
                        ->default(fn () => $this->record->end_date ?? now())
                        ->closeOnDateSelection(),

                    DatePicker::make('end_date')
                        ->label('Fecha de Finalización')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->closeOnDateSelection()
                        ->visible(fn () => ! $this->record->wouldBecomeIndefiniteOnRenewal())
                        ->required(fn () => ! $this->record->wouldBecomeIndefiniteOnRenewal())
                        ->helperText(fn () => $this->record->type === 'plazo_fijo' ? 'Art. 53 CLT: Máximo 1 año' : null),

                    TextInput::make('salary')
                        ->label(fn () => $this->record->salary_type === 'jornal' ? 'Jornal Diario' : 'Salario Mensual')
                        ->numeric()
                        ->required()
                        ->prefix('Gs.')
                        ->suffix(fn () => $this->record->salary_type === 'jornal' ? '/día' : '/mes')
                        ->default(fn () => $this->record->salary),

                    Textarea::make('notes')
                        ->label('Notas')
                        ->placeholder('Notas sobre la renovación...')
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    $oldType = $this->record->type;
                    $newContract = ContractService::renew($this->record, $data);

                    $typeMsg = $newContract->type === 'indefinido' && $oldType !== 'indefinido'
                        ? ' (convertido a INDEFINIDO por Art. 53 CLT)'
                        : '';

                    Notification::make()
                        ->title('Contrato Renovado')
                        ->body("Se creó un nuevo contrato{$typeMsg} para {$this->record->employee->full_name}.")
                        ->success()
                        ->send();

                    return redirect(ContractResource::getUrl('view', ['record' => $newContract]));
                }),

            Action::make('suspend')
                ->label('Suspender')
                ->icon('heroicon-o-pause-circle')
                ->color('warning')
                ->visible(fn () => $this->record->status === 'active' && auth()->user()->can('suspend_contract'))
                ->requiresConfirmation()
                ->modalHeading('Suspender contrato')
                ->modalDescription(fn () => "Se suspenderá el contrato de {$this->record->employee->full_name}. Sus percepciones y deducciones serán desactivadas temporalmente. Podrá reactivarse en cualquier momento.")
                ->modalSubmitActionLabel('Sí, suspender')
                ->action(function () {
                    $this->record->update(['status' => 'suspended']);
                    Notification::make()->success()->title('Contrato suspendido')->send();
                    $this->refreshFormData(['status']);
                }),

            Action::make('reactivate')
                ->label('Reactivar')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'suspended' && auth()->user()->can('reactivate_contract'))
                ->requiresConfirmation()
                ->modalHeading('Reactivar contrato')
                ->modalDescription(fn () => "Se reactivará el contrato de {$this->record->employee->full_name}. Sus percepciones y deducciones desactivadas por el sistema serán restauradas.")
                ->modalSubmitActionLabel('Sí, reactivar')
                ->action(function () {
                    $this->record->update(['status' => 'active']);
                    Notification::make()->success()->title('Contrato reactivado')->send();
                    $this->refreshFormData(['status']);
                }),

            Action::make('terminate')
                ->label('Terminar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status === 'active' && auth()->user()->can('terminate_contract'))
                ->requiresConfirmation()
                ->modalHeading('Terminar Contrato')
                ->modalDescription(fn () => "¿Está seguro de que desea terminar el contrato de {$this->record->employee->full_name}?")
                ->modalSubmitActionLabel('Sí, terminar')
                ->form([
                    Textarea::make('termination_notes')
                        ->label('Motivo de terminación')
                        ->placeholder('Ingrese el motivo...')
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    ContractService::terminate($this->record, $data['termination_notes'] ?? null);

                    Notification::make()
                        ->title('Contrato Terminado')
                        ->body("El contrato de {$this->record->employee->full_name} ha sido terminado. Puede crear una liquidación desde el módulo correspondiente.")
                        ->warning()
                        ->persistent()
                        ->actions([
                            NotificationAction::make('create_liquidacion')
                                ->label('Crear Liquidación')
                                ->url(route('filament.admin.resources.liquidaciones.create', [
                                    'employee_id' => $this->record->employee_id,
                                ]))
                                ->button(),
                        ])
                        ->send();
                }),

            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary'),

            DeleteAction::make()
                ->label('Eliminar borrador')
                ->visible(fn () => $this->record->status === 'draft')
                ->successRedirectUrl(ContractResource::getUrl('index')),
        ];
    }
}
