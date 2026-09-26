<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AguinaldoResource\Pages;
use App\Filament\Resources\AguinaldoResource\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\AguinaldoResource\RelationManagers\ItemsRelationManager;
use App\Filament\Traits\HasModuleAccess;
use App\Models\Aguinaldo;
use App\Models\AguinaldoPeriod;
use App\Models\Company;
use App\Services\AguinaldoService;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class AguinaldoResource extends Resource
{
    use HasModuleAccess;

    protected static string $moduleFlag = 'aguinaldo_enabled';

    protected static ?string $model = Aguinaldo::class;

    protected static ?string $navigationGroup = 'Nóminas';

    protected static ?string $navigationLabel = 'Recibos Aguinaldo';

    protected static ?string $label = 'Recibo de Aguinaldo';

    protected static ?string $pluralLabel = 'Recibos de Aguinaldo';

    protected static ?string $slug = 'aguinaldo-recibos';

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 10;

    /**
     * Siempre oculto de la navegación: es un recurso "hijo" accesible solo
     * desde AguinaldoPeriodResource, sin importar el estado de
     * `aguinaldo_enabled`. HasModuleAccess aporta shouldRegisterNavigation()
     * como método, que de otro modo pisaría la property estática de arriba
     * — este override explícito blinda el comportamiento actual.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Define la tabla de listados para los recibos de aguinaldo, incluyendo columnas, filtros, acciones y estado vacío.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['employee', 'period']))
            ->columns([
                ImageColumn::make('employee.photo')
                    ->label('Foto')
                    ->circular()
                    ->defaultImageUrl(fn ($record) => $record->employee?->avatar_url)
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

                TextColumn::make('period.company.name')
                    ->label('Empresa')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('period.year')
                    ->label('Año')
                    ->sortable()
                    ->badge()
                    ->color('info')
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
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->summarize([
                        Sum::make()
                            ->money('PYG', locale: 'es_PY')
                            ->label('Total'),
                    ]),

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
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('generated_at')
                    ->label('Generado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company')
                    ->label('Empresa')
                    ->options(fn () => Company::active()->get()->mapWithKeys(
                        fn ($c) => [$c->id => $c->name.($c->trade_name ? ' ('.$c->trade_name.')' : '')]
                    ))
                    ->query(function ($query, array $data) {
                        if (filled($data['value'])) {
                            return $query->whereHas('period', fn ($q) => $q->where('company_id', $data['value']));
                        }
                    })
                    ->searchable()
                    ->native(false),

                SelectFilter::make('aguinaldo_period_id')
                    ->label('Período')
                    ->relationship('period', 'year')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->year} - ".($record->company?->name ?? '—'))
                    ->searchable()
                    ->native(false),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->searchable(['first_name', 'last_name'])
                    ->native(false)
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name),

                SelectFilter::make('payment_method')
                    ->label('Método de pago')
                    ->options(Aguinaldo::getPaymentMethodOptions())
                    ->native(false),
            ])
            ->actions([
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
                            ->body('Se actualizó el método de pago de '.($record->employee?->full_name ?? 'empleado eliminado').' a '.Aguinaldo::getPaymentMethodLabel($data['payment_method']).'.')
                            ->send();
                    })
                    ->visible(fn (Aguinaldo $record) => $record->isPending() && is_null($record->disbursement_batch_id) && $record->period?->isProcessing()),

                Action::make('mark_paid')
                    ->label('Marcar Pagado')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('¿Marcar como Pagado?')
                    ->modalDescription(fn (Aguinaldo $record) => '¿Confirmar pago del aguinaldo de '.($record->employee?->full_name ?? 'empleado eliminado').' por '.Aguinaldo::formatCurrency($record->aguinaldo_amount).'?')
                    ->modalSubmitActionLabel('Sí, marcar como pagado')
                    ->action(function (Aguinaldo $record) {
                        $record->markAsPaid();

                        Notification::make()
                            ->success()
                            ->title('Aguinaldo marcado como pagado')
                            ->body('El aguinaldo de '.($record->employee?->full_name ?? 'empleado eliminado').' ha sido marcado como pagado.')
                            ->send();
                    })
                    ->visible(fn (Aguinaldo $record) => $record->isPending() && $record->period?->isProcessing() && auth()->user()->can('mark_paid_aguinaldo')),

                Action::make('unmark_paid')
                    ->label('Marcar Pendiente')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('¿Marcar como Pendiente?')
                    ->modalDescription(fn (Aguinaldo $record) => 'Se revertirá el pago del aguinaldo de '.($record->employee?->full_name ?? 'empleado eliminado').' por '.Aguinaldo::formatCurrency($record->aguinaldo_amount).' y volverá a estado Pendiente.')
                    ->modalSubmitActionLabel('Sí, marcar como pendiente')
                    ->action(function (Aguinaldo $record) {
                        $record->markAsPending();

                        Notification::make()
                            ->warning()
                            ->title('Pago revertido')
                            ->body('El aguinaldo de '.($record->employee?->full_name ?? 'empleado eliminado').' volvió a estado Pendiente.')
                            ->send();
                    })
                    ->visible(fn (Aguinaldo $record) => $record->isPaid() && $record->period?->isProcessing() && auth()->user()->can('mark_paid_aguinaldo')),

                Action::make('regenerate')
                    ->label('Regenerar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('¿Regenerar Aguinaldo?')
                    ->modalDescription(fn (Aguinaldo $record) => 'Se recalcularán los valores del aguinaldo de '.($record->employee?->full_name ?? 'empleado eliminado').'. ¿Deseas continuar?')
                    ->modalSubmitActionLabel('Sí, regenerar')
                    ->action(function (Aguinaldo $record, AguinaldoService $aguinaldoService) {
                        try {
                            $aguinaldoService->regenerateForEmployee($record);

                            Notification::make()
                                ->success()
                                ->title('Aguinaldo regenerado')
                                ->body('El aguinaldo de '.($record->employee?->full_name ?? 'empleado eliminado').' ha sido recalculado exitosamente.')
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error al regenerar')
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->visible(fn (Aguinaldo $record) => $record->period?->isProcessing()),

                Action::make('download_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->url(fn (Aguinaldo $record) => route('aguinaldos.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Aguinaldo $record) => (bool) $record->pdf_path),
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
                        ->form([
                            Select::make('payment_method')
                                ->label('Método de pago')
                                ->options(Aguinaldo::getPaymentMethodOptions())
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $count = $records
                                ->filter(fn ($r) => $r->isPending() && is_null($r->disbursement_batch_id) && $r->period?->isProcessing())
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
                        ->modalHeading('¿Marcar seleccionados como Pagados?')
                        ->modalDescription('Se marcarán como pagados los aguinaldos pendientes seleccionados cuyo período esté en proceso.')
                        ->modalSubmitActionLabel('Sí, marcar como pagados')
                        ->action(function (Collection $records) {
                            $count = $records
                                ->filter(fn ($r) => $r->isPending() && $r->period->isProcessing())
                                ->each->markAsPaid()
                                ->count();

                            Notification::make()
                                ->success()
                                ->title('Aguinaldos marcados como pagados')
                                ->body("{$count} aguinaldo(s) han sido marcados como pagados.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn () => auth()->user()->can('mark_paid_aguinaldo')),

                    BulkAction::make('bulk_unmark_paid')
                        ->label('Marcar como Pendientes')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('¿Marcar seleccionados como Pendientes?')
                        ->modalDescription('Se revertirá el pago de los aguinaldos pagados seleccionados cuyo período esté en proceso.')
                        ->modalSubmitActionLabel('Sí, marcar como pendientes')
                        ->action(function (Collection $records) {
                            $count = $records
                                ->filter(fn ($r) => $r->isPaid() && $r->period->isProcessing())
                                ->each->markAsPending()
                                ->count();

                            Notification::make()
                                ->warning()
                                ->title('Aguinaldos revertidos a Pendiente')
                                ->body("{$count} aguinaldo(s) han sido revertidos a Pendiente.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn () => auth()->user()->can('mark_paid_aguinaldo')),

                    BulkAction::make('bulk_regenerate')
                        ->label('Regenerar')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('¿Regenerar seleccionados?')
                        ->modalDescription('Se recalcularán los valores de los aguinaldos seleccionados cuyo período esté en proceso.')
                        ->modalSubmitActionLabel('Sí, regenerar')
                        ->action(function (Collection $records, AguinaldoService $aguinaldoService) {
                            $success = 0;
                            $errors = 0;

                            foreach ($records->filter(fn ($r) => $r->period->isProcessing()) as $record) {
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
                                    ->title('Regeneración completada')
                                    ->body("{$success} aguinaldo(s) han sido regenerados exitosamente.")
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
                        ->label('Eliminar')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('¿Eliminar aguinaldos seleccionados?')
                        ->modalDescription('Se eliminarán los aguinaldos seleccionados cuyo período esté en proceso. Esta acción no se puede deshacer.')
                        ->modalSubmitActionLabel('Sí, eliminar')
                        ->action(function (Collection $records) {
                            $count = $records
                                ->filter(fn ($r) => $r->period->isProcessing())
                                ->each->delete()
                                ->count();

                            Notification::make()
                                ->success()
                                ->title('Aguinaldos eliminados')
                                ->body("{$count} aguinaldo(s) han sido eliminados.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No hay recibos de aguinaldo')
            ->emptyStateDescription('Los recibos de aguinaldo se generan automáticamente desde los períodos de aguinaldo.')
            ->emptyStateIcon('heroicon-o-gift');
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

                InfolistSection::make('Información del Período')
                    ->schema([
                        Group::make([
                            TextEntry::make('period.company.name')
                                ->label('Empresa')
                                ->icon('heroicon-o-building-office'),

                            TextEntry::make('period.year')
                                ->label('Año Fiscal')
                                ->badge()
                                ->color('info'),

                            TextEntry::make('period.status')
                                ->label('Estado del Período')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => AguinaldoPeriod::getStatusLabel($state))
                                ->color(fn (string $state) => AguinaldoPeriod::getStatusColor($state)),
                        ])->columns(3),
                    ]),

                InfolistSection::make('Cálculo del Aguinaldo')
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
                                ->label('Aguinaldo a Pagar (Total / 12)')
                                ->money('PYG', locale: 'es_PY')
                                ->size('lg')
                                ->weight('bold')
                                ->color('success')
                                ->icon('heroicon-o-gift'),

                            TextEntry::make('payment_method')
                                ->label('Método de pago')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => Aguinaldo::getPaymentMethodLabel($state))
                                ->color(fn (string $state) => Aguinaldo::getPaymentMethodColor($state))
                                ->icon(fn (string $state) => Aguinaldo::getPaymentMethodIcon($state)),

                            TextEntry::make('status')
                                ->label('Estado de Pago')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => Aguinaldo::getStatusLabel($state))
                                ->color(fn (string $state) => Aguinaldo::getStatusColor($state))
                                ->icon(fn (string $state) => Aguinaldo::getStatusIcon($state)),

                            TextEntry::make('paid_at')
                                ->label('Fecha de Pago')
                                ->dateTime('d/m/Y H:i')
                                ->icon('heroicon-o-check-circle')
                                ->placeholder('Pendiente'),
                        ])->columns(4),
                    ]),

                InfolistSection::make('Información del Sistema')
                    ->schema([
                        Group::make([
                            TextEntry::make('generated_at')
                                ->label('Fecha de Generación')
                                ->dateTime('d/m/Y H:i')
                                ->icon('heroicon-o-clock'),

                            TextEntry::make('pdf_path')
                                ->label('PDF')
                                ->formatStateUsing(fn ($state) => $state ? 'Disponible' : 'No generado')
                                ->badge()
                                ->color(fn ($state) => $state ? 'success' : 'gray')
                                ->icon(fn ($state) => $state ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'),
                        ])->columns(2),
                    ])
                    ->collapsed(),
            ]);
    }

    /**
     * Define las relaciones para el recurso de Aguinaldo, incluyendo el gestor de relación para los ítems del aguinaldo.
     */
    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    /**
     * Define las páginas para el recurso de Aguinaldo, incluyendo la página de listado y la página de vista detallada.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAguinaldos::route('/'),
            'view' => Pages\ViewAguinaldo::route('/{record}'),
        ];
    }

    /**
     * Deshabilita la creación manual de recibos de aguinaldo desde el panel de administración, ya que estos se generan automáticamente desde los períodos de aguinaldo.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Define la insignia de navegación para el recurso de Aguinaldo.
     */
    public static function getNavigationBadge(): ?string
    {
        $pendingCount = Aguinaldo::query()
            ->where('status', 'pending')
            ->count();

        return $pendingCount > 0 ? (string) $pendingCount : null;
    }

    /**
     * Define el color de la insignia de navegación para el recurso de Aguinaldo.
     */
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Define el tooltip de la insignia de navegación para el recurso de Aguinaldo.
     */
    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Aguinaldos pendientes de revisión';
    }
}
