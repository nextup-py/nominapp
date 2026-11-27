<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\OvertimeAccumulationNotification;
use App\Services\OvertimeAccumulationService;
use Illuminate\Console\Command;

/**
 * Avisa a RR.HH. y a los jefes de quienes superaron el tope semanal o mensual de horas extra. No repite mientras el
 * destinatario tenga un aviso anterior sin leer.
 */
class NotifyOvertimeAccumulation extends Command
{
    protected $signature = 'overtime:notify-accumulation';

    protected $description = 'Avisa de las personas que superaron el tope semanal o mensual de horas extra';

    public function handle(OvertimeAccumulationService $service): int
    {
        $rows = $service->exceeding();

        if ($rows === []) {
            $this->info('Nadie superó los topes de horas extra.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach (User::all() as $user) {
            $mine = $service->rowsFor($user, $rows);

            if ($mine === [] || $user->unreadNotifications()->where('type', OvertimeAccumulationNotification::class)->exists()) {
                continue;
            }

            $user->notify(new OvertimeAccumulationNotification($mine));
            $notified++;
        }

        $this->info(count($rows)." persona(s) sobre el tope. Aviso enviado a {$notified} usuario(s).");

        return self::SUCCESS;
    }
}
