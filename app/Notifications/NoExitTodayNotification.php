<?php

namespace App\Notifications;

use App\Filament\Pages\AttendanceLiveMonitor;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso de la noche a RR.HH. con los empleados que terminaron su turno hoy y no marcaron la salida, para consultarles
 * mientras se acuerdan. Enlaza al Monitor en vivo; no se repite mientras el aviso anterior siga sin leer.
 */
class NoExitTodayNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /** Cantidad de nombres que se listan en el cuerpo del aviso. */
    private const LISTED_NAMES = 6;

    /**
     * @param  array<int, string>  $names  Nombres de los empleados sin salida hoy.
     */
    public function __construct(public readonly array $names) {}

    /**
     * Se arma con el builder de `Filament\Notifications\Notification` para que la campanita del panel la muestre.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $count = count($this->names);
        $listed = implode(', ', array_slice($this->names, 0, self::LISTED_NAMES));
        $extra = $count - self::LISTED_NAMES;

        return [
            ...FilamentNotification::make()
                ->title($count === 1 ? '1 empleado se fue sin marcar la salida' : "{$count} empleados se fueron sin marcar la salida")
                ->body($listed.($extra > 0 ? " y {$extra} más" : '').'. Consultales ahora, mientras se acuerdan; después se corrige en Por resolver.')
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ver Monitor en vivo')->url(AttendanceLiveMonitor::getUrl()),
                ])
                ->getDatabaseMessage(),
            'no_exit_count' => $count,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'attendance.no_exit_today';
    }
}
