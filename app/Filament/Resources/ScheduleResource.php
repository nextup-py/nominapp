<?php

namespace App\Filament\Resources;

use App\Filament\Actions\ScheduleAssignmentActions;
use App\Filament\Actions\ScheduleStatusAction;
use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\ScheduleResource\Pages;
use App\Filament\Resources\ScheduleResource\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\ScheduleResource\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\ScheduleResource\RelationManagers\EmployeesRelationManager;
use App\Filament\Resources\ScheduleResource\RelationManagers\PositionsRelationManager;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\ScheduleDay;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ScheduleResource extends Resource
{
    use UsesNavigationLayout;

    protected static ?string $model = Schedule::class;

    protected static ?string $navigationGroup = 'Horarios y turnos';

    protected static ?string $navigationLabel = 'Horarios';

    protected static ?string $modelLabel = 'Horario';

    protected static ?string $pluralModelLabel = 'Horarios';

    protected static ?string $slug = 'horarios';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static int $globalSearchResultsLimit = 8;

    /**
     * Quien puede asignar y quitar empleados de un horario (las acciones personalizadas de Filament no consultan la policy).
     */
    public static function canManageAssignments(): bool
    {
        return (bool) auth()->user()?->can('update_schedule');
    }

    /**
     * Detalle de cada horario en la búsqueda global: tipo de jornada y, si corresponde, que está inactivo.
     *
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Jornada' => Schedule::getShiftTypeLabels()[$record->shift_type] ?? null,
            'Estado' => $record->is_active ? null : 'Inactivo',
        ]);
    }

    /**
     * Define el formulario para crear y editar horarios, incluyendo la información general del horario y la configuración detallada de los días laborales con sus respectivos descansos.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información del Horario')
                    ->schema([
                        Placeholder::make('affected_employees')
                            ->label('Empleados afectados')
                            ->content(fn (?Schedule $record): string => ($count = $record?->currentAssignmentsCount() ?? 0).' '.($count === 1 ? 'empleado tiene' : 'empleados tienen').' asignado este horario: los cambios rigen desde hoy. Las jornadas ya calculadas no cambian hasta recalcularlas.')
                            ->visible(fn (?Schedule $record): bool => ($record?->currentAssignmentsCount() ?? 0) > 0)
                            ->columnSpanFull(),

                        TextInput::make('name')
                            ->label('Nombre')
                            ->placeholder('Ej: Horario Diurno')
                            ->required()
                            ->maxLength(60)
                            ->unique(ignoreRecord: true)
                            ->validationMessages(['unique' => 'Ya existe un horario con ese nombre. Usar uno distinto para no confundirlos al asignar.']),

                        Select::make('shift_type')
                            ->label('Tipo de Jornada')
                            ->options(Schedule::getShiftTypeOptions())
                            ->required()
                            ->native(false)
                            ->helperText(function (Get $get): string {
                                $suggested = Schedule::suggestShiftType($get('days') ?? []);

                                return $suggested
                                    ? 'Según las horas cargadas se sugiere: '.Schedule::getShiftTypeLabels()[$suggested].'.'
                                    : 'Se sugiere según las horas cargadas en los días.';
                            }),

                        TextInput::make('description')
                            ->label('Descripción')
                            ->placeholder('Ej: Horario de trabajo diurno con 8 horas de jornada y descansos establecidos.')
                            ->nullable()
                            ->maxLength(100)
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true)
                            ->disabled(fn (?Schedule $record): bool => $record !== null && $record->is_active && $record->deactivationBlockers() !== [])
                            ->helperText(fn (?Schedule $record): string => $record !== null && $record->is_active && $record->deactivationBlockers() !== []
                                ? 'No se puede desactivar: '.$record->deactivationBlockersSummary().'.'
                                : 'Los horarios inactivos no se ofrecen al asignar y conservan su historial.')
                            ->hiddenOn('create')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Configuración de Días')
                    ->description('Activar los días laborales y cargar entrada y salida. En un turno nocturno la salida puede ser menor que la entrada.')
                    ->schema([
                        Placeholder::make('weekly_total')
                            ->label('Resumen semanal')
                            ->content(function (Get $get): string {
                                $hours = collect($get('days') ?? [])
                                    ->filter(fn ($day): bool => (bool) ($day['is_active'] ?? false))
                                    ->map(fn ($day): ?float => Schedule::netHoursFromState($day));
                                $count = $hours->filter(fn ($value): bool => $value !== null)->count();

                                return $count === 0
                                    ? 'Sin días con turno cargado.'
                                    : $count.' '.($count === 1 ? 'día' : 'días').' · '.Schedule::formatHours((float) $hours->sum()).' netas por semana (descansos descontados).';
                            }),

                        Placeholder::make('few_days_notice')
                            ->label('Pocos días activos')
                            ->content(function (Get $get): string {
                                $active = collect($get('days') ?? [])->where('is_active', true)->count();

                                return "Este horario tiene solo {$active} día(s) activo(s): quien lo tenga asignado se considerará libre los demás días. Si es un turno partido por días, cargar todos los días en un único horario.";
                            })
                            ->visible(function (Get $get): bool {
                                $active = collect($get('days') ?? [])->where('is_active', true)->count();

                                return $active > 0 && $active <= Schedule::FEW_ACTIVE_DAYS;
                            }),

                        Repeater::make('days')
                            ->relationship(modifyQueryUsing: fn ($query) => $query->orderBy('day_of_week'))
                            ->label(false)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->extraItemActions([static::copyDayAction()])
                            ->default(
                                collect(range(1, 7))->map(fn ($day) => [
                                    'day_of_week' => $day,
                                    'is_active' => false,
                                    'start_time' => null,
                                    'end_time' => null,
                                ])->toArray()
                            )
                            ->schema([
                                Hidden::make('day_of_week'),

                                Toggle::make('is_active')
                                    ->label(fn (Get $get): string => ScheduleDay::getDayOptions()[(int) $get('day_of_week')] ?? 'Día')
                                    ->live()
                                    ->columnSpan(1),

                                TimePicker::make('start_time')
                                    ->label('Entrada')
                                    ->native(false)
                                    ->seconds(false)
                                    ->live(onBlur: true)
                                    ->required(fn (Get $get): bool => (bool) $get('is_active'))
                                    ->visible(fn (Get $get): bool => (bool) $get('is_active'))
                                    ->columnSpan(1),

                                TimePicker::make('end_time')
                                    ->label('Salida')
                                    ->native(false)
                                    ->seconds(false)
                                    ->live(onBlur: true)
                                    ->required(fn (Get $get): bool => (bool) $get('is_active'))
                                    ->visible(fn (Get $get): bool => (bool) $get('is_active'))
                                    ->columnSpan(1),

                                Placeholder::make('net_hours')
                                    ->label(false)
                                    ->content(function (Get $get): string {
                                        $day = ['start_time' => $get('start_time'), 'end_time' => $get('end_time')];
                                        $gross = Schedule::netHoursFromState($day);
                                        $net = Schedule::netHoursFromState($day + ['breaks' => $get('breaks') ?? []]);

                                        if ($net === null) {
                                            return '';
                                        }

                                        return Schedule::formatHours($net).' netas'.($net !== $gross ? ' (descansos descontados)' : '');
                                    })
                                    ->visible(fn (Get $get): bool => (bool) $get('is_active') && filled($get('start_time')) && filled($get('end_time')))
                                    ->columnSpanFull(),

                                Repeater::make('breaks')
                                    ->relationship(modifyQueryUsing: fn ($query) => $query->orderBy('start_time'))
                                    ->label('Descansos')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Nombre')
                                            ->placeholder('Ejemplo: Almuerzo')
                                            ->maxLength(60)
                                            ->required(),

                                        TimePicker::make('start_time')
                                            ->label('Inicio')
                                            ->native(false)
                                            ->seconds(false)
                                            ->live(onBlur: true)
                                            ->required(),

                                        TimePicker::make('end_time')
                                            ->label('Fin')
                                            ->native(false)
                                            ->seconds(false)
                                            ->live(onBlur: true)
                                            ->required()
                                            ->after('start_time'),
                                    ])
                                    ->columns(3)
                                    ->minItems(0)
                                    ->maxItems(6)
                                    ->defaultItems(0)
                                    ->reorderable(false)
                                    ->cloneable()
                                    ->addActionLabel('Agregar Descanso')
                                    ->visible(fn (Get $get): bool => (bool) $get('is_active'))
                                    ->columnSpanFull(),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }

    /**
     * Define la tabla para listar los horarios.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('days.breaks'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-clock')
                    ->iconColor('primary'),

                TextColumn::make('is_active')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'gray')
                    ->sortable(),

                TextColumn::make('shift_type')
                    ->label('Jornada')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Schedule::getShiftTypeLabels()[$state] ?? $state)
                    ->color(fn ($state) => Schedule::getShiftTypeColors()[$state] ?? 'success')
                    ->sortable(),

                TextColumn::make('weekly_summary')
                    ->label('Resumen semanal')
                    ->getStateUsing(fn (Schedule $record): string => $record->weeklySummaryLabel())
                    ->description(fn (Schedule $record): ?string => $record->crossesMidnight() ? 'Cruza la medianoche' : null),

                TextColumn::make('current_employees_count')
                    ->label('Empleados Asignados')
                    ->counts('currentEmployees')
                    ->alignCenter()
                    ->badge()
                    ->color('success')
                    ->sortable(),

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
                SelectFilter::make('shift_type')
                    ->label('Tipo de Jornada')
                    ->options(Schedule::getShiftTypeLabels())
                    ->native(false)
                    ->placeholder('Todos'),

                TernaryFilter::make('has_employees')
                    ->label('Empleados asignados')
                    ->placeholder('Todos')
                    ->trueLabel('Con empleados')
                    ->falseLabel('Sin empleados')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('currentEmployees'),
                        false: fn (Builder $query) => $query->whereDoesntHave('currentEmployees'),
                        blank: fn (Builder $query) => $query,
                    )
                    ->native(false),
            ])
            ->actions([
                Action::make('assignToEmployees')
                    ->authorize(fn (): bool => static::canManageAssignments())
                    ->label('Asignar a Empleados')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->fillForm(fn (Schedule $record) => ['schedule_id' => $record->id])
                    ->form([
                        Hidden::make('schedule_id'),

                        Select::make('filter_branch')
                            ->label('Filtrar por Sucursal')
                            ->options(fn () => Branch::active()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('employee_ids', []))
                            ->columnSpanFull(),

                        Select::make('employee_ids')
                            ->label('Empleados')
                            ->options(fn (Get $get): array => static::assignableEmployees((int) $get('schedule_id'), $get('filter_branch')))
                            ->helperText(fn (Get $get): string => count(static::assignableEmployees((int) $get('schedule_id'), $get('filter_branch'))).' empleado(s) disponible(s) para asignar.')
                            ->multiple()
                            ->searchable()
                            ->required()
                            ->native(false)
                            ->columnSpanFull(),

                        ...ScheduleAssignmentActions::periodFields(),
                    ])
                    ->modalHeading('Asignar Horario a Empleados')
                    ->modalDescription('Seleccionar los empleados a quienes asignar este horario. Solo se muestran empleados activos que no lo tienen asignado hoy; si ya tienen otro horario vigente, se indica junto al nombre.')
                    ->modalSubmitActionLabel('Asignar Horario')
                    ->modalWidth('2xl')
                    ->action(function (Schedule $record, array $data) {
                        $assigned = 0;
                        $errors = [];
                        $assignedEmployees = collect();

                        foreach (Employee::whereIn('id', $data['employee_ids'])->get() as $employee) {
                            try {
                                ScheduleAssignmentService::assign(
                                    employee: $employee,
                                    schedule: $record,
                                    validFrom: Carbon::parse($data['valid_from'])->startOfDay(),
                                    validUntil: filled($data['valid_until'] ?? null) ? Carbon::parse($data['valid_until'])->startOfDay() : null,
                                    notes: $data['notes'] ?? null,
                                );
                                $assigned++;
                                $assignedEmployees->push($employee);
                            } catch (\Exception $e) {
                                $errors[] = $employee->full_name;
                            }
                        }

                        if ($assigned > 0) {
                            Notification::make()
                                ->success()
                                ->title('Horario asignado')
                                ->body("Se asignó \"{$record->name}\" a {$assigned} empleado(s) a partir del ".Carbon::parse($data['valid_from'])->format('d/m/Y').'.')
                                ->send();
                        }

                        if ($data['recalculate'] ?? false) {
                            ScheduleAssignmentActions::recalculateAssigned(
                                $assignedEmployees,
                                Carbon::parse($data['valid_from'])->startOfDay(),
                                filled($data['valid_until'] ?? null) ? Carbon::parse($data['valid_until'])->startOfDay() : null,
                            );
                        }

                        if (! empty($errors)) {
                            Notification::make()
                                ->warning()
                                ->title('Algunos empleados no pudieron asignarse')
                                ->body('Revisar solapamientos en: '.implode(', ', $errors))
                                ->persistent()
                                ->send();
                        }
                    }),

                ActionGroup::make([
                    static::duplicateAction(Action::class),
                    ScheduleStatusAction::make(Action::class),
                ])->tooltip('Más acciones'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('deactivate_bulk')
                        ->label('Desactivar seleccionados')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->authorize(fn (): bool => (bool) auth()->user()?->can('update_schedule'))
                        ->requiresConfirmation()
                        ->modalHeading('¿Desactivar los horarios seleccionados?')
                        ->modalDescription('Dejan de ofrecerse al asignar horarios y conservan su historial. Se omiten los que tienen asignaciones vigentes o futuras.')
                        ->modalSubmitActionLabel('Sí, desactivar')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => static::changeStatusBulk($records, false)),

                    BulkAction::make('activate_bulk')
                        ->label('Activar seleccionados')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->authorize(fn (): bool => (bool) auth()->user()?->can('update_schedule'))
                        ->requiresConfirmation()
                        ->modalHeading('¿Activar los horarios seleccionados?')
                        ->modalDescription('Vuelven a ofrecerse al asignar horarios.')
                        ->modalSubmitActionLabel('Sí, activar')
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => static::changeStatusBulk($records, true)),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No hay horarios registrados')
            ->emptyStateDescription('Crear horarios de trabajo para asignarlos a los empleados.')
            ->emptyStateIcon('heroicon-o-clock');
    }

    /**
     * Activa o desactiva varios horarios a la vez: omite los que ya tienen ese estado y, al desactivar, los que tienen
     * asignaciones vigentes o futuras (`deactivationBlockers()`), e informa los conteos.
     *
     * @param  Collection<int, Schedule>  $records
     */
    public static function changeStatusBulk(Collection $records, bool $active): void
    {
        $changed = 0;
        $unchanged = 0;
        $blocked = 0;

        foreach ($records as $schedule) {
            if ($schedule->is_active === $active) {
                $unchanged++;

                continue;
            }

            if (! $active && $schedule->deactivationBlockers() !== []) {
                $blocked++;

                continue;
            }

            $schedule->update(['is_active' => $active]);
            $changed++;
        }

        $verb = $active ? 'activaron' : 'desactivaron';
        $parts = [];

        if ($blocked > 0) {
            $parts[] = "{$blocked} con asignaciones vigentes o futuras";
        }

        if ($unchanged > 0) {
            $parts[] = "{$unchanged} ya estaban ".($active ? 'activos' : 'inactivos');
        }

        $notification = Notification::make()
            ->title($changed > 0 ? "Se {$verb} {$changed} horario(s)" : 'No se cambió ningún horario')
            ->body($parts === [] ? null : 'Omitidos: '.implode('; ', $parts).'.');

        ($changed > 0 ? $notification->success() : $notification->warning())->send();
    }

    /**
     * Pone en mayúscula solo la primera letra del nombre (no la de cada palabra: «Turno de la mañana», no «Turno De La Mañana»).
     */
    public static function capitalizeName(string $name): string
    {
        $name = trim($name);

        return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($name, 1, null, 'UTF-8');
    }

    /**
     * Acción por día del formulario: copia la entrada, la salida y los descansos de un día a los días que se elijan
     * (p. ej. cargar el lunes y copiarlo de martes a viernes). Solo modifica el estado del formulario; se guarda al
     * guardar el horario.
     */
    public static function copyDayAction(): FormAction
    {
        return FormAction::make('copy_day')
            ->icon('heroicon-m-document-duplicate')
            ->tooltip('Copiar a otros días')
            ->visible(fn (Repeater $component, array $arguments): bool => (bool) ($component->getState()[$arguments['item'] ?? '']['is_active'] ?? false))
            ->modalHeading('Copiar a otros días')
            ->modalDescription('Se copian la entrada, la salida y los descansos. Los días elegidos pasan a estar activos y reemplazan lo que tengan cargado.')
            ->modalSubmitActionLabel('Copiar')
            ->form(fn (Repeater $component, array $arguments): array => [
                CheckboxList::make('targets')
                    ->label('Copiar a')
                    ->options(collect($component->getState())
                        ->reject(fn (array $day, string $key): bool => $key === ($arguments['item'] ?? ''))
                        ->mapWithKeys(fn (array $day): array => [(int) $day['day_of_week'] => ScheduleDay::getDayOptions()[(int) $day['day_of_week']] ?? 'Día'])
                        ->sortKeys()
                        ->all())
                    ->columns(2)
                    ->bulkToggleable()
                    ->required(),
            ])
            ->action(function (array $data, array $arguments, Repeater $component): void {
                $items = $component->getState();
                $source = $items[$arguments['item'] ?? ''] ?? null;

                if ($source === null) {
                    return;
                }

                $breaks = fn (): array => collect($source['breaks'] ?? [])
                    ->mapWithKeys(fn (array $break): array => [(string) Str::uuid() => Arr::only($break, ['name', 'start_time', 'end_time'])])
                    ->all();

                foreach ($items as $key => $item) {
                    if (in_array((int) $item['day_of_week'], array_map('intval', $data['targets']), true)) {
                        $items[$key] = array_merge($item, [
                            'is_active' => true,
                            'start_time' => $source['start_time'],
                            'end_time' => $source['end_time'],
                            'breaks' => $breaks(),
                        ]);
                    }
                }

                $component->state($items);
            });
    }

    /**
     * Empleados activos a quienes se puede asignar el horario (sin tenerlo ya vigente hoy), como `id => etiqueta`.
     * La etiqueta indica el horario que tienen hoy. Se memoiza por petición porque lo usan las opciones y el texto de ayuda.
     *
     * @return array<int, string>
     */
    public static function assignableEmployees(int $scheduleId, mixed $branchId = null): array
    {
        static $memo = [];

        $key = $scheduleId.'|'.($branchId ?: '');

        return $memo[$key] ??= Employee::query()
            ->where('status', 'active')
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(function (Employee $employee) use ($scheduleId): array {
                $current = $employee->getScheduleForDate(Carbon::today());

                if ($current?->id === $scheduleId) {
                    return [];
                }

                $label = "{$employee->full_name} - CI: {$employee->ci}";

                return [$employee->id => $current ? $label." — hoy: {$current->name}" : $label];
            })
            ->all();
    }

    /**
     * Acción "Duplicar": copia el horario con sus días y descansos bajo un nombre nuevo (sin asignaciones).
     * Se construye con la clase de acción de tabla o de página, según dónde se use.
     *
     * @param  class-string<\Filament\Actions\Action|Action>  $actionClass
     */
    public static function duplicateAction(string $actionClass): \Filament\Actions\Action|Action
    {
        return $actionClass::make('duplicate')
            ->label('Duplicar')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('create_schedule'))
            ->modalHeading('Duplicar horario')
            ->modalDescription('Se copian los días, horas y descansos. Los empleados asignados no se copian.')
            ->modalSubmitActionLabel('Duplicar')
            ->fillForm(fn (Schedule $record): array => ['name' => "{$record->name} (copia)"])
            ->form([
                TextInput::make('name')
                    ->label('Nombre del nuevo horario')
                    ->required()
                    ->maxLength(60)
                    ->unique(Schedule::class, 'name')
                    ->validationMessages(['unique' => 'Ya existe un horario con ese nombre.']),
            ])
            ->action(function (Schedule $record, array $data) {
                $copy = $record->duplicate($data['name']);

                Notification::make()
                    ->success()
                    ->title('Horario duplicado')
                    ->body("Se creó \"{$copy->name}\". Revisar las horas y asignarlo a los empleados.")
                    ->send();

                return redirect(static::getUrl('edit', ['record' => $copy]));
            });
    }

    /**
     * Define las relaciones disponibles para el recurso, en este caso la relación con los empleados asignados a cada horario.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            EmployeesRelationManager::class,
            AssignmentsRelationManager::class,
            PositionsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    /**
     * Define las páginas disponibles para el recurso, incluyendo la lista de horarios, creación, vista detallada y edición.
     *
     * @return array<string, class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSchedules::route('/'),
            'create' => Pages\CreateSchedule::route('/create'),
            'view' => Pages\ViewSchedule::route('/{record}'),
            'edit' => Pages\EditSchedule::route('/{record}/edit'),
        ];
    }
}
