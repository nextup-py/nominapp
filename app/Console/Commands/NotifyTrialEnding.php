<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\User;
use App\Notifications\TrialEndingNotification;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Avisa a quienes ven contratos de los períodos de prueba que terminan en `contract_trial_alert_days` días (0 = apagado).
 * Un resumen por usuario; no se repite mientras exista un aviso sin leer de **cualquier** usuario para los mismos contratos.
 */
class NotifyTrialEnding extends Command
{
    protected $signature = 'contracts:notify-trial-ending';

    protected $description = 'Avisa de los contratos cuyo período de prueba termina pronto (resumen diario)';

    public function handle(GeneralSettings $settings): int
    {
        $days = $settings->contract_trial_alert_days;

        if ($days <= 0) {
            $this->info('El aviso de fin de período de prueba está apagado.');

            return self::SUCCESS;
        }

        $alreadyNotified = DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('type', TrialEndingNotification::class)
            ->get()
            ->flatMap(fn (DatabaseNotification $n): array => (array) ($n->data['trial_contract_ids'] ?? []))
            ->map(fn ($id): int => (int) $id)
            ->all();

        $items = Contract::trialEndingWithin($days)
            ->reject(fn (Contract $c): bool => in_array($c->id, $alreadyNotified, true))
            ->map(fn (Contract $c): array => [
                'contract_id' => $c->id,
                'name' => $c->employee?->full_name ?? 'Empleado',
                'ends' => $c->trial_end_date->format('d/m'),
            ])
            ->values()
            ->all();

        if ($items === []) {
            $this->info('Sin períodos de prueba nuevos para avisar.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach (User::all() as $user) {
            if (! $user->can('view_any_contract')) {
                continue;
            }

            $user->notify(new TrialEndingNotification($items, $days));
            $notified++;
        }

        $this->info(count($items)." período(s) de prueba por terminar; aviso a {$notified} usuario(s).");

        return self::SUCCESS;
    }
}
