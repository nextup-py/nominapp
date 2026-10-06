<?php

namespace App\Console\Commands;

use App\Models\TerminalEvent;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Elimina los eventos de la bitácora de terminales más antiguos que la retención configurada
 * (`GeneralSettings::$terminal_events_retention_days`). Borra por lotes para no bloquear la tabla.
 */
class PurgeTerminalEvents extends Command
{
    /** Cantidad de registros eliminados por lote. */
    private const BATCH_SIZE = 5000;

    protected $signature = 'terminals:purge-events
        {--days= : Días de retención (por defecto, el valor de Configuración General)}
        {--dry-run : Solo cuenta los eventos que se eliminarían}';

    protected $description = 'Elimina los eventos de la bitácora de terminales más antiguos que la retención configurada';

    /**
     * Ejecuta la purga. Con retención 0 no elimina nada.
     */
    public function handle(GeneralSettings $settings): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) $settings->terminal_events_retention_days;

        if ($days < 0) {
            $this->error('La retención no puede ser negativa.');

            return self::FAILURE;
        }

        if ($days === 0) {
            $this->info('Retención ilimitada (0 días): no se elimina nada.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        if ($this->option('dry-run')) {
            $count = TerminalEvent::where('created_at', '<', $cutoff)->count();
            $this->info("Se eliminarían {$count} eventos anteriores al {$cutoff->format('d/m/Y')}.");

            return self::SUCCESS;
        }

        $deleted = 0;

        do {
            $ids = TerminalEvent::where('created_at', '<', $cutoff)->limit(self::BATCH_SIZE)->pluck('id');
            $batch = $ids->isEmpty() ? 0 : TerminalEvent::whereIn('id', $ids)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        if ($deleted > 0) {
            Log::info("terminals:purge-events: {$deleted} eventos anteriores al {$cutoff->toDateString()} eliminados (retención {$days} días).");
        }

        $this->info("Bitácora de terminales purgada: {$deleted} eventos eliminados (retención {$days} días).");

        return self::SUCCESS;
    }
}
