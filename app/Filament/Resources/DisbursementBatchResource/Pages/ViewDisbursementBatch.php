<?php

namespace App\Filament\Resources\DisbursementBatchResource\Pages;

use App\Filament\Resources\AguinaldoPeriodResource;
use App\Filament\Resources\DisbursementBatchResource;
use App\Filament\Resources\PayrollPeriodResource;
use App\Models\Aguinaldo;
use App\Models\CompanyBankAccount;
use App\Models\DisbursementBatch;
use App\Models\Loan;
use App\Models\Payroll;
use App\Services\BankPaymentExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Página de detalle de un lote de pago bancario.
 *
 * Acciones disponibles según estado del lote:
 *  - pending: Descargar TXT, Confirmar Lote, Cancelar Lote.
 *  - confirmed / partially_confirmed / cancelled: sin acciones de mutación.
 */
class ViewDisbursementBatch extends ViewRecord
{
    protected static string $resource = DisbursementBatchResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit_batch')
                ->label('Editar')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->visible(fn () => $this->record->isPending())
                ->modalHeading('Editar lote de pago')
                ->modalSubmitActionLabel('Guardar cambios')
                ->fillForm(fn () => [
                    'fecha_credito' => $this->record->fecha_credito,
                    'notes' => $this->record->notes,
                ])
                ->form([
                    DatePicker::make('fecha_credito')
                        ->label('Fecha de acreditación')
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->closeOnDateSelection()
                        ->helperText('Fecha en que el banco acreditará los fondos en las cuentas de los empleados.'),

                    Textarea::make('notes')
                        ->label('Notas')
                        ->placeholder('Observaciones opcionales...')
                        ->rows(3),
                ])
                ->action(function (array $data) {
                    $this->record->update([
                        'fecha_credito' => $data['fecha_credito'],
                        'notes' => $data['notes'],
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Lote actualizado')
                        ->body('La fecha de acreditación y las notas fueron actualizadas.')
                        ->send();

                    $this->refreshFormData(['fecha_credito', 'notes']);
                }),

            Action::make('download_txt')
                ->label('Descargar TXT')
                ->icon('heroicon-o-document-arrow-down')
                ->color('info')
                ->visible(fn () => $this->record->isPending())
                ->modalHeading('Generar archivo TXT Itaú')
                ->modalDescription(function () {
                    $batch = $this->record;
                    $fecha = $batch->fecha_credito->format('d/m/Y');

                    [$count, $total, $noun] = match ($batch->type) {
                        'payroll' => [
                            $batch->payrolls()->count(),
                            number_format((float) $batch->payrolls()->sum('net_salary'), 0, ',', '.'),
                            'recibo',
                        ],
                        'loan' => [
                            $batch->loans()->where('status', 'approved')->count(),
                            number_format((float) $batch->loans()->where('status', 'approved')->sum('amount'), 0, ',', '.'),
                            'préstamo',
                        ],
                        'aguinaldo' => [
                            $batch->aguinaldos()->where('status', 'pending')->count(),
                            number_format((float) $batch->aguinaldos()->where('status', 'pending')->sum('aguinaldo_amount'), 0, ',', '.'),
                            'aguinaldo',
                        ],
                        default => [
                            $batch->advances()->where('status', 'approved')->count(),
                            number_format((float) $batch->advances()->where('status', 'approved')->sum('amount'), 0, ',', '.'),
                            'adelanto',
                        ],
                    };

                    $noun_plural = $count === 1 ? $noun : match ($noun) {
                        'préstamo' => 'préstamos',
                        'aguinaldo' => 'aguinaldos',
                        'recibo' => 'recibos',
                        default => 'adelantos',
                    };

                    return "Se generará el archivo para acreditación bancaria del {$fecha} con {$count} {$noun_plural} por un total de Gs. {$total}.";
                })
                ->modalSubmitActionLabel('Descargar')
                ->action(function (Action $action) {
                    /** @var DisbursementBatch $batch */
                    $batch = $this->record;

                    $account = CompanyBankAccount::where('company_id', $batch->company_id)
                        ->where('is_primary', true)
                        ->where('status', 'active')
                        ->first();

                    if (! $account) {
                        Notification::make()->danger()
                            ->title('Sin cuenta bancaria principal')
                            ->body('La empresa no tiene cuenta bancaria principal activa configurada.')
                            ->send();
                        $action->halt();

                        return;
                    }

                    if (! $account->bank_company_id) {
                        Notification::make()->danger()
                            ->title('ID Empresa no configurado')
                            ->body('Completá el ID Empresa en la cuenta bancaria principal antes de generar el TXT.')
                            ->send();
                        $action->halt();

                        return;
                    }

                    $withBankAccounts = ['employee.bankAccounts' => fn ($q) => $q->where('is_primary', true)->where('status', 'active')];

                    [$items, $amountField, $emptyTitle, $emptyMessage] = match ($batch->type) {
                        'payroll' => [
                            $batch->payrolls()->with($withBankAccounts)->get(),
                            'net_salary',
                            'Sin recibos disponibles',
                            'No hay recibos en este lote para generar el TXT.',
                        ],
                        'loan' => [
                            $batch->loans()->where('status', 'approved')->with($withBankAccounts)->get(),
                            'amount',
                            'Sin préstamos disponibles',
                            'No hay préstamos aprobados en este lote para generar el TXT.',
                        ],
                        'aguinaldo' => [
                            $batch->aguinaldos()->where('status', 'pending')->with($withBankAccounts)->get(),
                            'aguinaldo_amount',
                            'Sin aguinaldos disponibles',
                            'No hay aguinaldos pendientes en este lote para generar el TXT.',
                        ],
                        default => [
                            $batch->advances()->where('status', 'approved')->with($withBankAccounts)->get(),
                            'amount',
                            'Sin adelantos disponibles',
                            'No hay adelantos aprobados en este lote para generar el TXT.',
                        ],
                    };

                    if ($items->isEmpty()) {
                        Notification::make()->warning()
                            ->title($emptyTitle)
                            ->body($emptyMessage)
                            ->send();
                        $action->halt();

                        return;
                    }

                    $params = [
                        'id_empresa' => $account->bank_company_id,
                        'cuenta_debito' => $account->account_number,
                        'moneda' => 'Guaraní',
                        'tipo' => 'Crédito',
                        'fecha_credito' => $batch->fecha_credito->format('Y-m-d'),
                    ];

                    $content = app(BankPaymentExportService::class)->generateTxt(
                        $params,
                        $items,
                        stampDate: false,
                        amountField: $amountField,
                    );

                    // Guarda el TXT en storage y registra la ruta en el lote.
                    if ($batch->file_path) {
                        Storage::disk('public')->delete($batch->file_path);
                    }

                    $filename = 'TRANSFER_'.$batch->id.'_'.now()->format('Y_m_d_H_i_s').'.txt';
                    $path = 'disbursement_batches/'.$filename;
                    Storage::disk('public')->put($path, $content);

                    $batch->update(['file_path' => $path]);

                    return response()->streamDownload(
                        fn () => print ($content),
                        $filename,
                        ['Content-Type' => 'text/plain; charset=UTF-8']
                    );
                }),

            Action::make('confirm_batch')
                ->label('Confirmar Lote')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->isPending() && auth()->user()->can('confirm_disbursement_batch'))
                ->modalHeading('Confirmar resultado bancario')
                ->modalDescription(fn () => match ($this->record->type) {
                    'payroll' => 'Adjuntá el comprobante del banco. Marcá los recibos rechazados (si los hay); los demás quedarán como Acreditados.',
                    'loan' => 'Adjuntá el comprobante del banco. Marcá los préstamos rechazados (si los hay); los demás quedarán como Desembolsados.',
                    'aguinaldo' => 'Adjuntá el comprobante del banco. Marcá los aguinaldos rechazados (si los hay); los demás quedarán como Pagados.',
                    default => 'Adjuntá el comprobante del banco y marcá los adelantos rechazados desde la tabla antes de confirmar. Los adelantos no rechazados quedarán como Entregados.',
                })
                ->modalSubmitActionLabel('Confirmar')
                ->form([
                    FileUpload::make('bank_confirmation_path')
                        ->label('Comprobante bancario')
                        ->disk('public')
                        ->directory('disbursement_batches/confirmations')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)
                        ->required()
                        ->getUploadedFileNameForStorageUsing(function ($file): string {
                            $ext = $file->getClientOriginalExtension();

                            return 'confirmacion_lote_'.$this->record->id.'_'.now()->format('Y-m-d_H-i-s').'.'.$ext;
                        })
                        ->helperText('Obligatorio. Formatos aceptados: PDF, JPG, PNG, WEBP. Tamaño máximo: 10 MB.'),

                    CheckboxList::make('rejected_payroll_ids')
                        ->label('Recibos rechazados por el banco')
                        ->options(fn () => $this->record->payrolls()
                            ->where('status', 'approved')
                            ->with('employee')
                            ->get()
                            ->mapWithKeys(fn (Payroll $p) => [
                                $p->id => $p->employee->full_name
                                    .' — CI: '.$p->employee->ci
                                    .' — Gs. '.number_format((float) $p->net_salary, 0, ',', '.'),
                            ])
                            ->toArray()
                        )
                        ->helperText('Marcá los recibos que el banco rechazó. Los no marcados quedarán como Acreditados.')
                        ->visible(fn () => $this->record->type === 'payroll'),

                    CheckboxList::make('rejected_loan_ids')
                        ->label('Préstamos rechazados por el banco')
                        ->options(fn () => $this->record->loans()
                            ->where('status', 'approved')
                            ->with('employee')
                            ->get()
                            ->mapWithKeys(fn (Loan $l) => [
                                $l->id => $l->employee->full_name
                                    .' — CI: '.$l->employee->ci
                                    .' — Gs. '.number_format((float) $l->amount, 0, ',', '.'),
                            ])
                            ->toArray()
                        )
                        ->helperText('Marcá los préstamos que el banco rechazó. Los no marcados quedarán como Desembolsados.')
                        ->visible(fn () => $this->record->type === 'loan'),

                    CheckboxList::make('rejected_aguinaldo_ids')
                        ->label('Aguinaldos rechazados por el banco')
                        ->options(fn () => $this->record->aguinaldos()
                            ->where('status', 'pending')
                            ->with('employee')
                            ->get()
                            ->mapWithKeys(fn (Aguinaldo $a) => [
                                $a->id => $a->employee->full_name
                                    .' — CI: '.$a->employee->ci
                                    .' — Gs. '.number_format((float) $a->aguinaldo_amount, 0, ',', '.'),
                            ])
                            ->toArray()
                        )
                        ->helperText('Marcá los aguinaldos que el banco rechazó. Los no marcados quedarán como Pagados.')
                        ->visible(fn () => $this->record->type === 'aguinaldo'),
                ])
                ->action(function (array $data) {
                    $rejectedIds = match ($this->record->type) {
                        'payroll' => array_map('intval', $data['rejected_payroll_ids'] ?? []),
                        'loan' => array_map('intval', $data['rejected_loan_ids'] ?? []),
                        'aguinaldo' => array_map('intval', $data['rejected_aguinaldo_ids'] ?? []),
                        default => [],
                    };

                    $result = $this->record->confirm(
                        confirmedById: Auth::id(),
                        bankConfirmationPath: $data['bank_confirmation_path'],
                        rejectedIds: $rejectedIds,
                        rejectionReasons: array_fill_keys($rejectedIds, 'otro'),
                    );

                    if ($result['success']) {
                        Notification::make()
                            ->success()
                            ->title('Lote confirmado')
                            ->body($result['message'])
                            ->send();

                        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Error')
                            ->body($result['message'])
                            ->send();
                    }
                }),

            Action::make('cancel_batch')
                ->label('Cancelar Lote')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->isPending() && auth()->user()->can('cancel_disbursement_batch'))
                ->requiresConfirmation()
                ->modalHeading('Cancelar lote de pago')
                ->modalDescription(fn () => $this->record->type === 'payroll'
                    ? 'Se cancelará el lote y los recibos incluidos quedarán disponibles para ser asignados a otro lote. ¿Confirmar?'
                    : 'Se cancelará el lote y los adelantos incluidos quedarán disponibles para ser asignados a otro lote. ¿Confirmar?'
                )
                ->modalSubmitActionLabel('Sí, cancelar lote')
                ->action(function () {
                    $result = $this->record->cancel();

                    if ($result['success']) {
                        Notification::make()
                            ->warning()
                            ->title('Lote cancelado')
                            ->body($result['message'])
                            ->send();

                        $this->refreshFormData(['status']);
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Error')
                            ->body($result['message'])
                            ->send();
                    }
                }),

            Action::make('view_period')
                ->label('Ver Planilla')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(function () {
                    $periodId = $this->record->payrolls()->value('payroll_period_id');

                    return $periodId
                        ? PayrollPeriodResource::getUrl('view', ['record' => $periodId])
                        : null;
                })
                ->visible(fn () => $this->record->type === 'payroll'
                    && $this->record->payrolls()->exists()),

            Action::make('view_aguinaldo_period')
                ->label('Ver Período de Aguinaldo')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(function () {
                    $periodId = $this->record->aguinaldos()->value('aguinaldo_period_id');

                    return $periodId
                        ? AguinaldoPeriodResource::getUrl('view', ['record' => $periodId])
                        : null;
                })
                ->visible(fn () => $this->record->type === 'aguinaldo'
                    && $this->record->aguinaldos()->exists()),
        ];
    }
}
