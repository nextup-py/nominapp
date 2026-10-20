<?php

namespace App\Notifications;

use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso a RR.HH. cuando un empleado sin permiso de marcación móvil intenta vincular su celular
 * (ver `MobileLinkController::claim()`). No se repite mientras el anterior siga sin leer.
 */
class MobileLinkBlockedNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    public function __construct(public readonly Employee $employee) {}

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Intento de vincular un celular sin permiso')
            ->body("{$this->employee->full_name} intentó vincular su celular, pero no está habilitado para marcar desde el celular. Si corresponde, habilítelo en su ficha.")
            ->icon('heroicon-o-device-phone-mobile')
            ->warning()
            ->actions([
                Action::make('view')->label('Ver empleado')->url(EmployeeResource::getUrl('view', ['record' => $this->employee])),
            ])
            ->getDatabaseMessage() + ['employee_id' => $this->employee->id];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'device.mobile_link_blocked';
    }
}
