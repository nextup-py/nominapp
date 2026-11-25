<?php

namespace App\Filament\Resources;

use App\Filament\Actions\UserAccountActions;
use App\Filament\Concerns\UsesNavigationLayout;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Support\PasswordPolicy;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    use UsesNavigationLayout;

    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $label = 'usuario';

    protected static ?string $pluralLabel = 'usuarios';

    protected static ?string $slug = 'usuarios';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Información del Usuario')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre completo')
                            ->placeholder('Ej: Juan Pérez')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        TextInput::make('email')
                            ->label('Correo electrónico')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->placeholder('usuario@ejemplo.com')
                            ->columnSpan(2),
                    ])
                    ->columns(2),

                Section::make('Seguridad')
                    ->schema([
                        TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->required(fn ($context) => $context === 'create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->rule(PasswordPolicy::rule(), fn ($context, $state) => filled($state))
                            ->maxLength(255)
                            ->placeholder('Mínimo 10 caracteres')
                            ->helperText(fn ($context) => $context === 'edit'
                                ? 'Dejar en blanco para mantener la contraseña actual'
                                : PasswordPolicy::hint())
                            ->columnSpan(1),

                        TextInput::make('password_confirmation')
                            ->label('Confirmar contraseña')
                            ->password()
                            ->required(fn ($context) => $context === 'create')
                            ->dehydrated(false)
                            ->same('password')
                            ->placeholder('Repite la contraseña')
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Section::make('Ficha de empleado')
                    ->description('Opcional: si la persona es empleada, vincularla muestra su cargo y sucursal en su perfil.')
                    ->schema([
                        Select::make('employee_id')
                            ->label('Empleado')
                            ->relationship('employee', 'first_name', fn ($query) => $query->where('status', 'active'))
                            ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->first_name} {$record->last_name} — CI: {$record->ci}")
                            ->searchable(['first_name', 'last_name', 'ci'])
                            ->placeholder('Sin ficha vinculada')
                            ->visible(fn () => auth()->user()?->hasRole('Super Admin') ?? false),
                    ]),

                Section::make('Roles')
                    ->schema([
                        Select::make('roles')
                            ->label('Roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->visible(fn () => auth()->user()?->hasRole('Super Admin') ?? false)
                            ->helperText('Solo Super Admin puede asignar roles.'),
                    ]),
            ]);
    }

    /** Consulta del listado: roles y ficha precargados y el último ingreso calculado en la misma consulta. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['roles:id,name', 'employee:id,first_name,last_name'])
            ->withMax('loginHistories as last_login_at', 'logged_in_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Correo electrónico')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-o-envelope'),

                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->color('primary')
                    ->placeholder('Sin rol'),

                TextColumn::make('is_active')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => $state ? 'Activo' : 'Inactivo')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'danger'),

                TextColumn::make('last_login_at')
                    ->label('Último acceso')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('Nunca ingresó'),

                TextColumn::make('employee.full_name')
                    ->label('Ficha de empleado')
                    ->placeholder('Sin ficha vinculada')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('role')
                    ->label('Rol')
                    ->relationship('roles', 'name')
                    ->preload(),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Editar')
                        ->icon('heroicon-o-pencil-square'),
                    UserAccountActions::toggleActive(),
                    UserAccountActions::sendPasswordLink(),
                    UserAccountActions::closeSessions(),
                    DeleteAction::make()
                        ->label('Eliminar')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->before(function (User $record, DeleteAction $action): void {
                            if ($blocker = $record->lockoutBlocker(auth()->user())) {
                                Notification::make()->danger()->title('No se puede eliminar')->body($blocker)->send();
                                $action->halt();
                            }
                        })
                        ->modalHeading('¿Eliminar usuario?')
                        ->modalDescription('Se pierde el acceso para siempre. Si solo querés impedir que entre, usá Desactivar.')
                        ->modalSubmitActionLabel('Sí, eliminar'),
                ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No hay usuarios registrados')
            ->emptyStateDescription('Comienza creando un nuevo usuario del sistema.')
            ->emptyStateIcon('heroicon-o-users');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageUsers::route('/'),
        ];
    }
}
