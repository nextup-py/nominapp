<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\User;
use App\Notifications\TerminalHealthNotification;
use App\Services\TerminalAttentionBanner;
use App\Services\TerminalHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/** Punto de la sucursal; los desplazamientos de abajo se miden respecto de él (0,001° de latitud ≈ 111 m). */
const BRANCH_LAT = -25.5000;
const BRANCH_LNG = -54.6000;

/** Terminal vinculado de una sucursal con coordenadas (radio de 150 m por defecto). */
function locationTerminal(bool $mobile = true, array $branch = []): Terminal
{
    static $n = 6800000;
    $n++;

    $company = Company::create(['name' => "EmpLoc {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branchModel = Branch::create(['name' => "SucLoc {$n}", 'company_id' => $company->id, 'coordinates' => ['lat' => BRANCH_LAT, 'lng' => BRANCH_LNG], ...$branch]);
    $terminal = Terminal::create(['name' => "Celular {$n}", 'branch_id' => $branchModel->id, 'is_mobile' => $mobile, 'last_heartbeat_at' => now()]);
    $terminal->claimSanctumToken();

    return $terminal->fresh();
}

/** Guarda una lectura de ubicación a `$offsetM` metros al norte de la sucursal, con la precisión y la hora dadas. */
function readLocation(Terminal $terminal, int $offsetM, int $accuracy = 15): void
{
    $terminal->update([
        'last_heartbeat_at' => now(),
        'device_report' => ['location' => ['lat' => BRANCH_LAT + $offsetM / 111_195, 'lng' => BRANCH_LNG, 'accuracy_m' => $accuracy, 'read_at' => now()->toIso8601String()]],
        'device_report_at' => now(),
    ]);
}

function evaluateLocationHealth(Terminal $terminal): array
{
    return app(TerminalHealthService::class)->evaluate($terminal->fresh());
}

beforeEach(function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00', config('app.timezone')));
});

afterEach(fn () => Carbon::setTestNow());

it('calcula la distancia a la sucursal y el radio (el de la sucursal o el por defecto)', function () {
    $terminal = locationTerminal();
    readLocation($terminal, 100);

    $status = $terminal->fresh()->locationStatus();

    expect($status['distance_m'])->toBeBetween(98, 102)
        ->and($status['radius_m'])->toBe(150)
        ->and($status['outside'])->toBeFalse();

    $custom = locationTerminal(branch: ['location_radius_m' => 50]);
    readLocation($custom, 100);

    expect($custom->fresh()->locationStatus())->toMatchArray(['radius_m' => 50, 'outside' => true]);
});

it('no hay estado de ubicación para un terminal fijo ni para una sucursal sin coordenadas', function () {
    $fixed = locationTerminal(mobile: false);
    readLocation($fixed, 500);
    expect($fixed->fresh()->locationStatus())->toBeNull();

    $noCoordinates = locationTerminal();
    $noCoordinates->branch->update(['coordinates' => null]);
    readLocation($noCoordinates, 500);
    expect($noCoordinates->fresh()->locationStatus())->toBeNull();
});

it('el latido guarda la ubicación solo de los equipos móviles y solo la última lectura', function () {
    $mobile = locationTerminal();
    $fixed = locationTerminal(mobile: false);
    $payload = ['device' => ['battery_level' => 80, 'location' => ['lat' => -25.5, 'lng' => -54.6, 'accuracy_m' => 20, 'read_at' => '2026-11-17T09:59:00Z', 'extra' => 'x'], 'location_error' => 'timeout']];

    Sanctum::actingAs($mobile, [Terminal::SYNC_ABILITY]);
    $this->postJson('/api/v1/terminal/heartbeat', $payload)->assertOk()->assertJsonPath('config.location_check', true);

    Sanctum::actingAs($fixed, [Terminal::SYNC_ABILITY]);
    $this->postJson('/api/v1/terminal/heartbeat', $payload)->assertOk()->assertJsonPath('config.location_check', false);

    expect($mobile->fresh()->device_report['location'])->toEqual(['lat' => -25.5, 'lng' => -54.6, 'accuracy_m' => 20, 'read_at' => '2026-11-17T09:59:00Z'])
        ->and($mobile->fresh()->device_report['location_error'])->toBe('timeout')
        ->and($fixed->fresh()->device_report)->not->toHaveKey('location')
        ->and($fixed->fresh()->device_report)->not->toHaveKey('location_error');
});

it('avisa con dos lecturas seguidas fuera del radio, una sola vez, y registra cuando vuelve', function () {
    $manager = tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo(Permission::firstOrCreate(['name' => 'update_terminal', 'guard_name' => 'web'])));
    $terminal = locationTerminal();

    readLocation($terminal, 20);
    evaluateLocationHealth($terminal); // línea de base: adentro

    Carbon::setTestNow(now()->addMinutes(10));
    readLocation($terminal, 400);
    expect(evaluateLocationHealth($terminal))->toBe([]);                 // primera lectura fuera: todavía no avisa
    expect(evaluateLocationHealth($terminal))->toBe([]);                 // el mismo latido repetido no cuenta como otra lectura

    Carbon::setTestNow(now()->addMinutes(10));
    readLocation($terminal, 420);
    expect(evaluateLocationHealth($terminal))->toBe(['outside_branch']);

    Carbon::setTestNow(now()->addMinutes(10));
    readLocation($terminal, 430);
    expect(evaluateLocationHealth($terminal))->toBe([]);                 // no repite

    Notification::assertSentToTimes($manager, TerminalHealthNotification::class, 1);
    Notification::assertSentTo($manager, TerminalHealthNotification::class, fn (TerminalHealthNotification $n) => $n->alert === 'outside_branch');

    Carbon::setTestNow(now()->addMinutes(10));
    readLocation($terminal, 30);
    evaluateLocationHealth($terminal);

    expect($terminal->events()->whereIn('type', ['health_outside_branch', 'health_back_in_branch'])->orderBy('id')->pluck('type')->all())
        ->toBe(['health_outside_branch', 'health_back_in_branch']);
});

it('una lectura imprecisa (peor que el radio) no cuenta y una lectura dentro reinicia la cuenta', function () {
    locationTerminalWithManager();
    $terminal = locationTerminal();

    readLocation($terminal, 20);
    evaluateLocationHealth($terminal);

    Carbon::setTestNow(now()->addMinutes(10));
    readLocation($terminal, 400);
    evaluateLocationHealth($terminal);                                    // 1 fuera

    Carbon::setTestNow(now()->addMinutes(10));
    readLocation($terminal, 450, accuracy: 900);                          // imprecisa: ni suma ni reinicia
    evaluateLocationHealth($terminal);

    Carbon::setTestNow(now()->addMinutes(10));
    readLocation($terminal, 40);                                          // adentro: reinicia
    evaluateLocationHealth($terminal);

    Carbon::setTestNow(now()->addMinutes(10));
    readLocation($terminal, 400);
    expect(evaluateLocationHealth($terminal))->toBe([]);                  // vuelve a ser la primera fuera

    expect($terminal->fresh()->health_snapshot['outside'])->toBeFalse();
});

it('un equipo fijo nunca avisa por ubicación', function () {
    locationTerminalWithManager();
    $terminal = locationTerminal(mobile: false);

    readLocation($terminal, 20);
    evaluateLocationHealth($terminal);

    foreach ([400, 420, 450] as $offset) {
        Carbon::setTestNow(now()->addMinutes(10));
        readLocation($terminal, $offset);
        expect(evaluateLocationHealth($terminal))->toBe([]);
    }
});

it('el cartel del panel incluye al equipo móvil que salió de la sucursal', function () {
    locationTerminalWithManager();
    $terminal = locationTerminal();
    readLocation($terminal, 20);
    evaluateLocationHealth($terminal);

    foreach ([400, 420] as $offset) {
        Carbon::setTestNow(now()->addMinutes(10));
        readLocation($terminal, $offset);
        evaluateLocationHealth($terminal);
    }

    TerminalAttentionBanner::forget();

    expect(collect(TerminalAttentionBanner::items())->pluck('status', 'id')->all())->toBe([$terminal->id => 'outside']);
});

it('el detalle recomienda volver a la sucursal o permitir la ubicación', function () {
    $terminal = locationTerminal();
    readLocation($terminal, 600);

    expect(collect($terminal->fresh()->recommendations())->pluck('title')->all())->toContain('Volver a la sucursal');

    $terminal->update(['device_report' => ['location_error' => 'denied']]);

    expect(collect($terminal->fresh()->recommendations())->pluck('title')->all())->toContain('Permitir la ubicación');
});

/** Alguien que reciba los avisos, para que `notify()` tenga a quién llegar. */
function locationTerminalWithManager(): User
{
    return tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo(Permission::firstOrCreate(['name' => 'update_terminal', 'guard_name' => 'web'])));
}
