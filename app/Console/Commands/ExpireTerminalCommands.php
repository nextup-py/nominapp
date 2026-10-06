<?php

namespace App\Console\Commands;

use App\Models\TerminalCommand;
use Illuminate\Console\Command;

/** Vence los comandos remotos que no se entregaron (o confirmaron) dentro de su vigencia. */
class ExpireTerminalCommands extends Command
{
    protected $signature = 'terminals:expire-commands';

    protected $description = 'Vence los comandos remotos de terminales sin entregar o sin confirmar';

    public function handle(): int
    {
        $count = TerminalCommand::expireStale()->count();

        $this->info("Comandos de terminales vencidos: {$count}.");

        return self::SUCCESS;
    }
}
