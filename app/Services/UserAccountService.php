<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Auth\ResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Altas y bajas de acceso de usuarios del panel: activar/desactivar (cierra sus sesiones), enviar el enlace para
 * definir o restablecer la contraseña, invitar a una persona y crear el usuario de un empleado.
 */
class UserAccountService
{
    /**
     * Desactiva al usuario y cierra todas sus sesiones. Devuelve el motivo si no se pudo, o null.
     */
    public function deactivate(User $user, ?User $actor): ?string
    {
        if ($blocker = $user->lockoutBlocker($actor)) {
            return $blocker;
        }

        $user->update(['is_active' => false]);
        UserSessionService::closeOthers($user, null);

        return null;
    }

    /** Vuelve a activar al usuario. */
    public function activate(User $user): void
    {
        $user->update(['is_active' => true]);
    }

    /**
     * Envía el enlace para definir (o restablecer) la contraseña. Devuelve false si no se pudo enviar
     * (correo sin configurar, SMTP caído, etc.): el llamador decide cómo avisar.
     */
    public function sendPasswordLink(User $user): bool
    {
        try {
            $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                ['email' => $user->email],
                function (User $user, string $token): void {
                    $notification = new ResetPassword($token);
                    $notification->url = Filament::getResetPasswordUrl($token, $user);
                    $user->notify($notification);
                },
            );

            return $status === Password::RESET_LINK_SENT;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Crea un usuario sin contraseña conocida (queda una aleatoria) y le envía el enlace para definirla.
     *
     * @param  array{name: string, email: string, employee_id?: int|null, roles?: array<int, int|string>}  $data
     * @return array{user: User, sent: bool}
     */
    public function invite(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'employee_id' => $data['employee_id'] ?? null,
            'password' => Str::password(32),
        ]);

        if (! empty($data['roles'])) {
            $user->syncRoles($data['roles']);
        }

        return ['user' => $user, 'sent' => $this->sendPasswordLink($user)];
    }

    /** Usuario vinculado a la ficha, si ya tiene. */
    public function userFor(Employee $employee): ?User
    {
        return User::query()->where('employee_id', $employee->id)->first();
    }
}
