<?php

namespace App\Filament\Resources;

use App\Filament\Actions\AttendanceDayActions;
use App\Filament\Pages\EmployeeMonthSheet;
use App\Filament\Resources\AttendanceDayResource\Pages;
use App\Filament\Resources\AttendanceDayResource\RelationManagers;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\AttendanceCalculator;
use App\Services\AttendanceEventCorrectionService;
use App\Services\OpenShiftCloser;
use App\Services\UnscheduledDayDiscarder;
use App\Settings\PayrollSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Fieldset;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Actions\ActionGroup as TableActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class AttendanceDayResource extends Resource
{
    protected static ?string $model = AttendanceDay::class;

    protected static ?string $navigationLabel = 'Asistencias';

    protected static ?string $label = 'asistencia';

    protected static ?string $pluralLabel = 'asistencias';

    protected static ?string $slug = 'asistencias';

    protected static ?string $navigationIcon = 'heroicon-o-check-circle';

    protected static ?string $navigationGroup = 'Asistencias';

    protected static ?int $navigationSort = 1;

    /**
     * Indica si el usuario puede modificar jornadas (aprobar, ajustar, recalcular). Las acciones
     * personalizadas de Filament no consultan la policy solas: sin esto cualquier usuario que
     * pueda ver el listado podría ejecutarlas.
     */
    public static function canManageDays(): bool
    {
        return auth()->user()?->can('update_attendance_day') === true;
    }

    /**
     * Indica si la fecha de la jornada ya está cubierta por una nómina generada del empleado y, si es
     * así, avisa al usuario. Cambiar aprobaciones o cálculos después dejaría la nómina desfasada.
     */
    public static function blockedByPayroll(AttendanceDay $day, ?OpenShiftCloser $closer = null): bool
    {
        if (! ($closer ?? app(OpenShiftCloser::class))->isPayrollLocked($day)) {
            return false;
        }

        Notification::make()
            ->warning()
            ->title('Nómina generada')
            ->body('El empleado ya tiene una nómina generada que incluye esta fecha: la asistencia no se puede modificar.')
            ->send();

        return true;
    }

    /**
     * Separa los días con horas extra pendientes de un rango entre los que se pueden aprobar en bloque
     * y los que requieren revisión individual (tope legal excedido, doble turno sin decidir, nómina generada).
     *
     * @return array{approvable: Collection<int, AttendanceDay>, skipped: array{limit: int, double_shift: int, payroll: int}}
     */
    public static function overtimeRangeCandidates(string $start, string $end): array
    {
        $closer = app(OpenShiftCloser::class);
        $skipped = ['limit' => 0, 'double_shift' => 0, 'payroll' => 0];

        $approvable = AttendanceDay::query()
            ->whereBetween('date', [$start, $end])
            ->where('extra_hours', '>', 0)
            ->where('overtime_approved', false)
            ->get()
            ->filter(function (AttendanceDay $day) use ($closer, &$skipped): bool {
                if ($day->overtime_limit_exceeded) {
                    $skipped['limit']++;

                    return false;
                }
                if (! $day->second_shift_regular && $day->hasMultipleShifts()) {
                    $skipped['double_shift']++;

                    return false;
                }
                if ($closer->isPayrollLocked($day)) {
                    $skipped['payroll']++;

                    return false;
                }

                return true;
            })
            ->values();

        return ['approvable' => $approvable, 'skipped' => $skipped];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información Básica')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Empleado')
                            ->relationship(
                                name: 'employee',
                                modifyQueryUsing: fn (Builder $query) => $query->orderBy('first_name')->orderBy('last_name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Model $record) => "{$record->first_name} {$record->last_name} (CI: {$record->ci})")
                            ->searchable(['first_name', 'last_name', 'ci'])
                            ->native(false)
                            ->required()
                            ->preload()
                            ->disabled(fn (string $operation) => $operation === 'edit')
                            ->columnSpanFull(),

                        DatePicker::make('date')
                            ->label('Fecha')
                            ->required()
                            ->maxDate(now())
                            ->native(false)
                            ->closeOnDateSelection()
                            ->disabled(fn (string $operation) => $operation === 'edit'),

                        Select::make('status')
                            ->label('Estado')
                            ->options(AttendanceDay::getStatusOptions())
                            ->native(false)
                            ->required(),

                        Placeholder::make('is_calculated_info')
                            ->label('Estado de Cálculo')
                            ->content(
                                fn (?AttendanceDay $record) => $record && $record->is_calculated
                                    ? 'Calculado el '.$record->calculated_at?->format('d/m/Y H:i')
                                    : 'Pendiente de calcular'
                            )
                            ->visible(fn (string $operation) => $operation === 'edit'),
                    ])
                    ->columns(3),

                Section::make('Configuración')
                    ->schema([
                        Toggle::make('anomaly_flag')
                            ->label('Marcar anomalía')
                            ->inline(false),

                        Placeholder::make('manual_adjustment')
                            ->label('Ajustado manualmente')
                            ->content(fn (?AttendanceDay $record) => $record?->manual_adjustment ? 'Sí' : 'No')
                            ->visibleOn('edit'),

                        Toggle::make('overtime_approved')
                            ->label('Aprobar horas extra')
                            ->inline(false)
                            ->visible(fn (?AttendanceDay $record) => $record && $record->extra_hours > 0),

                        Toggle::make('tardiness_deduction_approved')
                            ->label('Aprobar descuento de tardanza')
                            ->inline(false)
                            ->visible(fn (?AttendanceDay $record) => $record && $record->late_minutes > 0),

                        Textarea::make('notes')
                            ->label('Notas')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->collapsible(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('status', 'present')->orWhere('excluded_from_calculation', true))
                ->select('attendance_days.*')
                ->selectRaw(AttendanceDay::lastEventTypeSql().' as last_event_type')
                ->with(['employee.branch.company', 'employee.activeContract.position.department']))
            ->columns([
                TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('employee.full_name')
                    ->label('Empleado')
                    ->description(fn (AttendanceDay $record) => $record->employee ? "CI: {$record->employee->ci}" : '')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'employee',
                        fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('ci', 'like', "%{$search}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$search}%"]))
                    ))
                    ->wrap(),

                TextColumn::make('employee.branch.name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('employee.activeContract.position.name')
                    ->label('Cargo')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('employee.activeContract.position.department.name')
                    ->label('Departamento')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => AttendanceDay::getStatusLabel($state))
                    ->badge()
                    ->color(fn ($state) => AttendanceDay::getStatusColor($state))
                    ->icon(fn ($state) => AttendanceDay::getStatusIcon($state))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('check_in_time')
                    ->label('Entrada')
                    ->time('H:i')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color(fn (AttendanceDay $record) => $record->getCheckInStatusColor())
                    ->tooltip(fn (AttendanceDay $record) => $record->getCheckInTooltip())
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('check_out_time')
                    ->label('Salida')
                    ->time('H:i')
                    ->icon('heroicon-o-arrow-left-on-rectangle')
                    ->color(fn (AttendanceDay $record) => $record->getCheckOutStatusColor())
                    ->tooltip(fn (AttendanceDay $record) => $record->getCheckOutTooltip())
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('late_minutes')
                    ->label('Tardanza')
                    ->suffix(' min')
                    ->default(0)
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->toggleable(),

                TextColumn::make('total_hours')
                    ->label('Horas trabajadas')
                    ->suffix(' hrs')
                    ->default(0)
                    ->numeric(2)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('extra_hours')
                    ->label('Hrs Extra')
                    ->suffix(' hrs')
                    ->default(0)
                    ->numeric(2)
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->toggleable(),

                TextColumn::make('attention')
                    ->label('Pendiente')
                    ->getStateUsing(fn (AttendanceDay $record) => $record->attentionReasons())
                    ->badge()
                    ->color(fn (string $state) => $state === 'Sin salida' ? 'danger' : 'warning')
                    ->placeholder('Al día')
                    ->toggleable(),

            ])
            ->filters([
                SelectFilter::make('employee_id')
                    ->label('Empleado')
                    ->placeholder('Todos los empleados')
                    ->relationship('employee', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} (CI: {$record->ci})")
                    ->searchable(['first_name', 'last_name', 'ci'])
                    ->preload(false)
                    ->native(false)
                    ->multiple(),

                SelectFilter::make('branch')
                    ->label('Sucursal')
                    ->placeholder('Todas las sucursales')
                    ->options(function () {
                        return Branch::pluck('name', 'id');
                    })
                    ->query(function (Builder $query, array $data) {
                        if (filled($data['values'])) {
                            return $query->whereHas('employee', function (Builder $query) use ($data) {
                                $query->whereIn('branch_id', $data['values']);
                            });
                        }
                    })
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->multiple(),

                Filter::make('missing_check_out')
                    ->label('Sin salida registrada')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->missingCheckOut())
                    ->indicator('Sin salida registrada'),

                Filter::make('date')
                    ->label('Rango de Fechas')
                    ->form([
                        Select::make('preset')
                            ->label('Acceso rápido')
                            ->placeholder('Seleccionar período...')
                            ->options([
                                'today' => 'Hoy',
                                'this_week' => 'Esta semana',
                                'last_week' => 'Semana pasada',
                                'first_fortnight' => '1ra quincena (1-15)',
                                'second_fortnight' => '2da quincena (16-fin)',
                                'this_month' => 'Este mes',
                                'last_month' => 'Mes pasado',
                            ])
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, callable $set) {
                                if (! $state) {
                                    return;
                                }
                                [$from, $to] = match ($state) {
                                    'today' => [now(), now()],
                                    'this_week' => [now()->startOfWeek(), now()->endOfWeek()],
                                    'last_week' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
                                    'first_fortnight' => [now()->startOfMonth(), now()->startOfMonth()->addDays(14)],
                                    'second_fortnight' => [now()->startOfMonth()->addDays(15), now()->endOfMonth()],
                                    'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
                                    'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
                                    default => [null, null],
                                };
                                $set('from', $from?->toDateString());
                                $set('to', $to?->toDateString());
                            })
                            ->columnSpanFull(),
                        DatePicker::make('from')
                            ->label('Desde')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->maxDate(now()),
                        DatePicker::make('to')
                            ->label('Hasta')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->maxDate(now())
                            ->afterOrEqual('from'),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $query, $date): Builder => $query->where('date', '>=', $date))
                            ->when($data['to'], fn (Builder $query, $date): Builder => $query->where('date', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Desde '.Carbon::parse($data['from'])->format('d/m/Y'))
                                ->removeField('from');
                        }
                        if ($data['to'] ?? null) {
                            $indicators[] = Indicator::make('Hasta '.Carbon::parse($data['to'])->format('d/m/Y'))
                                ->removeField('to');
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                self::getFixEventsTableAction(),
                self::getMonthSheetTableAction(),

                TableActionGroup::make([
                    self::getApproveOvertimeTableAction(),
                    self::getRegularShiftTableAction(),
                    self::getApproveTardinessTableAction(),
                ])
                    ->label('Aprobar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->tooltip('Aprobar horas extra y tardanzas'),

                TableActionGroup::make([
                    self::getAdjustExtraHoursTableAction(),
                    self::getCalculateTableAction(),
                    self::getExcludeFromCalculationTableAction(),
                    self::getExportPdfTableAction(),
                ])
                    ->label('Más')
                    ->tooltip('Ajustar, recalcular o exportar'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    AttendanceDayActions::approveOvertimeBulk(),

                    AttendanceDayActions::revokeOvertimeBulk(),

                    AttendanceDayActions::approveTardinessBulk(),

                    AttendanceDayActions::revokeTardinessBulk(),

                    self::getCloseOpenShiftsBulkAction(),

                    self::getDiscardUnscheduledDaysBulkAction(),

                    AttendanceDayActions::calculateBulk(),
                ]),
            ])
            ->defaultSort('date', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No hay registros de asistencia')
            ->emptyStateDescription('Los registros de asistencia se generan automáticamente al registrar eventos de marcación. Asegúrate de que los empleados estén registrando sus entradas y salidas correctamente.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    /** Acción `approveOvertime` para tabla; ver {@see AttendanceDayActions}. */
    public static function getApproveOvertimeTableAction(): TableAction
    {
        return AttendanceDayActions::forTable()->approveOvertime();
    }

    /** Acción `approveOvertime` para páginas (encabezado); ver {@see AttendanceDayActions}. */
    public static function getApproveOvertimeAction(): Action
    {
        return AttendanceDayActions::forPage()->approveOvertime();
    }

    /**
     * Excluye (o vuelve a incluir) una jornada del cálculo: marcaciones de prueba o de instalación
     * que no deben generar tardanza, extras ni pago. Las marcaciones se conservan.
     */
    public static function getExcludeFromCalculationTableAction(): TableAction
    {
        return TableAction::make('exclude_from_calculation')
            ->label(fn (AttendanceDay $record) => $record->excluded_from_calculation ? 'Incluir en el cálculo' : 'Excluir del cálculo')
            ->icon(fn (AttendanceDay $record) => $record->excluded_from_calculation ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-no-symbol')
            ->color(fn (AttendanceDay $record) => $record->excluded_from_calculation ? 'gray' : 'warning')
            ->tooltip('Para marcaciones de prueba o de instalación: se conservan, pero no generan tardanza, extras ni pago')
            ->visible(fn (AttendanceDay $record) => auth()->user()?->can('update_attendance_day') === true)
            ->modalHeading(fn (AttendanceDay $record) => $record->excluded_from_calculation ? 'Incluir la jornada en el cálculo' : 'Excluir la jornada del cálculo')
            ->modalDescription(fn (AttendanceDay $record) => $record->excluded_from_calculation
                ? 'La jornada volverá a calcularse con sus marcaciones.'
                : 'La jornada dejará de contar como presente y no generará tardanza, extras ni pendientes. Las marcaciones no se borran y se puede deshacer.')
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->excluded_from_calculation ? 'Sí, incluir' : 'Sí, excluir')
            ->form(fn (AttendanceDay $record) => $record->excluded_from_calculation ? [] : [
                TextInput::make('reason')->label('Motivo')->placeholder('Ej: Prueba de instalación del terminal')->required()->maxLength(190),
            ])
            ->action(function (AttendanceDay $record, array $data) {
                if (app(OpenShiftCloser::class)->isPayrollLocked($record)) {
                    Notification::make()->warning()->title('No se puede cambiar')
                        ->body('El empleado ya tiene una nómina generada que incluye esta fecha.')->send();

                    return;
                }

                $exclude = ! $record->excluded_from_calculation;
                $record->excluded_from_calculation = $exclude;
                $record->exclusion_reason = $exclude ? ($data['reason'] ?? null) : null;
                $record->is_calculated = false;
                AttendanceCalculator::apply($record);
                $record->save();

                Notification::make()->success()
                    ->title($exclude ? 'Jornada excluida del cálculo' : 'Jornada incluida en el cálculo')->send();
            });
    }

    /**
     * Abre la ficha mensual del empleado en el mes de la jornada (botón de solo ícono, junto a Corregir marcaciones).
     */
    public static function getMonthSheetTableAction(): TableAction
    {
        return TableAction::make('view_month')
            ->label('Ver ficha del mes')
            ->icon('heroicon-o-calendar-days')
            ->iconButton()
            ->color('gray')
            ->tooltip('Ver el mes completo de este empleado: días, horas y alertas')
            ->url(fn (AttendanceDay $record) => EmployeeMonthSheet::getUrl([
                'employee' => $record->employee_id,
                'month' => $record->date->format('Y-m'),
            ]));
    }

    /**
     * Acción masiva que cierra las jornadas sin salida con la hora de fin del horario esperado de cada día.
     * Omite (y lo informa) las que no tienen horario, quedaron en un descanso, están en una nómina generada o
     * tienen aprobaciones de RR.HH.; lo cerrado queda como marcación manual.
     */
    public static function getCloseOpenShiftsBulkAction(): BulkAction
    {
        return BulkAction::make('close_open_shifts')
            ->label('Cerrar con la salida esperada')
            ->icon('heroicon-o-arrow-left-start-on-rectangle')
            ->color('warning')
            ->visible(fn () => auth()->user()?->can('update_attendance_event') ?? false)
            ->requiresConfirmation()
            ->modalHeading('Cerrar jornadas sin salida')
            ->modalDescription(function (Collection $records): string {
                $closer = app(OpenShiftCloser::class);
                $skips = [];
                $closable = 0;

                foreach ($records as $day) {
                    $skip = $closer->evaluate($day)['skip'];
                    $skip === null ? $closable++ : $skips[$skip] = ($skips[$skip] ?? 0) + 1;
                }

                $text = "Se agregará como salida la hora de fin del horario esperado a {$closable} de {$records->count()} jornada(s) seleccionada(s). Quedan como marcaciones manuales y cada jornada se recalcula.";

                if ($skips !== []) {
                    $labels = OpenShiftCloser::getSkipLabels();
                    $text .= ' Se omitirán: '.collect($skips)->map(fn (int $count, string $reason) => ($labels[$reason] ?? $reason).": {$count}")->implode('; ').'.';
                }

                return $text;
            })
            ->modalSubmitActionLabel('Sí, cerrar')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $closer = app(OpenShiftCloser::class);
                $closed = 0;
                $skips = [];
                $failed = 0;

                foreach ($records as $day) {
                    try {
                        $skip = $closer->close($day);
                    } catch (\InvalidArgumentException) {
                        $failed++;

                        continue;
                    }

                    $skip === null ? $closed++ : $skips[$skip] = ($skips[$skip] ?? 0) + 1;
                }

                $labels = OpenShiftCloser::getSkipLabels();
                $body = "Cerradas: {$closed}.";

                if ($skips !== []) {
                    $body .= ' Omitidas: '.collect($skips)->map(fn (int $count, string $reason) => ($labels[$reason] ?? $reason).": {$count}")->implode('; ').'.';
                }

                if ($failed > 0) {
                    $body .= " Con secuencia inválida (sin cambios): {$failed}.";
                }

                Notification::make()->title('Jornadas cerradas')->body($body)->color($closed > 0 ? 'success' : 'warning')->send();
            });
    }

    /**
     * Acción masiva que elimina jornadas sin horario esperado (típicamente marcaciones de prueba de la instalación
     * de un terminal). Elimina la jornada y sus marcaciones; omite las de nóminas generadas o con ajustes de RR.HH.
     */
    public static function getDiscardUnscheduledDaysBulkAction(): BulkAction
    {
        return BulkAction::make('discard_unscheduled_days')
            ->label('Descartar jornadas sin horario')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn () => auth()->user()?->can('delete_attendance_day') ?? false)
            ->requiresConfirmation()
            ->modalHeading('Descartar jornadas sin horario esperado')
            ->modalDescription(function (Collection $records): string {
                $discarder = app(UnscheduledDayDiscarder::class);
                $skips = [];
                $removable = 0;

                foreach ($records as $day) {
                    $skip = $discarder->skipReason($day);
                    $skip === null ? $removable++ : $skips[$skip] = ($skips[$skip] ?? 0) + 1;
                }

                $text = "Se eliminarán {$removable} de {$records->count()} jornada(s) seleccionada(s) junto con sus marcaciones. Usar solo con marcaciones de prueba: no se puede deshacer.";

                if ($skips !== []) {
                    $labels = UnscheduledDayDiscarder::getSkipLabels();
                    $text .= ' Se omitirán: '.collect($skips)->map(fn (int $count, string $reason) => ($labels[$reason] ?? $reason).": {$count}")->implode('; ').'.';
                }

                return $text;
            })
            ->modalSubmitActionLabel('Sí, descartar')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $discarder = app(UnscheduledDayDiscarder::class);
                $discarded = 0;
                $skips = [];

                foreach ($records as $day) {
                    $skip = $discarder->discard($day);
                    $skip === null ? $discarded++ : $skips[$skip] = ($skips[$skip] ?? 0) + 1;
                }

                $body = "Descartadas: {$discarded}.";

                if ($skips !== []) {
                    $labels = UnscheduledDayDiscarder::getSkipLabels();
                    $body .= ' Omitidas: '.collect($skips)->map(fn (int $count, string $reason) => ($labels[$reason] ?? $reason).": {$count}")->implode('; ').'.';
                }

                Notification::make()->title('Jornadas descartadas')->body($body)->color($discarded > 0 ? 'success' : 'warning')->send();
            });
    }

    /**
     * Corrige las marcaciones de la jornada desde el listado: agregar, cambiar tipo u hora y eliminar.
     */
    public static function getFixEventsTableAction(): TableAction
    {
        return TableAction::make('fix_events')
            ->label('Corregir marcaciones')
            ->icon('heroicon-o-pencil-square')
            ->iconButton()
            ->color('warning')
            ->visible(fn () => auth()->user()?->can('update_attendance_event') ?? false)
            ->tooltip('Agregar, cambiar o eliminar las marcaciones del día sin salir del listado')
            ->modalHeading(fn (AttendanceDay $record) => 'Marcaciones del '.$record->date->format('d/m/Y').' — '.($record->employee?->full_name ?? 'Empleado'))
            ->modalDescription('Los cambios se aplican al guardar y la jornada se recalcula. Una salida de madrugada va con la fecha del día siguiente.')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Guardar correcciones')
            ->mountUsing(function (Form $form, AttendanceDay $record) {
                $form->fill([
                    'events' => $record->events()->orderBy('recorded_at')->orderBy('id')->get()
                        ->map(fn (AttendanceEvent $event) => [
                            'id' => $event->id,
                            'event_type' => $event->event_type,
                            'recorded_at' => $event->recorded_at->format('Y-m-d H:i:s'),
                        ])->all(),
                ]);
            })
            ->form([
                Repeater::make('events')
                    ->label('Marcaciones')
                    ->schema([
                        Hidden::make('id'),
                        Select::make('event_type')
                            ->label('Tipo')
                            ->options(AttendanceEvent::getEventTypeOptions())
                            ->native(false)
                            ->required(),
                        DateTimePicker::make('recorded_at')
                            ->label('Fecha y hora')
                            ->seconds(false)
                            ->native(false)
                            ->displayFormat('d/m/Y H:i')
                            ->maxDate(now())
                            ->required(),
                    ])
                    ->columns(2)
                    ->addActionLabel('Agregar marcación')
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->itemLabel(fn (array $state) => filled($state['event_type'] ?? null)
                        ? AttendanceEvent::getEventTypeLabel($state['event_type'])
                        : 'Nueva marcación')
                    ->deleteAction(fn ($action) => $action->requiresConfirmation()->modalSubmitActionLabel('Sí, quitar')),
            ])
            ->action(function (AttendanceDay $record, array $data, TableAction $action) {
                try {
                    $summary = app(AttendanceEventCorrectionService::class)->apply($record, $data['events'] ?? []);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title('No se guardaron los cambios')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title('Marcaciones corregidas')
                    ->body("Agregadas: {$summary['created']}, modificadas: {$summary['updated']}, eliminadas: {$summary['deleted']}. La jornada se recalculó.")
                    ->send();
            });
    }

    /** Acción `regularShift` para tabla; ver {@see AttendanceDayActions}. */
    public static function getRegularShiftTableAction(): TableAction
    {
        return AttendanceDayActions::forTable()->regularShift();
    }

    /** Acción `regularShift` para páginas (encabezado); ver {@see AttendanceDayActions}. */
    public static function getRegularShiftAction(): Action
    {
        return AttendanceDayActions::forPage()->regularShift();
    }

    /** Acción `approveTardiness` para tabla; ver {@see AttendanceDayActions}. */
    public static function getApproveTardinessTableAction(): TableAction
    {
        return AttendanceDayActions::forTable()->approveTardiness();
    }

    /** Acción `approveTardiness` para páginas (encabezado); ver {@see AttendanceDayActions}. */
    public static function getApproveTardinessAction(): Action
    {
        return AttendanceDayActions::forPage()->approveTardiness();
    }

    /** Acción `exportPdf` para tabla; ver {@see AttendanceDayActions}. */
    public static function getExportPdfTableAction(): TableAction
    {
        return AttendanceDayActions::forTable()->exportPdf();
    }

    /** Acción `exportPdf` para páginas (encabezado); ver {@see AttendanceDayActions}. */
    public static function getExportPdfAction(): Action
    {
        return AttendanceDayActions::forPage()->exportPdf();
    }

    /** Acción `calculate` para tabla; ver {@see AttendanceDayActions}. */
    public static function getCalculateTableAction(): TableAction
    {
        return AttendanceDayActions::forTable()->calculate();
    }

    /** Acción `calculate` para páginas (encabezado); ver {@see AttendanceDayActions}. */
    public static function getCalculateAction(): Action
    {
        return AttendanceDayActions::forPage()->calculate();
    }

    /**
     * Retorna la acción de aprobar horas extra en un rango de fechas
     */
    public static function getApproveOvertimeRangeAction(): Action
    {
        return Action::make('approve_overtime_range')
            ->authorize(fn (): bool => self::canManageDays())
            ->label('Aprobar HE del período')
            ->icon('heroicon-o-check-badge')
            ->color('warning')
            ->tooltip('Aprueba masivamente las horas extra pendientes en un rango de fechas')
            ->form([
                DatePicker::make('start_date')
                    ->label('Fecha de inicio')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->closeOnDateSelection()
                    ->required()
                    ->maxDate(now())
                    ->default(now()->startOfMonth())
                    ->live(),

                DatePicker::make('end_date')
                    ->label('Fecha de fin')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->closeOnDateSelection()
                    ->required()
                    ->maxDate(now())
                    ->default(now())
                    ->afterOrEqual('start_date')
                    ->minDate(fn (Get $get) => $get('start_date'))
                    ->live(),

                Placeholder::make('preview')
                    ->label('Vista previa')
                    ->content(function (Get $get) {
                        $start = $get('start_date');
                        $end = $get('end_date');

                        if (! $start || ! $end) {
                            return 'Selecciona ambas fechas para ver el resumen.';
                        }

                        ['approvable' => $approvable, 'skipped' => $skipped] = self::overtimeRangeCandidates($start, $end);
                        $skippedTotal = array_sum($skipped);

                        if ($approvable->isEmpty() && $skippedTotal === 0) {
                            return 'No hay horas extra pendientes de aprobación en este período.';
                        }

                        $summary = "Se aprobarán {$approvable->count()} registro(s) con un total de {$approvable->sum('extra_hours')} hrs extra.";

                        if ($skippedTotal > 0) {
                            $summary .= " Quedan para revisión individual {$skippedTotal}: {$skipped['limit']} con tope legal excedido, {$skipped['double_shift']} con doble turno sin decidir y {$skipped['payroll']} con nómina generada.";
                        }

                        return $summary;
                    })
                    ->columnSpanFull(),
            ])
            ->requiresConfirmation()
            ->modalHeading('Aprobar horas extra del período')
            ->modalDescription('Se aprobarán todas las horas extra pendientes en el rango de fechas seleccionado.')
            ->modalSubmitActionLabel('Sí, aprobar todo')
            ->action(function (array $data) {
                $startDate = Carbon::parse($data['start_date']);
                $endDate = Carbon::parse($data['end_date']);

                ['approvable' => $approvable, 'skipped' => $skipped] = self::overtimeRangeCandidates($startDate->toDateString(), $endDate->toDateString());

                foreach ($approvable as $day) {
                    $day->overtime_approved = true;
                    $day->save();
                }

                $skippedTotal = array_sum($skipped);

                if ($approvable->isEmpty()) {
                    Notification::make()
                        ->title('Sin cambios')
                        ->body($skippedTotal > 0
                            ? "No se aprobó nada: {$skippedTotal} registro(s) requieren revisión individual."
                            : 'No había horas extra pendientes de aprobación en el período seleccionado.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Horas extra aprobadas')
                    ->body("Se aprobaron {$approvable->count()} registro(s) entre {$startDate->format('d/m/Y')} y {$endDate->format('d/m/Y')}."
                        .($skippedTotal > 0 ? " Quedan {$skippedTotal} para revisión individual (tope legal: {$skipped['limit']}, doble turno: {$skipped['double_shift']}, nómina generada: {$skipped['payroll']})." : ''))
                    ->success()
                    ->duration(8000)
                    ->send();
            });
    }

    /** Acción `adjustExtraHours` para tabla; ver {@see AttendanceDayActions}. */
    public static function getAdjustExtraHoursTableAction(): TableAction
    {
        return AttendanceDayActions::forTable()->adjustExtraHours();
    }

    /** Acción `adjustExtraHours` para páginas (encabezado); ver {@see AttendanceDayActions}. */
    public static function getAdjustExtraHoursAction(): Action
    {
        return AttendanceDayActions::forPage()->adjustExtraHours();
    }

    /**
     * Retorna la acción de registrar horas extras manualmente para empleados sin marcación (header action)
     */
    public static function getRegisterExtraHoursAction(): Action
    {
        $settings = app(PayrollSettings::class);
        $pctDiurno = (int) (($settings->overtime_multiplier_diurno - 1) * 100);
        $pctNocturno = (int) (($settings->overtime_multiplier_nocturno - 1) * 100);

        return Action::make('register_extra_hours')
            ->authorize(fn (): bool => self::canManageDays())
            ->label('Registrar Horas Extras')
            ->icon('heroicon-o-plus-circle')
            ->color('primary')
            ->tooltip('Registrar horas extras para empleados que no marcaron ese día')
            ->form([
                Select::make('employee_id')
                    ->label('Empleado')
                    ->options(fn () => Employee::where('status', 'active')
                        ->orderBy('first_name')->orderBy('last_name')
                        ->get()
                        ->mapWithKeys(fn ($e) => [$e->id => "{$e->first_name} {$e->last_name} (CI: {$e->ci})"])
                    )
                    ->searchable()
                    ->native(false)
                    ->required()
                    ->live()
                    ->columnSpanFull(),

                DatePicker::make('date')
                    ->label('Fecha')
                    ->required()
                    ->maxDate(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->closeOnDateSelection()
                    ->live(),

                TextInput::make('extra_hours_diurnas')
                    ->label("Horas extras diurnas ({$pctDiurno}%)")
                    ->numeric()
                    ->step(0.5)
                    ->minValue(0)
                    ->maxValue(24)
                    ->default(0)
                    ->suffix('hrs'),

                TextInput::make('extra_hours_nocturnas')
                    ->label("Horas extras nocturnas ({$pctNocturno}%)")
                    ->numeric()
                    ->step(0.5)
                    ->minValue(0)
                    ->maxValue(24)
                    ->default(0)
                    ->suffix('hrs'),

                Textarea::make('notes')
                    ->label('Notas')
                    ->maxLength(500)
                    ->rows(2)
                    ->columnSpanFull(),

                Placeholder::make('existing_warning')
                    ->label('Aviso')
                    ->content(function (Get $get) {
                        $employeeId = $get('employee_id');
                        $date = $get('date');

                        if (! $employeeId || ! $date) {
                            return null;
                        }

                        $existing = AttendanceDay::where('employee_id', $employeeId)
                            ->where('date', $date)
                            ->first();

                        if (! $existing) {
                            return null;
                        }

                        $statusLabel = AttendanceDay::getStatusLabel($existing->status);
                        $msg = "Ya existe un registro para este empleado en esta fecha (estado: {$statusLabel}";

                        if ((float) $existing->extra_hours > 0) {
                            $msg .= ", {$existing->extra_hours} hrs extra registradas";
                        }

                        return $msg.'). Los valores de horas extras serán reemplazados.';
                    })
                    ->visible(fn (Get $get) => filled($get('employee_id')) && filled($get('date'))
                        && AttendanceDay::where('employee_id', $get('employee_id'))->where('date', $get('date'))->exists()
                    )
                    ->columnSpanFull(),
            ])
            ->modalHeading('Registrar horas extras manuales')
            ->modalDescription('Carga horas extras para un empleado en una fecha específica, aunque no haya marcado entrada/salida ese día.')
            ->modalSubmitActionLabel('Registrar')
            ->action(function (array $data) {
                $diurnas = (float) ($data['extra_hours_diurnas'] ?? 0);
                $nocturnas = (float) ($data['extra_hours_nocturnas'] ?? 0);
                $total = round($diurnas + $nocturnas, 2);

                if ($total <= 0) {
                    Notification::make()
                        ->title('Sin horas extras')
                        ->body('Debe ingresar al menos 0.5 hrs de horas extras diurnas o nocturnas.')
                        ->warning()
                        ->send();

                    return;
                }

                $day = AttendanceDay::firstOrNew([
                    'employee_id' => $data['employee_id'],
                    'date' => $data['date'],
                ]);

                if (self::blockedByPayroll($day)) {
                    return;
                }

                $day->extra_hours_diurnas = $diurnas;
                $day->extra_hours_nocturnas = $nocturnas;
                $day->extra_hours = $total;
                $day->manual_adjustment = true;
                $day->overtime_approved = true;
                $day->status = 'present';
                $day->is_calculated = true;

                if (filled($data['notes'] ?? null)) {
                    $day->notes = $data['notes'];
                }

                $day->save();

                $employee = Employee::find($data['employee_id']);
                $empName = $employee ? "{$employee->first_name} {$employee->last_name}" : 'Empleado';
                $dateStr = Carbon::parse($data['date'])->format('d/m/Y');

                Notification::make()
                    ->title('Horas extras registradas')
                    ->body("Se registraron {$total} hrs extras ({$diurnas}h diurnas + {$nocturnas}h nocturnas) para {$empName} el {$dateStr}.")
                    ->success()
                    ->send();
            });
    }

    /**
     * Define las relaciones para el recurso
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\EventsRelationManager::class,
            RelationManagers\AuditsRelationManager::class,
        ];
    }

    /**
     * Define las páginas para el recurso
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendanceDays::route('/'),
            'view' => Pages\ViewAttendanceDay::route('/{record}'),
            'create' => Pages\CreateAttendanceDay::route('/create'),
            'edit' => Pages\EditAttendanceDay::route('/{record}/edit'),
        ];
    }

    /**
     * Define el diseño de la página de detalle (infolist) para un registro de asistencia
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Fieldset::make('Información General')
                    ->schema([
                        TextEntry::make('date')
                            ->label('Fecha')
                            ->date('d/m/Y')
                            ->icon('heroicon-o-calendar'),

                        TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->color(fn ($state) => AttendanceDay::getStatusColor($state))
                            ->formatStateUsing(fn ($state) => AttendanceDay::getStatusLabel($state))
                            ->icon(fn ($state) => AttendanceDay::getStatusIcon($state)),

                        TextEntry::make('is_calculated')
                            ->label('Estado de Cálculo')
                            ->badge()
                            ->color(fn ($state) => AttendanceDay::getBooleanColor($state, 'success', 'warning'))
                            ->formatStateUsing(fn ($state) => $state ? 'Calculado' : 'Pendiente')
                            ->icon(fn ($state) => $state ? 'heroicon-o-check-circle' : 'heroicon-o-clock'),

                        TextEntry::make('calculated_at')
                            ->label('Último Cálculo')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('Nunca calculado')
                            ->icon('heroicon-o-calendar-days')
                            ->hidden(fn ($record) => ! $record->is_calculated),

                        TextEntry::make('anomaly_flag')
                            ->label('Anomalía')
                            ->badge()
                            ->color(fn ($state) => AttendanceDay::getBooleanColor($state, 'danger', 'success'))
                            ->formatStateUsing(fn ($state) => AttendanceDay::formatBoolean($state))
                            ->icon(fn ($state) => $state ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'),

                        TextEntry::make('manual_adjustment')
                            ->label('Ajuste Manual')
                            ->badge()
                            ->color(fn ($state) => AttendanceDay::getBooleanColor($state, 'info', 'gray'))
                            ->formatStateUsing(fn ($state) => AttendanceDay::formatBoolean($state))
                            ->icon(fn ($state) => $state ? 'heroicon-o-pencil-square' : null),
                    ])->columns(3),

                Fieldset::make('Empleado')
                    ->schema([
                        TextEntry::make('employee.ci')
                            ->label('Cédula de Identidad')
                            ->icon('heroicon-o-identification')
                            ->copyable()
                            ->copyMessage('CI copiado')
                            ->weight('bold'),

                        TextEntry::make('employee.full_name')
                            ->label('Nombre Completo')
                            ->icon('heroicon-o-user'),

                        TextEntry::make('employee.branch.company.name')
                            ->label('Empresa')
                            ->icon('heroicon-o-building-office-2')
                            ->badge()
                            ->color('gray'),

                        TextEntry::make('employee.branch.name')
                            ->label('Sucursal')
                            ->icon('heroicon-o-building-storefront')
                            ->badge()
                            ->color('info'),

                        TextEntry::make('employee.activeContract.position.name')
                            ->label('Cargo')
                            ->icon('heroicon-o-briefcase'),

                        TextEntry::make('employee.activeContract.position.department.name')
                            ->label('Departamento')
                            ->icon('heroicon-o-building-library')
                            ->default('N/A'),
                    ])->columns(3),

                Fieldset::make('Condiciones Especiales')
                    ->schema([
                        TextEntry::make('is_extraordinary_work')
                            ->label('Trabajo Extraordinario')
                            ->badge()
                            ->color(fn ($state) => AttendanceDay::getBooleanColor($state, 'warning'))
                            ->formatStateUsing(fn ($state) => AttendanceDay::formatBoolean($state))
                            ->icon(fn ($state) => $state ? 'heroicon-o-star' : null)
                            ->hidden(fn ($record) => ! $record->is_extraordinary_work),

                        TextEntry::make('is_weekend')
                            ->label('Fin de Semana')
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn ($state) => AttendanceDay::formatBoolean($state))
                            ->icon('heroicon-o-calendar')
                            ->hidden(fn ($record) => ! $record->is_weekend),

                        TextEntry::make('is_holiday')
                            ->label('Feriado')
                            ->badge()
                            ->color('info')
                            ->formatStateUsing(fn ($state) => AttendanceDay::formatBoolean($state))
                            ->icon('heroicon-o-gift')
                            ->hidden(fn ($record) => ! $record->is_holiday),

                        TextEntry::make('on_vacation')
                            ->label('Vacaciones')
                            ->badge()
                            ->color('success')
                            ->formatStateUsing(fn ($state) => AttendanceDay::formatBoolean($state))
                            ->icon('heroicon-o-sun')
                            ->hidden(fn ($record) => ! $record->on_vacation),

                        TextEntry::make('justified_absence')
                            ->label('Ausencia Justificada')
                            ->badge()
                            ->color('warning')
                            ->formatStateUsing(fn ($state) => AttendanceDay::formatBoolean($state))
                            ->icon('heroicon-o-document-text')
                            ->hidden(fn ($record) => ! $record->justified_absence),

                        TextEntry::make('notes')
                            ->label('Notas')
                            ->columnSpanFull()
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->hidden(fn ($record) => empty($record->notes)),
                    ])->columns(3)
                    ->hidden(fn ($record) => ! $record->is_extraordinary_work && ! $record->is_weekend && ! $record->is_holiday && ! $record->on_vacation && ! $record->justified_absence && empty($record->notes)),

                Fieldset::make('Tiempos de Entrada y Salida')
                    ->schema([
                        TextEntry::make('expected_check_in')
                            ->label('Entrada Esperada')
                            ->icon('heroicon-o-clock')
                            ->placeholder('No definida'),

                        TextEntry::make('check_in_time')
                            ->label('Entrada Marcada')
                            ->icon('heroicon-o-arrow-right-on-rectangle')
                            ->color(fn ($record) => $record->late_minutes > 0 ? 'danger' : 'success')
                            ->weight(fn ($record) => $record->late_minutes > 0 ? 'bold' : null)
                            ->placeholder('Sin marcar'),

                        TextEntry::make('late_minutes')
                            ->label('Retraso')
                            ->badge()
                            ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                            ->formatStateUsing(fn ($state) => $state > 0 ? "{$state} min tarde" : 'A tiempo')
                            ->icon(fn ($state) => $state > 0 ? 'heroicon-o-exclamation-circle' : 'heroicon-o-check-circle'),

                        TextEntry::make('expected_check_out')
                            ->label('Salida Esperada')
                            ->icon('heroicon-o-clock')
                            ->placeholder('No definida'),

                        TextEntry::make('check_out_time')
                            ->label('Salida Marcada')
                            ->icon('heroicon-o-arrow-left-on-rectangle')
                            ->color(fn ($record) => $record->early_leave_minutes > 0 ? 'warning' : 'success')
                            ->weight(fn ($record) => $record->early_leave_minutes > 0 ? 'bold' : null)
                            ->placeholder('Sin marcar'),

                        TextEntry::make('early_leave_minutes')
                            ->label('Salida Anticipada')
                            ->badge()
                            ->color(fn ($state) => $state > 0 ? 'warning' : 'success')
                            ->formatStateUsing(fn ($state) => $state > 0 ? "{$state} min antes" : 'A tiempo')
                            ->icon(fn ($state) => $state > 0 ? 'heroicon-o-exclamation-circle' : 'heroicon-o-check-circle'),
                    ])->columns(3)
                    ->hidden(fn ($record) => ! $record->check_in_time && ! $record->check_out_time),

                Fieldset::make('Resumen de Horas')
                    ->schema([
                        TextEntry::make('expected_hours')
                            ->label('Horas Esperadas')
                            ->icon('heroicon-o-clock')
                            ->suffix(' hrs')
                            ->placeholder('No definidas'),

                        TextEntry::make('total_hours')
                            ->label('Horas Trabajadas')
                            ->icon('heroicon-o-calculator')
                            ->suffix(' hrs')
                            ->weight('bold')
                            ->color('primary')
                            ->placeholder('0 hrs'),

                        TextEntry::make('net_hours')
                            ->label('Horas Netas')
                            ->icon('heroicon-o-check-badge')
                            ->suffix(' hrs')
                            ->weight('bold')
                            ->color('success')
                            ->placeholder('0 hrs'),

                        TextEntry::make('expected_break_minutes')
                            ->label('Descanso Esperado')
                            ->icon('heroicon-o-clock')
                            ->suffix(' min')
                            ->placeholder('No definido'),

                        TextEntry::make('break_minutes')
                            ->label('Descanso Tomado')
                            ->icon('heroicon-o-pause-circle')
                            ->suffix(' min')
                            ->color(fn ($record) => $record->break_minutes > ($record->expected_break_minutes ?? 0) ? 'warning' : null)
                            ->weight(fn ($record) => $record->break_minutes > ($record->expected_break_minutes ?? 0) ? 'bold' : null)
                            ->placeholder('0 min'),

                        TextEntry::make('extra_hours')
                            ->label('Horas Extra')
                            ->badge()
                            ->icon('heroicon-o-star')
                            ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                            ->suffix(' hrs')
                            ->placeholder('0')
                            ->hidden(fn ($record) => $record->extra_hours <= 0 && ! $record->overtime_approved),

                        TextEntry::make('extra_hours_diurnas')
                            ->label('HE Diurnas')
                            ->badge()
                            ->color('success')
                            ->suffix(' hrs')
                            ->placeholder('0')
                            ->hidden(fn ($record) => ! $record->extra_hours_diurnas || $record->extra_hours_diurnas <= 0),

                        TextEntry::make('extra_hours_nocturnas')
                            ->label('HE Nocturnas')
                            ->badge()
                            ->color('info')
                            ->suffix(' hrs')
                            ->placeholder('0')
                            ->hidden(fn ($record) => ! $record->extra_hours_nocturnas || $record->extra_hours_nocturnas <= 0),

                        TextEntry::make('overtime_approved')
                            ->label('Aprobación Horas Extra')
                            ->badge()
                            ->color(fn ($state) => AttendanceDay::getBooleanColor($state, 'success', 'danger'))
                            ->formatStateUsing(fn ($state) => $state ? 'Aprobadas' : 'Pendientes')
                            ->icon(fn ($state) => $state ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                            ->hidden(fn ($record) => $record->extra_hours <= 0),

                        TextEntry::make('overtime_limit_exceeded')
                            ->label('Limite Legal')
                            ->badge()
                            ->color(fn ($state) => $state ? 'danger' : 'success')
                            ->formatStateUsing(fn ($state) => $state ? 'Excede 3 hrs/dia' : 'Dentro del limite')
                            ->icon(fn ($state) => $state ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
                            ->hidden(fn ($record) => $record->extra_hours <= 0),

                        TextEntry::make('tardiness_deduction_approved')
                            ->label('Descuento Tardanza')
                            ->badge()
                            ->color(fn ($state) => AttendanceDay::getBooleanColor($state, 'danger', 'gray'))
                            ->formatStateUsing(fn ($state) => $state ? 'Aprobado' : 'Sin descuento')
                            ->icon(fn ($state) => $state ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle')
                            ->hidden(fn ($record) => ($record->late_minutes ?? 0) <= 0),
                    ])->columns(3),
            ]);
    }
}
