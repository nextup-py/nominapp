<?php

use App\Filament\Actions\TerminalMonitoringActions;
use App\Filament\Resources\TerminalResource\Pages\ListTerminals;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Terminal;
use App\Models\TerminalEvent;
use App\Models\User;
use App\Notifications\TerminalHealthNotification;
use App\Services\TerminalHealthService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function monitoringTerminal(array $attributes = []): Terminal
{
    static $n = 9400000;
    $n++;

    $company = Company::create(['name' => "EmpMA {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucMA {$n}", 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => "Terminal MA {$n}", 'branch_id' => $branch->id, ...$attributes]);
    $terminal->claimSanctumToken();

    return $terminal->fresh();
}

function monitoringEmployee(Terminal $terminal, bool $withFace = true): Employee
{
    static $ci = 9500000;
    $ci++;

    return Employee::create([
        'first_name' => 'Monitoreo', 'last_name' => "Prueba{$ci}", 'ci' => (string) $ci, 'email' => "ma{$ci}@test.com",
        'branch_id' => $terminal->branch_id, 'status' => 'active', 'face_descriptor' => $withFace ? array_fill(0, 128, 0.1) : null,
    ]);
}

/** Terminal en línea con un reporte de dispositivo fresco. */
function monitoringReport(Terminal $terminal, array $device): void
{
    $terminal->update(['last_heartbeat_at' => now(), 'device_report' => $device, 'device_report_at' => now()]);
}

function monitoringEvaluate(Terminal $terminal): array
{
    return app(TerminalHealthService::class)->evaluate($terminal->fresh());
}

beforeEach(function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', config('app.timezone')));
    $settings = app(GeneralSettings::class);
    $settings->terminal_clock_skew_max_seconds = 120;
    $settings->save();
    User::factory()->create()->givePermissionTo(Permission::firstOrCreate(['name' => 'update_terminal', 'guard_name' => 'web']));
});

afterEach(fn () => Carbon::setTestNow());

it('avisa una sola vez cuando la sucursal queda sin empleados enrolados y registra cuando se normaliza', function () {
    $terminal = monitoringTerminal();
    $employee = monitoringEmployee($terminal);
    monitoringReport($terminal, ['cached_employees' => 1]);

    expect(monitoringEvaluate($terminal))->toBe([]); // línea base: sin avisos

    $employee->update(['face_descriptor' => null]);

    expect(monitoringEvaluate($terminal))->toBe(['no_enrolled'])
        ->and(monitoringEvaluate($terminal))->toBe([]);

    Notification::assertSentTo(User::permission('update_terminal')->get(), TerminalHealthNotification::class, fn ($n) => $n->alert === 'no_enrolled');

    $employee->update(['face_descriptor' => array_fill(0, 128, 0.2)]);

    expect(monitoringEvaluate($terminal))->toBe([])
        ->and(TerminalEvent::where('terminal_id', $terminal->id)->where('type', 'health_enrolled_ok')->exists())->toBeTrue();
});

it('avisa caché vacía solo con el terminal en línea, reporte fresco y empleados enrolados', function () {
    $terminal = monitoringTerminal();
    monitoringEmployee($terminal);
    monitoringReport($terminal, ['cached_employees' => 3]);

    expect(monitoringEvaluate($terminal))->toBe([]);

    monitoringReport($terminal, ['cached_employees' => 0]);

    expect(monitoringEvaluate($terminal))->toBe(['cache_empty'])
        ->and(monitoringEvaluate($terminal))->toBe([]);

    monitoringReport($terminal, ['cached_employees' => 3]);

    expect(monitoringEvaluate($terminal))->toBe([])
        ->and(TerminalEvent::where('terminal_id', $terminal->id)->where('type', 'health_cache_ok')->exists())->toBeTrue();
});

it('no avisa caché vacía si la sucursal no tiene empleados enrolados (ya lo cubre el otro aviso)', function () {
    $terminal = monitoringTerminal();
    $employee = monitoringEmployee($terminal);
    monitoringReport($terminal, ['cached_employees' => 1]);
    monitoringEvaluate($terminal);

    $employee->update(['face_descriptor' => null]);
    monitoringReport($terminal, ['cached_employees' => 0]);

    expect(monitoringEvaluate($terminal))->toBe(['no_enrolled']);
});

it('no avisa caché vacía con el reporte vencido', function () {
    $terminal = monitoringTerminal();
    monitoringEmployee($terminal);
    monitoringReport($terminal, ['cached_employees' => 3]);
    monitoringEvaluate($terminal);

    $terminal->update(['device_report' => ['cached_employees' => 0], 'device_report_at' => now()->subHours(5), 'last_heartbeat_at' => now()]);

    expect(monitoringEvaluate($terminal))->toBe([]);
});

it('avisa el reloj desajustado solo tras varios reportes seguidos y se recupera', function () {
    $terminal = monitoringTerminal();
    monitoringEmployee($terminal);
    monitoringReport($terminal, ['cached_employees' => 1, 'clock_skew_seconds' => 2]);
    monitoringEvaluate($terminal);

    $report = function (int $seconds, int $minutesLater) use ($terminal) {
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', config('app.timezone'))->addMinutes($minutesLater));
        monitoringReport($terminal, ['cached_employees' => 1, 'clock_skew_seconds' => $seconds]);

        return monitoringEvaluate($terminal);
    };

    expect($report(149, 1))->toBe([])
        ->and($report(150, 2))->toBe([])
        ->and($report(149, 3))->toBe(['clock_skew'])
        ->and($report(149, 4))->toBe([]);

    expect($report(3, 5))->toBe([])
        ->and(TerminalEvent::where('terminal_id', $terminal->id)->where('type', 'health_clock_ok')->exists())->toBeTrue();
});

it('un reporte con el reloj bien reinicia la cuenta de reportes desfasados', function () {
    $terminal = monitoringTerminal();
    monitoringEmployee($terminal);
    monitoringReport($terminal, ['cached_employees' => 1, 'clock_skew_seconds' => 0]);
    monitoringEvaluate($terminal);

    foreach ([[300, 1], [300, 2], [4, 3], [300, 4], [300, 5]] as [$seconds, $minutes]) {
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', config('app.timezone'))->addMinutes($minutes));
        monitoringReport($terminal, ['cached_employees' => 1, 'clock_skew_seconds' => $seconds]);

        expect(monitoringEvaluate($terminal))->toBe([]);
    }
});

it('la acción masiva configura el monitoreo de los seleccionados y queda en la bitácora', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $this->actingAs($user);
    $a = monitoringTerminal();
    $b = monitoringTerminal();

    Livewire::test(ListTerminals::class)
        ->callTableBulkAction('configure_monitoring_bulk', [$a, $b], data: [
            'reset' => false, 'stale_after_minutes' => 30, 'watch_days' => ['1', '2', '3', '4', '5', '6'], 'watch_from' => '06:00', 'watch_to' => '22:00',
        ])
        ->assertHasNoTableBulkActionErrors();

    expect($a->fresh()->stale_after_minutes)->toBe(30)
        ->and($a->fresh()->watch_days)->toBe([1, 2, 3, 4, 5, 6])
        ->and($b->fresh()->watchScheduleLabel())->toContain('06:00–22:00')
        ->and(TerminalEvent::where('terminal_id', $a->id)->where('type', 'monitoring_configured')->exists())->toBeTrue();

    Livewire::test(ListTerminals::class)
        ->callTableBulkAction('configure_monitoring_bulk', [$a, $b], data: ['reset' => true]);

    expect($a->fresh()->watch_days)->toBeNull()
        ->and($a->fresh()->watch_from)->toBeNull()
        ->and($a->fresh()->stale_after_minutes)->toBeNull();
});

it('la acción masiva de monitoreo no se ofrece sin el permiso de terminales', function () {
    $viewer = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'Solo ver terminales', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any_terminal', 'guard_name' => 'web']));
    $this->actingAs($viewer->assignRole($role));

    expect(TerminalMonitoringActions::canManage())->toBeFalse();
});

it('avisa una sola vez cuando la app sigue desactualizada pasado el tiempo de gracia y registra cuando se actualiza', function () {
    $terminal = monitoringTerminal();
    monitoringEmployee($terminal);
    monitoringReport($terminal, ['cached_employees' => 1, 'app_version' => Terminal::currentAppVersion()]);
    monitoringEvaluate($terminal);

    monitoringReport($terminal, ['cached_employees' => 1, 'app_version' => 'viejaversion']);
    expect(monitoringEvaluate($terminal))->toBe([]);

    Carbon::setTestNow(now()->addMinutes(TerminalHealthService::APP_OUTDATED_ALERT_MINUTES - 1));
    monitoringReport($terminal, ['cached_employees' => 1, 'app_version' => 'viejaversion']);
    expect(monitoringEvaluate($terminal))->toBe([]);

    Carbon::setTestNow(now()->addMinutes(2));
    monitoringReport($terminal, ['cached_employees' => 1, 'app_version' => 'viejaversion']);
    expect(monitoringEvaluate($terminal))->toBe(['app_outdated']);

    Carbon::setTestNow(now()->addMinutes(30));
    monitoringReport($terminal, ['cached_employees' => 1, 'app_version' => 'viejaversion']);
    expect(monitoringEvaluate($terminal))->toBe([]);

    monitoringReport($terminal, ['cached_employees' => 1, 'app_version' => Terminal::currentAppVersion()]);
    expect(monitoringEvaluate($terminal))->toBe([])
        ->and(TerminalEvent::where('terminal_id', $terminal->id)->where('type', 'health_app_outdated')->count())->toBe(1)
        ->and(TerminalEvent::where('terminal_id', $terminal->id)->where('type', 'health_app_ok')->exists())->toBeTrue();
});

it('reinicia la espera si la app se actualiza antes del aviso', function () {
    $terminal = monitoringTerminal();
    monitoringEmployee($terminal);
    monitoringReport($terminal, ['cached_employees' => 1, 'app_version' => Terminal::currentAppVersion()]);
    monitoringEvaluate($terminal);

    monitoringReport($terminal, ['cached_employees' => 1, 'app_version' => 'viejaversion']);
    monitoringEvaluate($terminal);

    Carbon::setTestNow(now()->addMinutes(100));
    monitoringReport($terminal, ['cached_employees' => 1, 'app_version' => Terminal::currentAppVersion()]);
    monitoringEvaluate($terminal);

    Carbon::setTestNow(now()->addMinutes(100));
    monitoringReport($terminal, ['cached_employees' => 1, 'app_version' => 'viejaversion']);

    expect(monitoringEvaluate($terminal))->toBe([]);
});
