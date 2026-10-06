<?php

namespace App\Notifications;

use App\Filament\Pages\TerminalPairingInbox;
use App\Models\TerminalPairingRequest;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso por la campanita cuando un dispositivo pide vincularse a un terminal.
 *
 * Deliberadamente NO incluye el código de emparejamiento: el admin debe
 * tipearlo desde la pantalla del terminal al aprobar, y mostrarlo acá le
 * quitaría sentido a esa verificación.
 */
class TerminalPairingRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly TerminalPairingRequest $request,
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
        $terminal = $this->request->terminal;
        $body = "Un dispositivo pidió vincularse al terminal \"{$terminal->name}\" ({$terminal->branch?->name}). Verificá el código en su pantalla para aprobarlo.";

        if ($this->request->replaces_active_device) {
            $body .= ' Atención: reemplazaría al dispositivo actualmente vinculado.';
        }

        return [
            ...FilamentNotification::make()
                ->title('Solicitud de vinculación de terminal')
                ->body($body)
                ->icon('heroicon-o-link')
                ->warning()
                ->actions([
                    FilamentAction::make('review')->label('Revisar y aprobar')->url(TerminalPairingInbox::getUrl()),
                ])
                ->getDatabaseMessage(),
            'terminal_id' => $terminal->id,
            'pairing_request_id' => $this->request->id,
        ];
    }
}
