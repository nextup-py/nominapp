<?php

namespace App\Notifications;

use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Aviso al propio usuario de que su cuenta ingresó desde una IP que no había usado antes. */
class NewLoginLocationNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    public function __construct(public readonly string $ip) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            ...FilamentNotification::make()
                ->title('Ingreso desde un lugar nuevo')
                ->body("Tu cuenta ingresó desde la IP {$this->ip}, que no habías usado antes. Si no fuiste vos, cambiá tu contraseña y avisale al administrador.")
                ->icon('heroicon-o-shield-exclamation')
                ->warning()
                ->getDatabaseMessage(),
            'ip' => $this->ip,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'system.new_login_location';
    }
}
