<?php

namespace App\Notifications;

use App\Filament\Pages\SystemHealth;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Aviso al Super Admin de que aparecieron errores nuevos en los logs. */
class NewErrorNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /**
     * @param  list<array{message: string, level: string, explanation: ?array{what: string, todo: string}}>  $groups  Primeros errores nuevos.
     * @param  int  $total  Cantidad total de errores nuevos.
     */
    public function __construct(public readonly array $groups, public readonly int $total) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $lines = collect($this->groups)->map(fn (array $group): string => '• '.($group['explanation']['what'] ?? mb_substr($group['message'], 0, 110)))->implode("\n");
        $more = $this->total > count($this->groups) ? "\n… y ".($this->total - count($this->groups)).' más.' : '';

        return [
            ...FilamentNotification::make()
                ->title($this->total === 1 ? 'Apareció un error nuevo' : "Aparecieron {$this->total} errores nuevos")
                ->body($lines.$more)
                ->icon('heroicon-o-exclamation-triangle')
                ->danger()
                ->actions([
                    FilamentAction::make('view')->label('Ver Salud del sistema')->url(SystemHealth::getUrl()),
                ])
                ->getDatabaseMessage(),
            'total' => $this->total,
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'system.new_error';
    }
}
