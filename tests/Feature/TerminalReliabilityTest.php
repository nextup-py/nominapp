<?php

use App\Exports\TerminalReliabilityExport;
use App\Filament\Resources\TerminalResource\Pages\ViewTerminal;
use App\Filament\Resources\TerminalResource\RelationManagers\ReliabilityRelationManager;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AttendanceMarkFailure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Terminal;
use App\Models\TerminalDailyStat;
use App\Models\User;
use App\Notifications\TerminalLowAvailabilityNotification;
use App\Services\TerminalHealthService;
use App\Services\TerminalReliabilityService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Confiabilidad de los terminales en el tiempo: disponibilidad y cortes (desde la evaluación de salud cada minuto),
 * identificaciones (desde el latido del dispositivo) y marcaciones/conflictos (desde sus tablas de origen).
 */
function reliabilityTerminal(array $attributes = []): Terminal
{
    static $n = 9950000;
    $n++;

    $company = Company::create(['name' => "EmpRel {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucRel {$n}", 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => "Terminal Rel {$n}", 'branch_id' => $branch->id, 'stale_after_minutes' => 5, ...$attributes]);
    $terminal->claimSanctumToken();

    return $terminal->fresh();
}

/** Muestra un minuto del terminal en el estado de conectividad dado y devuelve el `health_snapshot` resultante. */
function sampleMinute(Terminal $terminal, string $connectivity, array $snapshot = []): array
{
    app(TerminalReliabilityService::class)->sample($terminal, $connectivity, $snapshot);

    return $snapshot;
}

function reliabilityStat(Terminal $terminal, ?string $day = null): TerminalDailyStat
{
    return TerminalDailyStat::where('terminal_id', $terminal->id)->where('date', $day ?? today()->toDateString())->firstOrFail();
}

function reliabilityManager(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::firstOrCreate(['name' => 'update_terminal', 'guard_name' => 'web']));

    return $user;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', config('app.timezone'))); // lunes
});

afterEach(fn () => Carbon::setTestNow());

// ─── Disponibilidad y cortes ────────────────────────────────────────────────

it('cuenta los minutos en línea y los esperados dentro del horario', function () {
    $terminal = reliabilityTerminal();

    foreach (['online', 'online', 'online', 'stale'] as $connectivity) {
        sampleMinute($terminal, $connectivity);
    }

    $stat = reliabilityStat($terminal);

    expect($stat->expected_minutes)->toBe(4)
        ->and($stat->online_minutes)->toBe(3)
        ->and($stat->availabilityPercent())->toBe(75.0);
});

it('no mide los minutos sin vincular, fuera de horario ni sin conexión previa', function (string $connectivity) {
    $terminal = reliabilityTerminal();

    sampleMinute($terminal, $connectivity);

    expect(TerminalDailyStat::where('terminal_id', $terminal->id)->count())->toBe(0);
})->with(['unlinked', 'off_hours', 'never_connected']);

it('un corte se cuenta con su duración cuando la racha termina', function () {
    $terminal = reliabilityTerminal();
    $snapshot = [];

    foreach (['online', 'stale', 'stale', 'stale', 'online'] as $connectivity) {
        $snapshot = sampleMinute($terminal, $connectivity, $snapshot);
    }

    $stat = reliabilityStat($terminal);

    expect($stat->outages_count)->toBe(1)
        ->and($stat->outage_minutes)->toBe(3)
        ->and($stat->longest_outage_minutes)->toBe(3)
        ->and($snapshot['outage_run'])->toBe(0);
});

it('dos cortes del mismo día suman y el más largo es el mayor', function () {
    $terminal = reliabilityTerminal();
    $snapshot = [];

    foreach (['stale', 'stale', 'online', 'stale', 'stale', 'stale', 'stale', 'online'] as $connectivity) {
        $snapshot = sampleMinute($terminal, $connectivity, $snapshot);
    }

    $stat = reliabilityStat($terminal);

    expect($stat->outages_count)->toBe(2)
        ->and($stat->outage_minutes)->toBe(6)
        ->and($stat->longest_outage_minutes)->toBe(4);
});

it('un corte que sigue abierto no se cuenta todavía pero se acumula en la racha', function () {
    $terminal = reliabilityTerminal();
    $snapshot = [];

    foreach (['stale', 'stale'] as $connectivity) {
        $snapshot = sampleMinute($terminal, $connectivity, $snapshot);
    }

    expect(reliabilityStat($terminal)->outages_count)->toBe(0)
        ->and($snapshot['outage_run'])->toBe(2);
});

it('al salir del horario de vigilancia con un corte abierto, el corte se cierra', function () {
    $terminal = reliabilityTerminal();
    $snapshot = [];

    foreach (['stale', 'stale', 'off_hours'] as $connectivity) {
        $snapshot = sampleMinute($terminal, $connectivity, $snapshot);
    }

    $stat = reliabilityStat($terminal);

    expect($stat->outages_count)->toBe(1)
        ->and($stat->longest_outage_minutes)->toBe(2)
        ->and($snapshot['outage_run'])->toBe(0);
});

it('la evaluación de salud alimenta el resumen y guarda la racha en el snapshot', function () {
    Notification::fake();
    $terminal = reliabilityTerminal();
    $terminal->recordHeartbeat(0, 0);

    app(TerminalHealthService::class)->evaluate($terminal->fresh());

    $terminal->update(['last_heartbeat_at' => now()->subMinutes(30)]);
    app(TerminalHealthService::class)->evaluate($terminal->fresh());

    $stat = reliabilityStat($terminal);

    expect($stat->expected_minutes)->toBe(2)
        ->and($stat->online_minutes)->toBe(1)
        ->and($terminal->fresh()->health_snapshot['outage_run'])->toBe(1);
});

it('los terminales inactivos no se miden', function () {
    Notification::fake();
    $terminal = reliabilityTerminal(['status' => 'inactive']);

    app(TerminalHealthService::class)->evaluate($terminal->fresh());

    expect(TerminalDailyStat::count())->toBe(0);
});

// ─── Identificación ─────────────────────────────────────────────────────────

it('suma la diferencia entre reportes y no cuenta dos veces lo ya informado', function () {
    $terminal = reliabilityTerminal();
    $service = app(TerminalReliabilityService::class);

    $first = ['identification_attempts' => 10, 'identification_failures' => 2, 'identification_successes' => 8, 'identification_ms_total' => 16000];
    $second = ['identification_attempts' => 15, 'identification_failures' => 3, 'identification_successes' => 12, 'identification_ms_total' => 26000];

    $service->recordIdentification($terminal, null, $first);
    $service->recordIdentification($terminal, $first, $second);
    $service->recordIdentification($terminal, $second, $second);

    $stat = reliabilityStat($terminal);

    expect($stat->identification_attempts)->toBe(15)
        ->and($stat->identification_failures)->toBe(3)
        ->and($stat->identification_successes)->toBe(12)
        ->and($stat->identification_ms_total)->toBe(26000)
        ->and($stat->identificationFailurePercent())->toBe(20.0)
        ->and($stat->averageIdentificationMs())->toBe(2167);
});

it('si los contadores bajan (la página se recargó) todo lo informado es nuevo', function () {
    $terminal = reliabilityTerminal();
    $service = app(TerminalReliabilityService::class);

    $before = ['identification_attempts' => 50, 'identification_failures' => 5, 'identification_successes' => 45, 'identification_ms_total' => 90000];
    $reloaded = ['identification_attempts' => 4, 'identification_failures' => 1, 'identification_successes' => 3, 'identification_ms_total' => 6000];

    $service->recordIdentification($terminal, $before, $reloaded);

    $stat = reliabilityStat($terminal);

    expect($stat->identification_attempts)->toBe(4)->and($stat->identification_failures)->toBe(1);
});

it('un reporte sin contadores no crea resumen', function () {
    $terminal = reliabilityTerminal();

    app(TerminalReliabilityService::class)->recordIdentification($terminal, null, ['battery_level' => 80]);

    expect(TerminalDailyStat::count())->toBe(0);
});

it('el latido del dispositivo registra las identificaciones y valida los contadores', function () {
    $terminal = reliabilityTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $report = ['identification_attempts' => 6, 'identification_failures' => 1, 'identification_successes' => 5, 'identification_ms_total' => 9000];

    $this->postJson('/api/v1/terminal/heartbeat', ['device' => $report])->assertOk();
    $this->postJson('/api/v1/terminal/heartbeat', ['device' => [...$report, 'identification_attempts' => 9, 'identification_successes' => 8, 'identification_ms_total' => 14000]])->assertOk();

    $stat = reliabilityStat($terminal);

    expect($stat->identification_attempts)->toBe(9)->and($stat->identification_successes)->toBe(8);

    $this->postJson('/api/v1/terminal/heartbeat', ['device' => ['identification_attempts' => -1]])->assertUnprocessable();
});

// ─── Resumen y marcaciones/conflictos ───────────────────────────────────────

function reliabilityMark(Terminal $terminal, Carbon $at): void
{
    static $n = 9960000;
    $n++;

    $employee = Employee::create([
        'first_name' => 'Rel', 'last_name' => (string) $n, 'ci' => (string) $n, 'email' => "rel{$n}@test.com",
        'branch_id' => $terminal->branch_id, 'status' => 'active',
    ]);
    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => $at->toDateString(), 'status' => 'present',
        'expected_check_in' => '07:00:00', 'expected_check_out' => '15:00:00', 'expected_hours' => 8.0,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false, 'on_vacation' => false, 'justified_absence' => false,
    ]);

    AttendanceEvent::create([
        'attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => 'check_in',
        'recorded_at' => $at, 'terminal_id' => $terminal->id,
    ]);
}

function reliabilityConflict(Terminal $terminal, Carbon $at): void
{
    AttendanceMarkFailure::create([
        'mode' => 'terminal', 'failure_type' => 'sync_conflict', 'branch_id' => $terminal->branch_id,
        'attempted_event_type' => 'break_start', 'failure_message' => 'conflicto de prueba',
        'metadata' => ['terminal_id' => $terminal->id], 'occurred_at' => $at,
    ]);
}

it('el resumen del rango junta disponibilidad, cortes, marcaciones y conflictos de ese terminal', function () {
    $terminal = reliabilityTerminal();
    $other = reliabilityTerminal();

    TerminalDailyStat::create(['terminal_id' => $terminal->id, 'date' => '2026-10-10', 'expected_minutes' => 600, 'online_minutes' => 540, 'outages_count' => 2, 'outage_minutes' => 60, 'longest_outage_minutes' => 45]);
    TerminalDailyStat::create(['terminal_id' => $terminal->id, 'date' => '2026-10-11', 'expected_minutes' => 600, 'online_minutes' => 600, 'longest_outage_minutes' => 0]);
    TerminalDailyStat::create(['terminal_id' => $terminal->id, 'date' => '2026-09-01', 'expected_minutes' => 600, 'online_minutes' => 0]);
    TerminalDailyStat::create(['terminal_id' => $other->id, 'date' => '2026-10-10', 'expected_minutes' => 600, 'online_minutes' => 0]);

    reliabilityMark($terminal, Carbon::parse('2026-10-10 08:00:00'));
    reliabilityMark($terminal, Carbon::parse('2026-10-11 08:05:00'));
    reliabilityMark($terminal, Carbon::parse('2026-10-20 08:05:00')); // fuera del rango
    reliabilityMark($other, Carbon::parse('2026-10-10 08:10:00')); // otro terminal
    reliabilityConflict($terminal, Carbon::parse('2026-10-10 12:00:00'));
    reliabilityConflict($other, Carbon::parse('2026-10-10 12:00:00'));

    $summary = app(TerminalReliabilityService::class)->summary($terminal, Carbon::parse('2026-10-10'), Carbon::parse('2026-10-11'));

    expect($summary['availability'])->toBe(95.0)
        ->and($summary['outages'])->toBe(2)
        ->and($summary['outage_minutes'])->toBe(60)
        ->and($summary['longest_outage_minutes'])->toBe(45)
        ->and($summary['marks'])->toBe(2)
        ->and($summary['conflicts'])->toBe(1);
});

it('un rango sin datos no inventa porcentajes', function () {
    $summary = app(TerminalReliabilityService::class)->summary(reliabilityTerminal(), Carbon::parse('2026-10-10'), Carbon::parse('2026-10-11'));

    expect($summary['availability'])->toBeNull()
        ->and($summary['identification_failure_percent'])->toBeNull()
        ->and($summary['average_identification_ms'])->toBeNull()
        ->and($summary['marks'])->toBe(0);
});

// ─── Pestaña del detalle ────────────────────────────────────────────────────

it('la pestaña muestra los días del período con su disponibilidad y filtra por rango', function () {
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = reliabilityTerminal();

    $dentro = TerminalDailyStat::create(['terminal_id' => $terminal->id, 'date' => '2026-10-10', 'expected_minutes' => 600, 'online_minutes' => 540]);
    $fuera = TerminalDailyStat::create(['terminal_id' => $terminal->id, 'date' => '2026-07-01', 'expected_minutes' => 600, 'online_minutes' => 600]);
    reliabilityMark($terminal, Carbon::parse('2026-10-10 08:00:00'));

    Livewire::test(ReliabilityRelationManager::class, ['ownerRecord' => $terminal, 'pageClass' => ViewTerminal::class])
        ->assertCanSeeTableRecords([$dentro])
        ->assertCanNotSeeTableRecords([$fuera])
        ->assertSee('90%')
        ->assertSee('disponibilidad 90%')
        ->assertSee('1 marcaciones')
        ->filterTable('range', ['from' => '2026-06-25', 'until' => '2026-07-05'])
        ->assertCanSeeTableRecords([$fuera])
        ->assertCanNotSeeTableRecords([$dentro]);
});

it('el resumen avisa si el período elegido es inválido', function () {
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $terminal = reliabilityTerminal();

    Livewire::test(ReliabilityRelationManager::class, ['ownerRecord' => $terminal, 'pageClass' => ViewTerminal::class])
        ->filterTable('range', ['from' => '2026-10-20', 'until' => '2026-10-10'])
        ->assertSee('El período elegido es inválido');
});

it('las duraciones se muestran legibles', function (int $minutes, string $expected) {
    expect(ReliabilityRelationManager::duration($minutes))->toBe($expected);
})->with([[0, '0 min'], [45, '45 min'], [60, '1 h'], [80, '1 h 20 min'], [125, '2 h 5 min']]);

// ─── Excel ──────────────────────────────────────────────────────────────────

it('el Excel trae una fila por terminal con sus métricas del rango', function () {
    $terminal = reliabilityTerminal(['responsible_name' => 'Richard Sánchez', 'responsible_phone' => '0981123456']);
    $sin = reliabilityTerminal();

    TerminalDailyStat::create([
        'terminal_id' => $terminal->id, 'date' => '2026-10-10', 'expected_minutes' => 600, 'online_minutes' => 570, 'outages_count' => 1,
        'outage_minutes' => 30, 'longest_outage_minutes' => 30, 'identification_attempts' => 10, 'identification_failures' => 2,
        'identification_successes' => 8, 'identification_ms_total' => 16000,
    ]);

    $export = new TerminalReliabilityExport(Carbon::parse('2026-10-10'), Carbon::parse('2026-10-11'));
    $rows = $export->collection()->keyBy(fn ($row) => $row['terminal']->id);

    $mapped = $export->map($rows[$terminal->id]);
    $empty = $export->map($rows[$sin->id]);

    expect($export->collection())->toHaveCount(2)
        ->and($mapped[4])->toBe('Richard Sánchez (0981123456)')
        ->and($mapped[5])->toBe(95.0)
        ->and($mapped[8])->toBe(1)
        ->and($mapped[14])->toBe(20.0)
        ->and($mapped[15])->toBe(2.0)
        ->and($empty[5])->toBe('Sin datos')
        ->and(count($mapped))->toBe(count($export->headings()));
});

it('el Excel respeta la lista de terminales pedida', function () {
    $a = reliabilityTerminal();
    reliabilityTerminal();

    $export = new TerminalReliabilityExport(Carbon::parse('2026-10-10'), Carbon::parse('2026-10-11'), [$a->id]);

    expect($export->collection())->toHaveCount(1);
});

// ─── Aviso semanal de baja disponibilidad ───────────────────────────────────

function setAvailabilityThreshold(int $percent): void
{
    $settings = app(GeneralSettings::class);
    $settings->terminal_min_availability_percent = $percent;
    $settings->save();
}

it('avisa de los terminales por debajo del mínimo en la semana anterior', function () {
    Notification::fake();
    setAvailabilityThreshold(90);
    $manager = reliabilityManager();
    $bajo = reliabilityTerminal();
    $bien = reliabilityTerminal();

    TerminalDailyStat::create(['terminal_id' => $bajo->id, 'date' => '2026-10-08', 'expected_minutes' => 600, 'online_minutes' => 420, 'outages_count' => 3, 'longest_outage_minutes' => 90]);
    TerminalDailyStat::create(['terminal_id' => $bien->id, 'date' => '2026-10-08', 'expected_minutes' => 600, 'online_minutes' => 590]);

    $this->artisan('terminals:evaluate-availability')->assertSuccessful();

    Notification::assertSentTo($manager, TerminalLowAvailabilityNotification::class, function (TerminalLowAvailabilityNotification $notification) use ($bajo) {
        $body = $notification->toDatabase(new User)['body'];

        return $notification->rows->pluck('terminal.id')->all() === [$bajo->id]
            && str_contains($body, '70%') && str_contains($body, '3 cortes') && str_contains($body, '1 h 30 min');
    });
});

it('no avisa con el mínimo en 0, sin terminales bajos ni con una muestra insignificante', function () {
    Notification::fake();
    $manager = reliabilityManager();
    $terminal = reliabilityTerminal();

    TerminalDailyStat::create(['terminal_id' => $terminal->id, 'date' => '2026-10-08', 'expected_minutes' => 600, 'online_minutes' => 0]);

    setAvailabilityThreshold(0);
    $this->artisan('terminals:evaluate-availability')->assertSuccessful();

    setAvailabilityThreshold(90);
    TerminalDailyStat::query()->update(['expected_minutes' => 30, 'online_minutes' => 0]);
    $this->artisan('terminals:evaluate-availability')->assertSuccessful();

    TerminalDailyStat::query()->update(['expected_minutes' => 600, 'online_minutes' => 600]);
    $this->artisan('terminals:evaluate-availability')->assertSuccessful();

    Notification::assertNotSentTo($manager, TerminalLowAvailabilityNotification::class);
});

it('no avisa de un terminal inactivo ni de días fuera de la semana evaluada', function () {
    Notification::fake();
    setAvailabilityThreshold(90);
    $manager = reliabilityManager();
    $inactivo = reliabilityTerminal(['status' => 'inactive']);
    $viejo = reliabilityTerminal();

    TerminalDailyStat::create(['terminal_id' => $inactivo->id, 'date' => '2026-10-08', 'expected_minutes' => 600, 'online_minutes' => 0]);
    TerminalDailyStat::create(['terminal_id' => $viejo->id, 'date' => '2026-09-20', 'expected_minutes' => 600, 'online_minutes' => 0]);

    $this->artisan('terminals:evaluate-availability')->assertSuccessful();

    Notification::assertNotSentTo($manager, TerminalLowAvailabilityNotification::class);
});

// ─── Retención ──────────────────────────────────────────────────────────────

it('purga los resúmenes más viejos que la retención y conserva el resto', function () {
    $terminal = reliabilityTerminal();

    foreach (['2026-06-01', '2026-07-01', '2026-10-01'] as $date) {
        TerminalDailyStat::create(['terminal_id' => $terminal->id, 'date' => $date]);
    }

    $this->artisan('terminals:purge-stats', ['--days' => 90, '--dry-run' => true])->assertSuccessful();
    expect(TerminalDailyStat::count())->toBe(3);

    $this->artisan('terminals:purge-stats', ['--days' => 90])->assertSuccessful();
    expect(TerminalDailyStat::pluck('date')->map->toDateString()->all())->toBe(['2026-10-01']);
});

it('con retención 0 no purga nada y rechaza una negativa', function () {
    $terminal = reliabilityTerminal();
    TerminalDailyStat::create(['terminal_id' => $terminal->id, 'date' => '2020-01-01']);

    $this->artisan('terminals:purge-stats', ['--days' => 0])->assertSuccessful();
    expect(TerminalDailyStat::count())->toBe(1);

    $this->artisan('terminals:purge-stats', ['--days' => -5])->assertFailed();
});

it('un terminal eliminado se lleva su resumen', function () {
    $terminal = reliabilityTerminal();
    TerminalDailyStat::create(['terminal_id' => $terminal->id, 'date' => '2026-10-01']);

    $terminal->tokens()->delete();
    $terminal->delete();

    expect(TerminalDailyStat::count())->toBe(0);
});
