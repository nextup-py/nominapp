<?php

namespace App\Notifications;

use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notificación a los admins cuando una IP agota el límite diario de intentos
 * de vinculación de dispositivo (`/vincular-dispositivo`, 15/día). Puede ser un
 * empleado real trabado (CI o fecha de nacimiento mal recordada) o un
 * intento de fuerza bruta contra una credencial de baja entropía — en
 * cualquiera de los dos casos, antes nadie se enteraba hasta que el
 * empleado reclamaba directamente. Se dispara como máximo una vez por
 * IP por día (ver `MobileLinkController::notifyAdminsOfDailyLimitOnce()`).
 */
class MobileLinkThrottledNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    public function __construct(
        public readonly string $ip,
        public readonly ?string $lastCiAttempted,
    ) {}

    /**
     * Se arma con el builder de `Filament\Notifications\Notification` (no un
     * array plano) — la campanita del panel filtra por `data->format =
     * 'filament'`, que solo ese builder genera (ver
     * TerminalProvisionedNotification::toDatabase() para el detalle del gotcha).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $ciLabel = filled($this->lastCiAttempted) ? " (último CI intentado: {$this->lastCiAttempted})" : '';

        return FilamentNotification::make()
            ->title('Límite diario de vinculación de dispositivo agotado')
            ->body("La IP {$this->ip} alcanzó el límite de 15 intentos de vinculación hoy{$ciLabel}. Puede ser un empleado con datos incorrectos o un intento de acceso indebido — revisar los logs si es necesario.")
            ->icon('heroicon-o-shield-exclamation')
            ->warning()
            ->getDatabaseMessage();
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'device.link_throttled';
    }
}
