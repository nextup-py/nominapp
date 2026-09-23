<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LiquidacionResource\Pages;
use App\Filament\Resources\LiquidacionResource\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\LiquidacionResource\RelationManagers\ItemsRelationManager;
use App\Models\Employee;
use App\Models\Liquidacion;
use App\Services\LiquidacionService;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
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
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use pxlrbt\FilamentExcel\Actions\Pages\ExportAction;
use pxlrbt\FilamentExcel\Columns\Column;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class LiquidacionResource extends Resource
{
    protected static ?string $model = Liquidacion::class;

    protected static ?string $navigationGroup = 'Nóminas';

    protected static ?string $navigationLabel = 'Liquidaciones';

    protected static ?string $label = 'Liquidación';

    protected static ?string $pluralLabel = 'Liquidaciones';

    protected static ?string $slug = 'liquidaciones';

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 4;

    public static function getNavigationBadge(): ?string
    {
        $count = Liquidacion::whereIn('status', ['draft', 'calculated'])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Liquidaciones pendientes de cerrar';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Datos del Empleado')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Empleado')
                            ->options(function () {
                                return Employee::where('status', 'active')
                                    ->whereHas('activeContract')
                                    ->get()
                                    ->mapWithKeys(fn ($e) => [$e->id => "{$e->full_name} - CI: {$e->ci}"]);
                            })
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, Set $set) {
                                if ($state) {
                                    $employee = Employee::find($state);
                                    $contract = $employee?->activeContract;
                                    if ($employee && $contract) {
                                        $salary = (float) ($contract->salary ?? 0);
                                        $baseSalary = $contract->salary_type === 'jornal'
                                            ? round($salary * 30, 2)
                                            : $salary;
                                        $set('hire_date', $contract->start_date?->format('Y-m-d'));
                                        $set('base_salary', $baseSalary);
                                    }
                                }
                            })
                            ->disabled(fn (?Liquidacion $record) => $record && ! $record->isDraft())
                            ->columnSpan(2),

                        Placeholder::make('employee_hire_date')
                            ->label('Fecha de Ingreso')
                            ->content(function (Get $get) {
                                $employeeId = $get('employee_id');
                                if ($employeeId) {
                                    $employee = Employee::find($employeeId);

                                    return $employee?->hire_date?->format('d/m/Y') ?? '-';
                                }

                                return '-';
                            })
                            ->columnSpan(1),

                        Placeholder::make('employee_salary')
                            ->label(function (Get $get) {
                                $contract = Employee::find($get('employee_id'))?->activeContract;

                                return $contract?->salary_type === 'jornal' ? 'Jornal Diario' : 'Salario Mensual';
                            })
                            ->content(function (Get $get) {
                                $contract = Employee::find($get('employee_id'))?->activeContract;
                                if (! $contract) {
                                    return '-';
                                }
                                $suffix = $contract->salary_type === 'jornal' ? '/día' : '/mes';

                                return Liquidacion::formatCurrency((float) $contract->salary).$suffix;
                            })
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Section::make('Datos de la Desvinculación')
                    ->schema([
                        DatePicker::make('termination_date')
                            ->label('Fecha de Desvinculación')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->required()
                            ->disabled(fn (?Liquidacion $record) => $record && ! $record->isDraft())
                            ->columnSpan(1),

                        Select::make('termination_type')
                            ->label('Tipo de Desvinculación')
                            ->options(Liquidacion::getTerminationTypeOptions())
                            ->native(false)
                            ->required()
                            ->reactive()
                            ->disabled(fn (?Liquidacion $record) => $record && $record->isClosed())
                            ->columnSpan(1),

                        Toggle::make('preaviso_otorgado')
                            ->label('¿Se otorgó preaviso al empleado?')
                            ->helperText('Si el empleador dio aviso anticipado, no se paga preaviso')
                            ->default(false)
                            ->visible(function (Get $get) {
                                if (! Liquidacion::includesPreaviso($get('termination_type') ?? '')) {
                                    return false;
                                }
                                $employee = Employee::find($get('employee_id'));
                                $trialDays = $employee?->activeContract?->trial_days ?? 30;
                                $hireDate = $get('hire_date') ?? $employee?->activeContract?->start_date;
                                $terminationDate = $get('termination_date');
                                if ($hireDate && $terminationDate) {
                                    $daysOfService = Carbon::parse($hireDate)->diffInDays(Carbon::parse($terminationDate));
                                    if ($daysOfService <= $trialDays) {
                                        return false;
                                    }
                                }

                                return true;
                            })
                            ->disabled(fn (?Liquidacion $record) => $record && $record->isClosed())
                            ->columnSpan(2),

                        Placeholder::make('components_info')
                            ->label('Componentes a calcular')
                            ->content(function (Get $get) {
                                $type = $get('termination_type');
                                if (! $type) {
                                    return 'Seleccione un tipo de desvinculación';
                                }

                                $employee = Employee::find($get('employee_id'));
                                $trialDays = $employee?->activeContract?->trial_days ?? 30;
                                $hireDate = $get('hire_date') ?? $employee?->activeContract?->start_date;
                                $terminationDate = $get('termination_date');

                                $inTrialPeriod = false;
                                if ($hireDate && $terminationDate) {
                                    $daysOfService = Carbon::parse($hireDate)->diffInDays(Carbon::parse($terminationDate));
                                    $inTrialPeriod = $daysOfService <= $trialDays;
                                }

                                $components = [];
                                if (! $inTrialPeriod && Liquidacion::includesPreaviso($type) && ! $get('preaviso_otorgado')) {
                                    $components[] = 'Preaviso';
                                }
                                if (! $inTrialPeriod && Liquidacion::includesIndemnizacion($type)) {
                                    $components[] = 'Indemnización';
                                }
                                if (! $inTrialPeriod) {
                                    $components[] = 'Vacaciones proporcionales';
                                }
                                $components[] = 'Aguinaldo proporcional';
                                $components[] = 'Salario pendiente';

                                $result = 'Incluye: '.implode(' + ', $components);
                                if ($inTrialPeriod) {
                                    $result .= ' — ⚠️ Período de prueba activo: preaviso e indemnización excluidos';
                                }

                                return $result;
                            })
                            ->columnSpan(2),

                        Placeholder::make('estabilidad_laboral_alert')
                            ->label('')
                            ->content(function (Get $get) {
                                $employeeId = $get('employee_id');
                                if (! $employeeId) {
                                    return null;
                                }

                                $employee = Employee::find($employeeId);
                                $hireDate = $employee?->hire_date ?? Carbon::parse($get('hire_date') ?? null);
                                if (! $hireDate) {
                                    return null;
                                }

                                $years = Carbon::parse($hireDate)->diffInYears(now());
                                if ($years < 10) {
                                    return null;
                                }

                                return new HtmlString(
                                    '<div style="background:#fef3c7;border:1px solid #d97706;border-radius:6px;padding:10px 14px;color:#92400e;">'
                                    .'<strong>⚠ Estabilidad Laboral Propia — Art. 95 CLT</strong><br>'
                                    ."Este empleado cuenta con <strong>{$years} años</strong> de antigüedad. "
                                    .'El despido sin causa justificada probada en juicio puede ser legalmente impugnado. '
                                    .'De proceder la liquidación, corresponde <strong>indemnización doble</strong>.'
                                    .'</div>'
                                );
                            })
                            ->visible(fn (Get $get) => $get('termination_type') === 'unjustified_dismissal' && filled($get('employee_id')))
                            ->columnSpan(2),

                        Textarea::make('termination_reason')
                            ->label('Motivo de Desvinculación')
                            ->rows(2)
                            ->maxLength(65535)
                            ->disabled(fn (?Liquidacion $record) => $record && ! $record->isDraft())
                            ->columnSpan(2),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
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
                    ->sortable()
                    ->wrap(),

                TextColumn::make('termination_date')
                    ->label('Fecha Egreso')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('termination_type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'unjustified_dismissal' => 'danger',
                        'justified_dismissal' => 'warning',
                        'resignation' => 'info',
                        'mutual_agreement' => 'primary',
                        'contract_end' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => Liquidacion::getTerminationTypeLabel($state))
                    ->sortable(),

                TextColumn::make('total_haberes')
                    ->label('Haberes')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->color('success'),

                TextColumn::make('total_deductions')
                    ->label('Descuentos')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->color('danger'),

                TextColumn::make('net_amount')
                    ->label('Neto a Pagar')
                    ->money('PYG', locale: 'es_PY')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->color('success'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'calculated' => 'warning',
                        'closed' => 'success',
                        default => 'primary',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Borrador',
                        'calculated' => 'Calculada',
                        'closed' => 'Cerrada',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('termination_type')
                    ->label('Tipo')
                    ->options(Liquidacion::getTerminationTypeOptions())
                    ->native(false),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'draft' => 'Borrador',
                        'calculated' => 'Calculada',
                        'closed' => 'Cerrada',
                    ])
                    ->native(false),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                    ->searchable(['first_name', 'last_name'])
                    ->preload()
                    ->native(false),
            ])
            ->actions([
                Action::make('calculate')
                    ->label('Calcular')
                    ->icon('heroicon-o-calculator')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Calcular Liquidación')
                    ->modalDescription(
                        fn (Liquidacion $record) => "¿Calcular la liquidación de {$record->employee->full_name}? ".
                            'Tipo: '.Liquidacion::getTerminationTypeLabel($record->termination_type)
                    )
                    ->action(fn (Liquidacion $record, LiquidacionService $service) => static::performCalculation($record, $service, 'Liquidación calculada')
                    )
                    ->visible(fn (Liquidacion $record) => $record->isDraft() && auth()->user()->can('calculate_liquidacion')),

                Action::make('recalculate')
                    ->label('Recalcular')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Recalcular Liquidación')
                    ->modalDescription(
                        fn (Liquidacion $record) => "¿Recalcular la liquidación de {$record->employee->full_name}? ".
                            'Se eliminarán los conceptos actuales y se recalculará desde cero. '.
                            'Los cambios manuales en los items se perderán.'
                    )
                    ->action(fn (Liquidacion $record, LiquidacionService $service) => static::performCalculation($record, $service, 'Liquidación recalculada')
                    )
                    ->visible(fn (Liquidacion $record) => $record->isCalculated() && auth()->user()->can('calculate_liquidacion')),

                Action::make('close')
                    ->label('Cerrar')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cerrar Liquidación y Desactivar Empleado')
                    ->modalDescription(
                        fn (Liquidacion $record) => "¿Cerrar la liquidación de {$record->employee->full_name}? ".
                            'El empleado será marcado como INACTIVO y los préstamos pendientes serán cancelados. '.
                            'Esta acción no se puede deshacer.'
                    )
                    ->action(fn (Liquidacion $record, LiquidacionService $service) => static::performClose($record, $service)
                    )
                    ->visible(fn (Liquidacion $record) => $record->isCalculated() && auth()->user()->can('close_liquidacion')),

                Action::make('download_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (Liquidacion $record) => route('liquidaciones.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Liquidacion $record) => $record->pdf_path !== null),

            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function ($records) {
                            $deleted = 0;
                            foreach ($records as $record) {
                                if ($record->isDraft()) {
                                    $record->delete();
                                    $deleted++;
                                }
                            }
                            $ignored = $records->count() - $deleted;
                            if ($deleted > 0) {
                                Notification::make()
                                    ->success()
                                    ->title("Se eliminaron {$deleted} liquidacion(es) en borrador")
                                    ->send();
                            }
                            if ($ignored > 0) {
                                Notification::make()
                                    ->warning()
                                    ->title("{$ignored} liquidacion(es) no eliminadas")
                                    ->body('Solo se pueden eliminar liquidaciones en estado borrador.')
                                    ->send();
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No hay liquidaciones registradas')
            ->emptyStateDescription('Comience creando una liquidación para calcular el finiquito de un empleado.')
            ->emptyStateIcon('heroicon-o-document-minus');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Datos del Empleado')
                    ->schema([
                        Group::make([
                            TextEntry::make('employee.full_name')
                                ->label('Nombre Completo'),
                            TextEntry::make('employee.ci')
                                ->label('Cédula de Identidad')
                                ->icon('heroicon-o-identification')
                                ->badge()
                                ->color('gray')
                                ->copyable()
                                ->copyMessage('Cédula copiada'),
                        ])->columns(2),
                        Group::make([
                            TextEntry::make('employee.activeContract.position.name')
                                ->label('Cargo')
                                ->badge()
                                ->color('info')
                                ->default('N/A'),
                            TextEntry::make('employee.activeContract.position.department.name')
                                ->label('Departamento')
                                ->badge()
                                ->color('primary')
                                ->default('N/A'),
                        ])->columns(2),
                    ]),

                InfolistSection::make('Datos de la Desvinculación')
                    ->schema([
                        Group::make([
                            TextEntry::make('hire_date')
                                ->label('Fecha de Ingreso')
                                ->date('d/m/Y'),
                            TextEntry::make('termination_date')
                                ->label('Fecha de Egreso')
                                ->date('d/m/Y'),
                        ])->columns(2),
                        Group::make([
                            TextEntry::make('termination_type')
                                ->label('Tipo de Desvinculación')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'unjustified_dismissal' => 'danger',
                                    'justified_dismissal' => 'warning',
                                    'resignation' => 'info',
                                    'mutual_agreement' => 'primary',
                                    'contract_end' => 'gray',
                                    default => 'gray',
                                })
                                ->formatStateUsing(fn (string $state): string => Liquidacion::getTerminationTypeLabel($state)),
                            TextEntry::make('seniority')
                                ->label('Antigüedad')
                                ->state(fn (Liquidacion $record) => $record->years_of_service !== null
                                    ? "{$record->years_of_service} año(s), ".($record->months_of_service % 12).' mes(es)'
                                    : null)
                                ->placeholder('Pendiente de cálculo'),
                        ])->columns(2),
                        TextEntry::make('preaviso_otorgado')
                            ->label('Preaviso otorgado por empleador')
                            ->formatStateUsing(fn (bool $state) => $state ? 'Sí — no se cobra preaviso' : 'No — se cobra preaviso')
                            ->badge()
                            ->color(fn (bool $state) => $state ? 'info' : 'warning')
                            ->visible(fn (Liquidacion $record) => Liquidacion::includesPreaviso($record->termination_type)),
                        Group::make([
                            TextEntry::make('base_salary')
                                ->label(fn (Liquidacion $record) => $record->salary_type === 'jornal' ? 'Jornal Diario' : 'Salario Base')
                                ->formatStateUsing(function (Liquidacion $record) {
                                    if ($record->salary_type === 'jornal') {
                                        return Liquidacion::formatCurrency($record->daily_salary).'/día';
                                    }

                                    return Liquidacion::formatCurrency($record->base_salary).'/mes';
                                }),
                            TextEntry::make('average_salary_6m')
                                ->label(fn (Liquidacion $record) => $record->salary_type === 'jornal' ? 'Jornal Promedio 6 Meses' : 'Salario Promedio 6 Meses')
                                ->money('PYG', locale: 'es_PY')
                                ->placeholder('Pendiente de cálculo'),
                        ])->columns(2),
                        TextEntry::make('termination_reason')
                            ->label('Motivo')
                            ->placeholder('Sin motivo especificado')
                            ->columnSpanFull(),
                    ]),

                InfolistSection::make('Cálculo de Haberes')
                    ->visible(fn (Liquidacion $record) => ! $record->isDraft())
                    ->schema([
                        Group::make([
                            TextEntry::make('preaviso_info')
                                ->label(fn (Liquidacion $record) => $record->salary_type === 'jornal' ? 'Preaviso (jornales)' : 'Preaviso (días)')
                                ->state(fn (Liquidacion $record) => $record->preaviso_amount > 0
                                    ? "{$record->preaviso_days} ".($record->salary_type === 'jornal' ? 'jornales' : 'días').' - '.Liquidacion::formatCurrency($record->preaviso_amount)
                                    : ($record->preaviso_otorgado ? 'Otorgado (no aplica pago)' : 'No aplica')),
                            TextEntry::make('indemnizacion_amount')
                                ->label(fn (Liquidacion $record) => $record->salary_type === 'jornal' ? 'Indemnización (jornales)' : 'Indemnización')
                                ->money('PYG', locale: 'es_PY'),
                            TextEntry::make('indemnizacion_estabilidad_amount')
                                ->label('Estabilidad Laboral (Art. 95 CLT)')
                                ->money('PYG', locale: 'es_PY')
                                ->color('warning')
                                ->visible(fn (Liquidacion $record) => $record->indemnizacion_estabilidad_amount > 0),
                        ])->columns(2),
                        Group::make([
                            TextEntry::make('vacaciones_info')
                                ->label(fn (Liquidacion $record) => $record->salary_type === 'jornal' ? 'Vacaciones (jornales)' : 'Vacaciones Proporcionales')
                                ->state(fn (Liquidacion $record) => $record->vacaciones_amount > 0
                                    ? "{$record->vacaciones_days} ".($record->salary_type === 'jornal' ? 'jornales' : 'días').' - '.Liquidacion::formatCurrency($record->vacaciones_amount)
                                    : 'Gs. 0'),
                            TextEntry::make('aguinaldo_proporcional_amount')
                                ->label('Aguinaldo Proporcional')
                                ->money('PYG', locale: 'es_PY'),
                        ])->columns(2),
                        Group::make([
                            TextEntry::make('salario_pendiente_info')
                                ->label(fn (Liquidacion $record) => $record->salary_type === 'jornal' ? 'Jornales Pendientes' : 'Salario Pendiente')
                                ->state(fn (Liquidacion $record) => $record->salario_pendiente_amount > 0
                                    ? "{$record->salario_pendiente_days} ".($record->salary_type === 'jornal' ? 'jornales' : 'días').' - '.Liquidacion::formatCurrency($record->salario_pendiente_amount)
                                    : 'Gs. 0'),
                            TextEntry::make('total_haberes')
                                ->label('TOTAL HABERES')
                                ->money('PYG', locale: 'es_PY')
                                ->weight('bold')
                                ->color('success'),
                        ])->columns(2),
                    ]),

                InfolistSection::make('Descuentos')
                    ->visible(fn (Liquidacion $record) => ! $record->isDraft())
                    ->schema([
                        Group::make([
                            TextEntry::make('ips_deduction')
                                ->label('Aporte IPS (9%)')
                                ->money('PYG', locale: 'es_PY')
                                ->color('danger'),
                            TextEntry::make('loan_deduction')
                                ->label('Préstamos Pendientes')
                                ->money('PYG', locale: 'es_PY')
                                ->color('danger'),
                        ])->columns(2),
                        TextEntry::make('ausencias_deduction')
                            ->label('Ausencias Injustificadas')
                            ->state(fn (Liquidacion $record) => (float) $record->items()->where('category', 'ausencias')->sum('amount'))
                            ->money('PYG', locale: 'es_PY')
                            ->color('danger')
                            ->visible(fn (Liquidacion $record) => $record->items()->where('category', 'ausencias')->exists()),
                        TextEntry::make('total_deductions')
                            ->label('TOTAL DESCUENTOS')
                            ->money('PYG', locale: 'es_PY')
                            ->weight('bold')
                            ->color('danger'),
                    ]),

                InfolistSection::make('Neto a Pagar')
                    ->visible(fn (Liquidacion $record) => ! $record->isDraft())
                    ->schema([
                        TextEntry::make('net_amount')
                            ->label('NETO A PAGAR')
                            ->money('PYG', locale: 'es_PY')
                            ->size('lg')
                            ->weight('bold')
                            ->color('success')
                            ->icon('heroicon-o-banknotes'),
                    ]),

                InfolistSection::make('Estado y Sistema')
                    ->schema([
                        Group::make([
                            TextEntry::make('status')
                                ->label('Estado')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'draft' => 'gray',
                                    'calculated' => 'warning',
                                    'closed' => 'success',
                                    default => 'primary',
                                })
                                ->formatStateUsing(fn (string $state): string => match ($state) {
                                    'draft' => 'Borrador',
                                    'calculated' => 'Calculada',
                                    'closed' => 'Cerrada',
                                    default => $state,
                                }),
                            TextEntry::make('pdf_path')
                                ->label('PDF')
                                ->formatStateUsing(fn ($state) => $state ? 'Disponible' : 'No generado')
                                ->badge()
                                ->color(fn ($state) => $state ? 'success' : 'gray'),
                        ])->columns(2),
                        Group::make([
                            TextEntry::make('calculated_at')
                                ->label('Calculada')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Pendiente'),
                            TextEntry::make('closed_at')
                                ->label('Cerrada')
                                ->dateTime('d/m/Y H:i')
                                ->placeholder('Pendiente'),
                        ])->columns(2),
                        TextEntry::make('notes')
                            ->label('Notas')
                            ->placeholder('Sin notas')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function performCalculation(Liquidacion $record, LiquidacionService $service, string $successTitle): void
    {
        try {
            $service->calculate($record);

            Notification::make()
                ->success()
                ->title($successTitle)
                ->body("Neto a pagar: {$record->fresh()->formatted_net_amount}")
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Error al calcular')
                ->body($e->getMessage())
                ->send();
        }
    }

    public static function performClose(Liquidacion $record, LiquidacionService $service): void
    {
        try {
            $service->close($record);

            Notification::make()
                ->success()
                ->title('Liquidación cerrada')
                ->body("El empleado {$record->employee->full_name} ha sido desactivado.")
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Error al cerrar liquidación')
                ->body($e->getMessage())
                ->send();
        }
    }

    public static function getExcelExportAction(): ExportAction
    {
        return ExportAction::make('export_excel')
            ->label('Exportar a Excel')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('info')
            ->tooltip('Exportar registros visibles (respeta filtros y tabs activos)')
            ->visible(fn () => auth()->user()->can('export_liquidacion'))
            ->exports([
                ExcelExport::make()
                    ->withColumns([
                        Column::make('employee.ci')->heading('CI'),
                        Column::make('employee.full_name')->heading('Empleado'),
                        Column::make('hire_date')->heading('Fecha Ingreso'),
                        Column::make('termination_date')->heading('Fecha Egreso'),
                        Column::make('termination_type')
                            ->heading('Tipo Desvinculación')
                            ->getStateUsing(fn ($record) => Liquidacion::getTerminationTypeLabel($record->termination_type)),
                        Column::make('years_of_service')->heading('Años de Servicio'),
                        Column::make('salary_type')
                            ->heading('Tipo Salario')
                            ->getStateUsing(fn ($record) => $record->salary_type === 'jornal' ? 'Jornal' : 'Mensual'),
                        Column::make('base_salary')->heading('Salario Base / Jornal'),
                        Column::make('preaviso_days')->heading('Preaviso (días)'),
                        Column::make('preaviso_amount')->heading('Preaviso'),
                        Column::make('indemnizacion_amount')->heading('Indemnización'),
                        Column::make('indemnizacion_estabilidad_amount')->heading('Estabilidad Laboral'),
                        Column::make('vacaciones_days')->heading('Vacaciones (días)'),
                        Column::make('vacaciones_amount')->heading('Vacaciones'),
                        Column::make('aguinaldo_proporcional_amount')->heading('Aguinaldo Proporcional'),
                        Column::make('salario_pendiente_days')->heading('Sal. Pendiente (días)'),
                        Column::make('salario_pendiente_amount')->heading('Salario Pendiente'),
                        Column::make('total_haberes')->heading('Total Haberes'),
                        Column::make('ips_deduction')->heading('Descuento IPS'),
                        Column::make('loan_deduction')->heading('Descuento Préstamos'),
                        Column::make('ausencias_deduction')
                            ->heading('Descuento Ausencias')
                            ->getStateUsing(fn ($record) => (float) $record->items()->where('category', 'ausencias')->sum('amount')),
                        Column::make('total_deductions')->heading('Total Descuentos'),
                        Column::make('net_amount')->heading('Neto a Pagar'),
                        Column::make('status')
                            ->heading('Estado')
                            ->getStateUsing(fn ($record) => match ($record->status) {
                                'draft' => 'Borrador',
                                'calculated' => 'Calculada',
                                'closed' => 'Cerrada',
                                default => $record->status,
                            }),
                        Column::make('calculated_at')->heading('Fecha Cálculo'),
                        Column::make('closed_at')->heading('Fecha Cierre'),
                    ])
                    ->withFilename(fn () => 'liquidaciones_'.now()->format('d_m_Y_H_i_s')),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLiquidaciones::route('/'),
            'create' => Pages\CreateLiquidacion::route('/create'),
            'view' => Pages\ViewLiquidacion::route('/{record}'),
            'edit' => Pages\EditLiquidacion::route('/{record}/edit'),
        ];
    }
}
