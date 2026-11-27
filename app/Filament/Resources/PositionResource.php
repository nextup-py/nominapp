<?php

namespace App\Filament\Resources;

use App\Filament\Actions\MobileMarkingFields;
use App\Filament\Actions\OrgUnitStatusAction;
use App\Filament\Actions\OvertimeAllowedFields;
use App\Filament\Actions\PositionMergeAction;
use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\PositionResource\Pages;
use App\Filament\Resources\PositionResource\RelationManagers;
use App\Models\Company;
use App\Models\Department;
use App\Models\Position;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PositionResource extends Resource
{
    use UsesNavigationLayout;

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
                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true)
                            ->disabled(fn (?Position $record): bool => $record !== null && $record->is_active && $record->activeContractsCount() > 0)
                            ->helperText(fn (?Position $record): string => $record !== null && $record->is_active && ($count = $record->activeContractsCount()) > 0
                                ? 'No se puede desactivar: tiene '.($count === 1 ? '1 contrato vigente' : "{$count} contratos vigentes").'. Moverlos a otro cargo con Fusionar primero.'
                                : 'Los cargos inactivos no se ofrecen al asignar contratos ni como superior.')
                            ->hiddenOn('create')
                            ->columnSpanFull(),

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
                            ->relationship('department', 'name', fn ($query, ?Position $record) => $query->assignable($record?->department_id))
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

                        Textarea::make('description')
                            ->label('Descripción o funciones')
                            ->placeholder('Ej: Atiende la caja y concilia el arqueo al cierre.')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),

                        Toggle::make('is_managerial')
                            ->label('Cargo de jefatura')
                            ->helperText('Supervisa a otras personas.')
                            ->columnSpanFull(),

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
                                    ->assignable($record?->parent_id)
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

                Section::make('Remuneración y plazas')
                    ->description('Opcional. El rango salarial y las plazas solo avisan al cargar contratos; no bloquean.')
                    ->icon('heroicon-o-banknotes')
                    ->collapsible()
                    ->collapsed(fn (?Position $record): bool => $record === null || (blank($record->salary_min)
                        && blank($record->salary_max)
                        && blank($record->headcount)))
                    ->schema([
                        TextInput::make('salary_min')
                            ->label('Salario mínimo')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Gs.')
                            ->placeholder('Sin mínimo')
                            ->helperText('Salario mensual.'),

                        TextInput::make('salary_max')
                            ->label('Salario máximo')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Gs.')
                            ->placeholder('Sin máximo')
                            ->gte('salary_min')
                            ->validationMessages(['gte' => 'El máximo no puede ser menor que el mínimo.']),

                        TextInput::make('headcount')
                            ->label('Plazas autorizadas')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(9999)
                            ->placeholder('Sin límite')
                            ->helperText('Cuántas personas pueden ocupar el cargo. Vacío = sin límite.'),
                    ])
                    ->columns(3),

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

                        OvertimeAllowedFields::select('Rige para quien no tenga el suyo. «Heredar» usa la sucursal, la empresa o el valor general. Las horas extra de quien no está autorizado se registran igual, pero quedan marcadas.'),
                        OvertimeAllowedFields::requestSelect('Con «Sí», las horas extra sin un pedido previo aprobado quedan como no autorizadas. «Heredar» usa el siguiente nivel o Horas extra en Configuración.'),

                        Select::make('default_schedule_id')
                            ->label('Horario sugerido')
                            ->relationship('defaultSchedule', 'name', modifyQueryUsing: fn (Builder $query, ?Position $record) => $query->assignable($record?->default_schedule_id))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->placeholder('Sin horario sugerido')
                            ->helperText('Se preselecciona al asignar horario a quien ocupa el cargo; nunca se asigna solo.'),
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

                    TextEntry::make('is_active')
                        ->label('Estado')
                        ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo')
                        ->badge()
                        ->color(fn ($state) => $state ? 'success' : 'danger'),

                    TextEntry::make('name')
                        ->label('Nombre del Cargo')
                        ->weight('bold')
                        ->icon('heroicon-o-briefcase'),

                    TextEntry::make('parent.name')
                        ->label('Reporta a')
                        ->placeholder('Cargo de nivel superior')
                        ->icon('heroicon-o-arrow-up-circle'),

                    TextEntry::make('is_managerial')
                        ->label('Jefatura')
                        ->formatStateUsing(fn ($state) => $state ? 'Cargo de jefatura' : 'Sin personal a cargo')
                        ->badge()
                        ->color(fn ($state) => $state ? 'info' : 'gray'),

                    TextEntry::make('description')
                        ->label('Descripción o funciones')
                        ->placeholder('Sin descripción')
                        ->columnSpanFull(),
                ]),

            InfoSection::make('Remuneración y plazas')
                ->columns(3)
                ->schema([
                    TextEntry::make('salary_range')
                        ->label('Rango salarial')
                        ->getStateUsing(fn (Position $record) => $record->salaryRangeLabel())
                        ->placeholder('Sin rango definido'),

                    TextEntry::make('headcount')
                        ->label('Plazas ocupadas')
                        ->getStateUsing(fn (Position $record) => $record->headcount === null
                            ? $record->occupiedCount().' (sin límite)'
                            : $record->occupiedCount().' de '.$record->headcount)
                        ->badge()
                        ->color('info'),

                    TextEntry::make('vacancies')
                        ->label('Vacantes')
                        ->getStateUsing(fn (Position $record) => $record->vacanciesCount())
                        ->placeholder('Sin plazas definidas')
                        ->badge()
                        ->color(fn ($state) => (int) $state > 0 ? 'warning' : 'gray'),

                    TextEntry::make('overtime_allowed')
                        ->label('Horas extra')
                        ->getStateUsing(fn (Position $record) => match ($record->overtime_allowed) {
                            true => 'Sí, permitidas',
                            false => 'No, no autorizadas',
                            default => 'Heredar (se permiten)',
                        }),

                    TextEntry::make('defaultSchedule.name')
                        ->label('Horario sugerido')
                        ->placeholder('Sin horario sugerido'),
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

                TextColumn::make('is_active')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'danger')
                    ->sortable(),

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
                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos')
                    ->native(false),

                SelectFilter::make('department')
                    ->label('Departamento')
                    ->relationship('department', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->actions([
                ActionGroup::make([
                    PositionMergeAction::make(),

                    OrgUnitStatusAction::make(Action::class),

                    self::deleteAction(),
                ]),
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
            ->visible(fn (Position $record): bool => $record->deletionBlockers() === [])
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
