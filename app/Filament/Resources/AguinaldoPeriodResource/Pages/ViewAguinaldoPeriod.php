<?php

namespace App\Filament\Resources\AguinaldoPeriodResource\Pages;

use App\Exports\AguinaldosExport;
use App\Filament\Resources\AguinaldoPeriodResource;
use App\Filament\Resources\DisbursementBatchResource;
use App\Models\Aguinaldo;
use App\Models\DisbursementBatch;
use App\Services\AguinaldoService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Facades\Excel;

class ViewAguinaldoPeriod extends ViewRecord
{
    protected static string $resource = AguinaldoPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('provision_report')
                ->label('Ver Provisión')
                ->icon('heroicon-o-calculator')
                ->color('info')
                ->url(fn () => AguinaldoPeriodResource::getUrl('provision', ['record' => $this->record])),

            Action::make('generate_aguinaldos')
                ->label('Generar')
                ->icon('heroicon-o-document-plus')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('¿Generar los aguinaldos?')
                ->modalDescription(
                    fn () => "Esta acción generará los aguinaldos correspondientes al período de {$this->record->year} para la empresa {$this->record->company->name}. Si ya existen aguinaldos generados para este período, no se generarán duplicados."
                )
                ->modalSubmitActionLabel('Sí, generar')
                ->action(function (AguinaldoService $aguinaldoService) {
                    $count = $aguinaldoService->generateForPeriod($this->record);

                    if ($count > 0) {
                        $this->record->update(['status' => 'processing']);

                        Notification::make()
                            ->success()
                            ->title('Aguinaldos generados')
                            ->body("Se generaron exitosamente {$count} aguinaldos para el período {$this->record->year}.")
                            ->send();
                    } else {
                        Notification::make()
                            ->warning()
                            ->title('No se generaron aguinaldos')
                            ->body("Ya fueron generados o no hay nóminas para el período {$this->record->year} de {$this->record->company->name}.")
                            ->send();
                    }

                    $this->refreshFormData(['status']);
                })
                ->visible(fn () => $this->record->isDraft() && auth()->user()->can('generate_aguinaldos_period')),

            Action::make('mark_all_paid')
                ->label('Pagar Todos')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('¿Marcar todos los aguinaldos como pagados?')
                ->modalDescription(function () {
                    $pending = $this->record->pending_aguinaldos_count;

                    return "Se marcarán {$pending} aguinaldo(s) pendiente(s) como pagados. ¿Confirma esta acción?";
                })
                ->modalSubmitActionLabel('Sí, marcar como pagados')
                ->action(function () {
                    $count = $this->record->aguinaldos()
                        ->where('status', 'pending')
                        ->get()
                        ->each(fn ($a) => $a->markAsPaid())
                        ->count();

                    Notification::make()
                        ->success()
                        ->title('Aguinaldos marcados como pagados')
                        ->body("Se marcaron {$count} aguinaldo(s) como pagados para el período {$this->record->year}.")
                        ->send();

                    $this->refreshFormData(['status']);
                })
                ->visible(fn () => $this->record->isProcessing() && $this->record->pending_aguinaldos_count > 0 && auth()->user()->can('mark_paid_aguinaldo')),

            Action::make('send_to_bank')
                ->label('Enviar al Banco')
                ->icon('heroicon-o-building-library')
                ->color('info')
                ->mountUsing(function (Form $form, Action $action) {
                    $hasAguinaldos = Aguinaldo::query()
                        ->where('aguinaldo_period_id', $this->record->id)
                        ->where('status', 'pending')
                        ->where('payment_method', 'transfer')
                        ->whereNull('disbursement_batch_id')
                        ->exists();

                    if (! $hasAguinaldos) {
                        Notification::make()
                            ->warning()
                            ->title('Sin aguinaldos disponibles')
                            ->body('No hay aguinaldos pendientes por transferencia bancaria sin lote asignado.')
                            ->send();

                        $action->halt();

                        return;
                    }

                    $form->fill(['fecha_credito' => today()->format('Y-m-d')]);
                })
                ->modalHeading('Crear lote bancario de aguinaldo')
                ->modalSubmitActionLabel('Crear lote')
                ->form(function () {
                    $missing = Aguinaldo::query()
                        ->where('aguinaldo_period_id', $this->record->id)
                        ->where('status', 'pending')
                        ->where('payment_method', 'transfer')
                        ->whereNull('disbursement_batch_id')
                        ->whereDoesntHave('employee.bankAccounts', fn ($q) => $q->where('is_primary', true)->where('status', 'active'))
                        ->with('employee')
                        ->get();

                    return [
                        Placeholder::make('accounts_check')
                            ->label('')
                            ->content(function () use ($missing) {
                                if ($missing->isEmpty()) {
                                    return new HtmlString(
                                        '<div class="rounded-lg bg-success-50 border border-success-200 p-3 text-sm text-success-700">'
                                        .'✓ Todos los empleados tienen cuenta bancaria activa registrada.'
                                        .'</div>'
                                    );
                                }

                                $count = $missing->count();
                                $label = $count === 1 ? 'empleado' : 'empleados';
                                $names = $missing->map(fn ($a) => $a->employee->full_name)->join(', ');

                                return new HtmlString(
                                    '<div class="rounded-lg bg-danger-50 border border-danger-200 p-4 text-sm">'
                                    .'<p class="font-semibold text-danger-700 mb-1">⚠ '.$count.' '.$label.' sin cuenta bancaria activa</p>'
                                    .'<p class="text-danger-600 mb-2">No se puede crear el lote hasta que todos los empleados tengan cuenta bancaria registrada.</p>'
                                    .'<p class="text-danger-700">'.$names.'</p>'
                                    .'</div>'
                                );
                            }),

                        DatePicker::make('fecha_credito')
                            ->label('Fecha de acreditación')
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->helperText('Fecha en que el banco acreditará los fondos a los empleados.'),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->placeholder('Observaciones opcionales...')
                            ->rows(2),
                    ];
                })
                ->action(function (array $data) {
                    $missing = Aguinaldo::query()
                        ->where('aguinaldo_period_id', $this->record->id)
                        ->where('status', 'pending')
                        ->where('payment_method', 'transfer')
                        ->whereNull('disbursement_batch_id')
                        ->whereDoesntHave('employee.bankAccounts', fn ($q) => $q->where('is_primary', true)->where('status', 'active'))
                        ->count();

                    if ($missing > 0) {
                        Notification::make()
                            ->danger()
                            ->title('Hay empleados sin cuenta bancaria')
                            ->body('Registre las cuentas bancarias faltantes antes de crear el lote.')
                            ->send();

                        return;
                    }

                    $batch = DisbursementBatch::create([
                        'type' => 'aguinaldo',
                        'company_id' => $this->record->company_id,
                        'fecha_credito' => $data['fecha_credito'],
                        'notes' => $data['notes'] ?? null,
                        'status' => 'pending',
                        'created_by_id' => Auth::id(),
                    ]);

                    Aguinaldo::query()
                        ->where('aguinaldo_period_id', $this->record->id)
                        ->where('status', 'pending')
                        ->where('payment_method', 'transfer')
                        ->whereNull('disbursement_batch_id')
                        ->update(['disbursement_batch_id' => $batch->id]);

                    Notification::make()
                        ->success()
                        ->title('Lote bancario creado')
                        ->body('El lote de aguinaldo fue creado. Descargá el TXT y confirmá el resultado bancario.')
                        ->send();

                    $this->redirect(DisbursementBatchResource::getUrl('view', ['record' => $batch]));
                })
                ->visible(fn () => $this->record->isProcessing() && auth()->user()->can('create_disbursement_batch')),

            Action::make('export_excel')
                ->label('Exportar')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('¿Exportar aguinaldos a Excel?')
                ->modalDescription("Se incluirán todos los aguinaldos generados para el período {$this->record->year} de {$this->record->company->name} en un archivo Excel descargable.")
                ->modalSubmitActionLabel('Sí, exportar')
                ->action(function () {
                    Notification::make()
                        ->success()
                        ->title('Exportación lista')
                        ->body("La planilla de aguinaldos {$this->record->year} se está descargando.")
                        ->send();

                    return Excel::download(
                        new AguinaldosExport(periodId: $this->record->id),
                        'aguinaldos_año_'.$this->record->year.'_'.now()->format('Y_m_d_H_i_s').'.xlsx'
                    );
                })
                ->visible(fn () => $this->record->isProcessing() || $this->record->isClosed()),

            Action::make('reopen_period')
                ->label('Reabrir')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('¿Reabrir Período de Aguinaldo?')
                ->modalDescription(
                    fn () => "Esta acción reabrirá el período de aguinaldo {$this->record->year} de {$this->record->company->name}, permitiendo generar nuevos aguinaldos o modificar los existentes. ¿Confirma que desea reabrir este período?"
                )
                ->modalSubmitActionLabel('Sí, reabrir período')
                ->action(function () {
                    $this->record->update([
                        'status' => 'processing',
                        'closed_at' => null,
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Período reabierto')
                        ->body("El período de aguinaldo {$this->record->year} de {$this->record->company->name} ha sido reabierto.")
                        ->send();

                    $this->refreshFormData(['status', 'closed_at']);
                })
                ->visible(fn () => $this->record->isClosed() && auth()->user()->can('reopen_aguinaldo_period')),

            EditAction::make()
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->visible(fn () => $this->record->isDraft()),

            Action::make('close_period')
                ->label('Cerrar')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('¿Cerrar Período de Aguinaldo?')
                ->modalDescription(function () {
                    $pending = $this->record->pending_aguinaldos_count;
                    $base = "Esta acción cerrará el período de aguinaldo {$this->record->year} de {$this->record->company->name}. Una vez cerrado no se podrán generar más aguinaldos.";

                    return $pending > 0
                        ? "{$base} Atención: aún hay {$pending} aguinaldo(s) pendiente(s) de pago que no podrán ser marcados como pagados después de cerrar el período."
                        : $base;
                })
                ->modalSubmitActionLabel('Sí, cerrar período')
                ->action(function () {
                    $this->record->update([
                        'status' => 'closed',
                        'closed_at' => now(),
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Período cerrado')
                        ->body("El período de aguinaldo {$this->record->year} de {$this->record->company->name} ha sido cerrado.")
                        ->send();

                    $this->refreshFormData(['status', 'closed_at']);
                })
                ->visible(fn () => $this->record->isProcessing() && auth()->user()->can('close_aguinaldo_period')),

            Action::make('force_delete')
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('¿Eliminar Período de Aguinaldo?')
                ->modalDescription(function () {
                    $count = $this->record->aguinaldos()->count();

                    return "Esta acción eliminará permanentemente el período {$this->record->year} de {$this->record->company->name} "
                        ."junto con {$count} aguinaldo(s) generado(s) y todos sus ítems. Esta acción no se puede deshacer.";
                })
                ->modalSubmitActionLabel('Sí, eliminar todo')
                ->action(function () {
                    $this->record->delete();

                    Notification::make()
                        ->success()
                        ->title('Período eliminado')
                        ->body("El período de aguinaldo {$this->record->year} de {$this->record->company->name} y todos sus aguinaldos fueron eliminados.")
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                })
                ->visible(fn () => ($this->record->isProcessing() || $this->record->isClosed()) && auth()->user()->can('delete_aguinaldo_period')),
        ];
    }
}
