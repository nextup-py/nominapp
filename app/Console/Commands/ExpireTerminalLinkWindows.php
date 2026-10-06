<?php

namespace App\Console\Commands;

use App\Models\Terminal;
use Illuminate\Console\Command;

/** Cierra las ventanas de vinculación de terminales que vencieron sin usarse y avisa a quien las abrió. */
class ExpireTerminalLinkWindows extends Command
{
    protected $signature = 'terminals:expire-link-windows';

    protected $description = 'Cierra las ventanas de vinculación de terminales vencidas y avisa a quien las abrió';

    public function handle(): int
    {
        $expired = Terminal::expireLinkWindows();

        if ($expired > 0) {
            $this->info("{$expired} ventana(s) de vinculación vencida(s).");
        }

        return self::SUCCESS;
    }
}
