<?php

namespace App\Console\Commands;

use App\Models\AttendanceDay;
use App\Services\OpenShiftCloser;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Cierra en bloque las jornadas históricas con entrada y sin salida usando la misma regla que la acción masiva
 * «Cerrar jornadas sin salida» del panel (`OpenShiftCloser`): agrega la salida a la hora del horario y recalcula.
 * Omite las de nómina generada, con decisiones de RR.HH., en descanso o sin horario, y cuenta cada motivo.
 * Sirve para limpiar un atraso grande sin seleccionar jornada por jornada en el listado.
 *
 * Con `--break-as-exit` o `--at-last-mark` no se usa la hora del horario sino una marcación real: el inicio de
 * descanso marcado a 1 h o menos de la salida (quien se iba tocaba el botón equivocado) o el fin de descanso
 * marcado hasta 1 h después de la salida del horario.
 */
class CloseOpenShifts extends Command
{
    protected $signature = 'attendance:close-open-shifts
        {--before= : Cierra solo jornadas anteriores a esta fecha (Y-m-d). Obligatorio}
        {--from= : Fecha inicial (Y-m-d). Por defecto, sin límite}
        {--break-as-exit : En vez del horario, toma como salida el inicio de descanso marcado a 1 h o menos de la salida del horario}
        {--at-last-mark : En vez del horario, cierra en el fin de descanso marcado hasta 1 h después de la salida del horario}
        {--dry-run : Muestra qué se cerraría sin guardar nada}
        {--force : No pide confirmación antes de guardar}';

    protected $description = 'Cierra jornadas históricas sin salida con la hora de salida del horario (con --dry-run no guarda)';

    /**
     * Ejecuta el cierre (o la vista previa con --dry-run) y muestra los conteos por motivo de omisión.
     */
    public function handle(OpenShiftCloser $closer): int
    {
        if (! $this->option('before')) {
            $this->error('Falta --before=YYYY-MM-DD (se cierran solo jornadas anteriores a esa fecha).');

            return self::INVALID;
        }

        $before = Carbon::parse($this->option('before'))->startOfDay();
        $dryRun = (bool) $this->option('dry-run');
        $strategy = $this->strategy();

        if ($strategy === false) {
            $this->error('Elegí una sola regla: --break-as-exit o --at-last-mark (o ninguna para usar la hora del horario).');

            return self::INVALID;
        }

        if (! $dryRun && ! $this->option('force') && ! $this->confirm("Se agregará la salida del horario a las jornadas abiertas anteriores al {$before->format('d/m/Y')}. ¿Continuar?")) {
            $this->warn('Cancelado, no se guardó nada.');

            return self::SUCCESS;
        }

        $closed = 0;
        $failed = 0;
        $skipped = [];

        AttendanceDay::query()
            ->missingCheckOut()
            ->where('date', '<', $before->toDateString())
            ->when($this->option('from'), fn ($query, $from) => $query->where('date', '>=', Carbon::parse($from)->toDateString()))
            ->orderBy('date')
            ->chunkById(200, function ($days) use ($closer, $dryRun, $strategy, &$closed, &$failed, &$skipped) {
                foreach ($days as $day) {
                    $reason = $dryRun
                        ? ($strategy ? $closer->evaluateRealMark($day, $strategy)['skip'] : $closer->evaluate($day)['skip'])
                        : $this->close($closer, $day, $strategy, $failed);

                    if ($reason === null) {
                        $closed++;
                    } elseif ($reason !== 'error') {
                        $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                    }
                }
            });

        $this->info(($dryRun ? '[dry-run] Se cerrarían ' : 'Cerradas ')."{$closed} jornada(s).");

        foreach ($skipped as $reason => $count) {
            $this->line('  Omitidas por «'.(OpenShiftCloser::getSkipLabels()[$reason] ?? $reason)."»: {$count}");
        }

        if ($failed > 0) {
            $this->warn("  {$failed} jornada(s) no se pudieron cerrar por una secuencia de marcaciones inválida.");
        }

        return self::SUCCESS;
    }

    /**
     * Cierra una jornada y devuelve el motivo de omisión, null si se cerró o `error` si la secuencia no era válida.
     */
    private function close(OpenShiftCloser $closer, AttendanceDay $day, ?string $strategy, int &$failed): ?string
    {
        try {
            return $strategy ? $closer->closeWithRealMark($day, $strategy) : $closer->close($day);
        } catch (\InvalidArgumentException) {
            $failed++;

            return 'error';
        }
    }

    /**
     * Estrategia elegida con las opciones: null = la hora del horario, o `false` si pidieron las dos a la vez.
     */
    private function strategy(): string|false|null
    {
        $breakAsExit = (bool) $this->option('break-as-exit');
        $atLastMark = (bool) $this->option('at-last-mark');

        return match (true) {
            $breakAsExit && $atLastMark => false,
            $breakAsExit => OpenShiftCloser::STRATEGY_BREAK_AS_EXIT,
            $atLastMark => OpenShiftCloser::STRATEGY_LAST_MARK,
            default => null,
        };
    }
}
