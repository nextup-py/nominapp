<?php

namespace App\Filament\Resources\AguinaldoPeriodResource\RelationManagers;

use App\Filament\Resources\AguinaldoResource;
use App\Models\Aguinaldo;
use App\Services\AguinaldoService;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AguinaldosRelationManager extends RelationManager
{
    protected static string $relationship = 'aguinaldos';

    protected static ?string $title = 'Aguinaldos Generados';

    protected static ?string $modelLabel = 'aguinaldo';

    protected static ?string $pluralModelLabel = 'aguinaldos';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /**
     * Configura la tabla para mostrar los aguinaldos relacionados al período.
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Aguinaldo $record): string => "Aguinaldo de {$record->employee->full_name}")
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['employee.activeContract.position']))
            ->columns([
                ImageColumn::make('employee.photo')
                    ->label('Foto')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => $record->employee->avatar_url)
                    ->toggleable(),

                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->sortable()
                    ->wrap(),

                TextColumn::make('employee.ci')
                    ->label('CI')
                    ->icon('heroicon-o-identification')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->tooltip('Haz clic para copiar')
                    ->copyMessage('CI copiado al portapapeles')
                    ->toggleable(),

                TextColumn::make('employee.activeContract.position.name')
                    ->label('Cargo')
                    ->icon('heroicon-o-briefcase')
                    ->badge()
                    ->color('primary')
                    ->default('-')
                    ->toggleable(),

                TextColumn::make('months_worked')
                    ->label('Meses')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => number_format($state, 0))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_earned')
                    ->label('Total Devengado')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->toggleable()
                    ->summarize([
                        Sum::make()
                            ->money('PYG', locale: 'es_PY')
                            ->label('Total'),
                    ])
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('aguinaldo_amount')
                    ->label('Aguinaldo')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->weight('bold')
                    ->color('success')
                    ->summarize([
                        Sum::make()
                            ->money('PYG', locale: 'es_PY')
                            ->label('Total a Pagar'),
                    ]),

                TextColumn::make('payment_method')
                    ->label('Método de pago')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Aguinaldo::getPaymentMethodLabel($state))
                    ->color(fn (string $state) => Aguinaldo::getPaymentMethodColor($state))
                    ->icon(fn (string $state) => Aguinaldo::getPaymentMethodIcon($state))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Aguinaldo::getStatusLabel($state))
                    ->color(fn (string $state) => Aguinaldo::getStatusColor($state))
                    ->icon(fn (string $state) => Aguinaldo::getStatusIcon($state))
                    ->sortable(),

                TextColumn::make('paid_at')
                    ->label('Pagado')
                    ->date('d/m/Y')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('generated_at')
                    ->label('Generado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Aguinaldo::getStatusOptions())
                    ->native(false),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                    ->searchable(['first_name', 'last_name'])
                    ->native(false),
            ])
            ->actions([
                ViewAction::make()
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Aguinaldo $record) => AguinaldoResource::getUrl('view', ['record' => $record])),

                Action::make('change_payment_method')
                    ->label('Cambiar método de pago')
                    ->icon('heroicon-o-credit-card')
                    ->color('gray')
                    ->modalHeading('Cambiar método de pago')
                    ->modalSubmitActionLabel('Guardar')
                    ->fillForm(fn (Aguinaldo $record) => ['payment_method' => $record->payment_method])
                    ->form([
                        Select::make('payment_method')
                            ->label('Método de pago')
                            ->options(Aguinaldo::getPaymentMethodOptions())
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (Aguinaldo $record, array $data) {
                        $record->update(['payment_method' => $data['payment_method']]);

                        Notification::make()
                            ->success()
                            ->title('Método de pago actualizado')
                            ->body("Se actualizó el método de pago de {$record->employee->full_name} a ".Aguinaldo::getPaymentMethodLabel($data['payment_method']).'.')
                            ->send();
                    })
                    ->visible(fn (Aguinaldo $record) => $record->isPending() && is_null($record->disbursement_batch_id) && $this->getOwnerRecord()->isProcessing()),

                Action::make('mark_paid')
                    ->label('Marcar Pagado')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('¿Marcar Aguinaldo como Pagado?')
                    ->modalDescription(fn (Aguinaldo $record) => "¿Confirmar pago de {$record->employee->full_name} por ".Aguinaldo::formatCurrency($record->aguinaldo_amount).'?')
                    ->modalSubmitActionLabel('Sí, marcar como pagado')
                    ->action(function (Aguinaldo $record) {
                        $record->markAsPaid();

                        Notification::make()
                            ->success()
                            ->title('Aguinaldo marcado como pagado')
                            ->body("El aguinaldo de {$record->employee->full_name} por ".Aguinaldo::formatCurrency($record->aguinaldo_amount).' ha sido marcado como pagado.')
                            ->send();
                    })
                    ->visible(fn (Aguinaldo $record) => $record->isPending() && $this->getOwnerRecord()->isProcessing() && auth()->user()->can('mark_paid_aguinaldo')),

                Action::make('unmark_paid')
                    ->label('Marcar Pendiente')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('¿Marcar Aguinaldo como Pendiente?')
                    ->modalDescription(fn (Aguinaldo $record) => "Se revertirá el pago del aguinaldo de {$record->employee->full_name} por ".Aguinaldo::formatCurrency($record->aguinaldo_amount).' y volverá a estado Pendiente.')
                    ->modalSubmitActionLabel('Sí, marcar como pendiente')
                    ->action(function (Aguinaldo $record) {
                        $record->markAsPending();

                        Notification::make()
                            ->warning()
                            ->title('Pago revertido')
                            ->body("El aguinaldo de {$record->employee->full_name} volvió a estado Pendiente.")
                            ->send();
                    })
                    ->visible(fn (Aguinaldo $record) => $record->isPaid() && $this->getOwnerRecord()->isProcessing() && auth()->user()->can('mark_paid_aguinaldo')),

                Action::make('regenerate')
                    ->label('Regenerar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('¿Regenerar Aguinaldo?')
                    ->modalDescription('Se recalcularán los valores del aguinaldo para este empleado según los datos actuales. Esta acción no afectará el estado de pago actual.')
                    ->modalSubmitActionLabel('Sí, regenerar')
                    ->action(function (Aguinaldo $record, AguinaldoService $aguinaldoService) {
                        try {
                            $aguinaldoService->regenerateForEmployee($record);

                            Notification::make()
                                ->success()
                                ->title('Aguinaldo regenerado')
                                ->body("El aguinaldo de {$record->employee->full_name} ha sido regenerado exitosamente.")
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error al regenerar')
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->visible(fn () => $this->getOwnerRecord()->isProcessing()),

                Action::make('download_pdf')
                    ->label('Descargar PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->url(fn (Aguinaldo $record) => route('aguinaldos.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Aguinaldo $record) => (bool) $record->pdf_path),

                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('¿Eliminar Aguinaldo?')
                    ->modalDescription(fn (Aguinaldo $record) => "¿Confirma que desea eliminar el aguinaldo de {$record->employee->full_name} por ".Aguinaldo::formatCurrency($record->aguinaldo_amount).'? Esta acción no se puede deshacer.')
                    ->modalSubmitActionLabel('Sí, eliminar')
                    ->visible(fn () => $this->getOwnerRecord()->isProcessing())
                    ->successNotificationTitle('Aguinaldo eliminado exitosamente'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_change_payment_method')
                        ->label('Cambiar método de pago')
                        ->icon('heroicon-o-credit-card')
                        ->color('gray')
                        ->modalHeading('Cambiar método de pago')
                        ->modalDescription('Solo se actualizarán los aguinaldos pendientes sin lote asignado.')
                        ->modalSubmitActionLabel('Guardar')
                        ->visible(fn () => $this->getOwnerRecord()->isProcessing())
                        ->form([
                            Select::make('payment_method')
                                ->label('Método de pago')
                                ->options(Aguinaldo::getPaymentMethodOptions())
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $count = $records
                                ->filter(fn ($r) => $r->isPending() && is_null($r->disbursement_batch_id))
                                ->each->update(['payment_method' => $data['payment_method']])
                                ->count();

                            Notification::make()
                                ->success()
                                ->title('Método de pago actualizado')
                                ->body("Se actualizaron {$count} aguinaldo(s) a ".Aguinaldo::getPaymentMethodLabel($data['payment_method']).'.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulk_mark_paid')
                        ->label('Marcar como Pagados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn () => $this->getOwnerRecord()->isProcessing() && auth()->user()->can('mark_paid_aguinaldo'))
                        ->action(function (Collection $records) {
                            $count = $records->filter->isPending()->each->markAsPaid()->count();

                            Notification::make()
                                ->success()
                                ->title('Aguinaldos marcados como pagados')
                                ->body('Se han marcado '.($count === 1 ? '1 aguinaldo' : "{$count} aguinaldos").' como pagados exitosamente.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulk_unmark_paid')
                        ->label('Marcar como Pendientes')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('¿Marcar como Pendientes?')
                        ->modalDescription('Se revertirá el pago de los aguinaldos pagados seleccionados y volverán a estado Pendiente.')
                        ->modalSubmitActionLabel('Sí, marcar como pendientes')
                        ->visible(fn () => $this->getOwnerRecord()->isProcessing() && auth()->user()->can('mark_paid_aguinaldo'))
                        ->action(function (Collection $records) {
                            $count = $records->filter->isPaid()->each->markAsPending()->count();

                            Notification::make()
                                ->warning()
                                ->title('Aguinaldos revertidos a Pendiente')
                                ->body('Se han revertido '.($count === 1 ? '1 aguinaldo' : "{$count} aguinaldos").' a estado Pendiente.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulk_regenerate')
                        ->label('Regenerar')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('¿Regenerar Aguinaldos?')
                        ->modalDescription('Se recalcularán los valores de los aguinaldos seleccionados según los datos actuales. No afectará el estado de pago.')
                        ->modalSubmitActionLabel('Sí, regenerar')
                        ->visible(fn () => $this->getOwnerRecord()->isProcessing())
                        ->action(function (Collection $records, AguinaldoService $aguinaldoService) {
                            $success = 0;
                            $errors = 0;

                            foreach ($records as $record) {
                                try {
                                    $aguinaldoService->regenerateForEmployee($record);
                                    $success++;
                                } catch (\Throwable) {
                                    $errors++;
                                }
                            }

                            if ($success > 0) {
                                Notification::make()
                                    ->success()
                                    ->title('Aguinaldos regenerados')
                                    ->body('Se regeneraron '.($success === 1 ? '1 aguinaldo' : "{$success} aguinaldos").' exitosamente.'.($errors > 0 ? " {$errors} fallaron." : ''))
                                    ->send();
                            }

                            if ($errors > 0 && $success === 0) {
                                Notification::make()
                                    ->danger()
                                    ->title('Error al regenerar')
                                    ->body('No se pudo regenerar ningún aguinaldo.')
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulk_delete')
                        ->label('Eliminar Aguinaldos')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('¿Eliminar Aguinaldos?')
                        ->modalDescription('Se eliminarán los aguinaldos seleccionados. Esta acción no se puede deshacer.')
                        ->modalSubmitActionLabel('Sí, eliminar')
                        ->visible(fn () => $this->getOwnerRecord()->isProcessing())
                        ->action(function (Collection $records) {
                            $count = $records->count();
                            $records->each->delete();

                            Notification::make()
                                ->success()
                                ->title('Aguinaldos eliminados')
                                ->body('Se han eliminado '.($count === 1 ? '1 aguinaldo' : "{$count} aguinaldos").' exitosamente.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading('No hay aguinaldos generados')
            ->emptyStateDescription('Los aguinaldos aparecerán aquí una vez que se generen desde el período.')
            ->emptyStateIcon('heroicon-o-gift');
    }

    /**
     * Configura la infolist para mostrar detalles adicionales de cada aguinaldo cuando se visualiza un registro.
     */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Información del Empleado')
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
                                ->color('info')
                                ->placeholder('-'),

                            TextEntry::make('employee.activeContract.position.department.name')
                                ->label('Departamento')
                                ->icon('heroicon-o-building-office-2')
                                ->badge()
                                ->color('primary')
                                ->placeholder('-'),
                        ])->columns(2),
                    ]),

                Section::make('Cálculo del Aguinaldo')
                    ->schema([
                        Group::make([
                            TextEntry::make('total_earned')
                                ->label('Total Devengado en el Año')
                                ->money('PYG', locale: 'es_PY')
                                ->icon('heroicon-o-banknotes'),

                            TextEntry::make('months_worked')
                                ->label('Meses Trabajados')
                                ->formatStateUsing(fn ($state) => number_format($state, 0).' meses')
                                ->icon('heroicon-o-calendar'),
                        ])->columns(2),

                        Group::make([
                            TextEntry::make('aguinaldo_amount')
                                ->label('Aguinaldo a Pagar (1/12 del total)')
                                ->money('PYG', locale: 'es_PY')
                                ->size('lg')
                                ->weight('bold')
                                ->color('success')
                                ->icon('heroicon-o-gift'),

                            TextEntry::make('status')
                                ->label('Estado de Pago')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => Aguinaldo::getStatusLabel($state))
                                ->color(fn (string $state) => Aguinaldo::getStatusColor($state))
                                ->icon(fn (string $state) => Aguinaldo::getStatusIcon($state)),

                            TextEntry::make('paid_at')
                                ->label('Fecha de Pago')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Pendiente'),
                        ])->columns(3),
                    ]),

                Section::make('Información Adicional')
                    ->schema([
                        TextEntry::make('generated_at')
                            ->label('Fecha de Generación')
                            ->dateTime('d/m/Y H:i')
                            ->icon('heroicon-o-clock'),

                        TextEntry::make('pdf_path')
                            ->label('PDF Generado')
                            ->formatStateUsing(fn ($state) => $state ? 'Disponible' : 'No generado')
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'gray')
                            ->icon(fn ($state) => $state ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'),
                    ])
                    ->columns(2)
                    ->collapsed(),
            ]);
    }
}
