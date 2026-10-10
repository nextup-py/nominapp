<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AttendanceDayResource\Pages;
use App\Filament\Resources\AttendanceDayResource\RelationManagers;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\AttendanceCalculator;
use App\Settings\PayrollSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
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
use Illuminate\Support\Facades\Log;

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
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'present')->with(['employee.branch.company', 'employee.activeContract.position.department']))
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
                        fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                    ))
                    ->wrap(),

                TextColumn::make('employee.branch.name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->toggleable(),

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
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->toggleable(isToggledHiddenByDefault: true),

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
                            ->when($data['from'], fn (Builder $query, $date): Builder => $query->whereDate('date', '>=', $date))
                            ->when($data['to'], fn (Builder $query, $date): Builder => $query->whereDate('date', '<=', $date));
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
                TableActionGroup::make([
                    self::getApproveOvertimeTableAction(),
                    self::getRegularShiftTableAction(),
                    self::getApproveTardinessTableAction(),
                    self::getAdjustExtraHoursTableAction(),
                    self::getExportPdfTableAction(),
                    self::getCalculateTableAction(),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_overtime')
                        ->label('Aprobar Horas Extra')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar horas extra seleccionadas')
                        ->modalDescription(function (Collection $records) {
                            $withExtraHours = $records->where('extra_hours', '>', 0);
                            $totalHours = $withExtraHours->sum('extra_hours');
                            $alreadyApproved = $withExtraHours->where('overtime_approved', true)->count();
                            $pending = $withExtraHours->where('overtime_approved', false)->count();

                            return "Total: {$withExtraHours->count()} registro(s) con {$totalHours} hrs extra. Aprobadas: {$alreadyApproved}, Pendientes: {$pending}.";
                        })
                        ->modalSubmitActionLabel('Sí, aprobar')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $approved = 0;
                            $skipped = 0;

                            foreach ($records as $day) {
                                if ($day->extra_hours > 0 && ! $day->overtime_approved) {
                                    $day->overtime_approved = true;
                                    $day->save();
                                    $approved++;
                                } else {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->title('Aprobación completada')
                                ->body("Aprobadas: {$approved} | Omitidas (sin hrs extra o ya aprobadas): {$skipped}")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('revoke_overtime')
                        ->label('Revocar Horas Extra')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Revocar aprobación de horas extra')
                        ->modalDescription(function (Collection $records) {
                            $approved = $records->where('overtime_approved', true)->count();
                            $totalHours = $records->where('overtime_approved', true)->sum('extra_hours');

                            return "Se revocará la aprobación de {$approved} registro(s) con {$totalHours} hrs extra aprobadas.";
                        })
                        ->modalSubmitActionLabel('Sí, revocar')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $revoked = 0;
                            $skipped = 0;

                            foreach ($records as $day) {
                                if ($day->overtime_approved) {
                                    $day->overtime_approved = false;
                                    $day->save();
                                    $revoked++;
                                } else {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->title('Revocación completada')
                                ->body("Revocadas: {$revoked} | Omitidas (ya sin aprobar): {$skipped}")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('approve_tardiness')
                        ->label('Aprobar Descuento Tardanza')
                        ->icon('heroicon-o-check-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Aprobar descuento de tardanza seleccionados')
                        ->modalDescription(function (Collection $records) {
                            $withTardiness = $records->where('late_minutes', '>', 0);
                            $pending = $withTardiness->where('tardiness_deduction_approved', false)->count();

                            return "Total: {$withTardiness->count()} registro(s) con tardanza. Pendientes de aprobación: {$pending}.";
                        })
                        ->modalSubmitActionLabel('Sí, aprobar')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $approved = 0;
                            $skipped = 0;
                            foreach ($records as $day) {
                                if ($day->late_minutes > 0 && ! $day->tardiness_deduction_approved) {
                                    $day->tardiness_deduction_approved = true;
                                    $day->save();
                                    $approved++;
                                } else {
                                    $skipped++;
                                }
                            }
                            Notification::make()
                                ->title('Aprobación completada')
                                ->body("Aprobadas: {$approved} | Omitidas: {$skipped}")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('revoke_tardiness')
                        ->label('Revocar Descuento Tardanza')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Revocar aprobación de tardanza')
                        ->modalDescription(function (Collection $records) {
                            $approved = $records->where('tardiness_deduction_approved', true)->count();
                            $totalMinutes = $records->where('tardiness_deduction_approved', true)->sum('late_minutes');

                            return "Se revocará el descuento de {$approved} registro(s) con {$totalMinutes} min de tardanza aprobados.";
                        })
                        ->modalSubmitActionLabel('Sí, revocar')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $revoked = 0;
                            $skipped = 0;
                            foreach ($records as $day) {
                                if ($day->tardiness_deduction_approved) {
                                    $day->tardiness_deduction_approved = false;
                                    $day->save();
                                    $revoked++;
                                } else {
                                    $skipped++;
                                }
                            }
                            Notification::make()
                                ->title('Revocación completada')
                                ->body("Revocadas: {$revoked} | Omitidas: {$skipped}")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('calculate')
                        ->label('Calcular/Recalcular')
                        ->icon('heroicon-o-calculator')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Calcular asistencias seleccionadas')
                        ->modalDescription(function (Collection $records) {
                            $calculated = $records->where('is_calculated', true)->count();
                            $notCalculated = $records->where('is_calculated', false)->count();

                            return "Se procesarán {$records->count()} registro(s): {$notCalculated} sin calcular y {$calculated} para recalcular.";
                        })
                        ->modalSubmitActionLabel('Sí, calcular')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $successful = 0;
                            $failed = 0;
                            $recalculated = 0;
                            $calculated = 0;
                            $statusCounts = [];

                            foreach ($records as $day) {
                                try {
                                    $wasCalculated = $day->is_calculated;

                                    AttendanceCalculator::apply($day);
                                    $day->save();

                                    $successful++;
                                    $wasCalculated ? $recalculated++ : $calculated++;
                                    $statusCounts[$day->status] = ($statusCounts[$day->status] ?? 0) + 1;
                                } catch (\Exception $e) {
                                    $failed++;
                                    Log::error("Error calculando AttendanceDay ID {$day->id}: {$e->getMessage()}", [
                                        'attendance_day_id' => $day->id,
                                        'date' => $day->date,
                                        'trace' => $e->getTraceAsString(),
                                    ]);
                                }
                            }

                            $statusLabels = AttendanceDay::getStatusOptions();

                            $breakdown = collect($statusCounts)
                                ->map(fn ($count, $status) => ($statusLabels[$status] ?? $status).": {$count}")
                                ->implode(' · ');

                            $summary = "Procesados: {$successful} ({$calculated} nuevos · {$recalculated} recalculados)";
                            if ($breakdown) {
                                $summary .= "\nEstados: {$breakdown}";
                            }
                            if ($failed > 0) {
                                $summary .= "\nFallidos: {$failed}";
                            }

                            Notification::make()
                                ->title($failed > 0 ? 'Cálculo completado con advertencias' : 'Cálculo completado')
                                ->body($summary)
                                ->color($failed > 0 ? 'warning' : 'success')
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('date', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No hay registros de asistencia')
            ->emptyStateDescription('Los registros de asistencia se generan automáticamente al registrar eventos de marcación. Asegúrate de que los empleados estén registrando sus entradas y salidas correctamente.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    /**
     * Retorna la acción de aprobar/revocar horas extras para tabla
     */
    public static function getApproveOvertimeTableAction(): TableAction
    {
        return TableAction::make('approve_overtime')
            ->label(fn (AttendanceDay $record) => $record->overtime_approved ? 'Revocar' : 'Aprobar')
            ->icon(fn (AttendanceDay $record) => $record->overtime_approved ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
            ->color(fn (AttendanceDay $record) => $record->overtime_approved ? 'danger' : 'success')
            ->visible(fn (AttendanceDay $record) => $record->extra_hours > 0)
            ->tooltip(
                fn (AttendanceDay $record) => $record->overtime_approved
                    ? 'Revocar aprobación de '.$record->extra_hours.' hrs extra'
                    : 'Aprobar '.$record->extra_hours.' hrs extra'
            )
            ->requiresConfirmation()
            ->modalHeading(
                fn (AttendanceDay $record) => $record->overtime_approved
                    ? 'Revocar aprobación de horas extra'
                    : 'Aprobar horas extra'
            )
            ->modalDescription(
                function (AttendanceDay $record) {
                    return self::buildOvertimeModalDescription($record);
                }
            )
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->overtime_approved ? 'Sí, revocar' : 'Sí, aprobar')
            ->action(function (AttendanceDay $record) {
                $wasApproved = $record->overtime_approved;
                $record->overtime_approved = ! $wasApproved;
                $record->save();

                $action = $wasApproved ? 'revocada' : 'aprobada';

                Notification::make()
                    ->title("Aprobación {$action}")
                    ->body("Las {$record->extra_hours} hrs extra han sido {$action}s exitosamente.")
                    ->success()
                    ->send();
            });
    }

    /**
     * Retorna la acción de aprobar/revocar horas extras para páginas (header actions)
     */
    public static function getApproveOvertimeAction(): Action
    {
        return Action::make('approve_overtime')
            ->label(fn (AttendanceDay $record) => $record->overtime_approved ? 'Revocar Horas Extra' : 'Aprobar Horas Extra')
            ->icon(fn (AttendanceDay $record) => $record->overtime_approved ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
            ->color(fn (AttendanceDay $record) => $record->overtime_approved ? 'danger' : 'success')
            ->visible(fn (AttendanceDay $record) => $record->extra_hours > 0)
            ->tooltip(
                fn (AttendanceDay $record) => $record->overtime_approved
                    ? 'Revocar aprobación de '.$record->extra_hours.' hrs extra'
                    : 'Aprobar '.$record->extra_hours.' hrs extra'
            )
            ->requiresConfirmation()
            ->modalHeading(
                fn (AttendanceDay $record) => $record->overtime_approved
                    ? 'Revocar aprobación de horas extra'
                    : 'Aprobar horas extra'
            )
            ->modalDescription(
                function (AttendanceDay $record) {
                    return self::buildOvertimeModalDescription($record);
                }
            )
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->overtime_approved ? 'Sí, revocar' : 'Sí, aprobar')
            ->action(function (AttendanceDay $record) {
                $wasApproved = $record->overtime_approved;
                $record->overtime_approved = ! $wasApproved;
                $record->save();

                $action = $wasApproved ? 'revocada' : 'aprobada';

                Notification::make()
                    ->title("Aprobación {$action}")
                    ->body("Las {$record->extra_hours} hrs extra han sido {$action}s exitosamente.")
                    ->success()
                    ->send();
            });
    }

    /**
     * Indica si corresponde ofrecer la decisión de doble turno: el día tiene más de una entrada y
     * o bien horas extra por revisar, o bien ya se aceptó como jornada normal (para poder deshacerlo).
     */
    private static function canDecideRegularShift(AttendanceDay $record): bool
    {
        return ($record->second_shift_regular || (float) $record->extra_hours > 0) && $record->hasMultipleShifts();
    }

    /**
     * Acepta o revierte un doble turno como jornada normal y recalcula el día.
     * Aceptarlo deja las horas sobre el horario sin pago extra y quita el día de los pendientes.
     */
    private static function applyRegularShiftDecision(AttendanceDay $record, bool $accept): void
    {
        $extraBefore = (float) $record->extra_hours;

        $record->second_shift_regular = $accept;
        $record->overtime_approved = false;
        AttendanceCalculator::apply($record);
        $record->save();

        Notification::make()
            ->title($accept ? 'Doble turno aceptado como jornada normal' : 'Doble turno vuelto a revisión')
            ->body($accept
                ? "Las {$extraBefore} hrs del segundo turno ya no se cuentan como horas extra."
                : "Las horas sobre el horario ({$record->extra_hours} hrs extra) vuelven a quedar pendientes de aprobación.")
            ->success()
            ->send();
    }

    /**
     * Retorna la acción de aceptar/revertir un doble turno como jornada normal para tabla.
     */
    public static function getRegularShiftTableAction(): TableAction
    {
        return TableAction::make('regular_shift')
            ->label(fn (AttendanceDay $record) => $record->second_shift_regular ? 'Volver a revisar doble turno' : 'Aceptar doble turno como jornada normal')
            ->icon('heroicon-o-clock')
            ->color(fn (AttendanceDay $record) => $record->second_shift_regular ? 'gray' : 'info')
            ->visible(fn (AttendanceDay $record) => self::canDecideRegularShift($record))
            ->tooltip('El empleado hizo más de un turno el mismo día: las horas sobre el horario no se pagan como extra')
            ->requiresConfirmation()
            ->modalHeading(fn (AttendanceDay $record) => $record->second_shift_regular ? 'Volver a revisar el doble turno' : 'Aceptar el doble turno como jornada normal')
            ->modalDescription(fn (AttendanceDay $record) => $record->second_shift_regular
                ? 'Las horas sobre el horario volverán a contarse como horas extra pendientes de aprobación.'
                : "Las {$record->extra_hours} hrs sobre el horario no se pagarán como horas extra y el día saldrá de los pendientes. Se puede deshacer.")
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->second_shift_regular ? 'Sí, volver a revisar' : 'Sí, aceptar como jornada normal')
            ->action(fn (AttendanceDay $record) => self::applyRegularShiftDecision($record, ! $record->second_shift_regular));
    }

    /**
     * Retorna la acción de aceptar/revertir un doble turno como jornada normal para páginas (header actions).
     */
    public static function getRegularShiftAction(): Action
    {
        return Action::make('regular_shift')
            ->label(fn (AttendanceDay $record) => $record->second_shift_regular ? 'Volver a revisar doble turno' : 'Aceptar doble turno como jornada normal')
            ->icon('heroicon-o-clock')
            ->color(fn (AttendanceDay $record) => $record->second_shift_regular ? 'gray' : 'info')
            ->visible(fn (AttendanceDay $record) => self::canDecideRegularShift($record))
            ->requiresConfirmation()
            ->modalHeading(fn (AttendanceDay $record) => $record->second_shift_regular ? 'Volver a revisar el doble turno' : 'Aceptar el doble turno como jornada normal')
            ->modalDescription(fn (AttendanceDay $record) => $record->second_shift_regular
                ? 'Las horas sobre el horario volverán a contarse como horas extra pendientes de aprobación.'
                : "Las {$record->extra_hours} hrs sobre el horario no se pagarán como horas extra y el día saldrá de los pendientes. Se puede deshacer.")
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->second_shift_regular ? 'Sí, volver a revisar' : 'Sí, aceptar como jornada normal')
            ->action(function (AttendanceDay $record, $livewire) {
                self::applyRegularShiftDecision($record, ! $record->second_shift_regular);

                if (method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['second_shift_regular', 'extra_hours', 'overtime_approved']);
                }
            });
    }

    /**
     * Retorna la acción de aprobar/revocar descuento de tardanza para tabla.
     */
    public static function getApproveTardinessTableAction(): TableAction
    {
        return TableAction::make('approve_tardiness')
            ->label(fn (AttendanceDay $record) => $record->tardiness_deduction_approved ? 'Revocar Tardanza' : 'Aprobar Tardanza')
            ->icon(fn (AttendanceDay $record) => $record->tardiness_deduction_approved ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
            ->color(fn (AttendanceDay $record) => $record->tardiness_deduction_approved ? 'danger' : 'warning')
            ->visible(fn (AttendanceDay $record) => $record->late_minutes > 0)
            ->tooltip(fn (AttendanceDay $record) => $record->tardiness_deduction_approved
                ? "Revocar descuento por {$record->late_minutes} min de tardanza"
                : "Aprobar descuento por {$record->late_minutes} min de tardanza"
            )
            ->requiresConfirmation()
            ->modalHeading(fn (AttendanceDay $record) => $record->tardiness_deduction_approved
                ? 'Revocar descuento por tardanza'
                : 'Aprobar descuento por tardanza'
            )
            ->modalDescription(fn (AttendanceDay $record) => self::buildTardinessModalDescription($record))
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->tardiness_deduction_approved ? 'Sí, revocar' : 'Sí, aprobar')
            ->action(function (AttendanceDay $record) {
                $wasApproved = $record->tardiness_deduction_approved;
                $record->tardiness_deduction_approved = ! $wasApproved;
                $record->save();

                $action = $wasApproved ? 'revocado' : 'aprobado';
                Notification::make()
                    ->title("Descuento {$action}")
                    ->body("El descuento por {$record->late_minutes} min de tardanza ha sido {$action}.")
                    ->success()
                    ->send();
            });
    }

    /**
     * Retorna la acción de aprobar/revocar descuento de tardanza para páginas (header actions).
     */
    public static function getApproveTardinessAction(): Action
    {
        return Action::make('approve_tardiness')
            ->label(fn (AttendanceDay $record) => $record->tardiness_deduction_approved ? 'Revocar Descuento Tardanza' : 'Aprobar Descuento Tardanza')
            ->icon(fn (AttendanceDay $record) => $record->tardiness_deduction_approved ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
            ->color(fn (AttendanceDay $record) => $record->tardiness_deduction_approved ? 'danger' : 'warning')
            ->visible(fn (AttendanceDay $record) => $record->late_minutes > 0)
            ->requiresConfirmation()
            ->modalHeading(fn (AttendanceDay $record) => $record->tardiness_deduction_approved
                ? 'Revocar descuento por tardanza'
                : 'Aprobar descuento por tardanza'
            )
            ->modalDescription(fn (AttendanceDay $record) => self::buildTardinessModalDescription($record))
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->tardiness_deduction_approved ? 'Sí, revocar' : 'Sí, aprobar')
            ->action(function (AttendanceDay $record) {
                $wasApproved = $record->tardiness_deduction_approved;
                $record->tardiness_deduction_approved = ! $wasApproved;
                $record->save();

                $action = $wasApproved ? 'revocado' : 'aprobado';
                Notification::make()
                    ->title("Descuento {$action}")
                    ->body("El descuento por {$record->late_minutes} min de tardanza ha sido {$action}.")
                    ->success()
                    ->send();
            });
    }

    /**
     * Construye la descripción del modal de aprobación de tardanza.
     */
    private static function buildTardinessModalDescription(AttendanceDay $record): string
    {
        $action = $record->tardiness_deduction_approved ? 'Se revocará el descuento de' : 'Se aprobará el descuento de';
        $settings = app(PayrollSettings::class);
        $hourlyRate = $record->employee->base_salary / max(1, $settings->monthly_hours);
        $amount = round(($record->late_minutes / 60) * $hourlyRate, 2);

        return "{$action} {$record->late_minutes} min de tardanza para {$record->employee->full_name}"
            ." del {$record->date->format('d/m/Y')} (Gs. ".number_format($amount, 0, ',', '.').').';
    }

    private static function buildOvertimeModalDescription(AttendanceDay $record): string
    {
        $settings = app(PayrollSettings::class);
        $pctDiurno = (int) (($settings->overtime_multiplier_diurno - 1) * 100);
        $pctNocturno = (int) (($settings->overtime_multiplier_nocturno - 1) * 100);

        $action = $record->overtime_approved ? 'Se revocara la aprobacion de' : 'Se aprobaran';
        $desc = "{$action} {$record->extra_hours} hrs extra para {$record->employee->full_name} del {$record->date->format('d/m/Y')}.";

        if ($record->extra_hours_diurnas > 0 || $record->extra_hours_nocturnas > 0) {
            $parts = [];
            if ($record->extra_hours_diurnas > 0) {
                $parts[] = "{$record->extra_hours_diurnas}h diurnas ({$pctDiurno}%)";
            }
            if ($record->extra_hours_nocturnas > 0) {
                $parts[] = "{$record->extra_hours_nocturnas}h nocturnas ({$pctNocturno}%)";
            }
            $desc .= ' Desglose: '.implode(' + ', $parts).'.';
        }

        // Resumen semanal — usar copy() para no mutar $record->date
        $weekStart = $record->date->copy()->startOfWeek()->toDateString();
        $weekEnd = $record->date->copy()->endOfWeek()->toDateString();
        $weeklyOtherHours = AttendanceDay::where('employee_id', $record->employee_id)
            ->whereBetween('date', [$weekStart, $weekEnd])
            ->where('id', '!=', $record->id)
            ->sum('extra_hours');
        $weeklyTotal = (float) $weeklyOtherHours + (float) $record->extra_hours;
        $weeklyMax = $settings->overtime_max_weekly_hours;

        $desc .= " Total semana: {$weeklyTotal}h / {$weeklyMax}h permitidas.";

        if ($record->extra_hours > $settings->overtime_max_daily_hours) {
            $desc .= " ATENCION: Excede el limite diario de {$settings->overtime_max_daily_hours}h/dia (Art. 202 CLT).";
        }
        if ($weeklyTotal > $weeklyMax) {
            $desc .= " ATENCION: Excede el limite semanal de {$weeklyMax}h/semana (Art. 202 CLT).";
        }

        return $desc;
    }

    /**
     * Retorna la acción de exportar PDF para tabla
     */
    public static function getExportPdfTableAction(): TableAction
    {
        return TableAction::make('export')
            ->label('Exportar PDF')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('info')
            ->tooltip('Exportar registro como PDF')
            ->url(fn (AttendanceDay $record) => route('attendance-days.export', ['attendance_day' => $record->id]))
            ->openUrlInNewTab();
    }

    /**
     * Retorna la acción de exportar PDF para páginas (header actions)
     */
    public static function getExportPdfAction(): Action
    {
        return Action::make('export')
            ->label('Exportar PDF')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->tooltip('Exportar registro como PDF')
            ->url(fn (AttendanceDay $record) => route('attendance-days.export', ['attendance_day' => $record->id]))
            ->openUrlInNewTab();
    }

    /**
     * Retorna la acción de calcular/recalcular asistencia para tabla
     */
    public static function getCalculateTableAction(): TableAction
    {
        return TableAction::make('calculate')
            ->label(fn (AttendanceDay $record) => $record->is_calculated ? 'Recalcular' : 'Calcular')
            ->icon('heroicon-o-calculator')
            ->color(fn (AttendanceDay $record) => $record->is_calculated ? 'warning' : 'success')
            ->tooltip(
                fn (AttendanceDay $record) => $record->is_calculated
                    ? 'Última vez calculado: '.$record->calculated_at?->diffForHumans()
                    : 'Este registro aún no ha sido calculado'
            )
            ->requiresConfirmation()
            ->modalHeading(
                fn (AttendanceDay $record) => $record->is_calculated
                    ? 'Recalcular asistencia'
                    : 'Calcular asistencia'
            )
            ->modalDescription(
                fn (AttendanceDay $record) => $record->is_calculated
                    ? 'Este registro ya fue calculado el '.$record->calculated_at?->format('d/m/Y H:i').'. ¿Deseas recalcularlo?'
                    : 'Se calcularán todos los campos de asistencia para este registro.'
            )
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->is_calculated ? 'Sí, recalcular' : 'Sí, calcular')
            ->action(function (AttendanceDay $record) {
                self::calculateAttendanceRecord($record);
            });
    }

    /**
     * Retorna la acción de calcular/recalcular asistencia para páginas (header actions)
     */
    public static function getCalculateAction(): Action
    {
        return Action::make('calculate')
            ->label(fn (AttendanceDay $record) => $record->is_calculated ? 'Recalcular' : 'Calcular')
            ->icon('heroicon-o-calculator')
            ->color(fn (AttendanceDay $record) => $record->is_calculated ? 'warning' : 'success')
            ->tooltip(
                fn (AttendanceDay $record) => $record->is_calculated
                    ? 'Última vez calculado: '.$record->calculated_at?->diffForHumans()
                    : 'Este registro aún no ha sido calculado'
            )
            ->requiresConfirmation()
            ->modalHeading(
                fn (AttendanceDay $record) => $record->is_calculated
                    ? 'Recalcular asistencia'
                    : 'Calcular asistencia'
            )
            ->modalDescription(
                fn (AttendanceDay $record) => $record->is_calculated
                    ? 'Este registro ya fue calculado el '.$record->calculated_at?->format('d/m/Y H:i').'. ¿Deseas recalcularlo?'
                    : 'Se calcularán todos los campos de asistencia para este registro.'
            )
            ->modalSubmitActionLabel(fn (AttendanceDay $record) => $record->is_calculated ? 'Sí, recalcular' : 'Sí, calcular')
            ->action(function (AttendanceDay $record) {
                self::calculateAttendanceRecord($record);
            });
    }

    /**
     * Retorna la acción de aprobar horas extra en un rango de fechas
     */
    public static function getApproveOvertimeRangeAction(): Action
    {
        return Action::make('approve_overtime_range')
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

                        $pending = AttendanceDay::whereBetween('date', [$start, $end])
                            ->where('extra_hours', '>', 0)
                            ->where('overtime_approved', false)
                            ->count();

                        $alreadyApproved = AttendanceDay::whereBetween('date', [$start, $end])
                            ->where('extra_hours', '>', 0)
                            ->where('overtime_approved', true)
                            ->count();

                        $totalHours = AttendanceDay::whereBetween('date', [$start, $end])
                            ->where('extra_hours', '>', 0)
                            ->where('overtime_approved', false)
                            ->sum('extra_hours');

                        if ($pending === 0) {
                            return "No hay horas extra pendientes de aprobación en este período. ({$alreadyApproved} ya aprobadas)";
                        }

                        return "Se aprobarán {$pending} registro(s) con un total de {$totalHours} hrs extra pendientes. ({$alreadyApproved} ya aprobadas)";
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

                $approved = AttendanceDay::whereBetween('date', [
                    $startDate->toDateString(),
                    $endDate->toDateString(),
                ])
                    ->where('extra_hours', '>', 0)
                    ->where('overtime_approved', false)
                    ->update(['overtime_approved' => true]);

                if ($approved === 0) {
                    Notification::make()
                        ->title('Sin cambios')
                        ->body('No había horas extra pendientes de aprobación en el período seleccionado.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Horas extra aprobadas')
                    ->body("Se aprobaron {$approved} registro(s) entre {$startDate->format('d/m/Y')} y {$endDate->format('d/m/Y')}.")
                    ->success()
                    ->duration(6000)
                    ->send();
            });
    }

    /**
     * Retorna la acción de ajuste manual de horas extras para tabla
     */
    public static function getAdjustExtraHoursTableAction(): TableAction
    {
        $settings = app(PayrollSettings::class);
        $pctDiurno = (int) (($settings->overtime_multiplier_diurno - 1) * 100);
        $pctNocturno = (int) (($settings->overtime_multiplier_nocturno - 1) * 100);

        return TableAction::make('adjust_extra_hours')
            ->label('Ajustar Horas Extra')
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->tooltip('Cargar horas extras manualmente para este día')
            ->mountUsing(function (Form $form, AttendanceDay $record) {
                $form->fill([
                    'extra_hours_diurnas' => $record->extra_hours_diurnas ?? 0,
                    'extra_hours_nocturnas' => $record->extra_hours_nocturnas ?? 0,
                    'notes' => $record->notes,
                ]);
            })
            ->form([
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
            ])
            ->modalHeading(fn (AttendanceDay $record) => 'Ajustar horas extras — '.$record->employee?->full_name.' — '.$record->date->format('d/m/Y'))
            ->modalSubmitActionLabel('Guardar')
            ->action(function (AttendanceDay $record, array $data) {
                $diurnas = (float) ($data['extra_hours_diurnas'] ?? 0);
                $nocturnas = (float) ($data['extra_hours_nocturnas'] ?? 0);
                $total = round($diurnas + $nocturnas, 2);

                $record->extra_hours_diurnas = $diurnas;
                $record->extra_hours_nocturnas = $nocturnas;
                $record->extra_hours = $total;

                if (filled($data['notes'] ?? null)) {
                    $record->notes = $data['notes'];
                }

                if ($total > 0) {
                    $record->manual_adjustment = true;
                    $record->overtime_approved = true;
                    $record->status = 'present';
                    $record->is_calculated = true;
                } else {
                    $record->manual_adjustment = false;
                }

                $record->save();

                Notification::make()
                    ->title('Horas extras actualizadas')
                    ->body($total > 0
                        ? "Se registraron {$total} hrs extras ({$diurnas}h diurnas + {$nocturnas}h nocturnas)."
                        : 'Las horas extras fueron removidas del registro.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Retorna la acción de ajuste manual de horas extras para páginas (header actions)
     */
    public static function getAdjustExtraHoursAction(): Action
    {
        $settings = app(PayrollSettings::class);
        $pctDiurno = (int) (($settings->overtime_multiplier_diurno - 1) * 100);
        $pctNocturno = (int) (($settings->overtime_multiplier_nocturno - 1) * 100);

        return Action::make('adjust_extra_hours')
            ->label('Ajustar Horas Extra')
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->tooltip('Cargar horas extras manualmente para este día')
            ->mountUsing(function (Form $form, AttendanceDay $record) {
                $form->fill([
                    'extra_hours_diurnas' => $record->extra_hours_diurnas ?? 0,
                    'extra_hours_nocturnas' => $record->extra_hours_nocturnas ?? 0,
                    'notes' => $record->notes,
                ]);
            })
            ->form([
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
            ])
            ->modalHeading(fn (AttendanceDay $record) => 'Ajustar horas extras — '.$record->employee?->full_name.' — '.$record->date->format('d/m/Y'))
            ->modalSubmitActionLabel('Guardar')
            ->action(function (AttendanceDay $record, array $data) {
                $diurnas = (float) ($data['extra_hours_diurnas'] ?? 0);
                $nocturnas = (float) ($data['extra_hours_nocturnas'] ?? 0);
                $total = round($diurnas + $nocturnas, 2);

                $record->extra_hours_diurnas = $diurnas;
                $record->extra_hours_nocturnas = $nocturnas;
                $record->extra_hours = $total;

                if (filled($data['notes'] ?? null)) {
                    $record->notes = $data['notes'];
                }

                if ($total > 0) {
                    $record->manual_adjustment = true;
                    $record->overtime_approved = true;
                    $record->status = 'present';
                    $record->is_calculated = true;
                } else {
                    $record->manual_adjustment = false;
                }

                $record->save();

                Notification::make()
                    ->title('Horas extras actualizadas')
                    ->body($total > 0
                        ? "Se registraron {$total} hrs extras ({$diurnas}h diurnas + {$nocturnas}h nocturnas)."
                        : 'Las horas extras fueron removidas del registro.')
                    ->success()
                    ->send();
            });
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
     * Calcula un registro de asistencia y muestra notificación
     */
    private static function calculateAttendanceRecord(AttendanceDay $record): void
    {
        try {
            $wasCalculated = $record->is_calculated;

            AttendanceCalculator::apply($record);
            $record->save();

            $action = $wasCalculated ? 'recalculado' : 'calculado';
            $statusMessages = AttendanceDay::getCalculationStatusMessages($wasCalculated);
            $message = $statusMessages[$record->status] ?? "Cálculo {$action}";

            Notification::make()
                ->title("Registro {$action}")
                ->body($message)
                ->success()
                ->send();
        } catch (\Exception $e) {
            Log::error("Error calculando AttendanceDay ID {$record->id}: {$e->getMessage()}", [
                'attendance_day_id' => $record->id,
                'date' => $record->date,
                'trace' => $e->getTraceAsString(),
            ]);

            Notification::make()
                ->title('Error al calcular')
                ->body('Ocurrió un error al procesar el registro. Revisa los logs para más detalles.')
                ->danger()
                ->persistent()
                ->send();
        }
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
