<?php

namespace App\Console\Commands;

use App\Services\OvertimeRequestService;
use Illuminate\Console\Command;

/** Marca como vencidos los pedidos de horas extra pendientes cuya última fecha ya pasó. */
class ExpireOvertimeRequests extends Command
{
    protected $signature = 'overtime:expire-requests';

    protected $description = 'Vence los pedidos de horas extra pendientes de fechas pasadas';

    public function handle(OvertimeRequestService $service): int
    {
        $count = $service->expireStale();
        $this->info("Pedidos vencidos: {$count}.");

        return self::SUCCESS;
    }
}
