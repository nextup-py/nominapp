<?php

namespace App\Filament\Resources;

use App\Filament\Actions\MobileMarkingFields;
use App\Filament\Actions\PositionMergeAction;
use App\Filament\Resources\PositionResource\Pages;
use App\Filament\Resources\PositionResource\RelationManagers;
use App\Models\Company;
use App\Models\Department;
use App\Models\Position;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

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
                    ->icon('heroicon-o-briefcase')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre del Cargo')
                            ->placeholder('Ej: Gerente de Ventas')
                            ->required()
                            ->maxLength(60)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn ($rule, Get $get) => $rule->where('department_id', $get('department_id'))
                            )
                            ->validationMessages(['unique' => 'Ya existe un cargo con este nombre en el departamento.']),

                        Select::make('department_id')
                            ->label('Departamento')
                            ->placeholder('Seleccionar departamento')
                            ->relationship('department', 'name')
                            ->getOptionLabelFromRecordUsing(fn ($record) => Company::active()->count() > 1
                                ? $record->company?->display_name.' — '.$record->name
                                : $record->name)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('parent_id', null);
                                $set('terminalBranches', []);
                            })
                            ->disabled(fn (?Position $record): bool => $record !== null && $record->deletionBlockers() !== [])
                            ->helperText(fn (?Position $record): ?string => $record !== null && ($summary = $record->deletionBlockersSummary()) !== ''
                                ? "No se puede cambiar: el cargo tiene {$summary}. Para unir cargos, usar Fusionar."
                                : null),

                        Select::make('parent_id')
                            ->label('Reporta a')
                            ->placeholder('Ninguno (cargo de nivel superior)')
                            ->options(function (?Position $record, Get $get): array {
                                $companyId = Department::query()->whereKey($get('department_id'))->value('company_id');

                                if ($companyId === null) {
                                    return [];
                                }

                                $query = Position::query()
                                    ->with('department')
                                    ->whereHas('department', fn ($department) => $department->where('company_id', $companyId));

                                if ($record) {
                                    $query->whereNotIn('id', array_merge([$record->id], $record->getAllDescendantIds()));
                                }

                                return $query->get()
                                    ->mapWithKeys(fn (Position $p) => [$p->id => $p->name.($p->department ? ' — '.$p->department->name : '')])
                                    ->all();
                            })
                            ->searchable()
                            ->native(false)
                            ->helperText('Cargos de la misma empresa que no dependan de este. Elegir primero el departamento.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Marcación de asistencia')
                    ->icon('heroicon-o-finger-print')
                    ->schema([
                        Toggle::make('marks_breaks')
                            ->label('Marca descanso')
                            ->default(true)
                            ->helperText('Desactivar si no marcan descanso (ej. repartidores en ruta): no se les ofrece «Inicio descanso» y las horas netas lo descuentan del horario.')
                            ->columnSpanFull(),

                        Select::make('terminal_access')
                            ->label('Marcación en terminales')
                            ->options(Position::getTerminalAccessOptions())
                            ->default(Position::TERMINAL_OWN)
                            ->native(false)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->helperText('Su rostro se copia a los terminales de las otras sucursales que se habiliten.'),

                        Select::make('terminalBranches')
                            ->label('Sucursales adicionales')
                            ->relationship('terminalBranches', 'name', function ($query, Get $get) {
                                $companyId = Department::query()->whereKey($get('department_id'))->value('company_id');

                                return $companyId === null ? $query : $query->where('branches.company_id', $companyId);
                            })
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('terminal_access') === Position::TERMINAL_SELECTED)
                            ->required(fn (Get $get): bool => $get('terminal_access') === Position::TERMINAL_SELECTED)
                            ->helperText('Además de su propia sucursal, que siempre entra.'),

                        MobileMarkingFields::select('Rige para quien no tenga el suyo. «Heredar» usa la sucursal, la empresa o el valor general. Ej.: «Sí» para Supervisores.')
                            ->columnSpanFull(),
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
                        ->getStateUsing(fn (Position $record) => $record->department?->company?->display_name)
                        ->visible(fn (): bool => Company::active()->count() > 1)
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
                PositionMergeAction::make(),

                self::deleteAction(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    self::deleteBulkAction(),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No hay cargos registrados')
            ->emptyStateDescription('Agregar los cargos de la empresa para asignarlos a los empleados.')
            ->emptyStateIcon('heroicon-o-briefcase');
    }

    /**
     * Eliminación individual que respeta {@see Position::deletionBlockers()}: avisa y no borra un cargo con
     * contratos (el hook `deleting` del modelo lanzaría una excepción).
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Eliminar')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->modalHeading('¿Eliminar cargo?')
            ->modalDescription(fn (Position $record): string => ($summary = $record->deletionBlockersSummary()) !== ''
                ? "El cargo \"{$record->name}\" tiene {$summary} y no se puede eliminar. Moverlos a otro cargo con Fusionar primero."
                : "Se eliminará el cargo \"{$record->name}\". Esta acción no se puede deshacer.")
            ->modalSubmitActionLabel('Sí, eliminar')
            ->before(function (DeleteAction $action, Position $record): void {
                if (($summary = $record->deletionBlockersSummary()) === '') {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->title('No se puede eliminar el cargo')
                    ->body("Tiene {$summary}. Moverlos a otro cargo con Fusionar antes de eliminarlo.")
                    ->persistent()
                    ->send();

                $action->halt();
            });
    }

    /**
     * Eliminación masiva: borra solo los cargos sin contratos y avisa cuáles se omitieron y por qué.
     */
    public static function deleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->label('Eliminar seleccionados')
            ->modalHeading('Eliminar cargos')
            ->modalDescription('Se eliminarán solo los cargos sin contratos. Los demás se omiten y se avisa el motivo.')
            ->modalSubmitActionLabel('Sí, eliminar')
            ->action(function (Collection $records): void {
                $deleted = 0;
                $skipped = [];

                foreach ($records as $record) {
                    if (! auth()->user()?->can('delete', $record)) {
                        $skipped[] = "{$record->name} (sin permiso)";

                        continue;
                    }

                    if ($record->deletionBlockers() !== []) {
                        $skipped[] = "{$record->name} ({$record->deletionBlockersSummary()})";

                        continue;
                    }

                    $record->delete();
                    $deleted++;
                }

                $notification = Notification::make()
                    ->title($deleted > 0 ? "{$deleted} cargo(s) eliminado(s)" : 'No se eliminó ningún cargo');

                if ($skipped !== []) {
                    $notification
                        ->body('Omitidos: '.implode('; ', $skipped).'. Moverlos a otro cargo con Fusionar antes de eliminarlos.')
                        ->color($deleted > 0 ? 'warning' : 'danger')
                        ->icon($deleted > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-x-circle');
                } else {
                    $notification->success();
                }

                $notification->send();
            });
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
