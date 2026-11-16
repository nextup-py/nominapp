<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\AttendanceMarkFailure;
use App\Models\Terminal;
use App\Models\TerminalDailyStat;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Confiabilidad de los terminales en el tiempo. Cada minuto, `TerminalHealthService` le avisa el estado de
 * conectividad de cada terminal (`sample()`) y el latido del dispositivo le informa las identificaciones
 * (`recordIdentification()`); con eso arma un resumen por terminal y día (`terminal_daily_stats`) del que salen la
 * disponibilidad, los cortes y la calidad de identificación. Las marcaciones y los conflictos no se almacenan acá:
 * se cuentan al consultar desde sus tablas de origen.
 *
 * «En línea» usa exactamente la misma definición que la columna Conectividad del listado (latido vigente según el
 * umbral del terminal), y solo cuentan los minutos dentro de su horario de vigilancia.
 */
class TerminalReliabilityService
{
    /**
     * Anota un minuto de observación del terminal. Solo cuentan `online` y `stale` (los demás estados —sin vincular,
     * fuera de horario, nunca conectado— no son un corte ni una disponibilidad). Un corte es una racha seguida de
     * minutos `stale`; se cuenta, con su duración, cuando la racha termina.
     *
     * @param  array<string, mixed>  $snapshot  `health_snapshot` del terminal (se actualiza `outage_run`).
     */
    public function sample(Terminal $terminal, string $connectivity, array &$snapshot, ?CarbonInterface $at = null): void
    {
        $run = (int) ($snapshot['outage_run'] ?? 0);
        $day = ($at ?? now())->toDateString();

        if (in_array($connectivity, ['online', 'stale'], true)) {
            $stat = $this->statFor($terminal, $day);
            $stat->expected_minutes++;

            if ($connectivity === 'online') {
                $stat->online_minutes++;
                $this->closeOutage($stat, $run);
                $run = 0;
            } else {
                $stat->outage_minutes++;
                $run++;
            }

            $stat->save();
        } elseif ($run > 0) {
            $stat = $this->statFor($terminal, $day);
            $this->closeOutage($stat, $run);
            $stat->save();
            $run = 0;
        }

        $snapshot['outage_run'] = $run;
    }

    /**
     * Suma al día las identificaciones nuevas que informa el dispositivo. El dispositivo manda contadores
     * acumulados desde que se cargó la página; lo nuevo es la diferencia con el reporte anterior (si el contador
     * bajó, la página se recargó y todo lo informado es nuevo).
     *
     * @param  array<string, mixed>|null  $previous  Reporte anterior del terminal.
     * @param  array<string, mixed>  $report  Reporte recién recibido.
     */
    public function recordIdentification(Terminal $terminal, ?array $previous, array $report, ?CarbonInterface $at = null): void
    {
        $keys = ['identification_attempts', 'identification_failures', 'identification_successes', 'identification_ms_total'];

        if (! array_key_exists('identification_attempts', $report)) {
            return;
        }

        $delta = [];
        foreach ($keys as $key) {
            $now = max(0, (int) ($report[$key] ?? 0));
            $before = (int) ($previous[$key] ?? 0);
            $delta[$key] = $now >= $before ? $now - $before : $now;
        }

        if (array_sum($delta) === 0) {
            return;
        }

        $stat = $this->statFor($terminal, ($at ?? now())->toDateString());
        foreach ($delta as $key => $value) {
            $stat->{$key} += $value;
        }
        $stat->save();
    }

    /**
     * Resumen de un terminal en un rango de fechas (ambas inclusive).
     *
     * @return array{
     *     availability: float|null, expected_minutes: int, online_minutes: int, outages: int, outage_minutes: int,
     *     longest_outage_minutes: int, marks: int, conflicts: int, identification_attempts: int,
     *     identification_failure_percent: float|null, average_identification_ms: int|null
     * }
     */
    public function summary(Terminal $terminal, CarbonInterface $from, CarbonInterface $to): array
    {
        $stats = TerminalDailyStat::query()
            ->where('terminal_id', $terminal->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $successes = (int) $stats->sum('identification_successes');
        $attempts = (int) $stats->sum('identification_attempts');

        return [
            'availability' => TerminalDailyStat::percent((int) $stats->sum('online_minutes'), (int) $stats->sum('expected_minutes')),
            'expected_minutes' => (int) $stats->sum('expected_minutes'),
            'online_minutes' => (int) $stats->sum('online_minutes'),
            'outages' => (int) $stats->sum('outages_count'),
            'outage_minutes' => (int) $stats->sum('outage_minutes'),
            'longest_outage_minutes' => (int) $stats->max('longest_outage_minutes'),
            'marks' => $this->marksCount($terminal, $from, $to),
            'conflicts' => $this->conflictsCount($terminal, $from, $to),
            'identification_attempts' => $attempts,
            'identification_failure_percent' => TerminalDailyStat::percent((int) $stats->sum('identification_failures'), $attempts),
            'average_identification_ms' => $successes > 0 ? (int) round($stats->sum('identification_ms_total') / $successes) : null,
        ];
    }

    /** Marcaciones registradas desde el terminal en el rango (ambas fechas inclusive). */
    public function marksCount(Terminal $terminal, CarbonInterface $from, CarbonInterface $to): int
    {
        return AttendanceEvent::query()
            ->where('terminal_id', $terminal->id)
            ->where('recorded_at', '>=', $from->copy()->startOfDay())
            ->where('recorded_at', '<=', $to->copy()->endOfDay())
            ->count();
    }

    /** Conflictos de sincronización que el servidor rechazó de este terminal en el rango (ambas fechas inclusive). */
    public function conflictsCount(Terminal $terminal, CarbonInterface $from, CarbonInterface $to): int
    {
        return AttendanceMarkFailure::query()
            ->where('failure_type', 'sync_conflict')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.terminal_id')) = ?", [(string) $terminal->id])
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->count();
    }

    /**
     * Terminales con disponibilidad por debajo del mínimo en el rango, con su porcentaje. Solo se evalúan los que
     * tienen al menos `$minimumMinutes` minutos medidos, para no avisar por una muestra insignificante.
     *
     * @return Collection<int, array{terminal: Terminal, availability: float, outages: int, longest_outage_minutes: int}>
     */
    public function belowAvailability(CarbonInterface $from, CarbonInterface $to, int $threshold, int $minimumMinutes = 120): Collection
    {
        return TerminalDailyStat::query()
            ->selectRaw('terminal_id, SUM(expected_minutes) as expected, SUM(online_minutes) as online, SUM(outages_count) as outages, MAX(longest_outage_minutes) as longest')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('terminal_id')
            ->get()
            ->filter(fn ($row) => (int) $row->expected >= $minimumMinutes)
            ->map(fn ($row) => [
                'terminal' => Terminal::query()->with('branch')->find($row->terminal_id),
                'availability' => (float) TerminalDailyStat::percent((int) $row->online, (int) $row->expected),
                'outages' => (int) $row->outages,
                'longest_outage_minutes' => (int) $row->longest,
            ])
            ->filter(fn (array $row) => $row['terminal'] !== null && $row['terminal']->isActive() && $row['availability'] < $threshold)
            ->sortBy('availability')
            ->values();
    }

    /**
     * Borra los resúmenes más antiguos que `$days` días (0 = no borra nada). Devuelve cuántos eliminó.
     */
    public function purge(int $days, ?CarbonInterface $now = null): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = Carbon::instance(($now ?? now())->toDateTime())->subDays($days)->toDateString();
        $deleted = 0;

        do {
            $batch = DB::table('terminal_daily_stats')->where('date', '<', $cutoff)->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }

    /** Fila del día para el terminal (la crea con todo en cero si todavía no existe). */
    private function statFor(Terminal $terminal, string $day): TerminalDailyStat
    {
        return TerminalDailyStat::query()->firstOrCreate(['terminal_id' => $terminal->id, 'date' => $day]);
    }

    /** Cierra una racha de minutos sin conexión: cuenta el corte y actualiza el más largo del día. */
    private function closeOutage(TerminalDailyStat $stat, int $run): void
    {
        if ($run <= 0) {
            return;
        }

        $stat->outages_count++;
        $stat->longest_outage_minutes = max($stat->longest_outage_minutes, $run);
    }
}
