<?php

namespace App\Notifications;

use App\Filament\Pages\TerminalPairingInbox;
use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use App\Models\TerminalPairingRequest;
use App\Notifications\Concerns\HonorsPreferences;
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
    use HonorsPreferences;
    use Queueable;

    /**
     * @param  int  $requestsCount  Cuántas veces pidió vincularse este terminal mientras el aviso seguía sin leer.
     */
    public function __construct(
        public readonly TerminalPairingRequest $request,
        public readonly int $requestsCount = 1,
    ) {}

    /**
     * Se arma con el builder de Filament para que la campanita lo muestre (ver
     * `TerminalProvisionedNotification::toDatabase()` por el detalle del gotcha).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $terminal = $this->request->terminal;
        $times = $this->requestsCount > 1 ? " ({$this->requestsCount} veces)" : '';
        $body = "Un dispositivo pidió vincularse al terminal \"{$terminal->name}\" ({$terminal->branch?->name}){$times}. Verificá el código en su pantalla para aprobarlo.";

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
            'requests_count' => $this->requestsCount,
        ];
    }

    /**
     * Contenido del aviso cuando las solicitudes vencieron sin que nadie las aprobara. Reemplaza al de «solicitud»
     * (sigue sin leer) para que no desaparezca justo cuando nadie lo atendió; se marca leído al vincularse el terminal.
     *
     * @return array<string, mixed>
     */
    public static function unattendedData(Terminal $terminal, int $requestsCount): array
    {
        $times = $requestsCount > 1 ? "{$requestsCount} solicitudes de vinculación vencieron" : 'Una solicitud de vinculación venció';

        return [
            ...FilamentNotification::make()
                ->title('Vinculación sin atender')
                ->body("{$times} sin que nadie las aprobara en el terminal \"{$terminal->name}\" ({$terminal->branch?->name}). Mientras no se vincule, ese equipo no puede marcar. Abrí la ventana de vinculación del terminal o pedí que recarguen la página para generar un código nuevo.")
                ->icon('heroicon-o-exclamation-triangle')
                ->danger()
                ->actions([
                    FilamentAction::make('review')->label('Ver terminal')->url(TerminalResource::getUrl('view', ['record' => $terminal])),
                ])
                ->getDatabaseMessage(),
            'terminal_id' => $terminal->id,
            'requests_count' => $requestsCount,
            'unattended' => true,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'terminal.pairing_requested';
    }
}
