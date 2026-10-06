<?php

namespace App\Notifications;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso por la campanita a quien abrió una ventana de vinculación que venció
 * sin que ningún dispositivo la usara, para que no quede la duda de si el
 * terminal se vinculó o no.
 */
class TerminalLinkWindowExpiredNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Terminal $terminal,
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
        return [
            ...FilamentNotification::make()
                ->title('La ventana de vinculación venció sin usarse')
                ->body("Ningún dispositivo se vinculó al terminal \"{$this->terminal->name}\" durante la ventana. Si todavía hace falta, abrí una nueva o vinculalo con un código.")
                ->icon('heroicon-o-clock')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ver terminal')->url(TerminalResource::getUrl('view', ['record' => $this->terminal])),
                ])
                ->getDatabaseMessage(),
            'terminal_id' => $this->terminal->id,
        ];
    }
}
