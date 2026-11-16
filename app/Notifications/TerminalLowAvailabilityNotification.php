<?php

namespace App\Notifications;

use App\Filament\Resources\TerminalResource;
use App\Filament\Resources\TerminalResource\RelationManagers\ReliabilityRelationManager;
use App\Models\Terminal;
use App\Notifications\Concerns\HonorsPreferences;
use Carbon\CarbonInterface;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Resumen semanal, en una sola campanita, de los terminales que estuvieron en línea menos del mínimo configurado
 * (`GeneralSettings::$terminal_min_availability_percent`) durante el período, con su porcentaje y sus cortes.
 */
class TerminalLowAvailabilityNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /**
     * @param  Collection<int, array{terminal: Terminal, availability: float, outages: int, longest_outage_minutes: int}>  $rows
     */
    public function __construct(
        public readonly Collection $rows,
        public readonly CarbonInterface $from,
        public readonly CarbonInterface $to,
        public readonly int $threshold,
    ) {}

    /**
     * Se arma con el builder de Filament para que la campanita lo muestre (ver `TerminalProvisionedNotification`).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $list = $this->rows
            ->map(fn (array $row) => sprintf(
                '%s%s: %s%% (%d %s%s)',
                $row['terminal']->name,
                $row['terminal']->branch ? " ({$row['terminal']->branch->name})" : '',
                $row['availability'],
                $row['outages'],
                $row['outages'] === 1 ? 'corte' : 'cortes',
                $row['longest_outage_minutes'] > 0 ? ', el más largo '.ReliabilityRelationManager::duration($row['longest_outage_minutes']) : '',
            ))
            ->implode('; ');

        $notification = FilamentNotification::make()
            ->title($this->rows->count() === 1 ? 'Un terminal con poca disponibilidad' : "{$this->rows->count()} terminales con poca disponibilidad")
            ->body("Del {$this->from->format('d/m/Y')} al {$this->to->format('d/m/Y')} estuvieron en línea menos del {$this->threshold}% de su horario: {$list}.")
            ->icon('heroicon-o-chart-bar')
            ->warning()
            ->actions([
                FilamentAction::make('view')->label('Ver terminales')->url(TerminalResource::getUrl('index')),
            ]);

        return [
            ...$notification->getDatabaseMessage(),
            'terminal_ids' => $this->rows->pluck('terminal.id')->all(),
            'alert' => 'low_availability',
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'terminal.low_availability';
    }
}
