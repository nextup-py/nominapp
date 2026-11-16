<?php

namespace App\Console\Commands;

use App\Models\TerminalDailyStat;
use App\Services\TerminalReliabilityService;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Elimina el resumen diario de confiabilidad de los terminales más antiguo que la retención configurada
 * (`GeneralSettings::$terminal_stats_retention_days`).
 */
class PurgeTerminalStats extends Command
{
    protected $signature = 'terminals:purge-stats
        {--days= : Días de retención (por defecto, el valor de Configuración General)}
        {--dry-run : Solo cuenta los resúmenes que se eliminarían}';

    protected $description = 'Elimina el resumen diario de confiabilidad de terminales más antiguo que la retención configurada';

    /** Ejecuta la purga. Con retención 0 no elimina nada. */
    public function handle(GeneralSettings $settings, TerminalReliabilityService $service): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) $settings->terminal_stats_retention_days;

        if ($days < 0) {
            $this->error('La retención no puede ser negativa.');

            return self::FAILURE;
        }

        if ($days === 0) {
            $this->info('Retención ilimitada (0 días): no se elimina nada.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $count = TerminalDailyStat::where('date', '<', now()->subDays($days)->toDateString())->count();
            $this->info("Se eliminarían {$count} resúmenes diarios anteriores al {$this->cutoff($days)}.");

            return self::SUCCESS;
        }

        $deleted = $service->purge($days);

        if ($deleted > 0) {
            Log::info("terminals:purge-stats: {$deleted} resúmenes anteriores al {$this->cutoff($days)} eliminados (retención {$days} días).");
        }

        $this->info("Historial de confiabilidad purgado: {$deleted} resúmenes diarios eliminados (retención {$days} días).");

        return self::SUCCESS;
    }

    /** Fecha de corte legible. */
    private function cutoff(int $days): string
    {
        return now()->subDays($days)->format('d/m/Y');
    }
}
