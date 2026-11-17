<?php

namespace App\Notifications;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso de que dos o más equipos distintos pidieron vincularse al mismo terminal en pocas horas. Un terminal admite
 * un solo dispositivo: cada vez que uno se vincula, el otro queda sin acceso y vuelve a pedir un código.
 */
class TerminalMultipleDevicesNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    public function __construct(public readonly Terminal $terminal, public readonly int $devices) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $name = "\"{$this->terminal->name}\"".($this->terminal->branch ? " ({$this->terminal->branch->name})" : '');

        return [
            ...FilamentNotification::make()
                ->title('Dos equipos usan el mismo terminal')
                ->body("{$this->devices} equipos distintos pidieron vincularse al terminal {$name} en las últimas horas. Un terminal admite un solo dispositivo y se van a quitar el acceso uno al otro. Si necesitan los dos, creá otro terminal para el segundo equipo; si no, que dejen abierto solo uno.")
                ->icon('heroicon-o-device-phone-mobile')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ver terminal')->url(TerminalResource::getUrl('view', ['record' => $this->terminal])),
                ])
                ->getDatabaseMessage(),
            'terminal_id' => $this->terminal->id,
            'devices' => $this->devices,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'terminal.multiple_devices';
    }
}
