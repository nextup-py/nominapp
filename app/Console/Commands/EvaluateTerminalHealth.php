<?php

namespace App\Console\Commands;

use App\Services\TerminalHealthService;
use Illuminate\Console\Command;

/** Evalúa la salud de los terminales (desconexión, cola atascada, batería, desvinculación) y avisa solo en las transiciones. */
class EvaluateTerminalHealth extends Command
{
    protected $signature = 'terminals:evaluate-health';

    protected $description = 'Evalúa la salud de los terminales y avisa por la campanita cuando algo cambia';

    public function handle(TerminalHealthService $health): int
    {
        $alerts = $health->evaluateAll();

        if ($alerts > 0) {
            $this->info("{$alerts} aviso(s) de salud de terminales emitido(s).");
        }

        return self::SUCCESS;
    }
}
