<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\User;
use App\Notifications\ContractAlertNotification;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;

/**
 * Comando diario que envía notificaciones de campanita por contratos próximos a vencer o ya vencidos.
 * Solo notifica una vez por contrato: si ya existe una notificación no leída para ese contract_id, la omite.
 */
class NotifyExpiringContracts extends Command
{
    protected $signature = 'contracts:notify-expiring';

    protected $description = 'Notifica a todos los usuarios sobre contratos próximos a vencer o ya vencidos (una sola vez por contrato)';

    public function handle(GeneralSettings $settings): int
    {
        $alertDays = $settings->contract_alert_days;
        $users = User::all();

        if ($users->isEmpty()) {
            return self::SUCCESS;
        }

        // IDs de contratos que ya tienen notificación no leída en al menos un usuario
        $alreadyNotified = $this->getAlreadyNotifiedContractIds();

        $expiring = Contract::expiringSoon($alertDays)
            ->with('employee')
            ->whereNotIn('id', $alreadyNotified)
            ->get();

        $expired = Contract::query()
            ->where('status', 'expired')
            ->with('employee')
            ->whereNotIn('id', $alreadyNotified)
            ->get();

        $total = $expiring->count() + $expired->count();

        if ($total === 0) {
            $this->info('Sin contratos nuevos para notificar.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            foreach ($expiring as $contract) {
                $user->notify(new ContractAlertNotification($contract, 'expiring'));
            }
            foreach ($expired as $contract) {
                $user->notify(new ContractAlertNotification($contract, 'expired'));
            }
        }

        Log::info("contracts:notify-expiring: {$expiring->count()} por vencer, {$expired->count()} vencidos — notificados a {$users->count()} usuarios.");
        $this->info("Notificados: {$expiring->count()} por vencer, {$expired->count()} vencidos.");

        return self::SUCCESS;
    }

    /**
     * Retorna los contract_ids que ya tienen notificación no leída de tipo contrato en **cualquier**
     * usuario, para evitar duplicar alertas del mismo contrato. No alcanza con mirar a un solo
     * usuario: el aviso solo le llega a quien tiene el permiso y no lo apagó en «Mis notificaciones».
     *
     * @return array<int, int>
     */
    private function getAlreadyNotifiedContractIds(): array
    {
        return DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('type', ContractAlertNotification::class)
            ->get()
            ->map(fn (DatabaseNotification $notification) => (int) ($notification->data['contract_id'] ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }
}
