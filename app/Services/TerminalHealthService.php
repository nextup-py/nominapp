<?php

namespace App\Services;

use App\Models\Terminal;
use App\Models\TerminalEvent;
use App\Models\User;
use App\Notifications\TerminalHealthNotification;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Log;

/**
 * Evalúa la salud de los terminales y avisa solo cuando algo CAMBIA: nunca repite un
 * aviso mientras el estado siga igual. El último estado evaluado vive en
 * `terminals.health_snapshot`; la primera evaluación de un terminal solo fija esa línea
 * de base (sin avisos) y un terminal inactivo no se evalúa.
 *
 * Qué avisa a la campanita (a los usuarios con `update_terminal`):
 * - `offline`: pasó a Desconectado (solo dentro de su horario de vigilancia).
 * - `recovered`: volvió a estar en línea tras haber avisado la desconexión.
 * - `unlinked`: un terminal que estaba vinculado quedó sin acceso.
 * - `queue_stuck`: la cola de marcaciones no se vacía hace más del umbral (con el terminal en línea).
 * - `battery_low`: bajó del nivel configurado sin cargador.
 * La normalización de la cola y de la batería queda en la bitácora pero no notifica.
 */
class TerminalHealthService
{
    /** Puntos por encima del umbral que debe recuperar la batería para dejar de estar "baja" (evita parpadeo). */
    private const BATTERY_HYSTERESIS = 5;

    /** Reportes seguidos con el reloj desfasado antes de avisar (evita avisar por un reporte suelto). */
    private const CLOCK_SKEW_CONSECUTIVE_REPORTS = 3;

    public function __construct(private readonly GeneralSettings $settings) {}

    /**
     * Evalúa un terminal, actualiza su estado guardado y dispara los avisos de las transiciones.
     *
     * @return array<int, string> Tipos de aviso emitidos (`offline`, `recovered`, `unlinked`, `queue_stuck`, `battery_low`, `no_enrolled`, `cache_empty`, `clock_skew`).
     */
    public function evaluate(Terminal $terminal): array
    {
        if ($terminal->isInactive()) {
            if ($terminal->health_snapshot !== null) {
                $terminal->forceFill(['health_snapshot' => null])->save();
            }

            return [];
        }

        $previous = $terminal->health_snapshot;
        $connectivity = $terminal->connectivity_status;
        $linked = $connectivity !== 'unlinked';

        $snapshot = [
            'linked' => $linked,
            'connectivity' => $connectivity,
            'offline_alerted' => (bool) ($previous['offline_alerted'] ?? false),
            'queue_stuck' => (bool) ($previous['queue_stuck'] ?? false),
            'low_battery' => (bool) ($previous['low_battery'] ?? false),
            'cache_empty' => (bool) ($previous['cache_empty'] ?? false),
            'no_enrolled' => (bool) ($previous['no_enrolled'] ?? false),
            'clock_skew' => (bool) ($previous['clock_skew'] ?? false),
            'clock_hits' => (int) ($previous['clock_hits'] ?? 0),
            'clock_report_at' => $previous['clock_report_at'] ?? null,
        ];

        $alerts = [];

        if ($previous === null) {
            $snapshot['queue_stuck'] = $terminal->isQueueStuck();
            $snapshot['low_battery'] = $this->lowBattery($terminal, false) ?? false;
            $snapshot['no_enrolled'] = $linked && $terminal->enrolledEmployeesCount() === 0;
            $snapshot['cache_empty'] = $this->cacheEmpty($terminal, $connectivity, $terminal->enrolledEmployeesCount()) ?? false;
            $snapshot['clock_report_at'] = $terminal->device_report_at?->toIso8601String();
        } else {
            $alerts = [
                ...$this->evaluateConnectivity($terminal, $previous, $connectivity, $snapshot),
                ...$this->evaluateQueue($terminal, $connectivity, $snapshot),
                ...$this->evaluateBattery($terminal, $snapshot),
                ...$this->evaluateEnrollment($terminal, $connectivity, $snapshot),
                ...$this->evaluateClock($terminal, $snapshot),
            ];
        }

        $terminal->forceFill(['health_snapshot' => $snapshot])->save();

        return $alerts;
    }

    /**
     * Evalúa todos los terminales activos.
     *
     * @return int Cantidad de avisos emitidos.
     */
    public function evaluateAll(): int
    {
        $alerts = 0;

        Terminal::query()->withSyncTokenFlag()->each(function (Terminal $terminal) use (&$alerts) {
            $alerts += count($this->evaluate($terminal));
        });

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    private function evaluateConnectivity(Terminal $terminal, array $previous, string $connectivity, array &$snapshot): array
    {
        $alerts = [];

        if (($previous['linked'] ?? true) && $connectivity === 'unlinked') {
            $alerts[] = $this->raise($terminal, 'unlinked', 'health_unlinked');
        }

        if ($connectivity === 'stale' && ! $snapshot['offline_alerted']) {
            $snapshot['offline_alerted'] = true;
            $alerts[] = $this->raise($terminal, 'offline', 'health_offline', [
                'stale_after_minutes' => $terminal->effectiveStaleMinutes(),
                'last_heartbeat_at' => $terminal->last_heartbeat_at?->toIso8601String(),
            ]);
        } elseif ($connectivity === 'online' && $snapshot['offline_alerted']) {
            $snapshot['offline_alerted'] = false;
            $alerts[] = $this->raise($terminal, 'recovered', 'health_recovered');
        } elseif (in_array($connectivity, ['unlinked', 'never_connected'], true)) {
            $snapshot['offline_alerted'] = false;
        }

        return $alerts;
    }

    /**
     * La cola solo se evalúa con el terminal en línea: sin conexión es normal que no se vacíe
     * y el aviso de desconexión ya lo cubre.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    private function evaluateQueue(Terminal $terminal, string $connectivity, array &$snapshot): array
    {
        if ($connectivity !== 'online') {
            return [];
        }

        $stuck = $terminal->isQueueStuck();

        if ($stuck && ! $snapshot['queue_stuck']) {
            $snapshot['queue_stuck'] = true;

            return [$this->raise($terminal, 'queue_stuck', 'health_queue_stuck', [
                'pending' => $terminal->last_pending_events_count,
                'since' => $terminal->queue_backlog_since?->toIso8601String(),
            ])];
        }

        if (! $stuck && $snapshot['queue_stuck']) {
            $snapshot['queue_stuck'] = false;
            $this->record($terminal, 'health_queue_recovered');
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    private function evaluateBattery(Terminal $terminal, array &$snapshot): array
    {
        $low = $this->lowBattery($terminal, $snapshot['low_battery']);

        if ($low === null || $low === $snapshot['low_battery']) {
            return [];
        }

        $snapshot['low_battery'] = $low;

        if ($low) {
            return [$this->raise($terminal, 'battery_low', 'health_battery_low', ['level' => $terminal->reportedBatteryLevel()])];
        }

        $this->record($terminal, 'health_battery_ok', ['level' => $terminal->reportedBatteryLevel()]);

        return [];
    }

    /**
     * Terminal que no podría identificar a nadie: `no_enrolled` (la sucursal no tiene ningún empleado activo con rostro
     * registrado) y `cache_empty` (hay empleados enrolados pero el terminal en línea reporta 0 en su caché).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    private function evaluateEnrollment(Terminal $terminal, string $connectivity, array &$snapshot): array
    {
        $alerts = [];
        $enrolled = $terminal->enrolledEmployeesCount();
        $linked = $connectivity !== 'unlinked';

        $none = $linked && $enrolled === 0;

        if ($none && ! $snapshot['no_enrolled']) {
            $snapshot['no_enrolled'] = true;
            $alerts[] = $this->raise($terminal, 'no_enrolled', 'health_no_enrolled');
        } elseif (! $none && $snapshot['no_enrolled'] && $enrolled > 0) {
            $snapshot['no_enrolled'] = false;
            $this->record($terminal, 'health_enrolled_ok', ['enrolled' => $enrolled]);
        }

        $empty = $this->cacheEmpty($terminal, $connectivity, $enrolled);

        if ($empty === true && ! $snapshot['cache_empty']) {
            $snapshot['cache_empty'] = true;
            $alerts[] = $this->raise($terminal, 'cache_empty', 'health_cache_empty', ['enrolled' => $enrolled]);
        } elseif ($empty === false && $snapshot['cache_empty']) {
            $snapshot['cache_empty'] = false;
            $this->record($terminal, 'health_cache_ok', ['cached' => $terminal->reportedCachedEmployees()]);
        }

        return $alerts;
    }

    /**
     * Caché vacía con empleados enrolados, según el último reporte fresco de un terminal en línea.
     *
     * @return bool|null null si no se puede determinar (sin conexión, sin reporte fresco o sin dato): se conserva el estado.
     */
    private function cacheEmpty(Terminal $terminal, string $connectivity, int $enrolled): ?bool
    {
        $cached = $terminal->reportedCachedEmployees();

        if ($connectivity !== 'online' || $cached === null || ! $terminal->hasFreshDeviceReport()) {
            return null;
        }

        return $cached === 0 && $enrolled > 0;
    }

    /**
     * Reloj desajustado: el desfase del último reporte supera el máximo tolerado en varios reportes seguidos.
     * Solo cuenta reportes nuevos (cada minuto de evaluación no suma el mismo reporte dos veces).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    private function evaluateClock(Terminal $terminal, array &$snapshot): array
    {
        $skew = $terminal->reportedClockSkewSeconds();
        $reportAt = $terminal->device_report_at?->toIso8601String();

        if ($skew === null || $reportAt === null || $reportAt === $snapshot['clock_report_at']) {
            return [];
        }

        $snapshot['clock_report_at'] = $reportAt;

        if (abs($skew) > $this->settings->terminal_clock_skew_max_seconds) {
            $snapshot['clock_hits']++;

            if (! $snapshot['clock_skew'] && $snapshot['clock_hits'] >= self::CLOCK_SKEW_CONSECUTIVE_REPORTS) {
                $snapshot['clock_skew'] = true;

                return [$this->raise($terminal, 'clock_skew', 'health_clock_skew', ['seconds' => $skew])];
            }

            return [];
        }

        $snapshot['clock_hits'] = 0;

        if ($snapshot['clock_skew']) {
            $snapshot['clock_skew'] = false;
            $this->record($terminal, 'health_clock_ok', ['seconds' => $skew]);
        }

        return [];
    }

    /**
     * Batería baja según el último reporte: nivel por debajo del umbral y sin cargador. Con
     * `$wasLow` aplica histéresis (sigue baja hasta recuperar umbral + 5 puntos o enchufarse).
     *
     * @return bool|null null si no se puede determinar (sin reporte, reporte viejo o sin dato de batería): se conserva el estado.
     */
    private function lowBattery(Terminal $terminal, bool $wasLow): ?bool
    {
        $level = $terminal->reportedBatteryLevel();
        $reportIsFresh = $terminal->hasFreshDeviceReport();

        if ($level === null || ! $reportIsFresh) {
            return null;
        }

        if ($terminal->reportedCharging() === true) {
            return false;
        }

        $threshold = $this->settings->terminal_low_battery_percent;

        return $wasLow ? $level < $threshold + self::BATTERY_HYSTERESIS : $level < $threshold;
    }

    /**
     * Registra el evento en la bitácora y avisa a quienes gestionan terminales.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function raise(Terminal $terminal, string $alert, string $eventType, ?array $payload = null): string
    {
        $this->record($terminal, $eventType, $payload);

        try {
            Terminal::managers()->each(fn (User $user) => $user->notify(new TerminalHealthNotification($terminal, $alert)));
        } catch (\Throwable $e) {
            Log::warning("No se pudo avisar el estado '{$alert}' del terminal '{$terminal->code}': {$e->getMessage()}", [
                'terminal_id' => $terminal->id,
            ]);
        }

        return $alert;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function record(Terminal $terminal, string $eventType, ?array $payload = null): void
    {
        TerminalEvent::record($terminal, $eventType, $payload);
    }
}
