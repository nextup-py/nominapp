<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
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
                            ->minLength(8)
                            ->maxLength(255)
                            ->placeholder('Mínimo 8 caracteres')
                            ->helperText(fn ($context) => $context === 'edit'
                                ? 'Dejar en blanco para mantener la contraseña actual'
                                : 'La contraseña debe tener al menos 8 caracteres')
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-o-envelope'),

                TextColumn::make('email')
                    ->label('Correo Electrónico')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Registrado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->actions([
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary'),
                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('¿Eliminar usuario?')
                    ->modalSubmitActionLabel('Sí, eliminar'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
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
