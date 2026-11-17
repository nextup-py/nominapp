<?php

namespace App\Filament\Actions;

use App\Models\Employee;
use App\Services\UserAccountService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Invitar a una persona: crea el usuario sin contraseña y le envía el enlace para definirla. Se usa en el listado
 * de Usuarios (alta libre) y, con `forEmployee()`, en la ficha del empleado (correo y ficha ya conocidos).
 */
class UserInviteAction
{
    /** Campos comunes: correo, rol (solo lo asigna un Super Admin, como en el formulario de usuarios). */
    private static function roleField(): Select
    {
        return Select::make('roles')
            ->label('Roles')
            ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'id')->all())
            ->multiple()
            ->preload()
            ->searchable()
            ->visible(fn (): bool => auth()->user()?->hasRole('Super Admin') ?? false)
            ->helperText('Sin rol la persona no puede entrar hasta que se le asigne uno.');
    }

    /** Aviso final según si salió el correo. */
    private static function report(array $result, bool $hasRole): void
    {
        $user = $result['user'];
        $notification = Notification::make();

        if ($result['sent']) {
            $notification->success()->title('Invitación enviada')->body("Se envió el enlace a {$user->email}.".($hasRole ? '' : ' Recordá asignarle un rol.'));
        } else {
            $notification->warning()->title('Usuario creado, pero no se envió el correo')
                ->body('Configurá el correo (Configuración General → Correo) y usá «Enviar enlace de contraseña» desde Usuarios.');
        }

        $notification->persistent()->send();
    }

    /** Alta libre desde el listado de Usuarios (acción de encabezado). */
    public static function make(): Action
    {
        return Action::make('invite')
            ->label('Invitar por correo')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('create_user') ?? false)
            ->modalHeading('Invitar a un nuevo usuario')
            ->modalDescription('Se crea el usuario sin contraseña y se le envía un enlace para que la defina él mismo.')
            ->modalSubmitActionLabel('Enviar invitación')
            ->form([
                TextInput::make('name')->label('Nombre completo')->required()->maxLength(255),
                TextInput::make('email')->label('Correo electrónico')->email()->required()->maxLength(255)
                    ->unique('users', 'email'),
                self::roleField(),
            ])
            ->action(function (array $data): void {
                self::report(app(UserAccountService::class)->invite($data), ! empty($data['roles']));
            });
    }

    /** Crear el usuario de un empleado desde su ficha (acción de encabezado de la vista del empleado). */
    public static function forEmployee(): Action
    {
        return Action::make('create_user')
            ->label('Crear usuario')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('create_user') ?? false)
            ->visible(fn (Employee $record): bool => $record->status === 'active' && app(UserAccountService::class)->userFor($record) === null)
            ->modalHeading('Crear usuario para el empleado')
            ->modalDescription('Se crea el usuario, se vincula a esta ficha y se le envía un enlace para que defina su contraseña.')
            ->modalSubmitActionLabel('Crear y enviar invitación')
            ->fillForm(fn (Employee $record): array => ['email' => $record->email])
            ->form([
                TextInput::make('email')->label('Correo electrónico')->email()->required()->maxLength(255)
                    ->rule(fn () => Rule::unique('users', 'email')),
                self::roleField(),
            ])
            ->action(function (Employee $record, array $data): void {
                $result = app(UserAccountService::class)->invite([
                    'name' => $record->full_name,
                    'email' => $data['email'],
                    'employee_id' => $record->id,
                    'roles' => $data['roles'] ?? [],
                ]);

                self::report($result, ! empty($data['roles']));
            });
    }
}
