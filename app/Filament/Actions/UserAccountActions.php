<?php

namespace App\Filament\Actions;

use App\Models\User;
use App\Services\UserAccountService;
use App\Services\UserSessionService;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

/**
 * Acciones de fila sobre el acceso de un usuario (activar/desactivar, enviar enlace de contraseña, cerrar sesiones).
 * Las acciones personalizadas de Filament no consultan la policy: cada una exige `update_user`.
 */
class UserAccountActions
{
    /** Desactivar o reactivar al usuario. */
    public static function toggleActive(): Action
    {
        return Action::make('toggle_active')
            ->label(fn (User $record): string => $record->is_active ? 'Desactivar' : 'Activar')
            ->icon(fn (User $record): string => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
            ->color(fn (User $record): string => $record->is_active ? 'warning' : 'success')
            ->authorize(fn (): bool => auth()->user()?->can('update_user') ?? false)
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => $record->is_active ? '¿Desactivar usuario?' : '¿Activar usuario?')
            ->modalDescription(fn (User $record): string => $record->is_active
                ? "{$record->name} no podrá entrar al panel y se cerrarán sus sesiones abiertas. Conserva su historial."
                : "{$record->name} podrá volver a entrar con su contraseña.")
            ->modalSubmitActionLabel(fn (User $record): string => $record->is_active ? 'Sí, desactivar' : 'Sí, activar')
            ->action(function (User $record): void {
                $service = app(UserAccountService::class);

                if (! $record->is_active) {
                    $service->activate($record);
                    Notification::make()->success()->title('Usuario activado')->send();

                    return;
                }

                if ($blocker = $service->deactivate($record, auth()->user())) {
                    Notification::make()->danger()->title('No se puede desactivar')->body($blocker)->send();

                    return;
                }

                Notification::make()->success()->title('Usuario desactivado')->send();
            });
    }

    /** Envía por correo el enlace para definir una contraseña nueva. */
    public static function sendPasswordLink(): Action
    {
        return Action::make('send_password_link')
            ->label('Enviar enlace de contraseña')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('update_user') ?? false)
            ->visible(fn (User $record): bool => $record->is_active)
            ->requiresConfirmation()
            ->modalHeading('¿Enviar enlace de contraseña?')
            ->modalDescription(fn (User $record): string => "Se enviará a {$record->email} un enlace para que defina una contraseña nueva. Requiere el correo configurado.")
            ->modalSubmitActionLabel('Sí, enviar')
            ->action(function (User $record): void {
                if (app(UserAccountService::class)->sendPasswordLink($record)) {
                    Notification::make()->success()->title('Enlace enviado')->body("Se envió a {$record->email}.")->send();

                    return;
                }

                Notification::make()->danger()->title('No se pudo enviar el enlace')
                    ->body('Revisá que el correo esté configurado en Configuración General → Correo.')->send();
            });
    }

    /** Cierra todas las sesiones abiertas del usuario (la propia no se toca). */
    public static function closeSessions(): Action
    {
        return Action::make('close_sessions')
            ->label('Cerrar sesiones')
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('update_user') ?? false)
            ->visible(fn (User $record): bool => ! $record->is(auth()->user()))
            ->requiresConfirmation()
            ->modalHeading('¿Cerrar las sesiones abiertas?')
            ->modalDescription(fn (User $record): string => "{$record->name} deberá volver a ingresar en todos sus dispositivos.")
            ->modalSubmitActionLabel('Sí, cerrar sesiones')
            ->action(function (User $record): void {
                $closed = UserSessionService::closeOthers($record, null);

                Notification::make()->success()->title($closed === 0 ? 'No tenía sesiones abiertas' : "Sesiones cerradas: {$closed}")->send();
            });
    }
}
