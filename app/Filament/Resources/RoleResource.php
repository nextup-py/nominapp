<?php

namespace App\Filament\Resources;

use App\Filament\Actions\RoleActions;
use App\Filament\Resources\RoleResource\Pages;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/** Gestiona el CRUD de roles con permisos agrupados por módulo. */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $label = 'rol';

    protected static ?string $pluralLabel = 'roles';

    protected static ?string $slug = 'roles';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('Super Admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    /** El rol Super Admin no se edita: todo el sistema lo reconoce por su nombre. */
    public static function canEdit($record): bool
    {
        return static::canViewAny() && $record->name !== 'Super Admin';
    }

    /** No se elimina Super Admin ni un rol que todavía tiene usuarios asignados. */
    public static function canDelete($record): bool
    {
        return static::canViewAny() && $record->name !== 'Super Admin' && ! $record->users()->exists();
    }

    /**
     * Permisos asignables agrupados por módulo: `grupo => [nombre del permiso => etiqueta]`. Lo usan el formulario y el detalle.
     *
     * @return array<string, array<string, string>>
     */
    public static function permissionOptions(): array
    {
        $groups = [];

        foreach (PermissionSeeder::GROUPS as $groupName => $models) {
            $options = [];
            foreach ($models as $model) {
                if (in_array($model, PermissionSeeder::MODELS, true)) {
                    foreach (PermissionSeeder::ABILITIES as $ability) {
                        $options["{$ability}_{$model}"] = PermissionSeeder::ABILITY_LABELS[$ability].' — '.PermissionSeeder::MODEL_LABELS[$model];
                    }
                }
                foreach (BusinessActionPermissionSeeder::ACTIONS[$model] ?? [] as $action => $label) {
                    $options["{$action}_{$model}"] = $label.' — '.PermissionSeeder::MODEL_LABELS[$model];
                }
            }
            $groups[$groupName] = $options;
        }

        return $groups;
    }

    public static function form(Form $form): Form
    {
        $sections = [
            Section::make('Datos del Rol')
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre del rol')
                        ->required()
                        ->unique(table: Role::class, column: 'name', ignoreRecord: true)
                        ->validationMessages(['unique' => 'Ya existe un rol con ese nombre.'])
                        ->maxLength(255),

                    Textarea::make('description')
                        ->label('Descripción')
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText('Qué hace este rol, en pocas palabras. Se muestra en el listado.'),
                ]),
        ];

        foreach (self::permissionOptions() as $groupName => $options) {
            $sections[] = Section::make($groupName)
                ->collapsible()
                ->schema([
                    CheckboxList::make(self::groupFieldKey($groupName))
                        ->label('')
                        ->options($options)
                        ->columns(2)
                        ->searchable()
                        ->bulkToggleable()
                        ->dehydrated(false),
                ]);
        }

        return $form->schema($sections)->columns(1);
    }

    /**
     * Detalle del rol: descripción, usuarios que lo tienen y permisos resumidos por módulo.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        $sections = [
            InfoSection::make('Rol')
                ->schema([
                    TextEntry::make('description')->label('Descripción')->placeholder('Sin descripción'),
                    TextEntry::make('users_list')
                        ->label('Usuarios con este rol')
                        ->getStateUsing(fn (Role $record) => $record->users()->orderBy('name')->pluck('name')->all())
                        ->badge()
                        ->placeholder('Ningún usuario'),
                    TextEntry::make('permissions_total')
                        ->label('Permisos')
                        ->getStateUsing(fn (Role $record) => $record->name === 'Super Admin' ? 'Todos (acceso total)' : $record->permissions()->count()),
                ])
                ->columns(1),
        ];

        foreach (self::permissionOptions() as $groupName => $options) {
            $sections[] = InfoSection::make($groupName)
                ->collapsible()
                ->schema([
                    TextEntry::make('perm_'.self::groupFieldKey($groupName))
                        ->label('')
                        ->getStateUsing(fn (Role $record) => collect($options)
                            ->only($record->permissions->pluck('name')->all())
                            ->values()
                            ->all())
                        ->badge()
                        ->color('primary')
                        ->placeholder('Sin permisos en este módulo'),
                ])
                ->visible(fn (Role $record) => $record->name !== 'Super Admin');
        }

        return $infolist->schema($sections);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Rol')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Descripción')
                    ->limit(70)
                    ->tooltip(fn (Role $record) => $record->description)
                    ->placeholder('Sin descripción')
                    ->wrap(),

                TextColumn::make('users_count')
                    ->label('Usuarios')
                    ->counts('users')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray'),

                TextColumn::make('permissions_count')
                    ->label('Permisos')
                    ->counts('permissions')
                    ->badge(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->actions([
                ViewAction::make()
                    ->label('Ver')
                    ->icon('heroicon-o-eye'),
                RoleActions::duplicate(),
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square'),
                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('¿Eliminar rol?')
                    ->modalDescription('Solo se pueden eliminar roles sin usuarios asignados.')
                    ->modalSubmitActionLabel('Sí, eliminar'),
            ])
            ->bulkActions([])
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('No hay roles registrados')
            ->emptyStateDescription('Creá el primer rol para empezar a asignar permisos.')
            ->emptyStateIcon('heroicon-o-shield-check');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/crear'),
            'view' => Pages\ViewRole::route('/{record}'),
            'edit' => Pages\EditRole::route('/{record}/editar'),
        ];
    }

    public static function groupFieldKey(string $groupName): string
    {
        return 'group_'.Str::slug($groupName, '_');
    }

    /**
     * Reconstruye la lista plana de nombres de permiso seleccionados a
     * partir de los campos virtuales `group_*` (uno por módulo).
     *
     * @param  array<string, mixed>  $state
     * @return array<int, string>
     */
    public static function collectPermissions(array $state): array
    {
        $names = [];
        foreach (array_keys(PermissionSeeder::GROUPS) as $groupName) {
            $names = array_merge($names, $state[self::groupFieldKey($groupName)] ?? []);
        }

        return $names;
    }
}
