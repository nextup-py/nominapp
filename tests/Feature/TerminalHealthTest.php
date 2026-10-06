<?php

use App\Filament\Resources\TerminalResource\Pages\EditTerminal;
use App\Filament\Resources\TerminalResource\Pages\ListTerminals;
use App\Filament\Resources\TerminalResource\Pages\ViewTerminal;
use App\Filament\Widgets\TerminalHealthWidget;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TerminalEvent;
use App\Models\User;
use App\Notifications\TerminalHealthNotification;
use App\Services\TerminalHealthService;
use App\Settings\GeneralSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeHealthTerminal(array $attributes = [], bool $linked = true): Terminal
{
    static $n = 8300000;
    $n++;

    $company = Company::create(['name' => "Empresa Health {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Health {$n}", 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => "Terminal Health {$n}", 'branch_id' => $branch->id, ...$attributes]);

    if ($linked) {
        $terminal->claimSanctumToken();
    }

    return $terminal->fresh();
}

function healthManager(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::firstOrCreate(['name' => 'update_terminal', 'guard_name' => 'web']));

    return $user;
}

function healthSuperAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

/** Evalúa y devuelve los avisos emitidos. */
function evaluateHealth(Terminal $terminal): array
{
    return app(TerminalHealthService::class)->evaluate($terminal->fresh());
}

beforeEach(function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', config('app.timezone'))); // lunes
});

afterEach(fn () => Carbon::setTestNow());

// ─── Umbral de desconexión ──────────────────────────────────────────────────

it('usa el umbral propio en minutos y, sin él, el global en horas', function () {
    $settings = app(GeneralSettings::class);
    $settings->terminal_stale_threshold_hours = 2;
    $settings->save();

    $default = makeHealthTerminal();
    $custom = makeHealthTerminal(['stale_after_minutes' => 10]);

    expect($default->effectiveStaleMinutes())->toBe(120)->and($custom->effectiveStaleMinutes())->toBe(10);

    $default->update(['last_heartbeat_at' => now()->subMinutes(30)]);
    $custom->update(['last_heartbeat_at' => now()->subMinutes(30)]);

    expect($default->fresh()->connectivity_status)->toBe('online')
        ->and($custom->fresh()->connectivity_status)->toBe('stale');
});

it('las consultas SQL de heartbeat vencido/vigente respetan el umbral propio de cada terminal', function () {
    $settings = app(GeneralSettings::class);
    $settings->terminal_stale_threshold_hours = 2;
    $settings->save();

    $a = makeHealthTerminal(['stale_after_minutes' => 10, 'last_heartbeat_at' => now()->subMinutes(30)]);
    $b = makeHealthTerminal(['last_heartbeat_at' => now()->subMinutes(30)]);
    $c = makeHealthTerminal(['last_heartbeat_at' => now()->subHours(3)]);

    expect(Terminal::heartbeatStale()->pluck('id')->all())->toEqualCanonicalizing([$a->id, $c->id])
        ->and(Terminal::heartbeatFresh()->pluck('id')->all())->toBe([$b->id]);
});

// ─── Horario de vigilancia ──────────────────────────────────────────────────

it('sin horario configurado se vigila siempre (24/7)', function () {
    $terminal = makeHealthTerminal();

    expect($terminal->isWithinWatchWindow())->toBeTrue()
        ->and($terminal->isWithinWatchWindow(Carbon::parse('2026-10-11 03:00', config('app.timezone'))))->toBeTrue()
        ->and($terminal->watchScheduleLabel())->toBe('Siempre (24/7)');
});

it('respeta los días y el rango horario (lunes a viernes, 08:00 a 20:00)', function () {
    $terminal = makeHealthTerminal(['watch_days' => [1, 2, 3, 4, 5], 'watch_from' => '08:00:00', 'watch_to' => '20:00:00']);
    $tz = config('app.timezone');

    expect($terminal->isWithinWatchWindow(Carbon::parse('2026-10-12 08:00', $tz)))->toBeTrue()   // lunes, justo al abrir
        ->and($terminal->isWithinWatchWindow(Carbon::parse('2026-10-12 19:59', $tz)))->toBeTrue()
        ->and($terminal->isWithinWatchWindow(Carbon::parse('2026-10-12 20:00', $tz)))->toBeFalse() // el fin es exclusivo
        ->and($terminal->isWithinWatchWindow(Carbon::parse('2026-10-12 07:59', $tz)))->toBeFalse()
        ->and($terminal->isWithinWatchWindow(Carbon::parse('2026-10-17 10:00', $tz)))->toBeFalse() // sábado
        ->and($terminal->watchScheduleLabel())->toBe('Lun, Mar, Mié, Jue, Vie · 08:00–20:00');
});

it('un rango que cruza la medianoche pertenece al día en que empieza', function () {
    // Vigila solo la noche del lunes: de lunes 22:00 a martes 06:00.
    $terminal = makeHealthTerminal(['watch_days' => [1], 'watch_from' => '22:00:00', 'watch_to' => '06:00:00']);
    $tz = config('app.timezone');

    expect($terminal->isWithinWatchWindow(Carbon::parse('2026-10-12 23:00', $tz)))->toBeTrue()   // lunes 23:00
        ->and($terminal->isWithinWatchWindow(Carbon::parse('2026-10-13 02:00', $tz)))->toBeTrue()  // martes 02:00: sigue el turno del lunes
        ->and($terminal->isWithinWatchWindow(Carbon::parse('2026-10-13 23:00', $tz)))->toBeFalse() // martes 23:00: empieza el del martes
        ->and($terminal->isWithinWatchWindow(Carbon::parse('2026-10-12 02:00', $tz)))->toBeFalse() // lunes 02:00: es del domingo
        ->and($terminal->isWithinWatchWindow(Carbon::parse('2026-10-12 12:00', $tz)))->toBeFalse();
});

it('solo días, sin horas, vigila todo el día en esos días; solo horas vigila todos los días', function () {
    $tz = config('app.timezone');
    $onlyDays = makeHealthTerminal(['watch_days' => [6, 7]]);
    $onlyHours = makeHealthTerminal(['watch_from' => '09:00:00', 'watch_to' => '18:00:00']);

    expect($onlyDays->isWithinWatchWindow(Carbon::parse('2026-10-17 03:00', $tz)))->toBeTrue()
        ->and($onlyDays->isWithinWatchWindow(Carbon::parse('2026-10-12 10:00', $tz)))->toBeFalse()
        ->and($onlyDays->watchScheduleLabel())->toBe('Sáb, Dom')
        ->and($onlyHours->isWithinWatchWindow(Carbon::parse('2026-10-17 10:00', $tz)))->toBeTrue()
        ->and($onlyHours->isWithinWatchWindow(Carbon::parse('2026-10-17 21:00', $tz)))->toBeFalse();
});

it('los días guardados como texto (checkboxes) también funcionan', function () {
    $terminal = makeHealthTerminal(['watch_days' => ['1', '2']]);

    expect($terminal->isWithinWatchWindow())->toBeTrue(); // lunes
});

it('un heartbeat vencido fuera del horario es "Fuera de horario", no "Desconectado"', function () {
    $terminal = makeHealthTerminal([
        'stale_after_minutes' => 10,
        'watch_from' => '12:00:00', 'watch_to' => '20:00:00',
        'last_heartbeat_at' => now()->subHour(), // son las 10:00: fuera de horario
    ]);

    expect($terminal->connectivity_status)->toBe('off_hours');

    Carbon::setTestNow(Carbon::parse('2026-10-12 13:00:00', config('app.timezone')));
    expect($terminal->fresh()->connectivity_status)->toBe('stale');
});

it('un terminal fuera de horario pero que sí reporta figura en línea', function () {
    $terminal = makeHealthTerminal([
        'watch_from' => '12:00:00', 'watch_to' => '20:00:00',
        'last_heartbeat_at' => now()->subMinute(),
    ]);

    expect($terminal->connectivity_status)->toBe('online');
});

it('el listado filtra Desconectado y Fuera de horario según el horario de vigilancia', function () {
    $this->actingAs(healthSuperAdmin());
    $stale = makeHealthTerminal(['stale_after_minutes' => 10, 'last_heartbeat_at' => now()->subHour()]);
    $off = makeHealthTerminal(['stale_after_minutes' => 10, 'watch_from' => '12:00:00', 'watch_to' => '20:00:00', 'last_heartbeat_at' => now()->subHour()]);
    $online = makeHealthTerminal(['last_heartbeat_at' => now()->subMinute()]);

    Livewire::test(ListTerminals::class)
        ->filterTable('connectivity_status', 'stale')
        ->assertCanSeeTableRecords([$stale])
        ->assertCanNotSeeTableRecords([$off, $online])
        ->filterTable('connectivity_status', 'off_hours')
        ->assertCanSeeTableRecords([$off])
        ->assertCanNotSeeTableRecords([$stale, $online])
        ->filterTable('connectivity_status', 'online')
        ->assertCanSeeTableRecords([$online])
        ->assertCanNotSeeTableRecords([$stale, $off]);
});

// ─── Heartbeat extendido ────────────────────────────────────────────────────

it('el heartbeat guarda el reporte del dispositivo y solo los campos conocidos', function () {
    $terminal = makeHealthTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/terminal/heartbeat', [
        'pending_events' => 0,
        'conflict_events' => 0,
        'device' => [
            'app_version' => 'abc123', 'standalone' => true, 'sw_active' => true,
            'battery_level' => 55, 'battery_charging' => false, 'camera' => 'granted',
            'cached_employees' => 30, 'clock_skew_seconds' => -3, 'storage_used_mb' => 40, 'storage_quota_mb' => 1000,
            'campo_raro' => 'ignorado',
        ],
    ])->assertOk();

    $fresh = $terminal->fresh();
    expect($fresh->device_report)->toMatchArray(['app_version' => 'abc123', 'battery_level' => 55, 'cached_employees' => 30])
        ->and($fresh->device_report)->not->toHaveKey('campo_raro')
        ->and($fresh->device_report_at)->not->toBeNull()
        ->and($fresh->reportedBatteryLevel())->toBe(55)
        ->and($fresh->reportedCharging())->toBeFalse();
});

it('un cliente viejo sin "device" sigue funcionando y no pisa el último reporte', function () {
    $terminal = makeHealthTerminal(['device_report' => ['battery_level' => 80], 'device_report_at' => now()->subMinute()]);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/terminal/heartbeat', ['pending_events' => 0, 'conflict_events' => 0])->assertOk();

    expect($terminal->fresh()->device_report)->toBe(['battery_level' => 80]);
});

it('el heartbeat rechaza valores fuera de rango o desconocidos en el reporte', function (array $device) {
    $terminal = makeHealthTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/terminal/heartbeat', ['device' => $device])->assertUnprocessable();
})->with([
    'batería > 100' => [['battery_level' => 150]],
    'cámara inválida' => [['camera' => 'quizás']],
    'empleados negativos' => [['cached_employees' => -1]],
    'versión enorme' => [['app_version' => str_repeat('x', 100)]],
]);

it('marca desde cuándo la cola no está vacía y lo limpia cuando se vacía', function () {
    $terminal = makeHealthTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/terminal/heartbeat', ['pending_events' => 3, 'conflict_events' => 0])->assertOk();
    $since = $terminal->fresh()->queue_backlog_since;
    expect($since)->not->toBeNull();

    Carbon::setTestNow(now()->addMinutes(5));
    $this->postJson('/api/v1/terminal/heartbeat', ['pending_events' => 2, 'conflict_events' => 1])->assertOk();
    expect($terminal->fresh()->queue_backlog_since->equalTo($since))->toBeTrue(); // sigue desde el mismo momento

    $this->postJson('/api/v1/terminal/heartbeat', ['pending_events' => 0, 'conflict_events' => 0])->assertOk();
    expect($terminal->fresh()->queue_backlog_since)->toBeNull();
});

it('currentAppVersion() es estable y isAppOutdated() compara con lo que reportó el dispositivo', function () {
    $terminal = makeHealthTerminal();

    expect(Terminal::currentAppVersion())->toBe(Terminal::currentAppVersion())
        ->and($terminal->isAppOutdated())->toBeNull();

    $terminal->update(['device_report' => ['app_version' => Terminal::currentAppVersion()]]);
    expect($terminal->fresh()->isAppOutdated())->toBeFalse();

    $terminal->update(['device_report' => ['app_version' => 'vieja']]);
    expect($terminal->fresh()->isAppOutdated())->toBeTrue();
});

// ─── Evaluación de salud: solo transiciones ─────────────────────────────────

it('la primera evaluación solo fija la línea de base, sin avisos', function () {
    $terminal = makeHealthTerminal(['stale_after_minutes' => 10, 'last_heartbeat_at' => now()->subHour()]);
    healthManager();

    expect(evaluateHealth($terminal))->toBe([])
        ->and($terminal->fresh()->health_snapshot)->toMatchArray(['linked' => true, 'connectivity' => 'stale', 'offline_alerted' => false]);

    Notification::assertNothingSent();
});

it('avisa una sola vez cuando pasa a desconectado y otra cuando se recupera', function () {
    $manager = healthManager();
    $other = User::factory()->create(); // sin permiso sobre terminales
    $terminal = makeHealthTerminal(['stale_after_minutes' => 10, 'last_heartbeat_at' => now()->subMinute()]);

    evaluateHealth($terminal); // línea de base: en línea

    $terminal->update(['last_heartbeat_at' => now()->subMinutes(20)]);
    expect(evaluateHealth($terminal))->toBe(['offline'])
        ->and(evaluateHealth($terminal))->toBe([])   // no repite mientras siga igual
        ->and(evaluateHealth($terminal))->toBe([]);

    Notification::assertSentToTimes($manager, TerminalHealthNotification::class, 1);
    Notification::assertNotSentTo($other, TerminalHealthNotification::class);

    $terminal->update(['last_heartbeat_at' => now()]);
    expect(evaluateHealth($terminal))->toBe(['recovered'])
        ->and(evaluateHealth($terminal))->toBe([]);

    Notification::assertSentToTimes($manager, TerminalHealthNotification::class, 2);
    expect($terminal->events()->whereIn('type', ['health_offline', 'health_recovered'])->orderBy('id')->pluck('type')->all())
        ->toBe(['health_offline', 'health_recovered']);
});

it('fuera del horario de vigilancia no avisa; al abrir el horario, si sigue caído, avisa', function () {
    healthManager();
    $terminal = makeHealthTerminal([
        'stale_after_minutes' => 10, 'watch_from' => '12:00:00', 'watch_to' => '20:00:00',
        'last_heartbeat_at' => now()->subMinute(),
    ]);
    evaluateHealth($terminal);

    $terminal->update(['last_heartbeat_at' => now()->subHours(2)]); // son las 10:00, fuera de horario
    expect(evaluateHealth($terminal))->toBe([])
        ->and($terminal->fresh()->health_snapshot['connectivity'])->toBe('off_hours');

    Carbon::setTestNow(Carbon::parse('2026-10-12 12:05:00', config('app.timezone')));
    expect(evaluateHealth($terminal))->toBe(['offline']);
});

it('no avisa "recuperado" si nunca avisó la desconexión', function () {
    healthManager();
    $terminal = makeHealthTerminal([
        'stale_after_minutes' => 10, 'watch_from' => '12:00:00', 'watch_to' => '20:00:00',
        'last_heartbeat_at' => now()->subMinute(),
    ]);
    evaluateHealth($terminal);

    $terminal->update(['last_heartbeat_at' => now()->subHours(2)]); // fuera de horario: no se avisó
    evaluateHealth($terminal);
    $terminal->update(['last_heartbeat_at' => now()]);

    expect(evaluateHealth($terminal))->toBe([]);
});

it('avisa cuando un terminal que estaba vinculado queda sin acceso', function () {
    $manager = healthManager();
    $terminal = makeHealthTerminal(['last_heartbeat_at' => now()]);
    evaluateHealth($terminal);

    $terminal->revokeSyncTokens();

    expect(evaluateHealth($terminal))->toBe(['unlinked'])
        ->and(evaluateHealth($terminal))->toBe([]);

    Notification::assertSentToTimes($manager, TerminalHealthNotification::class, 1);
});

it('un terminal que nunca estuvo vinculado no genera aviso de "sin vincular"', function () {
    healthManager();
    $terminal = makeHealthTerminal([], linked: false);

    expect(evaluateHealth($terminal))->toBe([])->and(evaluateHealth($terminal))->toBe([]);
});

it('un terminal inactivo no se evalúa y su estado guardado se reinicia', function () {
    $manager = healthManager();
    $terminal = makeHealthTerminal(['last_heartbeat_at' => now()]);
    evaluateHealth($terminal);
    expect($terminal->fresh()->health_snapshot)->not->toBeNull();

    $terminal->update(['status' => 'inactive']);
    $terminal->revokeSyncTokens();

    expect(evaluateHealth($terminal))->toBe([])->and($terminal->fresh()->health_snapshot)->toBeNull();
    Notification::assertNotSentTo($manager, TerminalHealthNotification::class);
});

it('avisa la cola atascada una vez y no antes del umbral; la normalización queda en la bitácora sin notificar', function () {
    $settings = app(GeneralSettings::class);
    $settings->terminal_queue_stuck_minutes = 15;
    $settings->save();
    $manager = healthManager();
    $terminal = makeHealthTerminal(['last_heartbeat_at' => now(), 'last_pending_events_count' => 4, 'last_conflict_events_count' => 1, 'queue_backlog_since' => now()->subMinutes(10)]);
    evaluateHealth($terminal);

    expect(evaluateHealth($terminal))->toBe([]); // 10 min: todavía no

    $terminal->update(['queue_backlog_since' => now()->subMinutes(16), 'last_heartbeat_at' => now()]);
    expect(evaluateHealth($terminal))->toBe(['queue_stuck'])->and(evaluateHealth($terminal))->toBe([]);

    $terminal->update(['queue_backlog_since' => null, 'last_pending_events_count' => 0, 'last_conflict_events_count' => 0]);
    expect(evaluateHealth($terminal))->toBe([]);

    Notification::assertSentToTimes($manager, TerminalHealthNotification::class, 1);
    expect($terminal->events()->whereIn('type', ['health_queue_stuck', 'health_queue_recovered'])->orderBy('id')->pluck('type')->all())
        ->toBe(['health_queue_stuck', 'health_queue_recovered']);
});

it('con el terminal desconectado no se evalúa la cola (ya lo cubre el aviso de desconexión)', function () {
    healthManager();
    $terminal = makeHealthTerminal(['stale_after_minutes' => 10, 'last_heartbeat_at' => now()->subMinute(), 'queue_backlog_since' => now()->subHour(), 'last_pending_events_count' => 5]);
    evaluateHealth($terminal);

    $terminal->update(['last_heartbeat_at' => now()->subHour()]);

    expect(evaluateHealth($terminal))->toBe(['offline']);
});

it('avisa la batería baja sin cargador y aplica histéresis para dejar de estarlo', function () {
    $settings = app(GeneralSettings::class);
    $settings->terminal_low_battery_percent = 20;
    $settings->save();
    $manager = healthManager();
    $report = fn (int $level, bool $charging) => ['device_report' => ['battery_level' => $level, 'battery_charging' => $charging], 'device_report_at' => now(), 'last_heartbeat_at' => now()];

    $terminal = makeHealthTerminal($report(60, false));
    evaluateHealth($terminal);

    $terminal->update($report(19, false));
    expect(evaluateHealth($terminal))->toBe(['battery_low']);

    $terminal->update($report(22, false)); // supera el 20% pero no 20 + 5: sigue baja, sin avisar de nuevo
    expect(evaluateHealth($terminal))->toBe([])->and($terminal->fresh()->health_snapshot['low_battery'])->toBeTrue();

    $terminal->update($report(26, false));
    expect(evaluateHealth($terminal))->toBe([])->and($terminal->fresh()->health_snapshot['low_battery'])->toBeFalse();

    $terminal->update($report(10, false));
    expect(evaluateHealth($terminal))->toBe(['battery_low']); // vuelve a bajar: nuevo aviso

    Notification::assertSentToTimes($manager, TerminalHealthNotification::class, 2);
});

it('con cargador conectado o sin dato de batería no hay aviso de batería baja', function () {
    healthManager();
    $terminal = makeHealthTerminal(['device_report' => ['battery_level' => 60, 'battery_charging' => false], 'device_report_at' => now(), 'last_heartbeat_at' => now()]);
    evaluateHealth($terminal);

    $terminal->update(['device_report' => ['battery_level' => 5, 'battery_charging' => true], 'device_report_at' => now()]);
    expect(evaluateHealth($terminal))->toBe([]);

    $terminal->update(['device_report' => ['standalone' => true], 'device_report_at' => now()]); // el navegador no informa batería
    expect(evaluateHealth($terminal))->toBe([]);
});

it('un reporte de batería viejo no dispara el aviso', function () {
    healthManager();
    $terminal = makeHealthTerminal(['stale_after_minutes' => 10, 'device_report' => ['battery_level' => 60, 'battery_charging' => false], 'device_report_at' => now(), 'last_heartbeat_at' => now()]);
    evaluateHealth($terminal);

    $terminal->update(['device_report' => ['battery_level' => 3, 'battery_charging' => false], 'device_report_at' => now()->subHour(), 'last_heartbeat_at' => now()]);

    expect(evaluateHealth($terminal))->toBe([]);
});

it('un fallo al notificar no rompe la evaluación y el estado igual se guarda', function () {
    healthManager();
    $terminal = makeHealthTerminal(['stale_after_minutes' => 10, 'last_heartbeat_at' => now()->subMinute()]);
    evaluateHealth($terminal);
    $terminal->update(['last_heartbeat_at' => now()->subHour()]);

    app()->instance(Dispatcher::class, new class implements Dispatcher
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('canal caído');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new RuntimeException('canal caído');
        }
    });

    expect(evaluateHealth($terminal))->toBe(['offline'])
        ->and($terminal->fresh()->health_snapshot['offline_alerted'])->toBeTrue()
        ->and($terminal->events()->where('type', 'health_offline')->exists())->toBeTrue();
});

it('el comando evalúa todos los terminales activos y es idempotente', function () {
    $manager = healthManager();
    $terminal = makeHealthTerminal(['stale_after_minutes' => 10, 'last_heartbeat_at' => now()->subMinute()]);
    $this->artisan('terminals:evaluate-health')->assertSuccessful(); // línea de base

    $terminal->update(['last_heartbeat_at' => now()->subHour()]);
    $this->artisan('terminals:evaluate-health')->assertSuccessful()->expectsOutputToContain('1 aviso');
    $this->artisan('terminals:evaluate-health')->assertSuccessful();

    Notification::assertSentToTimes($manager, TerminalHealthNotification::class, 1);
});

it('el comando está programado cada minuto', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'terminals:evaluate-health'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
});

it('cada tipo de aviso usa el formato de la campanita y enlaza al terminal', function (string $alert, string $title) {
    $terminal = makeHealthTerminal(['stale_after_minutes' => 10, 'device_report' => ['battery_level' => 12], 'last_pending_events_count' => 2, 'last_conflict_events_count' => 1]);

    $data = (new TerminalHealthNotification($terminal, $alert))->toDatabase(healthManager());

    expect($data['format'])->toBe('filament')
        ->and($data['title'])->toBe($title)
        ->and($data['terminal_id'])->toBe($terminal->id)
        ->and($data['alert'])->toBe($alert)
        ->and(json_encode($data['actions']))->toContain((string) $terminal->id);
})->with([
    'offline' => ['offline', 'Terminal sin conexión'],
    'recovered' => ['recovered', 'Terminal reconectado'],
    'unlinked' => ['unlinked', 'Terminal sin vincular'],
    'queue_stuck' => ['queue_stuck', 'Cola de marcaciones atascada'],
    'battery_low' => ['battery_low', 'Batería baja en terminal'],
]);

// ─── Purga de la bitácora ───────────────────────────────────────────────────

it('la purga elimina los eventos más viejos que la retención configurada y conserva el resto', function () {
    $settings = app(GeneralSettings::class);
    $settings->terminal_events_retention_days = 90;
    $settings->save();
    $terminal = makeHealthTerminal();
    TerminalEvent::query()->delete();

    $old = TerminalEvent::record($terminal, 'linked');
    $old->forceFill(['created_at' => now()->subDays(91)])->save();
    $recent = TerminalEvent::record($terminal, 'revoked');
    $recent->forceFill(['created_at' => now()->subDays(89)])->save();

    $this->artisan('terminals:purge-events')->assertSuccessful()->expectsOutputToContain('1 eventos eliminados');

    expect(TerminalEvent::pluck('id')->all())->toBe([$recent->id]);
});

it('la purga con retención 0 conserva todo; --dry-run solo cuenta; --days reemplaza la configuración', function () {
    $settings = app(GeneralSettings::class);
    $settings->terminal_events_retention_days = 0;
    $settings->save();
    $terminal = makeHealthTerminal();
    TerminalEvent::query()->delete();
    $old = TerminalEvent::record($terminal, 'linked');
    $old->forceFill(['created_at' => now()->subDays(400)])->save();

    $this->artisan('terminals:purge-events')->assertSuccessful()->expectsOutputToContain('Retención ilimitada');
    expect(TerminalEvent::count())->toBe(1);

    $this->artisan('terminals:purge-events --days=30 --dry-run')->assertSuccessful()->expectsOutputToContain('Se eliminarían 1');
    expect(TerminalEvent::count())->toBe(1);

    $this->artisan('terminals:purge-events --days=30')->assertSuccessful();
    expect(TerminalEvent::count())->toBe(0);

    $this->artisan('terminals:purge-events --days=-1')->assertFailed();
});

it('la purga está programada a diario', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'terminals:purge-events'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('30 3 * * *');
});

// ─── Widget ─────────────────────────────────────────────────────────────────

it('el widget cuenta solo terminales activos por estado y enlaza al listado filtrado', function () {
    $this->actingAs(healthSuperAdmin());
    makeHealthTerminal(['last_heartbeat_at' => now()->subMinute()]);                                        // en línea
    makeHealthTerminal(['stale_after_minutes' => 10, 'last_heartbeat_at' => now()->subHour(), 'name' => 'Caído Uno']); // desconectado
    makeHealthTerminal([], linked: false);                                                                  // sin vincular
    makeHealthTerminal(['status' => 'inactive', 'last_heartbeat_at' => now()->subHour(), 'stale_after_minutes' => 10]); // inactivo: no cuenta

    $test = Livewire::test(TerminalHealthWidget::class);

    $test->assertSee('Terminales en línea')->assertSee('de 3 activos')->assertSee('Caído Uno')->assertSee('Sin vincular');

    $stats = (fn () => $this->getStats())->call($test->instance());
    expect($stats[0]->getValue())->toBe(1)
        ->and($stats[1]->getValue())->toBe(1)
        ->and($stats[2]->getValue())->toBe(1)
        ->and($stats[1]->getUrl())->toContain('connectivity_status');
});

it('el widget solo lo ve quien puede ver terminales', function () {
    $this->actingAs(User::factory()->create());
    expect(TerminalHealthWidget::canView())->toBeFalse();

    $this->actingAs(healthSuperAdmin());
    expect(TerminalHealthWidget::canView())->toBeTrue();
});

// ─── Formulario y detalle ───────────────────────────────────────────────────

it('el formulario valida el umbral mínimo y que desde/hasta vayan juntos', function () {
    $this->actingAs(healthSuperAdmin());
    $terminal = makeHealthTerminal();

    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->fillForm(['stale_after_minutes' => 3])
        ->call('save')
        ->assertHasFormErrors(['stale_after_minutes']);

    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->fillForm(['stale_after_minutes' => 10, 'watch_from' => '08:00', 'watch_to' => null])
        ->call('save')
        ->assertHasFormErrors(['watch_to']);

    Livewire::test(EditTerminal::class, ['record' => $terminal->getKey()])
        ->fillForm(['stale_after_minutes' => 10, 'watch_days' => [1, 2, 3], 'watch_from' => '08:00', 'watch_to' => '20:00'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $terminal->fresh();
    expect($fresh->stale_after_minutes)->toBe(10)
        ->and(array_map('intval', $fresh->watch_days))->toBe([1, 2, 3])
        ->and($fresh->watchScheduleLabel())->toBe('Lun, Mar, Mié · 08:00–20:00');
});

it('el detalle muestra el estado del dispositivo informado, con avisos de versión vieja y reloj desajustado', function () {
    $this->actingAs(healthSuperAdmin());
    $terminal = makeHealthTerminal([
        'last_heartbeat_at' => now()->subMinute(),
        'device_report' => [
            'app_version' => 'vieja', 'standalone' => false, 'sw_active' => false, 'battery_level' => 35, 'battery_charging' => false,
            'camera' => 'denied', 'cached_employees' => 12, 'clock_skew_seconds' => 125, 'storage_used_mb' => 40, 'storage_quota_mb' => 1000,
        ],
        'device_report_at' => now()->subMinute(),
    ]);

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])
        ->assertSee('Estado del dispositivo')
        ->assertSee('desactualizada')
        ->assertSee('En el navegador (sin instalar)')
        ->assertSee('sin service worker')
        ->assertSee('35% · sin cargador')
        ->assertSee('Bloqueada')
        ->assertSee('+125 s')
        ->assertSee('desajustado')
        ->assertSee('40 MB de 1000 MB')
        ->assertSee('Siempre (24/7)');
});

it('sin reporte del dispositivo el detalle no muestra la sección de estado', function () {
    $this->actingAs(healthSuperAdmin());
    $terminal = makeHealthTerminal();

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])->assertDontSee('Estado del dispositivo');
});

it('Configuración General trae los umbrales de alertas y retención con sus valores por defecto', function () {
    $settings = app(GeneralSettings::class);

    expect($settings->terminal_queue_stuck_minutes)->toBe(15)
        ->and($settings->terminal_low_battery_percent)->toBe(20)
        ->and($settings->terminal_events_retention_days)->toBe(90);
});
