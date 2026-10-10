<?php

namespace App\Notifications;

use App\Filament\Resources\AttendanceDayResource;
use Carbon\Carbon;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso diario a RR.HH. de las jornadas que quedaron sin salida (entrada sin cerrar) desde el
 * día siguiente al turno. Resume la cantidad y enlaza a Asistencias con el filtro "Sin salida
 * registrada" activo; no se repite mientras el aviso anterior siga sin leer.
 */
class OpenShiftsPendingNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $count,
        public readonly Carbon $oldestDate,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Se arma con el builder de `Filament\Notifications\Notification` para que la campanita del
     * panel la muestre (ver TerminalProvisionedNotification::toDatabase()).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $plural = $this->count === 1 ? 'jornada' : 'jornadas';
        $url = AttendanceDayResource::getUrl('index', [
            'tableFilters' => ['missing_check_out' => ['isActive' => true]],
        ]);

        return [
            ...FilamentNotification::make()
                ->title("{$this->count} {$plural} sin salida registrada")
                ->body("Hay empleados que marcaron entrada y nunca salida. La más antigua es del {$this->oldestDate->format('d/m/Y')}. Corregirlas desde Asistencias para que se calculen las horas.")
                ->icon('heroicon-o-clock')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ver jornadas')->url($url),
                ])
                ->getDatabaseMessage(),
            'open_shifts_count' => $this->count,
        ];
    }
}
