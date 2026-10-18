<?php

namespace App\Notifications;

use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notificación a los admins cuando un empleado vincula su dispositivo por
 * primera vez (sin dispositivo previo) para marcación offline. A diferencia
 * de `MobileDeviceRelinkedNotification` (tono de advertencia, posible
 * incidente de seguridad), esta es puramente informativa.
 */
class MobileDeviceLinkedNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    public function __construct(
        public readonly Employee $employee,
        public readonly ?string $userAgent = null,
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
        $body = "{$this->employee->full_name} (CI: {$this->employee->ci}) vinculó su dispositivo para marcar asistencia offline.";

        if (filled($this->userAgent)) {
            $body .= " Dispositivo: {$this->userAgent}";
        }

        return [
            ...FilamentNotification::make()
                ->title('Dispositivo vinculado')
                ->body($body)
                ->icon('heroicon-o-device-phone-mobile')
                ->success()
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
        return 'device.mobile_linked';
    }
}
