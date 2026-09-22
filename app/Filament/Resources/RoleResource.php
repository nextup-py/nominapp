<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
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

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
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
                ]),
        ];

        foreach (PermissionSeeder::GROUPS as $groupName => $models) {
            $options = [];
            foreach ($models as $model) {
                foreach (PermissionSeeder::ABILITIES as $ability) {
                    $options["{$ability}_{$model}"] = PermissionSeeder::ABILITY_LABELS[$ability].' — '.PermissionSeeder::MODEL_LABELS[$model];
                }
                foreach (BusinessActionPermissionSeeder::ACTIONS[$model] ?? [] as $action => $label) {
                    $options["{$action}_{$model}"] = $label.' — '.PermissionSeeder::MODEL_LABELS[$model];
                }
            }

            $sections[] = Section::make($groupName)
                ->collapsible()
                ->schema([
                    CheckboxList::make(self::groupFieldKey($groupName))
                        ->label('')
                        ->options($options)
                        ->columns(2)
                        ->dehydrated(false),
                ]);
        }

        return $form->schema($sections)->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Rol')
                    ->searchable()
                    ->sortable(),

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
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square'),
                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('¿Eliminar rol?')
                    ->modalDescription('Los usuarios que tengan este rol perderán los permisos asociados.')
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
