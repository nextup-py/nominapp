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
 * `TerminalHealthService`): `offline`, `recovered`, `unlinked`, `queue_stuck` o `battery_low`.
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
                ->body("El terminal {$name} no reporta hace más de {$this->terminal->effectiveStaleMinutes()} minutos.")
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
                ->body("El terminal {$name} tiene {$this->terminal->last_pending_events_count} marcación(es) pendiente(s) y {$this->terminal->last_conflict_events_count} en conflicto que no se vacían.")
                ->icon('heroicon-o-queue-list')
                ->warning(),
            'battery_low' => $notification
                ->title('Batería baja en terminal')
                ->body("El terminal {$name} está al {$this->terminal->reportedBatteryLevel()}% y sin cargador.")
                ->icon('heroicon-o-battery-0')
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
