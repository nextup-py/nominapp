<?php

namespace App\Notifications;

use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notificación a los admins cuando el dispositivo vinculado de un empleado se
 * re-vincula (había un dispositivo ya vinculado y se reemplazó por otro).
 *
 * CI + fecha de nacimiento es una credencial débil (baja entropía) — alguien
 * con esos datos de otro empleado podría re-vincular su propio dispositivo y así
 * revocar silenciosamente el dispositivo legítimo (denegación de servicio
 * dirigida). Esta notificación no previene el ataque, pero da visibilidad
 * para que un admin investigue una re-vinculación que el empleado no
 * reconoce (ver runbook de vinculación/revocación de dispositivos).
 */
class MobileDeviceRelinkedNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    public function __construct(
        public readonly Employee $employee,
        public readonly ?string $userAgent = null,
    ) {}

    /**
     * Envía por email a los admins además de la campanita — una
     * re-vinculación puede indicar un intento de acoso/DoS dirigido (ver
     * docblock de la clase) y no debería depender de que alguien revise
     * Filament a tiempo.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Dispositivo re-vinculado — '.$this->employee->full_name)
            ->line("El dispositivo vinculado de {$this->employee->full_name} (CI: {$this->employee->ci}) fue reemplazado por uno nuevo.")
            ->line('Si el empleado no reconoce este cambio, revocá el acceso desde su ficha.');

        if (filled($this->userAgent)) {
            $mail->line("Dispositivo nuevo: {$this->userAgent}");
        }

        return $mail->action('Ver empleado', EmployeeResource::getUrl('view', ['record' => $this->employee]));
    }

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
        $body = "El dispositivo vinculado de {$this->employee->full_name} (CI: {$this->employee->ci}) fue reemplazado por uno nuevo. Si el empleado no reconoce este cambio, revocá el acceso desde su ficha.";

        if (filled($this->userAgent)) {
            $body .= " Dispositivo nuevo: {$this->userAgent}";
        }

        return [
            ...FilamentNotification::make()
                ->title('Dispositivo re-vinculado')
                ->body($body)
                ->icon('heroicon-o-device-phone-mobile')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ver empleado')->url(EmployeeResource::getUrl('view', ['record' => $this->employee])),
                ])
                ->getDatabaseMessage(),
            'employee_id' => $this->employee->id,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'device.mobile_relinked';
    }
}
