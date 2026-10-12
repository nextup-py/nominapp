<?php

namespace App\Notifications;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use App\Models\TerminalCommand;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Aviso por la campanita a quien envió un comando remoto diferible (por ejemplo, recargar la app) que venció
 * porque el terminal no volvió a conectarse en el plazo largo: hace falta revisar el dispositivo en el lugar.
 */
class TerminalCommandExpiredNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Terminal $terminal,
        public readonly TerminalCommand $command,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Se arma con el builder de Filament para que la campanita lo muestre.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $label = TerminalCommand::getCommandLabels()[$this->command->command] ?? $this->command->command;
        $hours = TerminalCommand::DEFERRED_TTL_HOURS;

        return [
            ...FilamentNotification::make()
                ->title("\"{$label}\" venció sin aplicarse")
                ->body("El terminal \"{$this->terminal->name}\" no volvió a conectarse en {$hours} horas. Revisá que esté encendido, con internet y con la página abierta.")
                ->icon('heroicon-o-clock')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ver terminal')->url(TerminalResource::getUrl('view', ['record' => $this->terminal])),
                ])
                ->getDatabaseMessage(),
            'terminal_id' => $this->terminal->id,
            'command_id' => $this->command->id,
        ];
    }
}
