<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DepartmentResource\Pages;
use App\Filament\Resources\DepartmentResource\RelationManagers;
use App\Models\Company;
use App\Models\Department;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DepartmentResource extends Resource
{
    protected static ?string $model = Department::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'departamentos';

    protected static ?string $navigationLabel = 'Departamentos';

    protected static ?string $label = 'Departamento';

    protected static ?string $pluralLabel = 'Departamentos';

    protected static ?string $navigationGroup = 'Organización';

    protected static ?int $navigationSort = 3;

    /**
     * Define el formulario para crear y editar departamentos.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('company_id')
                    ->label('Empresa')
                    ->options(fn (?Department $record) => Company::query()
                        ->where(fn ($query) => $query->active()->orWhere('id', $record?->company_id))
                        ->get()
                        ->mapWithKeys(fn (Company $company) => [$company->id => $company->display_name])
                        ->all())
                    ->default(fn () => Company::active()->count() === 1 ? Company::active()->value('id') : null)
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->live()
                    ->disabled(fn (?Department $record): bool => $record?->deletionBlockers() !== [] && $record !== null)
                    ->helperText(fn (?Department $record): ?string => $record !== null && ($summary = $record->deletionBlockersSummary()) !== ''
                        ? "No se puede cambiar: el departamento tiene {$summary}."
                        : null)
                    ->visible(fn (): bool => Company::active()->count() > 1),

                TextInput::make('name')
                    ->label('Nombre')
                    ->placeholder('Ej: Recursos Humanos, Finanzas, IT...')
                    ->required()
                    ->maxLength(60)
                    ->unique(
                        table: Department::class,
                        column: 'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule, Get $get) => $rule->where('company_id', $get('company_id'))
                    )
                    ->validationMessages(['unique' => 'Ya existe un departamento con este nombre en la empresa.']),

                TextInput::make('cost_center')
                    ->label('Centro de Costo')
                    ->placeholder('Ej: RH-001, FIN-002, IT-003...')
                    ->unique(
                        table: Department::class,
                        column: 'cost_center',
                        ignoreRecord: true,
                        modifyRuleUsing: fn ($rule, Get $get) => $rule->where('company_id', $get('company_id'))
                    )
                    ->validationMessages(['unique' => 'Ya existe un departamento con este centro de costo en la empresa.'])
                    ->maxLength(30)
                    ->nullable()
                    ->helperText('Opcional. No se repite dentro de la empresa.'),

                Textarea::make('description')
                    ->label('Descripción')
                    ->placeholder('Ej: Gestiona el reclutamiento, la capacitación y el bienestar del personal.')
                    ->nullable()
                    ->maxLength(500)
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * Define el infolist para visualizar un departamento.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Resumen')
                ->columns(3)
                ->schema([
                    TextEntry::make('positions_count')
                        ->label('Cargos')
                        ->getStateUsing(fn (Department $record) => $record->positions()->count())
                        ->badge()
                        ->color('info')
                        ->icon('heroicon-o-briefcase'),

                    TextEntry::make('employees_count')
                        ->label('Empleados')
                        ->getStateUsing(fn (Department $record) => $record->loadCount('employees')->employees_count)
                        ->badge()
                        ->color('success')
                        ->icon('heroicon-o-users'),

                    TextEntry::make('created_at')
                        ->label('Creado')
                        ->icon('heroicon-o-pencil-square')
                        ->dateTime('d/m/Y H:i'),
                ]),

            InfoSection::make('Información del Departamento')
                ->columns(3)
                ->schema([
                    TextEntry::make('company.trade_name')
                        ->label('Empresa')
                        ->getStateUsing(fn (Department $record) => $record->company?->display_name)
                        ->badge()
                        ->color('gray')
                        ->icon('heroicon-o-building-office-2'),

                    TextEntry::make('name')
                        ->label('Nombre')
                        ->weight('bold')
                        ->icon('heroicon-o-building-library'),

                    TextEntry::make('cost_center')
                        ->label('Centro de Costo')
                        ->placeholder('—')
                        ->icon('heroicon-o-tag'),

                    TextEntry::make('description')
                        ->label('Descripción')
                        ->placeholder('Sin descripción')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Define la tabla para listar departamentos, con columnas, filtros y acciones.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.trade_name')
                    ->label('Empresa')
                    ->getStateUsing(fn (Department $record) => $record->company?->display_name)
                    ->visible(fn (): bool => Company::active()->count() > 1)
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('description')
                    ->label('Descripción')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cost_center')
                    ->label('Centro de Costo')
                    ->badge()
                    ->color('secondary')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('positions_count')
                    ->label('Cargos')
                    ->counts('positions')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('employees_count')
                    ->label('Empleados')
                    ->counts('employees')
                    ->badge()
                    ->color('success')
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->since()
                    ->description(fn ($record) => $record->created_at->format('d/m/Y H:i'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Última actualización')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->options(fn () => Company::active()->get()->mapWithKeys(fn (Company $company) => [$company->id => $company->display_name])->all())
                    ->searchable()
                    ->native(false)
                    ->visible(fn (): bool => Company::active()->count() > 1),

                SelectFilter::make('cargos')
                    ->label('Cargos')
                    ->options([
                        'with' => 'Con cargos',
                        'without' => 'Sin cargos',
                    ])
                    ->native(false)
                    ->query(fn ($query, $state) => match ($state['value'] ?? null) {
                        'with' => $query->has('positions'),
                        'without' => $query->doesntHave('positions'),
                        default => $query,
                    }),
            ])
            ->emptyStateHeading('No hay departamentos registrados')
            ->emptyStateDescription('Agregar el primer departamento de la empresa.')
            ->emptyStateIcon('heroicon-o-building-library');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PositionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDepartments::route('/'),
            'create' => Pages\CreateDepartment::route('/create'),
            'view' => Pages\ViewDepartment::route('/{record}'),
            'edit' => Pages\EditDepartment::route('/{record}/edit'),
        ];
    }
}
