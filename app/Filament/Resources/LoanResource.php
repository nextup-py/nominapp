<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanResource\Pages;
use App\Filament\Resources\LoanResource\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\LoanResource\RelationManagers\InstallmentsRelationManager;
use App\Models\Loan;
use App\Settings\PayrollSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static ?string $navigationLabel = 'Préstamos';

    protected static ?string $label = 'préstamo';

    protected static ?string $pluralLabel = 'préstamos';

    protected static ?string $slug = 'prestamos';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Créditos';

    protected static ?int $navigationSort = 1;

    /**
     * Define el formulario de creación/edición de préstamos.
     */
    public static function form(Form $form): Form
    {
        $payrollSettings = app(PayrollSettings::class);
        $maxLoanAmount = $payrollSettings->max_loan_amount;
        $maxInstallments = $payrollSettings->loan_max_installments;
        $maxInterestRate = $payrollSettings->loan_max_interest_rate;
        $defaultFirstInstallmentDays = $payrollSettings->loan_first_installment_days;

        return $form
            ->schema([
                Section::make('Información General')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Empleado')
                            ->relationship(
                                name: 'employee',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->where('status', 'active')
                                    ->orderBy('first_name')
                                    ->orderBy('last_name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->full_name_with_ci)
                            ->searchable(['first_name', 'last_name', 'ci'])
                            ->native(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('amount', null);
                                $set('installment_amount', null);
                            })
                            ->disabled(fn (string $operation) => $operation === 'edit')
                            ->helperText('Solo se muestran empleados activos.'),

                        Select::make('reason')
                            ->label('Motivo')
                            ->options([
                                'personal' => 'Personal',
                                'medical' => 'Médico',
                                'education' => 'Educación',
                                'other' => 'Otro',
                            ])
                            ->default('personal')
                            ->native(false)
                            ->placeholder('Seleccione un motivo')
                            ->required(),

                        Select::make('payment_method')
                            ->label('Método de pago')
                            ->options([
                                'cash' => 'Efectivo',
                                'transfer' => 'Transferencia bancaria',
                            ])
                            ->default('cash')
                            ->native(false)
                            ->required()
                            ->helperText('Transferencia: el préstamo se incluye en un lote bancario.'),

                        TextInput::make('amount')
                            ->label('Monto Total')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue($maxLoanAmount)
                            ->prefix('Gs.')
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                $installments = (int) $get('installments_count');
                                $rate = (float) $get('interest_rate');
                                if ($state && $installments) {
                                    $set('installment_amount', Loan::computePmt((float) $state, $installments, $rate));
                                }
                            })
                            ->helperText('Máximo: '.number_format($maxLoanAmount, 0, ',', '.').' Gs.')
                            ->disabled(fn (string $operation) => $operation === 'edit'),

                        TextInput::make('installments_count')
                            ->label('Cantidad de Cuotas')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue($maxInstallments)
                            ->default(12)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                $amount = (float) $get('amount');
                                $rate = (float) $get('interest_rate');
                                if ($amount && $state) {
                                    $set('installment_amount', Loan::computePmt($amount, (int) $state, $rate));
                                }
                            })
                            ->disabled(fn (string $operation) => $operation === 'edit'),

                        TextInput::make('interest_rate')
                            ->label('Tasa de Interés Anual (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue($maxInterestRate)
                            ->default(0)
                            ->suffix('%')
                            ->step(0.01)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                $amount = (float) $get('amount');
                                $installments = (int) $get('installments_count');
                                if ($amount && $installments) {
                                    $set('installment_amount', Loan::computePmt($amount, $installments, (float) $state));
                                }
                            })
                            ->helperText('Ingrese 0 para préstamos sin interés. Máximo: '.$maxInterestRate.'%')
                            ->disabled(fn (string $operation) => $operation === 'edit'),

                        TextInput::make('installment_amount')
                            ->label('Cuota Estimada (PMT)')
                            ->numeric()
                            ->prefix('Gs.')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Calculada al aprobar el préstamo (PMT con interés).'),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('first_installment_days')
                                    ->label('Días hasta primera cuota')
                                    ->numeric()
                                    ->required()
                                    ->minValue(1)
                                    ->maxValue(365)
                                    ->default($defaultFirstInstallmentDays)
                                    ->suffix('días')
                                    ->helperText('Días desde la aprobación hasta el vencimiento de la primera cuota.')
                                    ->disabled(fn (string $operation) => $operation === 'edit'),

                                Textarea::make('notes')
                                    ->label('Notas')
                                    ->placeholder('Notas adicionales...')
                                    ->rows(1),
                            ]),
                    ])
                    ->columns(3),

                Section::make('Estado y Aprobación')
                    ->schema([
                        Select::make('status')
                            ->label('Estado')
                            ->options(Loan::getStatusOptions())
                            ->required()
                            ->native(false)
                            ->default('pending')
                            ->disabled(),

                        DatePicker::make('granted_at')
                            ->label('Fecha de Otorgamiento')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->disabled(),

                        Select::make('granted_by_id')
                            ->label('Otorgado por')
                            ->relationship('grantedBy', 'name')
                            ->native(false)
                            ->disabled(),

                        Placeholder::make('progress')
                            ->label('Progreso')
                            ->content(fn (?Loan $record) => $record?->progress_description ?? '-')
                            ->visible(fn (string $operation) => $operation === 'edit'),
                    ])
                    ->columns(2)
                    ->visible(fn (string $operation) => $operation === 'edit'),
            ]);
    }

    /**
     * Función para definir el infolist de visualización de préstamos/adelantos
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Información del Empleado')
                    ->schema([
                        Group::make([
                            TextEntry::make('employee.full_name')
                                ->label('Empleado')
                                ->icon('heroicon-o-user'),

                            TextEntry::make('employee.ci')
                                ->label('CI')
                                ->icon('heroicon-o-identification')
                                ->badge()
                                ->color('gray')
                                ->copyable(),

                            TextEntry::make('employee.activeContract.position.name')
                                ->label('Cargo')
                                ->icon('heroicon-o-briefcase')
                                ->badge()
                                ->color('info')
                                ->placeholder('-'),

                            TextEntry::make('employee.company.name')
                                ->label('Empresa')
                                ->icon('heroicon-o-building-office')
                                ->badge()
                                ->color('success')
                                ->placeholder('-'),
                        ])->columns(2),
                    ]),

                InfolistSection::make('Datos del Préstamo')
                    ->schema([
                        Group::make([
                            TextEntry::make('reason')
                                ->label('Motivo')
                                ->formatStateUsing(fn (?string $state) => match ($state) {
                                    'personal' => 'Personal',
                                    'medical' => 'Médico',
                                    'education' => 'Educación',
                                    'other' => 'Otro',
                                    default => $state ?? '-',
                                })
                                ->badge()
                                ->color('gray'),

                            TextEntry::make('status')
                                ->label('Estado')
                                ->formatStateUsing(fn (string $state) => Loan::getStatusLabel($state))
                                ->color(fn (string $state) => Loan::getStatusColor($state))
                                ->icon(fn (string $state) => Loan::getStatusIcon($state))
                                ->badge(),

                            TextEntry::make('interest_rate')
                                ->label('Tasa Anual')
                                ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.').'%')
                                ->badge()
                                ->color('gray'),
                        ])->columns(3),

                        Group::make([
                            TextEntry::make('amount')
                                ->label('Monto Total')
                                ->money('PYG', locale: 'es_PY')
                                ->icon('heroicon-o-banknotes'),

                            TextEntry::make('installment_amount')
                                ->label('Cuota Mensual')
                                ->money('PYG', locale: 'es_PY'),

                            TextEntry::make('outstanding_balance')
                                ->label('Saldo Pendiente')
                                ->money('PYG', locale: 'es_PY')
                                ->icon('heroicon-o-banknotes')
                                ->visible(fn (Loan $record) => $record->isApproved()),

                            TextEntry::make('installments_count')
                                ->label('Progreso')
                                ->formatStateUsing(fn (Loan $record) => $record->progress_description),
                        ])->columns(3),

                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('Sin notas')
                            ->columnSpanFull(),
                    ]),

                InfolistSection::make('Aprobación')
                    ->schema([
                        Group::make([
                            TextEntry::make('granted_at')
                                ->label('Fecha de Otorgamiento')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar')
                                ->placeholder('-'),

                            TextEntry::make('grantedBy.name')
                                ->label('Otorgado por')
                                ->icon('heroicon-o-user-circle')
                                ->placeholder('-'),
                        ])->columns(2),
                    ])
                    ->visible(fn (Loan $record) => $record->isApproved() || $record->isPaid()),
            ]);
    }

    /**
     * Función para definir la tabla de visualización de préstamos/adelantos
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(
                fn (Builder $query) => $query
                    ->with(['employee', 'grantedBy'])
                    ->withCount(['installments as paid_count' => fn ($q) => $q->where('status', 'paid')])
            )
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(),

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
                    ->sortable(['first_name', 'last_name'])
                    ->wrap(),

                TextColumn::make('employee.ci')
                    ->label('CI')
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->tooltip('Haz clic para copiar')
                    ->copyMessage('CI copiada al portapapeles'),

                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('installments_count')
                    ->label('Cuotas')
                    ->formatStateUsing(fn (Loan $record) => $record->progress_description)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('installment_amount')
                    ->label('Monto por Cuota')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Loan::getStatusLabel($state))
                    ->color(fn (string $state): string => Loan::getStatusColor($state))
                    ->icon(fn (string $state): string => Loan::getStatusIcon($state))
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->label('Método de pago')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'transfer' ? 'Transferencia' : 'Efectivo')
                    ->color(fn (string $state) => $state === 'transfer' ? 'info' : 'gray')
                    ->icon(fn (string $state) => $state === 'transfer' ? 'heroicon-o-building-library' : 'heroicon-o-banknotes')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('granted_at')
                    ->label('Otorgado')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('grantedBy.name')
                    ->label('Otorgado por')
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
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Loan::getStatusOptions())
                    ->multiple()
                    ->native(false),

                SelectFilter::make('payment_method')
                    ->label('Método de pago')
                    ->options([
                        'cash' => 'Efectivo',
                        'transfer' => 'Transferencia bancaria',
                    ])
                    ->native(false),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} (CI: {$record->ci})")
                    ->searchable(['first_name', 'last_name', 'ci'])
                    ->multiple()
                    ->native(false),

                Filter::make('granted_at')
                    ->label('Fecha de Otorgamiento')
                    ->form([
                        DatePicker::make('granted_from')
                            ->label('Desde')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                        DatePicker::make('granted_until')
                            ->label('Hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['granted_from'], fn (Builder $q, $date) => $q->whereDate('granted_at', '>=', $date))
                            ->when($data['granted_until'], fn (Builder $q, $date) => $q->whereDate('granted_at', '<=', $date));
                    }),
            ])
            ->actions([
                Action::make('change_payment_method')
                    ->label('Cambiar método de pago')
                    ->icon('heroicon-o-credit-card')
                    ->color('gray')
                    ->modalHeading('Cambiar método de pago')
                    ->modalSubmitActionLabel('Guardar')
                    ->fillForm(fn (Loan $record) => ['payment_method' => $record->payment_method])
                    ->form([
                        Select::make('payment_method')
                            ->label('Método de pago')
                            ->options(Loan::getPaymentMethodOptions())
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (Loan $record, array $data) {
                        $record->update(['payment_method' => $data['payment_method']]);

                        Notification::make()
                            ->success()
                            ->title('Método de pago actualizado')
                            ->body('Se actualizó el método de pago de '.($record->employee?->full_name ?? 'empleado eliminado').' a '.Loan::getPaymentMethodLabel($data['payment_method']).'.')
                            ->send();
                    })
                    ->visible(fn (Loan $record) => $record->isPending() || $record->isApproved()),

                Action::make('activate')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Loan $record) => $record->isPending() && auth()->user()->can('approve_loan'))
                    ->requiresConfirmation()
                    ->modalHeading('Aprobar Préstamo')
                    ->modalDescription(function (Loan $record) {
                        $payrollType = $record->employee->payroll_type_label;

                        return "Se generarán {$record->installments_count} cuotas. La primera se descontará en la próxima nómina ({$payrollType}).";
                    })
                    ->modalSubmitActionLabel('Sí, aprobar')
                    ->action(function (Loan $record) {
                        $result = $record->activate(Auth::id());

                        Notification::make()
                            ->title($result['success'] ? 'Préstamo Aprobado' : 'Error')
                            ->body($result['message'])
                            ->{$result['success'] ? 'success' : 'danger'}()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->visible(fn (Loan $record) => $record->isPending() && auth()->user()->can('reject_loan'))
                    ->requiresConfirmation()
                    ->modalHeading('Rechazar Préstamo')
                    ->modalDescription(fn (Loan $record) => 'Se rechazará la solicitud de préstamo de '.number_format((float) $record->amount, 0, ',', '.').' Gs. para '.$record->employee->full_name.'. El préstamo quedará en estado Rechazado.')
                    ->modalSubmitActionLabel('Sí, rechazar')
                    ->form([
                        Textarea::make('reason')
                            ->label('Motivo del rechazo')
                            ->placeholder('Ingrese el motivo...')
                            ->rows(3),
                    ])
                    ->action(function (Loan $record, array $data) {
                        $result = $record->reject($data['reason'] ?? null);

                        Notification::make()
                            ->title($result['success'] ? 'Préstamo Rechazado' : 'Error')
                            ->body($result['message'])
                            ->{$result['success'] ? 'warning' : 'danger'}()
                            ->send();
                    }),

                Action::make('mark_disbursed')
                    ->label('Marcar Entregado')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->visible(fn (Loan $record) => $record->isApproved() && auth()->user()->can('disburse_loan'))
                    ->requiresConfirmation()
                    ->modalHeading('Marcar Préstamo como Entregado')
                    ->modalDescription(fn (Loan $record) => 'Confirme que el dinero de Gs. '.number_format((float) $record->amount, 0, ',', '.').' fue entregado a '.($record->employee?->full_name ?? 'empleado eliminado').'. Las cuotas comenzarán a descontarse en la próxima nómina.')
                    ->modalSubmitActionLabel('Sí, marcar como entregado')
                    ->action(function (Loan $record) {
                        $result = $record->disburse(Auth::id());

                        Notification::make()
                            ->title($result['success'] ? 'Préstamo Desembolsado' : 'Error')
                            ->body($result['message'])
                            ->{$result['success'] ? 'success' : 'danger'}()
                            ->send();
                    }),

                Action::make('export_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (Loan $record) => $record->isApproved() || $record->isDisbursed() || $record->isPaid())
                    ->url(fn (Loan $record) => route('loans.pdf', $record))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('changePaymentMethodBulk')
                        ->label('Cambiar método de pago')
                        ->icon('heroicon-o-credit-card')
                        ->color('gray')
                        ->modalHeading('Cambiar método de pago')
                        ->modalDescription('Solo se actualizarán los préstamos en estado Pendiente o Aprobado. Los demás serán ignorados.')
                        ->modalSubmitActionLabel('Guardar')
                        ->form([
                            Select::make('payment_method')
                                ->label('Método de pago')
                                ->options(Loan::getPaymentMethodOptions())
                                ->required()
                                ->native(false),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $count = $records
                                ->filter(fn ($r) => $r->isPending() || $r->isApproved())
                                ->each->update(['payment_method' => $data['payment_method']])
                                ->count();

                            Notification::make()
                                ->success()
                                ->title('Método de pago actualizado')
                                ->body("Se actualizaron {$count} préstamo(s) a ".Loan::getPaymentMethodLabel($data['payment_method']).'.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('activateBulk')
                        ->label('Aprobar')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->visible(fn () => auth()->user()->can('approve_loan'))
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar Préstamos')
                        ->modalDescription('Se aprobarán los préstamos seleccionados que estén en estado Pendiente. Los demás serán ignorados.')
                        ->modalSubmitActionLabel('Sí, aprobar seleccionados')
                        ->action(function (Collection $records) {
                            $activated = 0;
                            $skipped = 0;
                            $failed = 0;

                            foreach ($records as $record) {
                                if (! $record->isPending()) {
                                    $skipped++;

                                    continue;
                                }

                                $result = $record->activate(Auth::id());
                                $result['success'] ? $activated++ : $failed++;
                            }

                            $body = "Se aprobaron {$activated} préstamos.";
                            if ($skipped > 0) {
                                $body .= " {$skipped} ignorados por no estar en estado Pendiente.";
                            }
                            if ($failed > 0) {
                                $body .= " {$failed} no pudieron aprobarse.";
                            }

                            Notification::make()
                                ->title('Aprobación Completada')
                                ->body($body)
                                ->{($skipped + $failed) > 0 ? 'warning' : 'success'}()
                                ->send()
                                ->persistent();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('rejectBulk')
                        ->label('Rechazar')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->visible(fn () => auth()->user()->can('reject_loan'))
                        ->requiresConfirmation()
                        ->modalHeading('Rechazar Préstamos')
                        ->modalDescription('Se rechazarán los préstamos seleccionados que estén en estado Pendiente. Los demás serán ignorados.')
                        ->modalSubmitActionLabel('Sí, rechazar seleccionados')
                        ->form([
                            Textarea::make('reason')
                                ->label('Motivo del rechazo')
                                ->placeholder('Ingrese el motivo...')
                                ->rows(3),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $rejected = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (! $record->isPending()) {
                                    $skipped++;

                                    continue;
                                }

                                $record->reject($data['reason'] ?? null);
                                $rejected++;
                            }

                            $body = "Se rechazaron {$rejected} préstamos.";
                            if ($skipped > 0) {
                                $body .= " {$skipped} ignorados por no estar en estado Pendiente.";
                            }

                            Notification::make()
                                ->warning()
                                ->title('Rechazo Completado')
                                ->body($body)
                                ->send()
                                ->persistent();
                        })
                        ->deselectRecordsAfterCompletion(),

                    ExportBulkAction::make()
                        ->exports([
                            ExcelExport::make()
                                ->fromTable()
                                ->withFilename('prestamos_'.now()->format('Y_m_d_H_i_s').'.xlsx'),
                        ])
                        ->label('Exportar a Excel')
                        ->color('info')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn () => auth()->user()->can('export_loan')),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No hay préstamos aún')
            ->emptyStateDescription('Comienza registrando el primer préstamo al sistema')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    /**
     * Función para definir las relaciones del recurso
     */
    public static function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    /**
     * Función para definir las páginas del recurso
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoans::route('/'),
            'create' => Pages\CreateLoan::route('/create'),
            'view' => Pages\ViewLoan::route('/{record}'),
            'edit' => Pages\EditLoan::route('/{record}/edit'),
        ];
    }

    /**
     * Función para definir el badge de navegación del recurso
     */
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ?: null;
    }

    /**
     * Función para definir el color del badge de navegación del recurso
     */
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
