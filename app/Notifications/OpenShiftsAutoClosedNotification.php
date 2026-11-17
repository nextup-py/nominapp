<?php

namespace App\Notifications;

use App\Filament\Resources\AttendanceDayResource;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Resumen del cierre automático de jornadas sin salida: cuántas se cerraron solas (con la hora del horario o con una
 * marcación real cercana) y cuántas quedan para que RR.HH. las revise.
 */
class OpenShiftsAutoClosedNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    public function __construct(
        public readonly int $closed,
        public readonly int $review,
    ) {}

    /**
     * Se arma con el builder de `Filament\Notifications\Notification` para que la campanita del panel la muestre.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $closedText = $this->closed === 1 ? '1 jornada sin salida se cerró sola' : "{$this->closed} jornadas sin salida se cerraron solas";
        $reviewText = $this->review > 0
            ? ($this->review === 1 ? ' Queda 1 para revisar a mano.' : " Quedan {$this->review} para revisar a mano.")
            : ' No queda ninguna para revisar.';

        $url = AttendanceDayResource::getUrl('index', ['tableFilters' => ['missing_check_out' => ['isActive' => true]]]);

        return [
            ...FilamentNotification::make()
                ->title($closedText)
                ->body("Se agregó la salida del horario (o la marcación real cercana) a las jornadas abiertas de los últimos 7 días.{$reviewText}")
                ->icon('heroicon-o-clock')
                ->success()
                ->actions([
                    FilamentAction::make('view')->label('Ver jornadas sin salida')->url($url),
                ])
                ->getDatabaseMessage(),
            'closed_count' => $this->closed,
            'review_count' => $this->review,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'attendance.auto_closed';
    }
}
