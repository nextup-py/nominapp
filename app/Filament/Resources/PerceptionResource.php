<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PerceptionResource\Pages;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Perception;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Gestiona el catálogo de percepciones salariales y no salariales. */
class PerceptionResource extends Resource
{
    protected static ?string $model = Perception::class;

    protected static ?string $navigationGroup = 'Catálogos';

    protected static ?string $navigationLabel = 'Percepciones';

    protected static ?string $label = 'Percepción';

    protected static ?string $pluralLabel = 'Percepciones';

    protected static ?string $slug = 'percepciones';

    protected static ?string $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?int $navigationSort = 1;

    /**
     * Define el formulario para crear y editar percepciones.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información General')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->placeholder('Ejemplo: Bonificación por Desempeño')
                            ->required()
                            ->maxLength(60)
                            ->columnSpan(1),

                        TextInput::make('code')
                            ->label('Código')
                            ->placeholder('Ejemplo: BON-DES')
                            ->required()
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->columnSpan(1),

                        Textarea::make('description')
                            ->label('Descripción')
                            ->placeholder('Ejemplo: Bonificación por Desempeño otorgada trimestralmente según evaluación de desempeño')
                            ->maxLength(255)
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Configuración de Cálculo')
                    ->schema([
                        Select::make('calculation')
                            ->label('Tipo de Cálculo')
                            ->options([
                                'fixed' => 'Monto Fijo',
                                'percentage' => 'Porcentaje del Salario',
                            ])
                            ->default('fixed')
                            ->native(false)
                            ->live()
                            ->required()
                            ->helperText('Define cómo se calculará esta percepción.')
                            ->columnSpan(1),

                        TextInput::make('amount')
                            ->label('Monto Fijo')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(999999999.99)
                            ->step(1)
                            ->prefix('₲')
                            ->visible(fn (Get $get) => $get('calculation') === 'fixed')
                            ->required(fn (Get $get) => $get('calculation') === 'fixed')
                            ->helperText('Monto que se agregará al salario.')
                            ->columnSpan(1),

                        TextInput::make('percent')
                            ->label('Porcentaje')
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%')
                            ->visible(fn (Get $get) => $get('calculation') === 'percentage')
                            ->required(fn (Get $get) => $get('calculation') === 'percentage')
                            ->helperText('Porcentaje del salario base que se agregará.')
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Section::make('Configuración Adicional')
                    ->schema([
                        Select::make('type')
                            ->label('Tipo de percepción')
                            ->options(Perception::getTypeOptions())
                            ->default('salary')
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state) {
                                $forced = Perception::getAffectsIpsForType($state ?? 'other');
                                if ($forced !== null) {
                                    $set('affects_ips', $forced);
                                }
                            })
                            ->required()
                            ->helperText('Determina automáticamente si la percepción aplica a IPS.')
                            ->columnSpanFull(),

                        Toggle::make('affects_ips')
                            ->label('Afecta IPS')
                            ->helperText('Solo editable para el tipo "Otro". Para los demás tipos se asigna automáticamente.')
                            ->default(true)
                            ->inline(false)
                            ->disabled(fn (Get $get) => $get('type') !== 'other')
                            ->columnSpan(1),

                        Toggle::make('is_active')
                            ->label('Activo')
                            ->helperText('Habilitar o deshabilitar esta percepción.')
                            ->default(true)
                            ->inline(false)
                            ->columnSpan(1),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Define la tabla para listar percepciones.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Código copiado')
                    ->weight('bold'),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('type')
                    ->label('Percepción')
                    ->formatStateUsing(fn ($state) => Perception::getTypeLabels()[$state] ?? $state)
                    ->badge()
                    ->color(fn ($state) => Perception::getTypeColors()[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('calculation')
                    ->label('Cálculo')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'fixed' => 'Fijo',
                        'percentage' => 'Porcentaje',
                        default => '-',
                    })
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'fixed' => 'primary',
                        'percentage' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Monto')
                    ->money('PYG', locale: 'es_PY')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('percent')
                    ->label('Porcentaje')
                    ->formatStateUsing(fn ($state) => Perception::formatPercent($state))
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),

                IconColumn::make('affects_ips')
                    ->label('IPS')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Estado')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('active_employees_count')
                    ->label('Empleados')
                    ->counts('activeEmployees')
                    ->badge()
                    ->color('info')
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
                SelectFilter::make('type')
                    ->label('Tipo de percepción')
                    ->options(Perception::getTypeLabels())
                    ->native(false),

                SelectFilter::make('calculation')
                    ->label('Tipo de Cálculo')
                    ->options([
                        'fixed' => 'Monto Fijo',
                        'percentage' => 'Porcentaje',
                    ])
                    ->native(false),

                TernaryFilter::make('affects_ips')
                    ->label('Afecta IPS')
                    ->placeholder('Todos')
                    ->trueLabel('Afecta IPS')
                    ->falseLabel('No Afecta IPS')
                    ->native(false),

                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos')
                    ->native(false),
            ])
            ->actions([
                Action::make('assignToAllEmployees')
                    ->label('Asignar a Todos')
                    ->icon('heroicon-o-users')
                    ->color('success')
                    ->visible(fn (Perception $record) => $record->is_active)
                    ->modalHeading('Asignar Percepción a Empleados')
                    ->modalDescription(fn (Perception $record) => "Asigna \"{$record->name}\" a todos los empleados activos que aún no la tienen. Filtrá opcionalmente por empresa o sucursal.")
                    ->modalSubmitActionLabel('Sí, asignar')
                    ->form([
                        Select::make('company_id')
                            ->label('Empresa')
                            ->options(fn () => Company::active()->pluck('name', 'id'))
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('branch_id', null))
                            ->placeholder('Todas las empresas'),

                        Select::make('branch_id')
                            ->label('Sucursal')
                            ->options(fn (Get $get) => $get('company_id')
                                ? Branch::where('company_id', $get('company_id'))->pluck('name', 'id')
                                : [])
                            ->native(false)
                            ->placeholder('Todas las sucursales')
                            ->disabled(fn (Get $get) => ! $get('company_id')),
                    ])
                    ->action(function (Perception $record, array $data) {
                        try {
                            $query = Employee::where('status', 'active');

                            if (! empty($data['branch_id'])) {
                                $query->where('branch_id', $data['branch_id']);
                            } elseif (! empty($data['company_id'])) {
                                $query->whereHas('branch', fn ($q) => $q->where('company_id', $data['company_id']));
                            }

                            $allActiveIds = $query->pluck('id');

                            if ($allActiveIds->isEmpty()) {
                                Notification::make()
                                    ->warning()
                                    ->title('No hay empleados activos')
                                    ->body('No hay empleados activos para el filtro seleccionado.')
                                    ->send();

                                return;
                            }

                            $alreadyActiveIds = DB::table('employee_perceptions')
                                ->where('perception_id', $record->id)
                                ->whereNull('end_date')
                                ->whereIn('employee_id', $allActiveIds)
                                ->pluck('employee_id');

                            $toProcessIds = $allActiveIds->diff($alreadyActiveIds);
                            $alreadyAssigned = $alreadyActiveIds->count();

                            if ($toProcessIds->isEmpty()) {
                                Notification::make()
                                    ->info()
                                    ->title('Sin cambios')
                                    ->body('Todos los empleados activos ya tienen esta percepción asignada.')
                                    ->send();

                                return;
                            }

                            DB::transaction(function () use ($record, $toProcessIds) {
                                $now = now();
                                $today = $now->toDateString();

                                $reactivateIds = DB::table('employee_perceptions')
                                    ->where('perception_id', $record->id)
                                    ->whereIn('employee_id', $toProcessIds)
                                    ->whereDate('start_date', $today)
                                    ->pluck('employee_id');

                                if ($reactivateIds->isNotEmpty()) {
                                    DB::table('employee_perceptions')
                                        ->where('perception_id', $record->id)
                                        ->whereIn('employee_id', $reactivateIds)
                                        ->whereDate('start_date', $today)
                                        ->update([
                                            'end_date' => null,
                                            'notes' => 'Reasignado masivamente desde el panel de percepciones',
                                            'updated_at' => $now,
                                        ]);
                                }

                                $newIds = $toProcessIds->diff($reactivateIds);

                                if ($newIds->isNotEmpty()) {
                                    DB::table('employee_perceptions')->insert(
                                        $newIds->map(fn ($id) => [
                                            'employee_id' => $id,
                                            'perception_id' => $record->id,
                                            'start_date' => $today,
                                            'end_date' => null,
                                            'custom_amount' => null,
                                            'notes' => 'Asignado masivamente desde el panel de percepciones',
                                            'created_at' => $now,
                                            'updated_at' => $now,
                                        ])->values()->toArray()
                                    );
                                }
                            });

                            Notification::make()
                                ->success()
                                ->title('Percepción asignada exitosamente')
                                ->body("La percepción \"{$record->name}\" fue asignada a {$toProcessIds->count()} empleado(s). {$alreadyAssigned} empleado(s) ya la tenían.")
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error al asignar la percepción')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Action::make('removeFromAllEmployees')
                    ->label('Remover de Todos')
                    ->icon('heroicon-o-user-group')
                    ->color('warning')
                    ->modalHeading('Remover Percepción de Empleados')
                    ->modalDescription(fn (Perception $record) => "Remueve \"{$record->name}\" de todos los empleados que la tienen asignada. Filtrá opcionalmente por empresa o sucursal.")
                    ->modalSubmitActionLabel('Sí, remover')
                    ->form([
                        Select::make('company_id')
                            ->label('Empresa')
                            ->options(fn () => Company::active()->pluck('name', 'id'))
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('branch_id', null))
                            ->placeholder('Todas las empresas'),

                        Select::make('branch_id')
                            ->label('Sucursal')
                            ->options(fn (Get $get) => $get('company_id')
                                ? Branch::where('company_id', $get('company_id'))->pluck('name', 'id')
                                : [])
                            ->native(false)
                            ->placeholder('Todas las sucursales')
                            ->disabled(fn (Get $get) => ! $get('company_id')),
                    ])
                    ->action(function (Perception $record, array $data) {
                        try {
                            $employeeIds = null;

                            if (! empty($data['branch_id'])) {
                                $employeeIds = Employee::where('branch_id', $data['branch_id'])->pluck('id');
                            } elseif (! empty($data['company_id'])) {
                                $employeeIds = Employee::whereHas('branch', fn ($q) => $q->where('company_id', $data['company_id']))->pluck('id');
                            }

                            $activeAssignments = DB::table('employee_perceptions')
                                ->where('perception_id', $record->id)
                                ->whereNull('end_date')
                                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                                ->count();

                            if ($activeAssignments === 0) {
                                Notification::make()
                                    ->info()
                                    ->title('Sin asignaciones activas')
                                    ->body('Esta percepción no está asignada a ningún empleado en el filtro seleccionado.')
                                    ->send();

                                return;
                            }

                            DB::table('employee_perceptions')
                                ->where('perception_id', $record->id)
                                ->whereNull('end_date')
                                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                                ->update([
                                    'end_date' => now()->toDateString(),
                                    'notes' => 'Removido masivamente desde el panel de percepciones',
                                    'updated_at' => now(),
                                ]);

                            Notification::make()
                                ->success()
                                ->title('Percepción removida exitosamente')
                                ->body("La percepción \"{$record->name}\" fue removida de {$activeAssignments} empleado(s).")
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error al remover la percepción')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No hay percepciones registradas')
            ->emptyStateDescription('Comienza a agregar percepciones para gestionar los adicionales en los salarios de los empleados.')
            ->emptyStateIcon('heroicon-o-plus-circle');
    }

    /**
     * Define el infolist para visualizar una percepción.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfoSection::make('Información General')
                    ->schema([
                        TextEntry::make('code')
                            ->label('Código')
                            ->badge()
                            ->color('gray')
                            ->copyable()
                            ->copyMessage('Código copiado'),

                        TextEntry::make('name')
                            ->label('Nombre'),

                        TextEntry::make('description')
                            ->label('Descripción')
                            ->placeholder('Sin descripción')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                InfoSection::make('Configuración de Cálculo')
                    ->schema([
                        TextEntry::make('calculation')
                            ->label('Tipo de Cálculo')
                            ->badge()
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'fixed' => 'Monto Fijo',
                                'percentage' => 'Porcentaje del Salario',
                                default => '-',
                            })
                            ->color(fn ($state) => match ($state) {
                                'fixed' => 'primary',
                                'percentage' => 'info',
                                default => 'gray',
                            }),

                        TextEntry::make('amount')
                            ->label('Monto Fijo')
                            ->money('PYG', locale: 'es_PY')
                            ->placeholder('-')
                            ->visible(fn (Perception $record) => $record->calculation === 'fixed'),

                        TextEntry::make('percent')
                            ->label('Porcentaje')
                            ->formatStateUsing(fn ($state) => $state ? number_format($state, 2).'%' : '-')
                            ->visible(fn (Perception $record) => $record->calculation === 'percentage'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                InfoSection::make('Configuración Adicional')
                    ->schema([
                        TextEntry::make('type')
                            ->label('Tipo de percepción')
                            ->badge()
                            ->formatStateUsing(fn ($state) => Perception::getTypeLabels()[$state] ?? $state)
                            ->color(fn ($state) => Perception::getTypeColors()[$state] ?? 'gray'),

                        IconEntry::make('affects_ips')
                            ->label('Afecta IPS')
                            ->boolean()
                            ->trueIcon('heroicon-o-check-circle')
                            ->falseIcon('heroicon-o-x-circle')
                            ->trueColor('success')
                            ->falseColor('danger'),

                        IconEntry::make('is_active')
                            ->label('Estado')
                            ->boolean()
                            ->trueIcon('heroicon-o-check-circle')
                            ->falseIcon('heroicon-o-x-circle')
                            ->trueColor('success')
                            ->falseColor('danger'),
                    ])
                    ->columns(3)
                    ->collapsible(),

                InfoSection::make('Auditoría')
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Creado')
                            ->dateTime('d/m/Y H:i'),

                        TextEntry::make('updated_at')
                            ->label('Última Actualización')
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPerceptions::route('/'),
            'create' => Pages\CreatePerception::route('/create'),
            'view' => Pages\ViewPerception::route('/{record}'),
            'edit' => Pages\EditPerception::route('/{record}/edit'),
        ];
    }
}
