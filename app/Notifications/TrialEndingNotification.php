<?php

namespace App\Notifications;

use App\Filament\Resources\ContractResource;
use App\Notifications\Concerns\HonorsPreferences;
use Filament\Notifications\Actions\Action as FilamentAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Resumen diario de contratos cuyo período de prueba termina en los próximos días (Art. 58 CLT): hay que decidir si
 * se confirma al empleado, se renueva o se da de baja. Un solo aviso por usuario; no se repite mientras siga sin leer.
 */
class TrialEndingNotification extends Notification
{
    use HonorsPreferences;
    use Queueable;

    /**
     * @param  array<int, array{contract_id: int, name: string, ends: string}>  $items
     */
    public function __construct(public readonly array $items, public readonly int $days) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $count = count($this->items);
        $lines = collect($this->items)->take(6)->map(fn (array $i): string => "{$i['name']} ({$i['ends']})")->implode(', ');
        $more = $count > 6 ? ' y '.($count - 6).' más' : '';

        return [
            ...FilamentNotification::make()
                ->title($count === 1 ? '1 período de prueba por terminar' : "{$count} períodos de prueba por terminar")
                ->body("Terminan en los próximos {$this->days} días: {$lines}{$more}. Decidir si se confirma, se renueva o se da de baja.")
                ->icon('heroicon-o-clock')
                ->warning()
                ->actions([
                    FilamentAction::make('view')->label('Ver contratos')->url(ContractResource::getUrl('index')),
                ])
                ->getDatabaseMessage(),
            'trial_contract_ids' => array_column($this->items, 'contract_id'),
        ];
    }

    /** Clave de este aviso en `NotificationCatalog`. */
    public function preferenceKey(): string
    {
        return 'contract.trial_ending';
    }
}
