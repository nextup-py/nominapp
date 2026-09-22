<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayrollResource\Pages;
use App\Filament\Resources\PayrollResource\RelationManagers;
use App\Models\Payroll;
use App\Services\PayrollService;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Maatwebsite\Excel\Excel;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class PayrollResource extends Resource
{
    protected static ?string $model = Payroll::class;

    protected static ?string $navigationGroup = 'Nóminas';

    protected static ?string $navigationLabel = 'Recibos';

    protected static ?string $label = 'Recibo';

    protected static ?string $pluralLabel = 'Recibos';

    protected static ?string $slug = 'recibos';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('employee.ci')
                    ->label('CI')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('CI copiado')
                    ->weight('bold'),

                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->sortable(['first_name', 'last_name'])
                    ->wrap(),

                TextColumn::make('period.name')
                    ->label('Período')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => Payroll::getStatusColors()[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Payroll::getStatusLabels()[$state] ?? $state)
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->label('Método de pago')
                    ->badge()
                    ->color(fn ($state) => Payroll::getPaymentMethodColors()[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => $state ? (Payroll::getPaymentMethodLabels()[$state] ?? $state) : '—')
                    ->icon(fn ($state) => Payroll::getPaymentMethodIcons()[$state] ?? null)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('base_salary')
                    ->label('Salario Base / Jornal')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->description(fn (Payroll $record): ?string => $record->employee->employment_type === 'day_laborer'
                        ? 'Jornal'
                        : null)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_perceptions')
                    ->label('Percepciones')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->color('success')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_deductions')
                    ->label('Deducciones')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('gross_salary')
                    ->label('Salario Bruto')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('net_salary')
                    ->label('Salario Neto')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->weight('bold')
                    ->color('success'),

                TextColumn::make('approvedBy.name')
                    ->label('Aprobado por')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('approved_at')
                    ->label('Fecha Aprobación')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('generated_at')
                    ->label('Generado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('payroll_period_id')
                    ->label('Período')
                    ->relationship('period', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->searchable(['first_name', 'last_name'])
                    ->preload()
                    ->native(false)
                    ->getOptionLabelFromRecordUsing(function ($record) {
                        return "{$record->first_name} {$record->last_name}";
                    }),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Payroll::getStatusLabels())
                    ->native(false),

                SelectFilter::make('payment_method')
                    ->label('Método de pago')
                    ->options(Payroll::getPaymentMethodOptions())
                    ->native(false),

                Filter::make('current_year')
                    ->label('Año Actual')
                    ->query(fn ($query) => $query->whereHas('period', function ($q) {
                        $q->whereYear('start_date', now()->year);
                    })),
            ])
            ->actions([
                Action::make('download_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (Payroll $record) => route('payrolls.download', $record))
                    ->openUrlInNewTab(),

                Action::make('approve')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Aprobar Recibo')
                    ->modalDescription(fn (Payroll $record) => "¿Está seguro de aprobar el recibo de {$record->employee->full_name} por ".Payroll::formatCurrency($record->net_salary).'?')
                    ->modalSubmitActionLabel('Sí, aprobar')
                    ->action(function (Payroll $record) {
                        $record->update([
                            'status' => 'approved',
                            'approved_by_id' => Auth::id(),
                            'approved_at' => now(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Recibo aprobado')
                            ->body("El recibo de {$record->employee->full_name} ha sido aprobado.")
                            ->send();
                    })
                    ->visible(fn (Payroll $record) => $record->status === 'draft' && auth()->user()->can('approve_payroll')),

                Action::make('mark_disbursed')
                    ->label('Marcar Acreditado')
                    ->icon('heroicon-o-building-library')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Marcar como Acreditado')
                    ->modalDescription(fn (Payroll $record) => "¿Confirma que el pago de {$record->employee->full_name} fue acreditado/entregado?")
                    ->modalSubmitActionLabel('Sí, acreditar')
                    ->action(function (Payroll $record) {
                        $result = $record->markAsDisbursed(Auth::id());

                        Notification::make()
                            ->{$result['success'] ? 'success' : 'danger'}()
                            ->title($result['success'] ? 'Recibo acreditado' : 'Error')
                            ->body($result['message'])
                            ->send();
                    })
                    ->visible(fn (Payroll $record) => $record->isApproved() && auth()->user()->can('disburse_payroll')),

                Action::make('mark_paid')
                    ->label('Marcar Pagado')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Marcar como Pagado')
                    ->modalDescription(fn (Payroll $record) => "¿Confirma que el banco acreditó el pago de {$record->employee->full_name}?")
                    ->modalSubmitActionLabel('Sí, marcar como pagado')
                    ->action(function (Payroll $record) {
                        $record->update(['status' => 'paid']);

                        Notification::make()
                            ->success()
                            ->title('Recibo marcado como pagado')
                            ->send();
                    })
                    ->visible(fn (Payroll $record) => $record->isDisbursed() && auth()->user()->can('mark_paid_payroll')),

                Action::make('revert_paid')
                    ->label('Revertir Pago')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Revertir Pago')
                    ->modalDescription(fn (Payroll $record) => "¿Está seguro de revertir el pago del recibo de {$record->employee->full_name}? Volverá a estado Acreditado.")
                    ->modalSubmitActionLabel('Sí, revertir')
                    ->action(function (Payroll $record) {
                        $record->update(['status' => 'disbursed']);

                        Notification::make()
                            ->success()
                            ->title('Pago revertido')
                            ->body("El recibo de {$record->employee->full_name} ha vuelto a estado Acreditado.")
                            ->send();
                    })
                    ->visible(fn (Payroll $record) => $record->isPaid() && auth()->user()->can('revert_payroll')),

                Action::make('revert_to_approved')
                    ->label('Revertir a Aprobado')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Revertir a Aprobado')
                    ->modalDescription(fn (Payroll $record) => "¿Está seguro de revertir el recibo de {$record->employee->full_name} a estado Aprobado?")
                    ->modalSubmitActionLabel('Sí, revertir')
                    ->action(function (Payroll $record) {
                        $result = $record->revertToApproved();

                        Notification::make()
                            ->{$result['success'] ? 'success' : 'danger'}()
                            ->title($result['success'] ? 'Recibo revertido' : 'No se pudo revertir')
                            ->body($result['message'])
                            ->send();
                    })
                    ->visible(fn (Payroll $record) => $record->isDisbursed() && $record->disbursement_batch_id === null && auth()->user()->can('revert_payroll')),

                Action::make('unapprove')
                    ->label('Desaprobar')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Desaprobar Recibo')
                    ->modalDescription(fn (Payroll $record) => "¿Está seguro de desaprobar el recibo de {$record->employee->full_name}? Volverá a estado Borrador.")
                    ->modalSubmitActionLabel('Sí, desaprobar')
                    ->action(function (Payroll $record) {
                        $record->update([
                            'status' => 'draft',
                            'approved_by_id' => null,
                            'approved_at' => null,
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Recibo desaprobado')
                            ->body("El recibo de {$record->employee->full_name} ha vuelto a estado Borrador.")
                            ->send();
                    })
                    ->visible(fn (Payroll $record) => $record->status === 'approved' && auth()->user()->can('revert_payroll')),

                Action::make('regenerate')
                    ->label('Regenerar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Regenerar Recibo')
                    ->modalDescription(fn (Payroll $record) => "Se recalcularán todos los ítems del recibo de {$record->employee->full_name}. Esta acción reemplazará los valores actuales.")
                    ->modalSubmitActionLabel('Sí, regenerar')
                    ->action(function (Payroll $record, PayrollService $payrollService) {
                        try {
                            $payrollService->regenerateForEmployee($record);

                            Notification::make()
                                ->success()
                                ->title('Recibo regenerado')
                                ->body("El recibo de {$record->employee->full_name} ha sido recalculado exitosamente.")
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error al regenerar')
                                ->body('Ocurrió un error al regenerar el recibo: '.$e->getMessage())
                                ->send();
                        }
                    })
                    ->visible(fn (Payroll $record) => $record->status === 'draft' && auth()->user()->can('regenerate_payroll')),

                DeleteAction::make()
                    ->visible(fn (Payroll $record) => $record->status === 'draft'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_selected')
                        ->label('Aprobar Seleccionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn () => auth()->user()->can('approve_payroll'))
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar Recibos Seleccionados')
                        ->modalDescription('¿Está seguro de aprobar todos los recibos seleccionados? Solo se aprobarán los que estén en estado "Borrador".')
                        ->modalSubmitActionLabel('Sí, aprobar')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'draft') {
                                    $record->update([
                                        'status' => 'approved',
                                        'approved_by_id' => Auth::id(),
                                        'approved_at' => now(),
                                    ]);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("{$count} recibos aprobados")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('mark_disbursed_selected')
                        ->label('Marcar Acreditados')
                        ->icon('heroicon-o-building-library')
                        ->color('info')
                        ->visible(fn () => auth()->user()->can('disburse_payroll'))
                        ->requiresConfirmation()
                        ->modalHeading('Marcar como Acreditados')
                        ->modalDescription('¿Confirma que los recibos seleccionados fueron acreditados/entregados? Solo se procesarán los que estén en estado "Aprobado".')
                        ->modalSubmitActionLabel('Sí, acreditar')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->isApproved()) {
                                    $record->markAsDisbursed(Auth::id());
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("{$count} recibos marcados como acreditados")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('mark_paid_selected')
                        ->label('Marcar Pagados')
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->visible(fn () => auth()->user()->can('mark_paid_payroll'))
                        ->requiresConfirmation()
                        ->modalHeading('Marcar como Pagados')
                        ->modalDescription('¿Confirma que los recibos seleccionados han sido pagados? Solo se marcarán los que estén en estado "Acreditado".')
                        ->modalSubmitActionLabel('Sí, marcar como pagados')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->isDisbursed()) {
                                    $record->update(['status' => 'paid']);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("{$count} recibos marcados como pagados")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('revert_paid_selected')
                        ->label('Revertir Pagos')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->visible(fn () => auth()->user()->can('revert_payroll'))
                        ->requiresConfirmation()
                        ->modalHeading('Revertir Pagos Seleccionados')
                        ->modalDescription('¿Está seguro? Solo se revertirán los recibos en estado "Pagado". Volverán a estado Acreditado.')
                        ->modalSubmitActionLabel('Sí, revertir')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->isPaid()) {
                                    $record->update(['status' => 'disbursed']);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("{$count} pagos revertidos a Acreditado")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('unapprove_selected')
                        ->label('Desaprobar Seleccionados')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->visible(fn () => auth()->user()->can('revert_payroll'))
                        ->requiresConfirmation()
                        ->modalHeading('Desaprobar Recibos Seleccionados')
                        ->modalDescription('¿Está seguro? Solo se desaprobarán los recibos en estado "Aprobado". Volverán a estado Borrador.')
                        ->modalSubmitActionLabel('Sí, desaprobar')
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'approved') {
                                    $record->update([
                                        'status' => 'draft',
                                        'approved_by_id' => null,
                                        'approved_at' => null,
                                    ]);
                                    $count++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("{$count} recibos desaprobados")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('download_pdfs')
                        ->label('Descargar PDFs')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('gray')
                        ->visible(fn () => auth()->user()->can('export_payroll'))
                        ->action(function (Collection $records, Component $livewire) {
                            $records->load('employee');
                            $validRecords = $records->filter(
                                fn (Payroll $r) => $r->pdf_path && Storage::disk('public')->exists($r->pdf_path)
                            );

                            if ($validRecords->isEmpty()) {
                                Notification::make()
                                    ->warning()
                                    ->title('Sin PDFs disponibles')
                                    ->body('Ninguno de los recibos seleccionados tiene PDF generado.')
                                    ->send();

                                return;
                            }

                            $tempDir = storage_path('app/public/temp');
                            if (! is_dir($tempDir)) {
                                mkdir($tempDir, 0755, true);
                            }

                            // Limpiar archivos temporales de más de 1 hora
                            foreach (glob($tempDir.'/*.{pdf,zip}', GLOB_BRACE) as $file) {
                                if (is_file($file) && (time() - filemtime($file)) > 3600) {
                                    @unlink($file);
                                }
                            }

                            $uniqueId = Str::uuid();

                            if ($validRecords->count() === 1) {
                                $record = $validRecords->first();
                                $filename = $uniqueId.'_recibo_'.$record->employee->ci.'.pdf';
                                copy(Storage::disk('public')->path($record->pdf_path), $tempDir.'/'.$filename);
                            } else {
                                $filename = $uniqueId.'_recibos_'.now()->format('d_m_Y_H_i_s').'.zip';
                                $zip = new \ZipArchive;
                                $zip->open($tempDir.'/'.$filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                                foreach ($validRecords as $record) {
                                    $zip->addFromString(
                                        'recibo_'.$record->employee->ci.'_'.$record->id.'.pdf',
                                        Storage::disk('public')->get($record->pdf_path)
                                    );
                                }
                                $zip->close();
                            }

                            $livewire->js("window.open('".route('payrolls.download.temp', ['filename' => $filename])."', '_blank')");

                            Notification::make()
                                ->success()
                                ->title('Descarga iniciada')
                                ->body('Los recibos se están descargando.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    ExportBulkAction::make()
                        ->visible(fn () => auth()->user()->can('export_payroll'))
                        ->exports([
                            ExcelExport::make()
                                ->fromTable()
                                ->withFilename(fn () => 'recibos_seleccionados_'.now()->format('d_m_Y_H_i_s'))
                                ->withWriterType(Excel::XLSX),
                        ]),

                    DeleteBulkAction::make()
                        ->action(function (Collection $records) {
                            $deleted = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'draft') {
                                    $record->delete();
                                    $deleted++;
                                }
                            }

                            if ($deleted > 0) {
                                Notification::make()
                                    ->success()
                                    ->title("{$deleted} recibos eliminados")
                                    ->send();
                            } else {
                                Notification::make()
                                    ->warning()
                                    ->title('Solo se pueden eliminar recibos en borrador')
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No hay recibos de nómina')
            ->emptyStateDescription('Los recibos se generan automáticamente desde los períodos de nómina.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Información del Empleado')
                    ->schema([
                        Group::make([
                            TextEntry::make('employee.ci')
                                ->label('Cédula de Identidad')
                                ->icon('heroicon-o-identification')
                                ->copyable(),

                            TextEntry::make('employee.full_name')
                                ->label('Nombre Completo'),
                        ])->columns(2),

                        Group::make([
                            TextEntry::make('employee.activeContract.position.name')
                                ->label('Cargo')
                                ->icon('heroicon-o-briefcase')
                                ->badge()
                                ->color('info'),

                            TextEntry::make('employee.activeContract.position.department.name')
                                ->label('Departamento')
                                ->icon('heroicon-o-building-office-2')
                                ->badge()
                                ->color('primary'),

                            TextEntry::make('employee.employment_type')
                                ->label('Tipo de Remuneración')
                                ->formatStateUsing(fn (string $state): string => match ($state) {
                                    'day_laborer' => 'Jornalero (Jornal Diario)',
                                    default => 'Mensualizado (Sueldo)',
                                })
                                ->icon(fn (string $state): string => match ($state) {
                                    'day_laborer' => 'heroicon-o-calendar-days',
                                    default => 'heroicon-o-banknotes',
                                })
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'day_laborer' => 'warning',
                                    default => 'info',
                                }),
                        ])->columns(3),
                    ])
                    ->collapsible(),

                InfolistSection::make('Información del Período')
                    ->schema([
                        Group::make([
                            TextEntry::make('period.name')
                                ->label('Período')
                                ->icon('heroicon-o-calendar-days')
                                ->badge()
                                ->color('info'),

                            TextEntry::make('period.frequency')
                                ->label('Frecuencia')
                                ->formatStateUsing(fn (string $state): string => match ($state) {
                                    'monthly' => 'Mensual',
                                    'biweekly' => 'Quincenal',
                                    'weekly' => 'Semanal',
                                    default => $state,
                                })
                                ->badge(),
                        ])->columns(2),

                        Group::make([
                            TextEntry::make('period.start_date')
                                ->label('Fecha Inicio')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar'),

                            TextEntry::make('period.end_date')
                                ->label('Fecha Fin')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar'),
                        ])->columns(2),
                    ])
                    ->collapsible(),

                InfolistSection::make('Detalle de Nómina')
                    ->schema([
                        Group::make([
                            TextEntry::make('base_salary')
                                ->label(fn (Payroll $record): string => $record->employee->employment_type === 'day_laborer'
                                    ? 'Jornal del Período'
                                    : 'Salario Base')
                                ->money('PYG', locale: 'es_PY')
                                ->icon('heroicon-o-banknotes'),

                            TextEntry::make('total_perceptions')
                                ->label('Total Percepciones')
                                ->money('PYG', locale: 'es_PY')
                                ->color('success')
                                ->icon('heroicon-o-plus-circle'),
                        ])->columns(2),

                        Group::make([
                            TextEntry::make('gross_salary')
                                ->label('Salario Bruto')
                                ->money('PYG', locale: 'es_PY')
                                ->weight('bold'),

                            TextEntry::make('total_deductions')
                                ->label('Total Deducciones')
                                ->money('PYG', locale: 'es_PY')
                                ->color('danger')
                                ->icon('heroicon-o-minus-circle'),
                        ])->columns(2),

                        TextEntry::make('net_salary')
                            ->label('Salario Neto a Pagar')
                            ->money('PYG', locale: 'es_PY')
                            ->size('lg')
                            ->weight('bold')
                            ->color('success')
                            ->icon('heroicon-o-currency-dollar'),
                    ])
                    ->collapsible(),

                InfolistSection::make('Estado y Aprobación')
                    ->schema([
                        Group::make([
                            TextEntry::make('status')
                                ->label('Estado')
                                ->badge()
                                ->color(fn ($state) => Payroll::getStatusColors()[$state] ?? 'gray')
                                ->formatStateUsing(fn ($state) => Payroll::getStatusLabels()[$state] ?? $state),

                            TextEntry::make('payment_method')
                                ->label('Método de pago')
                                ->badge()
                                ->color(fn (?string $state) => Payroll::getPaymentMethodColors()[$state] ?? 'gray')
                                ->icon(fn (?string $state) => Payroll::getPaymentMethodIcons()[$state] ?? null)
                                ->formatStateUsing(fn (?string $state) => Payroll::getPaymentMethodLabels()[$state] ?? '—'),

                            TextEntry::make('approvedBy.name')
                                ->label('Aprobado por')
                                ->placeholder('Sin aprobar')
                                ->icon('heroicon-o-user'),

                            TextEntry::make('approved_at')
                                ->label('Fecha de Aprobación')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Sin aprobar')
                                ->icon('heroicon-o-clock'),
                        ])->columns(4),
                    ])
                    ->collapsible(),

                InfolistSection::make('Información del Sistema')
                    ->schema([
                        Group::make([
                            TextEntry::make('generated_at')
                                ->label('Generado')
                                ->dateTime('d/m/Y H:i')
                                ->icon('heroicon-o-clock'),

                            TextEntry::make('updated_at')
                                ->label('Actualizado')
                                ->dateTime('d/m/Y H:i'),
                        ])->columns(2),
                    ])
                    ->collapsible(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
            RelationManagers\AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayrolls::route('/'),
            'view' => Pages\ViewPayroll::route('/{record}'),
        ];
    }
}
