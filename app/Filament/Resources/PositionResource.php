<?php

namespace App\Filament\Resources;

use App\Filament\Actions\MobileMarkingFields;
use App\Filament\Resources\PositionResource\Pages;
use App\Filament\Resources\PositionResource\RelationManagers;
use App\Models\Position;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PositionResource extends Resource
{
    protected static ?string $model = Position::class;

    protected static ?string $navigationGroup = 'Organización';

    protected static ?string $navigationLabel = 'Cargos';

    protected static ?string $label = 'Cargo';

    protected static ?string $pluralLabel = 'Cargos';

    protected static ?string $slug = 'cargos';

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    /**
     * Define el formulario para crear y editar cargos, incluyendo validaciones y relaciones.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información del Cargo')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre del Cargo')
                            ->placeholder('Ejemplo: Gerente de Ventas')
                            ->required()
                            ->maxLength(60)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn ($rule, Get $get) => $rule->where('department_id', $get('department_id'))
                            )
                            ->columnSpan(1),

                        Select::make('department_id')
                            ->label('Departamento')
                            ->placeholder('Seleccione un departamento')
                            ->relationship('department', 'name')
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->company?->name.' — '.$record->name)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->columnSpan(1),

                        Select::make('parent_id')
                            ->label('Reporta a')
                            ->placeholder('Ninguno (cargo de nivel superior)')
                            ->relationship('parent', 'name')
                            ->options(function (?Position $record) {
                                $query = Position::with('department');

                                if ($record) {
                                    $excludeIds = array_merge([$record->id], $record->getAllDescendantIds());
                                    $query->whereNotIn('id', $excludeIds);
                                }

                                return $query->get()->mapWithKeys(
                                    fn ($p) => [$p->id => $p->name.($p->department ? ' — '.$p->department->name : '')]
                                );
                            })
                            ->searchable()
                            ->native(false)
                            ->helperText('Seleccione el cargo al que este puesto reporta directamente')
                            ->columnSpan(1),

                        MobileMarkingFields::select('Valor para quienes ocupan este cargo y no tengan el suyo propio. «Heredar» usa el de la sucursal, la empresa o el valor general. Ej.: «Sí» para Supervisores y Gerentes.')
                            ->columnSpan(1),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Define la infolist para mostrar detalles del cargo, incluyendo conteos de empleados y cargos subordinados.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Resumen')
                ->columns(3)
                ->schema([
                    TextEntry::make('employees_count')
                        ->label('Empleados asignados')
                        ->getStateUsing(fn (Position $record) => $record->employees()->count())
                        ->badge()
                        ->color('success')
                        ->icon('heroicon-o-users'),

                    TextEntry::make('children_count')
                        ->label('Cargos subordinados')
                        ->getStateUsing(fn (Position $record) => $record->children()->count())
                        ->badge()
                        ->color('info')
                        ->icon('heroicon-o-arrow-down-circle'),

                    TextEntry::make('created_at')
                        ->label('Creado')
                        ->dateTime('d/m/Y H:i'),
                ]),

            InfoSection::make('Información del Cargo')
                ->columns(2)
                ->schema([
                    TextEntry::make('department.name')
                        ->label('Departamento')
                        ->badge()
                        ->color('info')
                        ->icon('heroicon-o-building-library'),

                    TextEntry::make('department.company.trade_name')
                        ->label('Empresa')
                        ->badge()
                        ->color('gray')
                        ->icon('heroicon-o-building-office-2'),

                    TextEntry::make('name')
                        ->label('Nombre del Cargo')
                        ->weight('bold')
                        ->icon('heroicon-o-briefcase'),

                    TextEntry::make('parent.name')
                        ->label('Reporta a')
                        ->placeholder('Cargo de nivel superior')
                        ->icon('heroicon-o-arrow-up-circle'),
                ]),
        ]);
    }

    /**
     * Define la tabla para listar cargos, con columnas, filtros y acciones.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Cargo')
                    ->sortable()
                    ->searchable()
                    ->weight('bold')
                    ->icon('heroicon-o-briefcase')
                    ->iconColor('primary'),

                TextColumn::make('department.name')
                    ->label('Departamento')
                    ->sortable()
                    ->searchable()
                    ->badge()
                    ->color('info'),

                TextColumn::make('parent.name')
                    ->label('Reporta a')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->iconColor('warning'),

                TextColumn::make('employees_count')
                    ->label('Empleados')
                    ->counts('employees')
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
                SelectFilter::make('department')
                    ->label('Departamento')
                    ->relationship('department', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->actions([
                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('¿Eliminar cargo?')
                    ->modalSubmitActionLabel('Sí, eliminar'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No hay cargos registrados')
            ->emptyStateDescription('Comienza a agregar los cargos de tu empresa para asignarlos a los empleados.')
            ->emptyStateIcon('heroicon-o-briefcase');
    }

    /**
     * Define las relaciones para el recurso de cargos, permitiendo gestionar empleados asignados a cada cargo.
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\EmployeesRelationManager::class,
        ];
    }

    /**
     * Define las páginas para el recurso de cargos, incluyendo rutas para listar, crear, ver y editar cargos.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPositions::route('/'),
            'create' => Pages\CreatePosition::route('/create'),
            'view' => Pages\ViewPosition::route('/{record}'),
            'edit' => Pages\EditPosition::route('/{record}/edit'),
        ];
    }
}
