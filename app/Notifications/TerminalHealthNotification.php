<?php

namespace App\Notifications;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso por la campanita sobre un cambio de salud de un terminal (ver
 * `TerminalHealthService`): `offline`, `recovered`, `unlinked`, `queue_stuck`, `battery_low`, `no_enrolled`, `cache_empty`, `clock_skew` o `app_outdated`.
 */
class TerminalHealthNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Terminal $terminal,
        public readonly string $alert,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Se arma con el builder de Filament para que la campanita lo muestre (ver
     * `TerminalProvisionedNotification::toDatabase()` por el detalle del gotcha).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $name = "\"{$this->terminal->name}\"".($this->terminal->branch ? " ({$this->terminal->branch->name})" : '');

        $notification = FilamentNotification::make()
            ->actions([
                FilamentAction::make('view')->label('Ver terminal')->url(TerminalResource::getUrl('view', ['record' => $this->terminal])),
            ]);

        match ($this->alert) {
            'offline' => $notification
                ->title('Terminal sin conexión')
                ->body("El terminal {$name} no reporta hace más de {$this->terminal->effectiveStaleMinutes()} minutos. Causa probable: ".implode(' ', $this->terminal->offlineHints()))
                ->icon('heroicon-o-signal-slash')
                ->danger(),
            'recovered' => $notification
                ->title('Terminal reconectado')
                ->body("El terminal {$name} volvió a reportar.")
                ->icon('heroicon-o-signal')
                ->success(),
            'unlinked' => $notification
                ->title('Terminal sin vincular')
                ->body("El terminal {$name} perdió su acceso (se desvinculó, venció o se desactivó) y no puede marcar hasta volver a vincularlo.")
                ->icon('heroicon-o-link-slash')
                ->danger(),
            'queue_stuck' => $notification
                ->title('Cola de marcaciones atascada')
                ->body("El terminal {$name} tiene {$this->terminal->last_pending_events_count} marcación(es) pendiente(s) que no se envían hace más del tiempo configurado.")
                ->icon('heroicon-o-queue-list')
                ->warning(),
            'battery_low' => $notification
                ->title('Batería baja en terminal')
                ->body("El terminal {$name} está al {$this->terminal->reportedBatteryLevel()}% y sin cargador.")
                ->icon('heroicon-o-battery-0')
                ->warning(),
            'no_enrolled' => $notification
                ->title('Terminal sin empleados enrolados')
                ->body("La sucursal del terminal {$name} no tiene ningún empleado activo con rostro registrado: no podrá identificar a nadie. Enrolar a los empleados o revisar su sucursal.")
                ->icon('heroicon-o-user-group')
                ->warning(),
            'cache_empty' => $notification
                ->title('Terminal sin empleados en caché')
                ->body("El terminal {$name} está en línea pero reporta 0 empleados en su caché, aunque su sucursal tiene empleados enrolados. Enviar el comando remoto Limpiar caché o Recargar app.")
                ->icon('heroicon-o-circle-stack')
                ->warning(),
            'clock_skew' => $notification
                ->title('Reloj del terminal desajustado')
                ->body("El reloj del terminal {$name} difiere del servidor en ".($this->terminal->reportedClockSkewSeconds() ?? 0).' segundos. Revisar la fecha y hora del dispositivo (activar la hora automática).')
                ->icon('heroicon-o-clock')
                ->warning(),
            'app_outdated' => $notification
                ->title('Terminal con la app desactualizada')
                ->body("El terminal {$name} sigue con una versión anterior de la app. Enviar el comando remoto Recargar app para actualizarlo.")
                ->icon('heroicon-o-arrow-path')
                ->warning(),
            default => $notification->title('Aviso de terminal')->body("Cambió el estado del terminal {$name}.")->icon('heroicon-o-computer-desktop'),
        };

        return [
            ...$notification->getDatabaseMessage(),
            'terminal_id' => $this->terminal->id,
            'alert' => $this->alert,
        ];
    }
}
