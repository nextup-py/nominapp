<?php

namespace App\Console\Commands;

use App\Services\OvertimeRequestService;
use Illuminate\Console\Command;

/** Pasa a RR.HH. los pedidos de horas extra de empleados que el jefe no resolvió en el plazo configurado. */
class EscalateOvertimeRequests extends Command
{
    protected $signature = 'overtime:escalate-requests';

    protected $description = 'Pasa a RR.HH. los pedidos de horas extra que el jefe no resolvió a tiempo';

    public function handle(OvertimeRequestService $service): int
    {
        $this->info('Pedidos escalados a RR.HH.: '.$service->escalateStale().'.');

        return self::SUCCESS;
    }
}
