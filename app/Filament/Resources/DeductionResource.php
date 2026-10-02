<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeductionResource\Pages;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Deduction;
use App\Models\Employee;
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

/** Gestiona el catálogo de deducciones salariales. */
class DeductionResource extends Resource
{
    protected static ?string $model = Deduction::class;

    protected static ?string $navigationGroup = 'Nóminas';

    protected static ?string $navigationLabel = 'Deducciones';

    protected static ?string $label = 'Deducción';

    protected static ?string $pluralLabel = 'Deducciones';

    protected static ?string $slug = 'deducciones';

    protected static ?string $navigationIcon = 'heroicon-o-minus-circle';

    protected static ?int $navigationSort = 4;

    /**
     * Define el formulario para crear y editar deducciones.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información General')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->placeholder('Ejemplo: Aporte Obrero IPS')
                            ->required()
                            ->maxLength(60)
                            ->columnSpan(1),

                        TextInput::make('code')
                            ->label('Código')
                            ->placeholder('Ejemplo: IPS001')
                            ->required()
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->columnSpan(1),

                        Select::make('type')
                            ->label('Tipo')
                            ->options(Deduction::getTypeOptions())
                            ->default('legal')
                            ->native(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (string $state, Set $set) {
                                $set('apply_judicial_limit', $state === 'judicial');
                            })
                            ->columnSpan(1),

                        Textarea::make('description')
                            ->label('Descripción')
                            ->placeholder('Ejemplo: Aporte del 9% del salario bruto cotizable (Art. 17 Ley 430/73)')
                            ->maxLength(255)
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

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
                            ->helperText('Define cómo se calculará esta deducción.')
                            ->columnSpan(1),

                        TextInput::make('amount')
                            ->label('Monto Fijo')
                            ->numeric()
                            ->minValue(fn (Get $get) => $get('type') !== 'loan' ? 1 : null)
                            ->maxValue(999999999)
                            ->step(1)
                            ->prefix('₲')
                            ->visible(fn (Get $get) => $get('calculation') === 'fixed')
                            ->required(fn (Get $get) => $get('calculation') === 'fixed' && $get('type') !== 'loan')
                            ->helperText(fn (Get $get) => $get('type') === 'loan'
                                ? 'Dejar vacío — el monto se fija automáticamente por cada cuota/adelanto.'
                                : 'Monto que se descontará del salario.')
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
                            ->helperText('Porcentaje del salario base que se descontará.')
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Section::make('Configuración Adicional')
                    ->schema([
                        Toggle::make('apply_judicial_limit')
                            ->label('Aplicar tope legal (Art. 245 CLT)')
                            ->helperText('Limita el embargo al 25% del excedente del salario mínimo. Desactivar para prestaciones alimentarias.')
                            ->default(false)
                            ->inline(false)
                            ->visible(fn (Get $get) => $get('type') === 'judicial')
                            ->columnSpanFull(),

                        Toggle::make('is_mandatory')
                            ->label('Deducción Obligatoria')
                            ->helperText(fn (?Deduction $record) => $record?->isSystem()
                                ? 'Deducción de sistema: la genera el módulo correspondiente por evento y no puede ser obligatoria.'
                                : 'Indica que esta deducción debe aplicarse a todos los empleados.')
                            ->default(false)
                            ->inline(false)
                            ->disabled(fn (?Deduction $record) => $record?->isSystem() ?? false)
                            ->dehydrated(fn (?Deduction $record) => ! ($record?->isSystem() ?? false))
                            ->columnSpan(1),

                        Toggle::make('is_active')
                            ->label('Activo')
                            ->helperText('Habilitar o deshabilitar esta deducción.')
                            ->default(true)
                            ->inline(false)
                            ->columnSpan(1),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Define la tabla para listar deducciones.
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
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Deduction::getTypeLabels()[$state] ?? $state)
                    ->color(fn ($state) => Deduction::getTypeColors()[$state] ?? 'gray')
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
                    ->formatStateUsing(fn ($state) => Deduction::formatPercent($state))
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),

                IconColumn::make('is_mandatory')
                    ->label('Obligatorio')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable()
                    ->toggleable(),

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
                    ->label('Tipo de Deducción')
                    ->options(Deduction::getTypeLabels())
                    ->native(false),

                SelectFilter::make('calculation')
                    ->label('Tipo de Cálculo')
                    ->options([
                        'fixed' => 'Monto Fijo',
                        'percentage' => 'Porcentaje',
                    ])
                    ->native(false),

                TernaryFilter::make('is_mandatory')
                    ->label('Obligatorio')
                    ->placeholder('Todos')
                    ->trueLabel('Obligatorios')
                    ->falseLabel('No Obligatorios')
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
                    ->visible(fn (Deduction $record) => $record->is_active)
                    ->modalHeading('Asignar Deducción a Empleados')
                    ->modalDescription(fn (Deduction $record) => "Asigna \"{$record->name}\" a todos los empleados activos que aún no la tienen. Filtrá opcionalmente por empresa o sucursal.")
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
                    ->action(function (Deduction $record, array $data) {
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

                            $alreadyActiveIds = DB::table('employee_deductions')
                                ->where('deduction_id', $record->id)
                                ->where('start_date', '<=', now())
                                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
                                ->whereIn('employee_id', $allActiveIds)
                                ->pluck('employee_id');

                            $toProcessIds = $allActiveIds->diff($alreadyActiveIds);
                            $alreadyAssigned = $alreadyActiveIds->count();

                            if ($toProcessIds->isEmpty()) {
                                Notification::make()
                                    ->info()
                                    ->title('Sin cambios')
                                    ->body('Todos los empleados activos ya tienen esta deducción asignada.')
                                    ->send();

                                return;
                            }

                            DB::transaction(function () use ($record, $toProcessIds) {
                                $now = now();
                                $today = $now->toDateString();

                                $reactivateIds = DB::table('employee_deductions')
                                    ->where('deduction_id', $record->id)
                                    ->whereIn('employee_id', $toProcessIds)
                                    ->whereDate('start_date', $today)
                                    ->pluck('employee_id');

                                if ($reactivateIds->isNotEmpty()) {
                                    DB::table('employee_deductions')
                                        ->where('deduction_id', $record->id)
                                        ->whereIn('employee_id', $reactivateIds)
                                        ->whereDate('start_date', $today)
                                        ->update([
                                            'end_date' => null,
                                            'notes' => 'Reasignado masivamente desde el panel de deducciones',
                                            'updated_at' => $now,
                                        ]);
                                }

                                $newIds = $toProcessIds->diff($reactivateIds);

                                if ($newIds->isNotEmpty()) {
                                    DB::table('employee_deductions')->insert(
                                        $newIds->map(fn ($id) => [
                                            'employee_id' => $id,
                                            'deduction_id' => $record->id,
                                            'start_date' => $today,
                                            'end_date' => null,
                                            'custom_amount' => null,
                                            'notes' => 'Asignado masivamente desde el panel de deducciones',
                                            'created_at' => $now,
                                            'updated_at' => $now,
                                        ])->values()->toArray()
                                    );
                                }
                            });

                            Notification::make()
                                ->success()
                                ->title('Deducción asignada exitosamente')
                                ->body("La deducción \"{$record->name}\" fue asignada a {$toProcessIds->count()} empleado(s). {$alreadyAssigned} empleado(s) ya la tenían.")
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error al asignar la deducción')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Action::make('removeFromAllEmployees')
                    ->label('Remover de Todos')
                    ->icon('heroicon-o-user-group')
                    ->color('warning')
                    ->modalHeading('Remover Deducción de Empleados')
                    ->modalDescription(fn (Deduction $record) => "Remueve \"{$record->name}\" de todos los empleados que la tienen asignada. Filtrá opcionalmente por empresa o sucursal.")
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
                    ->action(function (Deduction $record, array $data) {
                        try {
                            $employeeIds = null;

                            if (! empty($data['branch_id'])) {
                                $employeeIds = Employee::where('branch_id', $data['branch_id'])->pluck('id');
                            } elseif (! empty($data['company_id'])) {
                                $employeeIds = Employee::whereHas('branch', fn ($q) => $q->where('company_id', $data['company_id']))->pluck('id');
                            }

                            $activeAssignments = DB::table('employee_deductions')
                                ->where('deduction_id', $record->id)
                                ->where('start_date', '<=', now())
                                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
                                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                                ->count();

                            if ($activeAssignments === 0) {
                                Notification::make()
                                    ->info()
                                    ->title('Sin asignaciones activas')
                                    ->body('Esta deducción no está asignada a ningún empleado en el filtro seleccionado.')
                                    ->send();

                                return;
                            }

                            DB::table('employee_deductions')
                                ->where('deduction_id', $record->id)
                                ->where('start_date', '<=', now())
                                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()))
                                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                                ->update([
                                    'end_date' => now()->toDateString(),
                                    'notes' => 'Removido masivamente desde el panel de deducciones',
                                    'updated_at' => now(),
                                ]);

                            Notification::make()
                                ->success()
                                ->title('Deducción removida exitosamente')
                                ->body("La deducción \"{$record->name}\" fue removida de {$activeAssignments} empleado(s).")
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error al remover la deducción')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No hay deducciones registradas')
            ->emptyStateDescription('Comienza a agregar deducciones para gestionar los descuentos en los salarios de los empleados.')
            ->emptyStateIcon('heroicon-o-minus-circle');
    }

    /**
     * Define el infolist para visualizar una deducción.
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

                        TextEntry::make('type')
                            ->label('Tipo')
                            ->badge()
                            ->formatStateUsing(fn ($state) => Deduction::getTypeLabels()[$state] ?? $state)
                            ->color(fn ($state) => Deduction::getTypeColors()[$state] ?? 'gray'),

                        TextEntry::make('description')
                            ->label('Descripción')
                            ->placeholder('Sin descripción')
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
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
                            ->visible(fn (Deduction $record) => $record->calculation === 'fixed'),

                        TextEntry::make('percent')
                            ->label('Porcentaje')
                            ->formatStateUsing(fn ($state) => $state ? number_format($state, 2).'%' : '-')
                            ->visible(fn (Deduction $record) => $record->calculation === 'percentage'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                InfoSection::make('Configuración Adicional')
                    ->schema([
                        IconEntry::make('apply_judicial_limit')
                            ->label('Tope legal Art. 245 CLT')
                            ->boolean()
                            ->trueIcon('heroicon-o-check-circle')
                            ->falseIcon('heroicon-o-minus-circle')
                            ->trueColor('warning')
                            ->falseColor('gray')
                            ->visible(fn (Deduction $record) => $record->type === 'judicial')
                            ->columnSpanFull(),

                        IconEntry::make('is_mandatory')
                            ->label('Deducción Obligatoria')
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
                    ->columns(2)
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
            'index' => Pages\ListDeductions::route('/'),
            'create' => Pages\CreateDeduction::route('/create'),
            'view' => Pages\ViewDeduction::route('/{record}'),
            'edit' => Pages\EditDeduction::route('/{record}/edit'),
        ];
    }
}
