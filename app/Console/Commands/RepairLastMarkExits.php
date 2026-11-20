<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Services\AttendanceEventCorrectionService;
use App\Services\OpenShiftCloser;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Repara jornadas cerradas con `attendance:close-open-shifts --at-last-mark` cuando la salida agregada quedó al
 * minuto (17:38:00) y antes de la última marcación real (17:38:13): la jornada seguía figurando sin salida y no
 * se podía volver a cerrar. Mueve esa salida al minuto siguiente a la última marcación.
 */
class RepairLastMarkExits extends Command
{
    protected $signature = 'attendance:repair-last-mark-exits {--dry-run : Muestra qué se repararía sin guardar nada}';

    protected $description = 'Repara salidas agregadas antes de la última marcación real de la jornada (con --dry-run no guarda)';

    /**
     * Recorre las jornadas sin salida cuya salida quedó a menos de un minuto antes de la última marcación.
     */
    public function handle(OpenShiftCloser $closer, AttendanceEventCorrectionService $corrections): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;
        $skipped = 0;
        $failed = 0;

        AttendanceDay::query()->missingCheckOut()->eachById(function (AttendanceDay $day) use ($closer, $corrections, $dryRun, &$fixed, &$skipped, &$failed) {
            $events = $day->events()->orderBy('recorded_at')->orderBy('id')->get();
            $last = $events->last();
            $misplaced = $events->first(fn ($e) => $e->event_type === 'check_out'
                && $e->recorded_at->lte($last->recorded_at)
                && $e->recorded_at->diffInSeconds($last->recorded_at, true) < 60);

            if (! $misplaced || $misplaced->is($last)) {
                return;
            }

            if ($closer->isPayrollLocked($day) || $day->hasProtectedDecisions()) {
                $skipped++;

                return;
            }

            $fixed++;

            if ($dryRun) {
                return;
            }

            $rows = $events->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'recorded_at' => $e->is($misplaced) ? $closer->exitAfter($last->recorded_at) : $e->recorded_at,
            ])->all();

            try {
                $corrections->apply($day, $rows);
            } catch (InvalidArgumentException) {
                $fixed--;
                $failed++;
            }
        });

        $this->line("Salidas reparadas: {$fixed} · omitidas (nómina o decisiones de RR.HH.): {$skipped} · con secuencia inválida: {$failed}.");
        $this->info($dryRun ? 'Simulación: no se guardó nada.' : 'Cambios guardados.');

        return self::SUCCESS;
    }
}
