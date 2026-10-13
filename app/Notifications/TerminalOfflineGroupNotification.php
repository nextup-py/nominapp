<?php

namespace App\Notifications;

use App\Filament\Resources\TerminalResource;
use App\Models\Terminal;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Resumen en una sola campanita de varios terminales que pasaron a Desconectado en la misma evaluación
 * (ver `TerminalHealthService::evaluateAll()`), con la causa probable más frecuente entre ellos.
 */
class TerminalOfflineGroupNotification extends Notification
{
    use Queueable;

    /**
     * @param  Collection<int, Terminal>  $terminals  Terminales con la sucursal cargada.
     */
    public function __construct(public readonly Collection $terminals) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Se arma con el builder de Filament para que la campanita lo muestre (ver `TerminalProvisionedNotification`).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $names = $this->terminals
            ->map(fn (Terminal $terminal) => $terminal->name.($terminal->branch ? " ({$terminal->branch->name})" : ''))
            ->implode(', ');

        $commonHint = $this->terminals
            ->flatMap(fn (Terminal $terminal) => $terminal->offlineHints())
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        $notification = FilamentNotification::make()
            ->title("{$this->terminals->count()} terminales sin conexión")
            ->body("Dejaron de reportar: {$names}.".($commonHint ? " Causa probable más frecuente: {$commonHint}" : ''))
            ->icon('heroicon-o-signal-slash')
            ->danger()
            ->actions([
                FilamentAction::make('view')->label('Ver terminales')->url(TerminalResource::getUrl('index')),
            ]);

        return [
            ...$notification->getDatabaseMessage(),
            'terminal_ids' => $this->terminals->pluck('id')->all(),
            'alert' => 'offline_group',
        ];
    }
}
