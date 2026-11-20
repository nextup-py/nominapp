<?php

namespace App\Notifications;

use App\Filament\Resources\EmployeeResource;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso semanal a RR.HH. con los empleados activos que no marcaron nada en los últimos días y no están de
 * vacaciones ni con licencia. No se repite mientras el aviso anterior siga sin leer.
 */
class SilentEmployeesNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /** Cantidad máxima de nombres que se muestran en el texto. */
    public const NAMES_SHOWN = 8;

    /**
     * @param  array<int, string>  $names  Nombres con sucursal, ya ordenados.
     */
    public function __construct(public readonly int $count, public readonly int $days, public readonly array $names = []) {}

    /**
     * Se arma con el builder de `Filament\Notifications\Notification` para que la campanita lo muestre.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $noun = $this->count === 1 ? 'empleado activo sin ninguna marcación' : 'empleados activos sin ninguna marcación';
        $shown = array_slice($this->names, 0, self::NAMES_SHOWN);
        $more = $this->count - count($shown);
        $list = implode('; ', $shown).($more > 0 ? " y {$more} más" : '');

        return [
            ...FilamentNotification::make()
                ->title("{$this->count} {$noun} en {$this->days} días")
                ->body("No están de vacaciones ni con licencia aprobada. Revisar si les falta celular vinculado, horario o terminal: {$list}.")
                ->icon('heroicon-o-user-minus')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ver empleados')->url(EmployeeResource::getUrl('index', ['activeTab' => 'active'])),
                ])
                ->getDatabaseMessage(),
            'silent_employees_count' => $this->count,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'attendance.silent_employees';
    }
}
