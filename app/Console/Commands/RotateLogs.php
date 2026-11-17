<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Mantiene chico el directorio de logs: borra los `laravel-*.log` diarios más viejos que `--days` y recorta los demás
 * `*.log` (como `cron.log`) que pasan de `--max-mb`, conservando el final. Con `--dry-run` solo informa.
 */
class RotateLogs extends Command
{
    protected $signature = 'logs:rotate {--days=30 : Días a conservar de los logs diarios} {--max-mb=20 : Tamaño máximo de los demás logs} {--dry-run : Muestra qué haría sin borrar nada}';

    protected $description = 'Borra logs diarios viejos y recorta los logs que crecen sin límite';

    /**
     * Aplica la retención en `storage/logs`.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = now()->subDays((int) $this->option('days'))->timestamp;
        $maxBytes = (int) $this->option('max-mb') * 1024 * 1024;
        $deleted = 0;
        $trimmed = 0;

        foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
            $name = basename($file);

            if (preg_match('/^laravel-\d{4}-\d{2}-\d{2}\.log$/', $name) && filemtime($file) < $limit) {
                $deleted++;
                $this->line(($dryRun ? 'Se borraría ' : 'Borrado ').$name);
                $dryRun || unlink($file);

                continue;
            }

            if (! preg_match('/^laravel-\d{4}-\d{2}-\d{2}\.log$/', $name) && filesize($file) > $maxBytes) {
                $trimmed++;
                $this->line(($dryRun ? 'Se recortaría ' : 'Recortado ').$name.' ('.round(filesize($file) / 1024 / 1024, 1).' MB)');
                $dryRun || $this->trim($file, $maxBytes);
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."{$deleted} borrado(s), {$trimmed} recortado(s).");

        return self::SUCCESS;
    }

    /** Deja solo el último cuarto del tamaño máximo, desde el inicio de una línea. */
    private function trim(string $file, int $maxBytes): void
    {
        $keep = (int) ($maxBytes / 4);
        $handle = fopen($file, 'rb');
        fseek($handle, -$keep, SEEK_END);
        fgets($handle);
        $tail = stream_get_contents($handle);
        fclose($handle);

        file_put_contents($file, $tail, LOCK_EX);
    }
}
