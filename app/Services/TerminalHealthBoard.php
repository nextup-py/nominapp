<?php

namespace App\Services;

use App\Models\AttendanceMarkFailure;
use App\Models\Terminal;
use App\Models\TerminalDailyStat;
use App\Settings\GeneralSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Tabla de salud de los terminales activos con un semáforo por terminal. Regla del semáforo: gana la peor señal.
 * Rojo = sin vincular, desconectado o disponibilidad bajo el mínimo; ámbar = cualquier otra alerta (sin primer
 * latido, cola atascada, conflictos, batería, caché vacía, reloj, app vieja, identificación lenta, garantía);
 * verde = sin alertas. Todo se arma con consultas agregadas (sin una consulta por terminal).
 */
class TerminalHealthBoard
{
    /** Días que cubre la disponibilidad y el conteo de conflictos de la tabla. */
    public const WINDOW_DAYS = 7;

    /** Mínimo de minutos medidos para evaluar la disponibilidad (misma regla que el aviso semanal). */
    public const MIN_MEASURED_MINUTES = 120;

    /** Orden de gravedad del semáforo (menor = más grave). */
    private const SEVERITY = ['danger' => 0, 'warning' => 1, 'success' => 2];

    /**
     * Una fila por terminal activo, ordenadas de más a menos grave y luego por nombre.
     *
     * @return Collection<int, array{
     *     terminal: Terminal, level: string, reasons: array<int, string>, availability: float|null,
     *     conflicts: int, identification_ms: int|null
     * }>
     */
    public function rows(?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $from = $now->copy()->subDays(self::WINDOW_DAYS - 1)->startOfDay();

        $terminals = Terminal::query()->withSyncTokenFlag()->with('branch')->where('status', 'active')->get();
        $availability = $this->availabilityByTerminal($from, $now);
        $conflicts = $this->conflictsByTerminal($from, $now);
        $threshold = app(GeneralSettings::class)->terminal_min_availability_percent;

        return $terminals
            ->map(function (Terminal $terminal) use ($availability, $conflicts, $threshold) {
                $percent = $availability[$terminal->id] ?? null;
                $count = $conflicts[$terminal->id] ?? 0;
                [$level, $reasons] = $this->evaluate($terminal, $percent, $count, $threshold);

                return [
                    'terminal' => $terminal,
                    'level' => $level,
                    'reasons' => $reasons,
                    'availability' => $percent,
                    'conflicts' => $count,
                    'identification_ms' => $terminal->identificationTiming()['median_ms'] ?? null,
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => self::SEVERITY[$a['level']] <=> self::SEVERITY[$b['level']],
                fn (array $a, array $b) => strcasecmp($a['terminal']->name, $b['terminal']->name),
            ])
            ->values();
    }

    /**
     * Nivel del semáforo y motivos de un terminal (la peor señal define el nivel).
     *
     * @return array{0: string, 1: array<int, string>}
     */
    public function evaluate(Terminal $terminal, ?float $availability, int $conflicts, int $threshold): array
    {
        $danger = [];
        $warning = [];
        $status = $terminal->connectivity_status;
        $snapshot = $terminal->health_snapshot ?? [];

        match ($status) {
            'unlinked' => $danger[] = 'Sin vincular',
            'stale' => $danger[] = 'Desconectado',
            'never_connected' => $warning[] = 'Vinculado, todavía sin primer latido',
            default => null,
        };

        if ($threshold > 0 && $availability !== null && $availability < $threshold) {
            $danger[] = "Disponibilidad {$availability}% (mínimo {$threshold}%)";
        }

        if ($status !== 'unlinked' && ($snapshot['no_enrolled'] ?? false)) {
            $warning[] = 'Sucursal sin empleados enrolados';
        }

        if ($status === 'online') {
            $terminal->isQueueStuck() && $warning[] = 'Cola de marcaciones atascada';
            ($snapshot['low_battery'] ?? false) && $warning[] = 'Batería baja';
            ($snapshot['cache_empty'] ?? false) && $warning[] = 'Sin empleados en caché';
            ($snapshot['clock_skew'] ?? false) && $warning[] = 'Reloj desajustado';
            $terminal->isAppOutdated() && $warning[] = 'App desactualizada';
        }

        $conflicts > 0 && $warning[] = $conflicts === 1 ? '1 conflicto de marcación' : "{$conflicts} conflictos de marcación";
        $terminal->isIdentificationSlow() && $warning[] = 'Identificación lenta';

        match ($terminal->warrantyStatus()) {
            'expired' => $warning[] = 'Garantía vencida',
            'expiring' => $warning[] = 'Garantía por vencer',
            default => null,
        };

        $level = $danger !== [] ? 'danger' : ($warning !== [] ? 'warning' : 'success');

        return [$level, [...$danger, ...$warning]];
    }

    /**
     * Disponibilidad del rango por terminal, solo de los que tienen suficientes minutos medidos.
     *
     * @return array<int, float>
     */
    private function availabilityByTerminal(CarbonInterface $from, CarbonInterface $to): array
    {
        return TerminalDailyStat::query()
            ->selectRaw('terminal_id, SUM(expected_minutes) as expected, SUM(online_minutes) as online')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('terminal_id')
            ->get()
            ->filter(fn ($row) => (int) $row->expected >= self::MIN_MEASURED_MINUTES)
            ->mapWithKeys(fn ($row) => [(int) $row->terminal_id => (float) TerminalDailyStat::percent((int) $row->online, (int) $row->expected)])
            ->all();
    }

    /**
     * Conflictos de sincronización del rango por terminal.
     *
     * @return array<int, int>
     */
    private function conflictsByTerminal(CarbonInterface $from, CarbonInterface $to): array
    {
        return AttendanceMarkFailure::query()
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.terminal_id')) as terminal_id, COUNT(*) as total")
            ->where('failure_type', 'sync_conflict')
            ->where('occurred_at', '>=', $from->copy()->startOfDay())
            ->where('occurred_at', '<=', $to->copy()->endOfDay())
            ->groupBy('terminal_id')
            ->get()
            ->filter(fn ($row) => $row->terminal_id !== null)
            ->mapWithKeys(fn ($row) => [(int) $row->terminal_id => (int) $row->total])
            ->all();
    }
}
