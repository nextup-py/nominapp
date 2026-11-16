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
 * Aviso por la campanita cuando un terminal que tenía su acceso vigente en el servidor vuelve a pedir vincularse: el
 * navegador del dispositivo perdió su copia local del token (datos borrados, modo privado, perfil restaurado, otro
 * perfil...). El servidor no revocó nada, así que no es una desvinculación del panel.
 */
class TerminalLinkLostNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /**
     * @param  array<string, mixed>  $context  Motivo y estado del almacenamiento que informó el dispositivo.
     */
    public function __construct(
        public readonly Terminal $terminal,
        public readonly array $context = [],
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
        $body = "El terminal {$name} perdió su vínculo en el dispositivo y pidió vincularse de nuevo. El servidor no lo desvinculó.";

        if (($this->context['storage_available'] ?? null) === false) {
            $body .= ' El navegador no conserva datos: revisar que no sea una ventana privada ni borre los datos al cerrar.';
        }

        return [
            ...FilamentNotification::make()
                ->title('Terminal perdió el vínculo')
                ->body($body)
                ->icon('heroicon-o-link-slash')
                ->danger()
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
        return 'terminal.link_lost';
    }
}
