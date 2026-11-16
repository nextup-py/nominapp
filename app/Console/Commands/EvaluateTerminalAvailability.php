<?php

namespace App\Console\Commands;

use App\Models\Terminal;
use App\Models\User;
use App\Notifications\TerminalLowAvailabilityNotification;
use App\Services\TerminalReliabilityService;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Avisa de los terminales que estuvieron en línea menos del mínimo (`terminal_min_availability_percent`) durante los
 * últimos días cerrados (por defecto la semana anterior). Un solo resumen por corrida; con el mínimo en 0 no avisa.
 */
class EvaluateTerminalAvailability extends Command
{
    protected $signature = 'terminals:evaluate-availability
        {--days=7 : Cantidad de días cerrados a evaluar (termina ayer)}';

    protected $description = 'Avisa por la campanita de los terminales con poca disponibilidad en la última semana';

    /** Ejecuta la evaluación y envía el resumen a quienes gestionan terminales. */
    public function handle(GeneralSettings $settings, TerminalReliabilityService $service): int
    {
        $threshold = (int) $settings->terminal_min_availability_percent;
        $days = max(1, (int) $this->option('days'));

        if ($threshold <= 0) {
            $this->info('El aviso de disponibilidad está apagado (mínimo en 0).');

            return self::SUCCESS;
        }

        $to = now()->subDay()->startOfDay();
        $from = $to->copy()->subDays($days - 1);

        $rows = $service->belowAvailability($from, $to, $threshold);

        if ($rows->isEmpty()) {
            $this->info('Ningún terminal quedó por debajo del mínimo.');

            return self::SUCCESS;
        }

        try {
            $notification = new TerminalLowAvailabilityNotification($rows, $from, $to, $threshold);
            Terminal::managers()->each(fn (User $user) => $user->notify($notification));
        } catch (\Throwable $e) {
            Log::warning("No se pudo avisar la baja disponibilidad de terminales: {$e->getMessage()}");
        }

        $this->info("{$rows->count()} terminal(es) por debajo del {$threshold}% de disponibilidad.");

        return self::SUCCESS;
    }
}
