<?php

namespace App\Console\Commands;

use Database\Seeders\DemoLiveAttendanceSeeder;
use Database\Seeders\DemoTerminalSeeder;
use Illuminate\Console\Command;

/**
 * Pone al día la demo respecto de la hora actual: rehace las marcaciones de hoy y los latidos de los terminales
 * sembrados por `DemoSeeder`. Solo corre en una instancia marcada como demo (`APP_DEMO=true`) para que nunca toque
 * datos reales; con `--force` se puede correr a mano en una base de demo sin esa variable.
 */
class RefreshDemo extends Command
{
    protected $signature = 'demo:refresh {--force : Corre aunque APP_DEMO no esté activo (solo para una base de demo)}';

    protected $description = 'Refresca la demo: marcaciones de hoy y latidos de los terminales respecto de la hora actual';

    public function handle(DemoLiveAttendanceSeeder $attendance, DemoTerminalSeeder $terminals): int
    {
        if (! config('app.demo') && ! $this->option('force')) {
            $this->error('Esta instancia no está marcada como demo (APP_DEMO=true): no se hizo nada.');

            return self::FAILURE;
        }

        $terminals->refresh();
        $result = $attendance->generate();

        $this->info("Demo al día: {$result['marked']} con marcaciones, {$result['missing']} sin marcar, {$result['open_yesterday']} jornadas del último día laboral sin salida.");

        return self::SUCCESS;
    }
}
