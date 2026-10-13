<?php

namespace App\Filament\Resources;

use App\Filament\Actions\BranchStatusAction;
use App\Filament\Resources\BranchResource\Pages;
use App\Filament\Resources\BranchResource\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\BranchResource\RelationManagers\EmployeesRelationManager;
use App\Filament\Resources\BranchResource\RelationManagers\TerminalsRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PyDepartment;
use Cheesegrits\FilamentGoogleMaps\Fields\Map;
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
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rules\Unique;

/** Recurso Filament para la gestión de sucursales. */
class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Sucursales';

    protected static ?string $navigationGroup = 'Organización';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Sucursal';

    protected static ?string $pluralModelLabel = 'Sucursales';

    protected static ?string $slug = 'sucursales';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Define el formulario de creación y edición de sucursales.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Información general')
                ->description('Datos básicos de la sucursal.')
                ->schema([
                    Select::make('company_id')
                        ->label('Empresa')
                        ->relationship('company', 'name', fn ($query) => $query->active())
                        ->getOptionLabelFromRecordUsing(
                            fn (Company $record) => $record->name.($record->trade_name ? ' ('.$record->trade_name.')' : '')
                        )
                        ->searchable(['name', 'trade_name'])
                        ->preload()
                        ->required()
                        ->helperText('Empresa a la que pertenece esta sucursal.'),

                    TextInput::make('name')
                        ->label('Nombre')
                        ->placeholder('Ej: Sucursal Central')
                        ->required()
                        ->maxLength(100)
                        ->unique(
                            table: Branch::class,
                            column: 'name',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('company_id', $get('company_id'))
                        )
                        ->helperText('El nombre debe ser único dentro de la empresa.'),

                    TextInput::make('phone')
                        ->label('Teléfono')
                        ->tel()
                        ->placeholder('Ej: 0981123456')
                        ->maxLength(10)
                        ->regex('/^0\d{8,9}$/')
                        ->validationMessages([
                            'regex' => 'Ingrese un número válido de Paraguay: móvil (09XXXXXXXX) o fijo (021XXXXXX / 0XXXXXXXX).',
                        ])
                        ->helperText('Número sin espacios ni guiones. Ej: 0981123456'),

                    TextInput::make('email')
                        ->label('Correo electrónico')
                        ->email()
                        ->placeholder('Ej: sucursal@empresa.com')
                        ->maxLength(100)
                        ->unique(Branch::class, 'email', ignoreRecord: true)
                        ->helperText('Debe ser único entre las sucursales.'),

                    Toggle::make('is_active')
                        ->label('Activa')
                        ->default(true)
                        ->disabled(fn (?Branch $record) => $record?->is_active && $record->activeEmployeesCount() > 0)
                        ->helperText(fn (?Branch $record) => $record?->is_active && ($active = $record->activeEmployeesCount()) > 0
                            ? "No se puede desactivar: la sucursal tiene {$active} empleados activos. Reasígnelos primero."
                            : 'Las sucursales inactivas no se ofrecen al asignar empleados o terminales.')
                        ->hiddenOn('create'),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make('Ubicación')
                ->description('Dirección y coordenadas GPS de la sucursal.')
                ->schema([
                    TextInput::make('address')
                        ->label('Dirección')
                        ->maxLength(100)
                        ->placeholder('Ej: Av. España 1234 c/ Brasil')
                        ->helperText('Se autocompleta al mover el pin en el mapa; la ciudad se elige del catálogo.'),

                    Select::make('department')
                        ->label('Departamento')
                        ->options(fn () => PyDepartment::getOptions())
                        ->searchable()
                        ->native(false)
                        ->live()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Select $component, ?Branch $record): void {
                            $component->state(Company::departmentIdForCity($record?->city));
                        })
                        ->afterStateUpdated(fn (Set $set) => $set('city', null))
                        ->visible(fn () => PyDepartment::query()->exists())
                        ->helperText('Filtra las ciudades; no se guarda.'),

                    Select::make('city')
                        ->label('Ciudad')
                        ->options(fn (Get $get, ?Branch $record) => Company::citiesOptions(
                            filled($get('department')) ? (int) $get('department') : null,
                            $record?->city,
                        ))
                        ->searchable()
                        ->native(false)
                        ->helperText('Ciudad del catálogo oficial donde está la sucursal.'),

                    Map::make('coordinates')
                        ->label('Ubicación en el mapa')
                        ->columnSpanFull()
                        ->defaultLocation([-25.2867, -57.6478]) // Asunción, Paraguay
                        ->draggable()
                        ->clickable()
                        ->autocomplete('address')
                        ->autocompleteReverse(true)
                        ->reverseGeocode([
                            'address' => '%n %S',
                        ])
                        ->geolocate()
                        ->height('400px'),
                ])
                ->columns(2)
                ->collapsible(),
        ]);
    }

    /**
     * Define la vista de detalle de una sucursal.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Datos pendientes')
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->visible(fn (Branch $record) => $record->missingDataWarnings() !== [])
                ->schema([
                    TextEntry::make('_missing_data')
                        ->hiddenLabel()
                        ->getStateUsing(fn (Branch $record) => $record->missingDataWarnings())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->color('warning'),
                ])
                ->collapsible(),

            InfoSection::make('Estadísticas')
                ->columns(4)
                ->schema([
                    TextEntry::make('active_employees')
                        ->label('Empleados Activos / Total')
                        ->getStateUsing(
                            fn (Branch $record) => $record->activeEmployees()->count().' / '.$record->employees()->count()
                        )
                        ->badge()
                        ->color('success')
                        ->icon('heroicon-o-users'),

                    TextEntry::make('employees_without_contract')
                        ->label('Activos sin contrato')
                        ->getStateUsing(fn (Branch $record) => $record->activeEmployeesWithoutContractCount())
                        ->badge()
                        ->color(fn (string $state) => (int) $state > 0 ? 'danger' : 'gray')
                        ->icon('heroicon-o-exclamation-circle'),

                    TextEntry::make('active_terminals')
                        ->label('Terminales Activos')
                        ->getStateUsing(fn (Branch $record) => $record->activeTerminalsCount())
                        ->badge()
                        ->color(fn (string $state) => (int) $state > 0 ? 'info' : 'gray')
                        ->icon('heroicon-o-computer-desktop'),

                    TextEntry::make('is_active')
                        ->label('Estado')
                        ->formatStateUsing(fn ($state) => $state ? 'Activa' : 'Inactiva')
                        ->badge()
                        ->color(fn ($state) => $state ? 'success' : 'danger'),
                ])
                ->collapsible(),

            InfoSection::make('Información general')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')
                        ->label('Nombre de la sucursal')
                        ->copyable()
                        ->copyMessage('Nombre copiado'),

                    TextEntry::make('company.name')
                        ->label('Empresa'),

                    TextEntry::make('company.trade_name')
                        ->label('Nombre comercial')
                        ->placeholder('Sin nombre comercial'),
                ])
                ->collapsible(),

            InfoSection::make('Contacto y dirección')
                ->columns(2)
                ->schema([
                    TextEntry::make('address')
                        ->label('Dirección')
                        ->icon('heroicon-o-map')
                        ->copyable()
                        ->copyMessage('Dirección copiada')
                        ->placeholder('Sin dirección'),

                    TextEntry::make('city')
                        ->label('Ciudad')
                        ->badge()
                        ->color('info')
                        ->placeholder('Sin ciudad'),

                    TextEntry::make('phone')
                        ->label('Teléfono')
                        ->icon('heroicon-o-phone')
                        ->copyable()
                        ->copyMessage('Teléfono copiado')
                        ->placeholder('Sin teléfono'),

                    TextEntry::make('email')
                        ->label('Correo electrónico')
                        ->icon('heroicon-o-envelope')
                        ->copyable()
                        ->copyMessage('Correo copiado')
                        ->placeholder('Sin correo'),
                ])
                ->collapsible(),
        ]);
    }

    /**
     * Define la tabla de listado de sucursales con filtros y acciones.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')
                    ->label('Empresa')
                    ->description(fn ($record) => $record->company?->trade_name)
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->visible(fn () => Company::active()->count() > 1),

                TextColumn::make('name')
                    ->label('Sucursal')
                    ->description(fn ($record) => $record->address)
                    ->icon('heroicon-o-building-storefront')
                    ->sortable()
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('city')
                    ->label('Ciudad')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->searchable()
                    ->placeholder('Sin ciudad'),

                TextColumn::make('contact_info')
                    ->label('Contacto')
                    ->getStateUsing(fn ($record) => $record->phone ?: 'Sin teléfono')
                    ->description(fn ($record) => $record->email ?: null)
                    ->icon('heroicon-o-phone')
                    ->placeholder('Sin contacto'),

                TextColumn::make('active_employees_count')
                    ->label('Empleados Activos')
                    ->counts('activeEmployees')
                    ->badge()
                    ->color('success')
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('terminals_count')
                    ->label('Terminales')
                    ->counts('terminals')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(),

                TextColumn::make('pending_data_count')
                    ->label('Datos')
                    ->getStateUsing(fn (Branch $record) => (int) $record->pending_data_count)
                    ->formatStateUsing(fn (int $state) => $state === 0 ? 'Completos' : "{$state} pendiente".($state > 1 ? 's' : ''))
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'success' : 'warning')
                    ->icon(fn (int $state) => $state === 0 ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')
                    ->tooltip(fn (Branch $record) => $record->pendingDataList() === [] ? null : implode(' · ', $record->pendingDataList()))
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => $state ? 'Activa' : 'Inactiva')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'danger')
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
                SelectFilter::make('company_id')
                    ->label('Empresa')
                    ->relationship('company', 'name', fn ($query) => $query->active())
                    ->getOptionLabelFromRecordUsing(
                        fn (Company $record) => $record->name.($record->trade_name ? ' ('.$record->trade_name.')' : '')
                    )
                    ->searchable(['name', 'trade_name'])
                    ->preload()
                    ->placeholder('Todas las empresas')
                    ->visible(fn () => Company::active()->count() > 1),

                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas')
                    ->native(false),

                SelectFilter::make('city')
                    ->label('Ciudad')
                    ->options(
                        fn () => Branch::query()
                            ->distinct()
                            ->pluck('city', 'city')
                            ->filter()
                            ->sort()
                            ->toArray()
                    )
                    ->placeholder('Todas las ciudades')
                    ->searchable()
                    ->native(false),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('view_map')
                        ->label('Ver en mapa')
                        ->icon('heroicon-o-map')
                        ->color('info')
                        ->url(
                            fn ($record) => isset($record->coordinates['lat'], $record->coordinates['lng'])
                                ? sprintf('https://www.google.com/maps?q=%s,%s', $record->coordinates['lat'], $record->coordinates['lng'])
                                : null
                        )
                        ->openUrlInNewTab()
                        ->visible(fn ($record) => isset($record->coordinates['lat'], $record->coordinates['lng'])),

                    BranchStatusAction::make(Action::class),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withPendingData())
            ->bulkActions([
                BulkActionGroup::make([
                    self::deleteBulkAction(),
                ]),
            ])
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultSort('name')
            ->emptyStateHeading('No hay sucursales registradas')
            ->emptyStateDescription('Comienza agregando la primera sucursal.')
            ->emptyStateIcon('heroicon-o-building-storefront');
    }

    /**
     * Eliminación masiva que respeta {@see Branch::deletionBlockers()}: borra solo las sucursales
     * vacías y avisa cuáles se omitieron y por qué (el hook `deleting` del modelo lanzaría una excepción).
     */
    public static function deleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->label('Eliminar seleccionadas')
            ->modalHeading('Eliminar sucursales')
            ->modalDescription('Se eliminarán solo las sucursales sin empleados, terminales ni fallas de marcación. Las demás se omiten y se avisa el motivo.')
            ->modalSubmitActionLabel('Sí, eliminar')
            ->action(function (Collection $records): void {
                $deleted = 0;
                $skipped = [];
                $user = auth()->user();

                foreach ($records as $record) {
                    if (! $user?->can('delete', $record)) {
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
                    ->title($deleted > 0 ? "{$deleted} sucursal(es) eliminada(s)" : 'No se eliminó ninguna sucursal');

                if ($skipped !== []) {
                    $notification
                        ->body('Omitidas: '.implode('; ', $skipped).'. Reasígnelos antes de eliminarlas.')
                        ->color($deleted > 0 ? 'warning' : 'danger')
                        ->icon($deleted > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-x-circle');
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }

    /**
     * Retorna los relation managers asociados al recurso.
     *
     * @return array<class-string<RelationManager>>
     */
    public static function getRelations(): array
    {
        return [
            EmployeesRelationManager::class,
            TerminalsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    /**
     * Retorna las páginas registradas para este recurso.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranches::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'view' => Pages\ViewBranch::route('/{record}'),
            'edit' => Pages\EditBranch::route('/{record}/edit'),
        ];
    }
}
