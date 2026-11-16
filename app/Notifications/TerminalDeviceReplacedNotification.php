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
 * Aviso por la campanita cuando un dispositivo nuevo se vincula a un terminal cuyo dispositivo anterior seguía en línea:
 * el anterior queda sin acceso y vuelve a pedir un código. Es el síntoma típico de dos equipos abiertos en la misma URL
 * del terminal, cada uno dejando afuera al otro cada vez que se aprueba un código.
 */
class TerminalDeviceReplacedNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /**
     * @param  string|null  $previousIp  IP desde la que se había vinculado el dispositivo reemplazado.
     * @param  string|null  $newIp  IP del dispositivo que acaba de vincularse.
     */
    public function __construct(
        public readonly Terminal $terminal,
        public readonly ?string $previousIp = null,
        public readonly ?string $newIp = null,
    ) {}

    /**
     * Se arma con el builder de Filament para que la campanita lo muestre (ver
     * `TerminalProvisionedNotification::toDatabase()` por el detalle del gotcha).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $name = "\"{$this->terminal->name}\"".($this->terminal->branch ? " ({$this->terminal->branch->name})" : '');
        $body = "Se vinculó un dispositivo nuevo al terminal {$name}".($this->newIp ? " (IP {$this->newIp})" : '').' y el anterior seguía en línea'.($this->previousIp ? " (IP {$this->previousIp})" : '').'. El anterior quedó sin acceso. Si aparece pidiendo vincularse otra vez, hay dos equipos abiertos en la misma URL del terminal: dejar uno solo.';

        return [
            ...FilamentNotification::make()
                ->title('Dispositivo reemplazado en un terminal')
                ->body($body)
                ->icon('heroicon-o-arrows-right-left')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ver terminal')->url(TerminalResource::getUrl('view', ['record' => $this->terminal])),
                ])
                ->getDatabaseMessage(),
            'terminal_id' => $this->terminal->id,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'terminal.device_replaced';
    }
}
