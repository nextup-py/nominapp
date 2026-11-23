<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Terminal;
use App\Models\TerminalEvent;
use App\Services\TerminalPairingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Siembra los terminales de la demo en estados distintos (en línea y sano, abierto en una pestaña, dormido con la
 * batería baja, sin cargador y sin vincular con una solicitud pendiente), con responsable, horario de vigilancia,
 * bitácora y estadísticas de confiabilidad de los últimos 14 días. `refresh()` los pone al día respecto de la hora
 * actual (lo usa `demo:refresh`), para que el Monitor en vivo y los avisos se vean siempre vigentes.
 */
class DemoTerminalSeeder extends Seeder
{
    /** Marcador de los terminales de la demo en `device_notes`: `DEMO:<estado>`. */
    public const MARKER = 'DEMO:';

    /**
     * Terminales de la demo.
     *
     * @var array<int, array<string, mixed>>
     */
    private const TERMINALS = [
        ['name' => 'Terminal Entrada Central', 'branch' => 'Sucursal Central', 'state' => 'online', 'brand' => 'Samsung', 'model' => 'Galaxy Tab A8', 'mode' => 'standalone', 'battery' => 100, 'charging' => true, 'availability' => 99, 'failure' => 0.04, 'responsible' => ['Marta Ortiz', '0981000111']],
        ['name' => 'Terminal Recepción Central', 'branch' => 'Sucursal Central', 'state' => 'tab', 'brand' => 'Lenovo', 'model' => 'Tab M10', 'mode' => 'browser', 'battery' => 68, 'charging' => true, 'availability' => 96, 'failure' => 0.07, 'responsible' => ['Marta Ortiz', '0981000111']],
        ['name' => 'Terminal Este 1', 'branch' => 'Sucursal Este', 'state' => 'stale', 'brand' => 'Samsung', 'model' => 'Galaxy A22', 'mode' => 'browser', 'battery' => 14, 'charging' => false, 'availability' => 72, 'failure' => 0.14, 'responsible' => ['Carlos Benítez', '0981000222']],
        ['name' => 'Terminal Norte 1', 'branch' => 'Sucursal Norte', 'state' => 'nocharger', 'brand' => 'Xiaomi', 'model' => 'Redmi Pad', 'mode' => 'standalone', 'battery' => 62, 'charging' => false, 'availability' => 93, 'failure' => 0.06, 'responsible' => ['Lucía Acosta', '0981000333']],
        ['name' => 'Terminal Sur 1', 'branch' => 'Sucursal Sur', 'state' => 'unlinked', 'brand' => 'Samsung', 'model' => 'Galaxy A13', 'mode' => 'browser', 'battery' => 80, 'charging' => true, 'availability' => 0, 'failure' => 0.0, 'responsible' => ['Hugo Cabrera', '0981000444']],
    ];

    public function run(): void
    {
        $this->setBranchWatchSchedules();

        foreach (self::TERMINALS as $definition) {
            $terminal = $this->createTerminal($definition);

            if ($definition['state'] !== 'unlinked') {
                $this->recordHistory($terminal, $definition);
                $this->seedDailyStats($terminal, $definition);
            } else {
                $this->requestPairing($terminal);
            }
        }

        $this->refresh();
    }

    /**
     * Pone al día los latidos y el reporte del dispositivo de los terminales de la demo respecto de la hora actual:
     * los sanos reportan hace segundos y el dormido sigue sin reportar hace unas horas.
     */
    public function refresh(?Carbon $now = null): void
    {
        $now ??= now();

        Terminal::query()->where('device_notes', 'like', self::MARKER.'%')->get()->each(function (Terminal $terminal) use ($now) {
            $definition = $this->definitionFor($terminal);
            $state = $definition['state'] ?? 'unlinked';

            if ($state === 'unlinked') {
                return;
            }

            $heartbeat = $state === 'stale' ? $now->copy()->subHours(3)->subMinutes(12) : $now->copy()->subSeconds(25 + ($terminal->id * 17) % 55);

            $terminal->forceFill([
                'last_heartbeat_at' => $heartbeat,
                'last_seen_at' => $heartbeat,
                'last_employee_sync_at' => $heartbeat,
                'last_event_sync_at' => $heartbeat,
                'last_pending_events_count' => 0,
                'device_report' => $this->deviceReport($terminal, $definition),
                'device_report_at' => $heartbeat,
            ])->save();
        });
    }

    /** Horario de atención de las sucursales de la demo: lunes a sábado, con el sábado hasta el mediodía. */
    private function setBranchWatchSchedules(): void
    {
        Branch::query()->each(fn (Branch $branch) => $branch->update([
            'watch_days' => [1, 2, 3, 4, 5, 6],
            'watch_from' => '06:30',
            'watch_to' => '19:00',
            'watch_day_hours' => [['day' => 6, 'from' => '06:30', 'to' => '13:00']],
        ]));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function createTerminal(array $definition): Terminal
    {
        $branchId = Branch::query()->where('name', $definition['branch'])->value('id');

        $terminal = Terminal::create([
            'name' => $definition['name'],
            'branch_id' => $branchId,
            'status' => 'active',
            'device_brand' => $definition['brand'],
            'device_model' => $definition['model'],
            'device_notes' => self::MARKER.$definition['state'],
            'responsible_name' => $definition['responsible'][0],
            'responsible_phone' => $definition['responsible'][1],
            'installed_at' => today()->subDays(40),
            'purchased_at' => today()->subMonths(8),
            'warranty_until' => today()->addMonths(4),
        ]);

        if ($definition['state'] !== 'unlinked') {
            $terminal->claimSanctumToken($this->userAgent($definition), "{$definition['brand']} {$definition['model']}", '200.85.10.'.(10 + $terminal->id), 'pairing', 'demo-device-'.$terminal->id);
        }

        return $terminal->fresh();
    }

    /**
     * Bitácora con lo que cuenta cada estado, fechada en las últimas horas.
     *
     * @param  array<string, mixed>  $definition
     */
    private function recordHistory(Terminal $terminal, array $definition): void
    {
        $events = match ($definition['state']) {
            'stale' => [
                ['health_battery_low', ['level' => 18], now()->subHours(5)],
                ['health_offline', ['stale_after_minutes' => 20, 'hints' => ['Funcionaba sin cargador (batería al 14%).', 'La pestaña del terminal estaba oculta o minimizada.']], now()->subHours(2)->subMinutes(40)],
            ],
            'tab' => [['health_not_installed', [], now()->subHours(3)]],
            'nocharger' => [['health_no_charger', ['minutes' => 40], now()->subMinutes(35)]],
            default => [['health_installed_ok', [], now()->subDays(2)]],
        };

        foreach ($events as [$type, $payload, $at]) {
            TerminalEvent::record($terminal, $type, $payload)->forceFill(['created_at' => $at])->save();
        }
    }

    /**
     * Confiabilidad de los últimos 14 días (lunes a sábado) según la disponibilidad y la tasa de fallos del terminal.
     *
     * @param  array<string, mixed>  $definition
     */
    private function seedDailyStats(Terminal $terminal, array $definition): void
    {
        $rows = [];

        for ($i = 14; $i >= 1; $i--) {
            $date = today()->subDays($i);

            if ($date->isSunday()) {
                continue;
            }

            $expected = $date->isSaturday() ? 390 : 750;
            $online = (int) round($expected * min(100, $definition['availability'] + (($i * 3 + $terminal->id) % 5) - 2) / 100);
            $outage = $expected - $online;
            $attempts = 80 + (($i * 11 + $terminal->id * 5) % 60);
            $failures = (int) round($attempts * $definition['failure']);

            $rows[] = [
                'terminal_id' => $terminal->id,
                'date' => $date->toDateString(),
                'expected_minutes' => $expected,
                'online_minutes' => $online,
                'outages_count' => $outage > 0 ? 1 + $i % 3 : 0,
                'outage_minutes' => $outage,
                'longest_outage_minutes' => $outage > 0 ? (int) ceil($outage / (1 + $i % 3)) : 0,
                'identification_attempts' => $attempts,
                'identification_failures' => $failures,
                'identification_successes' => $attempts - $failures,
                'identification_ms_total' => ($attempts - $failures) * 2200,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('terminal_daily_stats')->insert($rows);
    }

    /** Un código de emparejamiento pendiente para mostrar la bandeja **Solicitudes de vinculación**. */
    private function requestPairing(Terminal $terminal): void
    {
        try {
            app(TerminalPairingService::class)->request($terminal, '200.85.10.99', $this->userAgent(['brand' => 'Samsung', 'model' => 'Galaxy A13']), 'Samsung Galaxy A13', ['device_id' => 'demo-device-new']);
        } catch (\Throwable $e) {
            $this->command?->warn('No se pudo crear la solicitud de vinculación de la demo: '.$e->getMessage());
        }
    }

    /**
     * Reporte del dispositivo según el estado del terminal.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function deviceReport(Terminal $terminal, array $definition): array
    {
        $healthy = $definition['state'] !== 'stale';
        $standalone = $definition['mode'] === 'standalone';

        return [
            'app_version' => Terminal::currentAppVersion(),
            'standalone' => $standalone,
            'display_mode' => $standalone ? 'standalone' : 'browser',
            'visible' => $healthy,
            'sw_active' => true,
            'wake_lock' => $healthy,
            'camera' => 'granted',
            'battery_level' => $definition['battery'],
            'battery_charging' => $definition['charging'],
            'cached_employees' => $terminal->enrolledEmployeesCount(),
            'clock_skew_seconds' => 1,
            'storage_used_mb' => 42,
            'storage_quota_mb' => 4096,
            'identification_median_ms' => $healthy ? 2100 : 3600,
            'identification_p90_ms' => $healthy ? 3000 : 5200,
            'identification_samples' => 20,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function userAgent(array $definition): string
    {
        return "Mozilla/5.0 (Linux; Android 13; {$definition['model']}) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36";
    }

    /**
     * Definición de la demo a la que corresponde un terminal, por el estado que dejó en el marcador de `device_notes`.
     *
     * @return array<string, mixed>
     */
    private function definitionFor(Terminal $terminal): array
    {
        return collect(self::TERMINALS)->firstWhere('name', $terminal->name) ?? [];
    }
}
