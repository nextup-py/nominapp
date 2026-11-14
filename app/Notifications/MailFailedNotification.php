<?php

namespace App\Notifications;

use App\Filament\Pages\ManageGeneralSettings;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso al Super Admin de que un correo no pudo enviarse (servidor caído, clave vencida, remitente rechazado). Llega por
 * la campanita —el correo es justamente lo que falló— y no se repite mientras el anterior siga sin leer.
 */
class MailFailedNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /** @param  string  $reason  Mensaje del error del servidor de correo, ya recortado. */
    public function __construct(public readonly string $reason) {}

    /**
     * Se arma con el builder de `Filament\Notifications\Notification` para que la campanita del panel la muestre
     * (ver TerminalProvisionedNotification::toDatabase()).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            ...FilamentNotification::make()
                ->title('No se pudo enviar un correo')
                ->body("Los avisos por correo no están llegando. Motivo: {$this->reason}. Revisá Configuración General → Correo y probá con «Enviar correo de prueba».")
                ->icon('heroicon-o-envelope')
                ->danger()
                ->actions([
                    FilamentAction::make('view')->label('Ir a Configuración de correo')->url(ManageGeneralSettings::getUrl()),
                ])
                ->getDatabaseMessage(),
            'reason' => $this->reason,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'system.mail_failed';
    }
}
