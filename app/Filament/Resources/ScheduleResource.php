<?php

namespace App\Filament\Resources;

use App\Filament\Actions\ScheduleAssignmentActions;
use App\Filament\Resources\ScheduleResource\Pages;
use App\Filament\Resources\ScheduleResource\RelationManagers\EmployeesRelationManager;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\ScheduleDay;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Filament\Forms\Components\Hidden;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScheduleResource extends Resource
{
    protected static ?string $model = Schedule::class;

    protected static ?string $navigationGroup = 'Organización';

    protected static ?string $navigationLabel = 'Horarios';

    protected static ?string $modelLabel = 'Horario';

    protected static ?string $pluralModelLabel = 'Horarios';

    protected static ?string $slug = 'horarios';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Define el formulario para crear y editar horarios, incluyendo la información general del horario y la configuración detallada de los días laborales con sus respectivos descansos.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información del Horario')
                    ->description('Datos generales del horario de trabajo.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->placeholder('Ej: Horario Diurno')
                            ->required()
                            ->maxLength(60)
                            ->helperText('Nombre descriptivo para identificar el horario'),

                        Select::make('shift_type')
                            ->label('Tipo de Jornada')
                            ->options(Schedule::getShiftTypeOptions())
                            ->required()
                            ->native(false)
                            ->helperText('Selecciona el tipo de jornada laboral'),

                        TextInput::make('description')
                            ->label('Descripción')
                            ->placeholder('Ej: Horario de trabajo diurno con 8 horas de jornada y descansos establecidos.')
                            ->nullable()
                            ->maxLength(100)
                            ->helperText('Descripción opcional para detallar características del horario. Máximo 100 caracteres.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Configuración de Días')
                    ->description('Activá los días laborales e ingresá los horarios. Los horarios nocturnos pueden tener hora de salida menor a la de entrada.')
                    ->schema([
                        Repeater::make('days')
                            ->relationship(modifyQueryUsing: fn ($query) => $query->orderBy('day_of_week'))
                            ->label(false)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
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
                                    ->required(fn (Get $get): bool => (bool) $get('is_active'))
                                    ->visible(fn (Get $get): bool => (bool) $get('is_active'))
                                    ->columnSpan(1),

                                TimePicker::make('end_time')
                                    ->label('Salida')
                                    ->native(false)
                                    ->seconds(false)
                                    ->required(fn (Get $get): bool => (bool) $get('is_active'))
                                    ->visible(fn (Get $get): bool => (bool) $get('is_active'))
                                    ->columnSpan(1),

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
                                            ->required(),

                                        TimePicker::make('end_time')
                                            ->label('Fin')
                                            ->native(false)
                                            ->seconds(false)
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
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-clock')
                    ->iconColor('primary'),

                TextColumn::make('shift_type')
                    ->label('Jornada')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Schedule::getShiftTypeLabels()[$state] ?? $state)
                    ->color(fn ($state) => Schedule::getShiftTypeColors()[$state] ?? 'success')
                    ->sortable(),

                TextColumn::make('active_days_count')
                    ->label('Días Activos')
                    ->counts('activeDays')
                    ->alignCenter()
                    ->badge()
                    ->color('info')
                    ->sortable(),

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
                    ->label('Asignar a Empleados')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->fillForm(fn (Schedule $record) => ['schedule_id' => $record->id])
                    ->form([
                        Hidden::make('schedule_id'),

                        Select::make('filter_branch')
                            ->label('Filtrar por Sucursal')
                            ->options(fn () => Branch::pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('employee_ids', []))
                            ->columnSpanFull(),

                        Select::make('employee_ids')
                            ->label('Empleados')
                            ->options(function (callable $get) {
                                $scheduleId = (int) $get('schedule_id');
                                $today = Carbon::today();

                                $query = Employee::query()->where('status', 'active');

                                if ($get('filter_branch')) {
                                    $query->where('branch_id', $get('filter_branch'));
                                }

                                return $query
                                    ->orderBy('first_name')
                                    ->orderBy('last_name')
                                    ->get()
                                    ->mapWithKeys(function (Employee $employee) use ($scheduleId, $today) {
                                        $current = $employee->getScheduleForDate($today);

                                        if ($current?->id === $scheduleId) {
                                            return [];
                                        }

                                        $label = "{$employee->full_name} - CI: {$employee->ci}";
                                        if ($current) {
                                            $label .= " ⚠ (Horario actual: {$current->name})";
                                        }

                                        return [$employee->id => $label];
                                    })
                                    ->filter();
                            })
                            ->multiple()
                            ->searchable()
                            ->required()
                            ->native(false)
                            ->columnSpanFull(),

                        ...ScheduleAssignmentActions::periodFields(),
                    ])
                    ->modalHeading('Asignar Horario a Empleados')
                    ->modalDescription('Selecciona los empleados a los que deseas asignar este horario. Solo se mostrarán empleados activos que no tengan este horario asignado actualmente.')
                    ->modalSubmitActionLabel('Asignar Horario')
                    ->modalWidth('2xl')
                    ->action(function (Schedule $record, array $data) {
                        $assigned = 0;
                        $errors = [];

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

                        if (! empty($errors)) {
                            Notification::make()
                                ->warning()
                                ->title('Algunos empleados no pudieron asignarse')
                                ->body('Revisar solapamientos en: '.implode(', ', $errors))
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No hay horarios registrados')
            ->emptyStateDescription('Comienza a crear horarios de trabajo para asignar a los empleados.')
            ->emptyStateIcon('heroicon-o-clock');
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
