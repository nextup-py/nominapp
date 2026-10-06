<?php

namespace App\Console\Commands;

use App\Services\TerminalPairingService;
use Illuminate\Console\Command;

/** Marca como vencidas las solicitudes de vinculación de terminales pendientes cuyo plazo ya pasó. */
class ExpireTerminalPairingRequests extends Command
{
    protected $signature = 'terminals:expire-pairing';

    protected $description = 'Vence las solicitudes de vinculación de terminales sin resolver';

    public function handle(TerminalPairingService $pairing): int
    {
        $expired = $pairing->expireStale();

        if ($expired > 0) {
            $this->info("{$expired} solicitud(es) de vinculación vencida(s).");
        }

        return self::SUCCESS;
    }
}
