<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VacationResource\Pages;
use App\Filament\Traits\HasModuleAccess;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Vacation;
use App\Models\VacationBalance;
use App\Services\VacationService;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class VacationResource extends Resource
{
    use HasModuleAccess;

    protected static string $moduleFlag = 'vacations_enabled';

    protected static ?string $model = Vacation::class;

    protected static ?string $navigationGroup = 'Empleados';

    protected static ?string $navigationLabel = 'Vacaciones';

    protected static ?string $label = 'Vacación';

    protected static ?string $pluralLabel = 'Vacaciones';

    protected static ?string $slug = 'vacaciones';

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?int $navigationSort = 3;

    /**
     * Define el formulario para crear/editar solicitudes de vacaciones.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Empleado')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Empleado')
                            ->relationship('employee', 'first_name', fn ($query) => $query->where('status', 'active')->orderBy('first_name'))
                            ->searchable(['first_name', 'last_name', 'ci'])
                            ->preload()
                            ->native(false)
                            ->required()
                            ->live()
                            ->columnSpanFull()
                            ->afterStateUpdated(function (Set $set) {
                                $set('start_date', null);
                                $set('end_date', null);
                                $set('business_days', null);
                                $set('return_date', null);
                            })
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->first_name} {$record->last_name} — CI: {$record->ci}"),

                        Placeholder::make('employee_info')
                            ->label('Saldo de vacaciones')
                            ->content(function (Get $get) {
                                $employeeId = $get('employee_id');

                                if (! $employeeId) {
                                    return new HtmlString("<span class='text-gray-400 text-sm'>Seleccione un empleado para ver su saldo.</span>");
                                }

                                $employee = Employee::find($employeeId);
                                if (! $employee instanceof Employee) {
                                    return 'Empleado no encontrado';
                                }

                                $minYears = app(PayrollSettings::class)->vacation_min_years_service;
                                $yearsOfService = VacationService::getYearsOfService($employee);
                                $antiquityColor = $yearsOfService < $minYears ? 'text-danger-600' : 'text-success-600';
                                $entitledLabel = VacationBalance::getEntitledDaysLabel($yearsOfService);

                                if ($yearsOfService < $minYears) {
                                    return new HtmlString("
                                        <div class='space-y-1 text-sm'>
                                            <p><strong>Antigüedad:</strong> <span class='{$antiquityColor}'>{$employee->antiquity_description}</span></p>
                                            <p class='text-danger-600'>⛔ Sin derecho a vacaciones. Se requiere mínimo {$minYears} año(s) de antigüedad.</p>
                                        </div>
                                    ");
                                }

                                VacationService::getOrCreateBalance($employee, now()->year);

                                $balances = VacationBalance::where('employee_id', $employee->id)->orderByDesc('year')->get();
                                $totalAvail = VacationService::getTotalAvailableDays($employee);
                                $totalUsed = $balances->sum('used_days');
                                $totalPending = $balances->sum('pending_days');

                                // Desglose por año cuando hay más de un año con saldo positivo
                                $yearsWithBalance = $balances->filter(
                                    fn ($b) => ($b->entitled_days - $b->used_days - $b->pending_days) > 0
                                );

                                $breakdownHtml = '';
                                if ($yearsWithBalance->count() > 1) {
                                    $parts = $yearsWithBalance->map(fn ($b) => ($b->entitled_days - $b->used_days - $b->pending_days).' de '.$b->year)->join(' · ');
                                    $breakdownHtml = "<span class='text-gray-400 text-xs'>({$parts})</span>";
                                }

                                $availColor = $totalAvail === 0 ? 'text-danger-600' : 'text-success-600';
                                $availMsg = $totalAvail === 0
                                    ? "<span class='text-danger-600'>⛔ Sin días disponibles. No es posible solicitar vacaciones.</span>"
                                    : "<strong>Disponibles:</strong> <span class='{$availColor}'>{$totalAvail} días</span> {$breakdownHtml}";

                                return new HtmlString("
                                    <div class='space-y-1 text-sm'>
                                        <p><strong>Antigüedad:</strong> <span class='{$antiquityColor}'>{$employee->antiquity_description}</span></p>
                                        <p><strong>Derecho:</strong> {$entitledLabel}</p>
                                        <p>{$availMsg} &nbsp;·&nbsp; <strong>Usados:</strong> {$totalUsed} días &nbsp;·&nbsp; <strong>Pendientes:</strong> {$totalPending} días</p>
                                    </div>
                                ");
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(1),

                Section::make('Período de Vacaciones')
                    ->icon('heroicon-o-calendar-days')
                    ->hidden(function (Get $get, $record): bool {
                        // En edición siempre visible
                        if ($record !== null) {
                            return false;
                        }

                        $employeeId = $get('employee_id');
                        if (! $employeeId) {
                            return true;
                        }

                        $employee = Employee::find($employeeId);
                        if (! $employee instanceof Employee) {
                            return true;
                        }

                        $minYears = app(PayrollSettings::class)->vacation_min_years_service;
                        if (VacationService::getYearsOfService($employee) < $minYears) {
                            return true;
                        }

                        VacationService::getOrCreateBalance($employee, now()->year);

                        return VacationService::getTotalAvailableDays($employee) === 0;
                    })
                    ->schema([
                        Select::make('payment_method')
                            ->label('Forma de pago')
                            ->options(Vacation::getPaymentMethodOptions())
                            ->default('immediate')
                            ->native(false)
                            ->required()
                            ->live()
                            ->helperText(fn (Get $get) => match ($get('payment_method')) {
                                'immediate' => 'El empleado cobrará la remuneración vacacional antes de iniciar las vacaciones.',
                                'with_payroll' => 'La remuneración vacacional se incluirá automáticamente en la nómina del período.',
                                default => null,
                            }),

                        DatePicker::make('start_date')
                            ->label('Fecha de Inicio')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('end_date', null);
                                $set('business_days', null);
                                $set('return_date', null);
                            }),

                        DatePicker::make('end_date')
                            ->label('Fecha de Fin')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->required()
                            ->minDate(fn (Get $get) => $get('start_date'))
                            ->disabled(fn (Get $get) => ! $get('start_date'))
                            ->live()
                            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                $employeeId = $get('employee_id');
                                $startDate = $get('start_date');

                                if (! $employeeId || ! $startDate || ! $state) {
                                    return;
                                }

                                $employee = Employee::find($employeeId);
                                if (! $employee instanceof Employee) {
                                    return;
                                }

                                $start = Carbon::parse($startDate);
                                $end = Carbon::parse($state);
                                $businessDays = VacationService::calculateBusinessDays($employee, $start, $end);
                                $returnDate = VacationService::calculateReturnDate($employee, $end);

                                $set('business_days', $businessDays);
                                $set('return_date', $returnDate->format('Y-m-d'));
                            }),

                        Placeholder::make('calculation_info')
                            ->label('Resumen del período')
                            ->content(function (Get $get) {
                                $startDate = $get('start_date');
                                $endDate = $get('end_date');
                                $employeeId = $get('employee_id');
                                $paymentMethod = $get('payment_method') ?? 'immediate';

                                if (! $startDate || ! $endDate) {
                                    return new HtmlString("<span class='text-gray-400 text-sm'>Seleccione las fechas para ver el resumen.</span>");
                                }

                                $businessDays = $get('business_days') ?? 0;
                                $returnDate = $get('return_date');
                                $start = Carbon::parse($startDate);
                                $end = Carbon::parse($endDate);
                                $workingDays = app(PayrollSettings::class)->vacation_business_days;

                                $balance = $employeeId
                                    ? VacationBalance::where('employee_id', $employeeId)->where('year', $start->year)->first()
                                    : null;
                                $available = $balance?->available_days ?? 0;

                                $holidays = Holiday::whereBetween('date', [$start, $end])->orderBy('date')->get();

                                $holidayDates = $holidays->pluck('date')->map(fn ($d) => $d->format('Y-m-d'))->toArray();
                                $nonWorkingDays = 0;
                                foreach (CarbonPeriod::create($start, $end) as $date) {
                                    if (! in_array($date->dayOfWeekIso, $workingDays) && ! in_array($date->format('Y-m-d'), $holidayDates)) {
                                        $nonWorkingDays++;
                                    }
                                }

                                $daysColor = $businessDays > $available ? 'text-danger-600' : 'text-success-600';
                                $returnFormatted = $returnDate ? Carbon::parse($returnDate)->format('d/m/Y') : '-';

                                // Estimación del monto si hay empleado y días
                                $amountHtml = '';
                                if ($employeeId && $businessDays > 0) {
                                    $employee = Employee::find($employeeId);
                                    if ($employee instanceof Employee) {
                                        $tempVacation = new Vacation([
                                            'employee_id' => $employee->id,
                                            'start_date' => $startDate,
                                            'end_date' => $endDate,
                                            'business_days' => $businessDays,
                                        ]);
                                        $estimated = VacationService::calculateVacationPay($employee, $tempVacation);

                                        if ($estimated > 0) {
                                            $formatted = 'Gs. '.number_format($estimated, 0, ',', '.');
                                            $methodLabel = $paymentMethod === 'with_payroll' ? 'con nómina' : 'adelantado';
                                            $amountHtml = "<p class='text-success-600 text-sm mt-1'><strong>Estimación remuneración vacacional:</strong> {$formatted} <span class='text-gray-400 text-xs'>(cobro {$methodLabel})</span></p>";
                                        }
                                    }
                                }

                                $holidaysHtml = '';
                                if ($holidays->isNotEmpty()) {
                                    $list = $holidays->map(fn ($h) => $h->date->format('d/m').' – '.e($h->name))->join(', ');
                                    $label = $holidays->count() === 1 ? '1 feriado' : $holidays->count().' feriados';
                                    $holidaysHtml = "<p class='text-info-600 text-xs mt-1'>📅 {$label}: {$list}</p>";
                                }

                                $nonWorkingHtml = $nonWorkingDays > 0
                                    ? "<p class='text-gray-500 text-xs mt-1'>🚫 ".($nonWorkingDays === 1 ? '1 día no hábil' : "{$nonWorkingDays} días no hábiles").' (domingos)</p>'
                                    : '';

                                $warningHtml = ($businessDays > 0 && $businessDays < 6)
                                    ? "<p class='text-warning-600 text-xs mt-1'>⚠️ El fraccionamiento mínimo legal es de 6 días hábiles</p>"
                                    : '';

                                return new HtmlString("
                                    <div class='space-y-1 text-sm'>
                                        <p><strong>Días hábiles:</strong> <span class='{$daysColor}'>{$businessDays} días</span> <span class='text-gray-400'>(disponibles: {$available})</span></p>
                                        <p><strong>Fecha de reintegro:</strong> {$returnFormatted}</p>
                                        {$amountHtml}
                                        {$holidaysHtml}
                                        {$nonWorkingHtml}
                                        {$warningHtml}
                                    </div>
                                ");
                            })
                            ->columnSpanFull(),

                        Textarea::make('reason')
                            ->label('Motivo / observaciones')
                            ->placeholder('Opcional: notas sobre este período de vacaciones…')
                            ->rows(2)
                            ->maxLength(500)
                            ->nullable()
                            ->columnSpanFull(),

                        Hidden::make('business_days'),
                        Hidden::make('return_date'),
                        Hidden::make('vacation_balance_id'),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * Define la tabla para listar las solicitudes de vacaciones.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->sortable(['first_name', 'last_name'])
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->wrap(),

                TextColumn::make('employee.ci')
                    ->label('CI')
                    ->icon('heroicon-o-identification')
                    ->sortable()
                    ->searchable()
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->tooltip('Haz clic para copiar')
                    ->copyMessage('Cédula copiada'),

                TextColumn::make('start_date')
                    ->label('Inicio')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('end_date')
                    ->label('Fin')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('business_days')
                    ->label('Días Háb.')
                    ->alignCenter()
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->tooltip('Días hábiles (excluye domingos y feriados)'),

                TextColumn::make('return_date')
                    ->label('Reintegro')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('payment_method')
                    ->label('Forma de pago')
                    ->badge()
                    ->color(fn (string $state): string => Vacation::getPaymentMethodColor($state))
                    ->icon(fn (string $state): string => Vacation::getPaymentMethodIcon($state))
                    ->formatStateUsing(fn (string $state): string => Vacation::getPaymentMethodLabel($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Estado')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => Vacation::getStatusColor($state))
                    ->icon(fn (string $state): string => Vacation::getStatusIcon($state))
                    ->formatStateUsing(fn (string $state): string => Vacation::getStatusLabel($state)),

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
            ->defaultSort('start_date', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->options(Vacation::getStatusOptions())
                    ->native(false),

                SelectFilter::make('payment_method')
                    ->label('Forma de pago')
                    ->placeholder('Todos')
                    ->options(Vacation::getPaymentMethodOptions())
                    ->native(false),

                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} (CI: {$record->ci})")
                    ->searchable(['first_name', 'last_name', 'ci'])
                    ->preload()
                    ->native(false),

                Filter::make('current_year')
                    ->label('Año Actual')
                    ->query(fn ($query) => $query->whereYear('start_date', now()->year))
                    ->default(),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Aprobar Solicitud de Vacaciones')
                    ->modalDescription(function ($record) {
                        $days = $record->business_days ?? $record->total_days;
                        $returnDate = $record->return_date?->format('d/m/Y') ?? 'No calculada';

                        return "¿Aprobar vacaciones de {$record->employee->full_name}?\n\nPeríodo: {$record->start_date->format('d/m/Y')} al {$record->end_date->format('d/m/Y')}\nDías hábiles: {$days}\nFecha de reintegro: {$returnDate}";
                    })
                    ->action(function ($record) {
                        VacationService::approve($record);

                        Notification::make()
                            ->title('Vacaciones aprobadas')
                            ->body("Las vacaciones de {$record->employee->full_name} fueron aprobadas exitosamente.")
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->modalHeading('Rechazar Solicitud de Vacaciones')
                    ->modalDescription(fn ($record) => "¿Está seguro de rechazar las vacaciones de {$record->employee->full_name}?")
                    ->action(function ($record) {
                        VacationService::reject($record);

                        Notification::make()
                            ->title('Vacaciones rechazadas')
                            ->body("Las vacaciones de {$record->employee->full_name} fueron rechazadas.")
                            ->warning()
                            ->send();
                    }),

                Action::make('unapprove')
                    ->label('Desaprobar')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === 'approved' && $record->payment_status !== 'paid')
                    ->requiresConfirmation()
                    ->modalHeading('Desaprobar Solicitud de Vacaciones')
                    ->modalDescription(function ($record) {
                        $base = "¿Revertir la aprobación de las vacaciones de {$record->employee->full_name}? La solicitud volverá a estado pendiente.";

                        if (! $record->start_date->isFuture()) {
                            $base = "⚠️ Atención: estas vacaciones ya comenzaron o ya pasaron. Al desaprobar, los días usados se devolverán al balance. Proceda solo si fue un error de carga.\n\n".$base;
                        }

                        return $base;
                    })
                    ->modalSubmitActionLabel('Sí, desaprobar')
                    ->action(function ($record) {
                        VacationService::unapprove($record);

                        Notification::make()
                            ->title('Vacaciones desaprobadas')
                            ->body("Las vacaciones de {$record->employee->full_name} fueron desaprobadas.")
                            ->warning()
                            ->send();
                    }),

                Action::make('mark_paid')
                    ->label('Marcar como pagado')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn ($record) => $record->isApproved()
                        && $record->payment_status === 'unpaid'
                        && $record->payment_method === 'immediate')
                    ->modalHeading('Registrar pago vacacional')
                    ->modalDescription(fn ($record) => 'Monto a registrar: Gs. '.number_format((float) $record->payment_amount, 0, ',', '.'))
                    ->modalSubmitActionLabel('Sí, registrar pago')
                    ->form([
                        DatePicker::make('paid_at')
                            ->label('Fecha de pago')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->closeOnDateSelection()
                            ->default(now())
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        VacationService::recordPayment($record, Carbon::parse($data['paid_at']));

                        Notification::make()
                            ->success()
                            ->title('Pago registrado')
                            ->body("El pago vacacional de {$record->employee->full_name} fue registrado.")
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approveBulk')
                        ->label('Aprobar')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar Vacaciones')
                        ->modalDescription('Se aprobarán las vacaciones seleccionadas que estén en estado pendiente.')
                        ->action(function (Collection $records) {
                            $approved = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if ($record->isPending()) {
                                    VacationService::approve($record);
                                    $approved++;
                                } else {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title('Vacaciones Aprobadas')
                                ->body("Se aprobaron {$approved} solicitudes.".($skipped > 0 ? " Se omitieron {$skipped} que no estaban pendientes." : ''))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('rejectBulk')
                        ->label('Rechazar')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Rechazar Vacaciones')
                        ->modalDescription('Se rechazarán las vacaciones seleccionadas que estén en estado pendiente.')
                        ->action(function (Collection $records) {
                            $rejected = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if ($record->isPending()) {
                                    VacationService::reject($record);
                                    $rejected++;
                                } else {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->warning()
                                ->title('Vacaciones Rechazadas')
                                ->body("Se rechazaron {$rejected} solicitudes.".($skipped > 0 ? " Se omitieron {$skipped} que no estaban pendientes." : ''))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    ExportBulkAction::make()
                        ->exports([
                            ExcelExport::make()
                                ->fromTable()
                                ->except([
                                    'created_at',
                                    'updated_at',
                                    'employee_id',
                                ])
                                ->withFilename('vacaciones_'.now()->format('d_m_Y_H_i_s')),
                        ])
                        ->label('Exportar a Excel')
                        ->color('info')
                        ->icon('heroicon-o-arrow-down-tray'),
                ]),
            ])
            ->emptyStateHeading('No hay solicitudes de vacaciones')
            ->emptyStateDescription('Las solicitudes de vacaciones aparecerán aquí una vez que sean creadas.')
            ->emptyStateIcon('heroicon-o-sun');
    }

    /**
     * Define el infolist para la vista de detalle de una vacación.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfolistSection::make('Empleado')
                    ->icon('heroicon-o-user')
                    ->schema([
                        Group::make([
                            TextEntry::make('employee.full_name')
                                ->label('Nombre')
                                ->icon('heroicon-o-user'),

                            TextEntry::make('employee.ci')
                                ->label('Cédula de Identidad')
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

                            TextEntry::make('employee.branch.name')
                                ->label('Sucursal')
                                ->icon('heroicon-o-building-storefront')
                                ->placeholder('-'),
                        ])->columns(4),
                    ]),

                InfolistSection::make('Período de Vacaciones')
                    ->icon('heroicon-o-sun')
                    ->schema([
                        Group::make([
                            TextEntry::make('start_date')
                                ->label('Fecha de Inicio')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar'),

                            TextEntry::make('end_date')
                                ->label('Fecha de Fin')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-calendar'),

                            TextEntry::make('return_date')
                                ->label('Fecha de Reintegro')
                                ->date('d/m/Y')
                                ->icon('heroicon-o-arrow-uturn-right')
                                ->placeholder('-'),

                            TextEntry::make('business_days')
                                ->label('Días Hábiles')
                                ->icon('heroicon-o-calculator')
                                ->badge()
                                ->color('info')
                                ->suffix(' días'),
                        ])->columns(4),

                        Group::make([
                            TextEntry::make('payment_method')
                                ->label('Forma de pago')
                                ->formatStateUsing(fn (string $state) => Vacation::getPaymentMethodLabel($state))
                                ->color(fn (string $state) => Vacation::getPaymentMethodColor($state))
                                ->icon(fn (string $state) => Vacation::getPaymentMethodIcon($state))
                                ->badge(),

                            TextEntry::make('status')
                                ->label('Estado')
                                ->formatStateUsing(fn (string $state) => Vacation::getStatusLabel($state))
                                ->color(fn (string $state) => Vacation::getStatusColor($state))
                                ->icon(fn (string $state) => Vacation::getStatusIcon($state))
                                ->badge(),

                            TextEntry::make('created_at')
                                ->label('Solicitado')
                                ->dateTime('d/m/Y H:i')
                                ->icon('heroicon-o-clock'),
                        ])->columns(3),
                    ]),

                InfolistSection::make('Balance de Vacaciones')
                    ->icon('heroicon-o-chart-bar')
                    ->schema([
                        Group::make([
                            TextEntry::make('vacationBalance.entitled_days')
                                ->label('Días con Derecho')
                                ->suffix(' días')
                                ->icon('heroicon-o-gift'),

                            TextEntry::make('vacationBalance.used_days')
                                ->label('Días Usados')
                                ->suffix(' días')
                                ->icon('heroicon-o-check-circle')
                                ->color('success'),

                            TextEntry::make('vacationBalance.pending_days')
                                ->label('Días Pendientes')
                                ->suffix(' días')
                                ->icon('heroicon-o-clock')
                                ->color('warning'),

                            TextEntry::make('vacationBalance.available_days')
                                ->label('Días Disponibles')
                                ->suffix(' días')
                                ->icon('heroicon-o-star')
                                ->color('info'),
                        ])->columns(4),
                    ])
                    ->visible(fn (Vacation $record) => $record->vacationBalance !== null),

                InfolistSection::make('Remuneración Vacacional')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Group::make([
                            TextEntry::make('payment_amount')
                                ->label('Monto a Cobrar')
                                ->money('PYG', locale: 'es_PY')
                                ->icon('heroicon-o-banknotes')
                                ->placeholder('No calculado'),

                            TextEntry::make('payment_status')
                                ->label('Estado de Pago')
                                ->formatStateUsing(fn (?string $state) => match ($state) {
                                    'paid' => 'Pagado',
                                    'unpaid' => 'Pendiente',
                                    default => '-',
                                })
                                ->color(fn (?string $state) => match ($state) {
                                    'paid' => 'success',
                                    'unpaid' => 'warning',
                                    default => 'gray',
                                })
                                ->badge(),

                            TextEntry::make('paid_at')
                                ->label('Fecha de Pago')
                                ->dateTime('d/m/Y H:i')
                                ->icon('heroicon-o-calendar-days')
                                ->placeholder('No registrado'),
                        ])->columns(3),
                    ])
                    ->visible(fn (Vacation $record) => $record->isApproved()),
            ]);
    }

    /**
     * Define las páginas para el recurso de vacaciones.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVacations::route('/'),
            'create' => Pages\CreateVacation::route('/create'),
            'view' => Pages\ViewVacation::route('/{record}'),
            'edit' => Pages\EditVacation::route('/{record}/edit'),
        ];
    }

    /**
     * Define la insignia de navegación para el recurso de vacaciones.
     */
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ?: null;
    }

    /**
     * Define el color de la insignia de navegación para el recurso de vacaciones.
     */
    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Define el tooltip de la insignia de navegación para el recurso de vacaciones, indicando que el número representa las "Solicitudes de vacaciones pendientes".
     */
    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Solicitudes de vacaciones pendientes';
    }
}
