<?php

namespace App\Console\Commands;

use App\Settings\GeneralSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Models\Audit;

/**
 * Elimina los registros de auditoría con más antigüedad que la retención configurada
 * (`GeneralSettings::$audit_retention_months`). Borra por lotes para no bloquear la tabla.
 */
class PurgeAudits extends Command
{
    /** Cantidad de registros eliminados por lote. */
    private const BATCH_SIZE = 5000;

    protected $signature = 'audits:purge
        {--months= : Meses de retención (por defecto, el valor de Configuración General)}
        {--dry-run : Solo cuenta los registros que se eliminarían}';

    protected $description = 'Elimina el historial de auditoría más antiguo que la retención configurada';

    /**
     * Ejecuta la purga. Con retención 0 no elimina nada.
     */
    public function handle(GeneralSettings $settings): int
    {
        $months = $this->option('months') !== null
            ? (int) $this->option('months')
            : (int) $settings->audit_retention_months;

        if ($months < 0) {
            $this->error('La retención no puede ser negativa.');

            return self::FAILURE;
        }

        if ($months === 0) {
            $this->info('Retención ilimitada (0 meses): no se elimina nada.');

            return self::SUCCESS;
        }

        $cutoff = now()->subMonthsNoOverflow($months);

        if ($this->option('dry-run')) {
            $count = Audit::where('created_at', '<', $cutoff)->count();
            $this->info("Se eliminarían {$count} registros anteriores al {$cutoff->format('d/m/Y')}.");

            return self::SUCCESS;
        }

        $deleted = 0;

        do {
            $ids = Audit::where('created_at', '<', $cutoff)->limit(self::BATCH_SIZE)->pluck('id');
            $batch = $ids->isEmpty() ? 0 : Audit::whereIn('id', $ids)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        if ($deleted > 0) {
            Log::info("audits:purge: {$deleted} registros anteriores al {$cutoff->toDateString()} eliminados (retención {$months} meses).");
        }

        $this->info("Auditoría purgada: {$deleted} registros eliminados (retención {$months} meses).");

        return self::SUCCESS;
    }
}
