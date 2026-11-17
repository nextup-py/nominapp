<?php

namespace App\Console\Commands;

use App\Notifications\NewErrorNotification;
use App\Services\LogErrorAnalyzer;
use App\Services\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Avisa a los Super Admin de errores que no se habían visto antes en los logs (por firma, ver `LogErrorAnalyzer`). La
 * primera corrida solo toma nota de lo que ya existe para no avisar de todo el historial. Un mismo error nuevo avisa
 * una vez; si vuelve a aparecer mucho después no se repite (queda en «Salud del sistema»).
 */
class NotifyNewErrors extends Command
{
    /** Clave de caché con las firmas ya conocidas. */
    public const KNOWN_KEY = 'system:known_error_signatures';

    protected $signature = 'logs:notify-new-errors {--dry-run : Muestra los errores nuevos sin avisar ni recordarlos}';

    protected $description = 'Avisa a los Super Admin de errores nuevos en los logs';

    /**
     * Compara los errores de las últimas 24 horas con los conocidos y avisa de los nuevos.
     */
    public function handle(LogErrorAnalyzer $analyzer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $known = Cache::get(self::KNOWN_KEY);
        $firstRun = $known === null;
        $known ??= [];
        $groups = $analyzer->groups(days: 1);
        $new = $groups->reject(fn (array $group): bool => in_array($group['signature'], $known, true));

        if (! $firstRun && $new->isNotEmpty()) {
            $this->line('Errores nuevos: '.$new->count());
            $new->each(fn (array $group) => $this->line("  [{$group['level']}] {$group['message']}"));

            if (! $dryRun) {
                SystemHealthService::recipients()->each(fn ($user) => $user->notify(new NewErrorNotification($new->take(5)->values()->all(), $new->count())));
            }
        }

        if (! $dryRun) {
            Cache::forever(self::KNOWN_KEY, array_slice(array_values(array_unique([...$known, ...$groups->pluck('signature')->all()])), -500));
        }

        $this->info($firstRun ? 'Primera corrida: se registraron '.$groups->count().' error(es) existentes sin avisar.' : $new->count().' error(es) nuevo(s).');

        return self::SUCCESS;
    }
}
