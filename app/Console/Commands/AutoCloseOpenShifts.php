<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Models\User;
use App\Notifications\OpenShiftsAutoClosedNotification;
use App\Services\OpenShiftCloser;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cierra solo las jornadas que quedaron sin salida en los últimos días (un olvido normal, ~10% de las entradas), con
 * la misma regla que el cierre en bloque (`OpenShiftCloser::closeBest()`): la hora del horario o, si hay una marcación
 * real cerca de la hora de salida, esa marcación. Respeta nóminas generadas y decisiones de RR.HH. y deja un resumen
 * en la campanita. Corre a la hora configurada en Configuración General y solo si está activado.
 */
class AutoCloseOpenShifts extends Command
{
    /** Días hacia atrás que se cierran solas; lo más viejo se limpia a mano con `attendance:close-open-shifts`. */
    public const LOOKBACK_DAYS = 7;

    protected $signature = 'attendance:auto-close-open-shifts
        {--dry-run : Muestra qué se cerraría sin guardar nada}
        {--force : Corre aunque el cierre automático esté apagado en Configuración General}';

    protected $description = 'Cierra solas las jornadas sin salida de los últimos días y avisa a RR.HH. (si está activado)';

    /**
     * Cierra las jornadas abiertas de los últimos días y avisa del resultado.
     */
    public function handle(OpenShiftCloser $closer): int
    {
        if (! $this->option('force') && ! app(GeneralSettings::class)->attendance_auto_close_enabled) {
            $this->info('El cierre automático está apagado en Configuración General.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $closed = 0;
        $failed = 0;
        $review = 0;

        AttendanceDay::query()
            ->missingCheckOut()
            ->where('date', '>=', Carbon::today()->subDays(self::LOOKBACK_DAYS)->toDateString())
            ->orderBy('date')
            ->chunkById(200, function ($days) use ($closer, $dryRun, &$closed, &$failed, &$review) {
                foreach ($days as $day) {
                    if ($dryRun) {
                        $closes = $closer->evaluate($day)['skip'] === null
                            || $closer->evaluateRealMark($day, OpenShiftCloser::STRATEGY_BREAK_AS_EXIT)['skip'] === null
                            || $closer->evaluateRealMark($day, OpenShiftCloser::STRATEGY_LAST_MARK)['skip'] === null;

                        $closes ? $closed++ : $review++;

                        continue;
                    }

                    try {
                        $result = $closer->closeBest($day);
                    } catch (\InvalidArgumentException) {
                        $failed++;
                        $review++;

                        continue;
                    }

                    $result['skip'] === null ? $closed++ : $review++;
                }
            });

        $this->info(($dryRun ? '[dry-run] Se cerrarían ' : 'Cerradas ')."{$closed} jornada(s); {$review} quedan para revisar".($failed > 0 ? " ({$failed} con una secuencia de marcaciones inválida)." : '.'));

        if (! $dryRun && ($closed > 0 || $review > 0)) {
            $this->notifyManagers($closed, $review);
        }

        return self::SUCCESS;
    }

    /**
     * Resumen en la campanita para quienes ven asistencias (best-effort: un fallo del aviso no deshace el cierre).
     */
    private function notifyManagers(int $closed, int $review): void
    {
        try {
            foreach (User::all() as $user) {
                if ($user->can('view_any_attendance_day')) {
                    $user->notify(new OpenShiftsAutoClosedNotification($closed, $review));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar del cierre automático de jornadas: '.$e->getMessage());
        }
    }
}
