<?php

use App\Filament\Resources\TerminalResource;
use App\Filament\Resources\TerminalResource\Pages\ListTerminals;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TerminalEvent;
use App\Models\User;
use App\Notifications\TerminalHealthNotification;
use App\Services\TerminalHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function displayTerminal(array $attributes = []): Terminal
{
    static $n = 9600000;
    $n++;

    $company = Company::create(['name' => "EmpDM {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucDM {$n}", 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => "Terminal DM {$n}", 'branch_id' => $branch->id, ...$attributes]);
    $terminal->claimSanctumToken();

    return $terminal->fresh();
}

/** Terminal en línea con un reporte fresco; `$device` pisa los datos de un terminal sano en una ventana propia. */
function displayReport(Terminal $terminal, array $device = []): void
{
    $terminal->update([
        'last_heartbeat_at' => now(),
        'device_report' => ['cached_employees' => 1, 'app_version' => Terminal::currentAppVersion(), 'display_mode' => 'standalone', 'standalone' => true, 'visible' => true, ...$device],
        'device_report_at' => now(),
    ]);
}

function displayEvaluate(Terminal $terminal): array
{
    return app(TerminalHealthService::class)->evaluate($terminal->fresh());
}

beforeEach(function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-12 10:00:00', config('app.timezone')));
    User::factory()->create()->givePermissionTo(Permission::firstOrCreate(['name' => 'update_terminal', 'guard_name' => 'web']));
});

afterEach(fn () => Carbon::setTestNow());

it('el heartbeat guarda el modo de visualización y la visibilidad, y rechaza un modo desconocido', function () {
    $terminal = displayTerminal();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/terminal/heartbeat', ['device' => ['display_mode' => 'fullscreen', 'standalone' => true, 'visible' => false]])->assertOk();

    expect($terminal->fresh()->device_report)->toMatchArray(['display_mode' => 'fullscreen', 'standalone' => true, 'visible' => false]);

    $this->postJson('/api/v1/terminal/heartbeat', ['device' => ['display_mode' => 'otro']])->assertUnprocessable();
    $this->postJson('/api/v1/terminal/heartbeat', ['device' => ['wake_lock' => false, 'wake_lock_error' => 'hidden']])->assertOk();
    expect($terminal->fresh()->device_report['wake_lock_error'])->toBe('hidden');
});

it('displayKind distingue pestaña de ventana propia y cae a standalone en un reporte viejo', function (array $report, ?string $kind) {
    $terminal = displayTerminal();
    $terminal->update(['device_report' => $report]);

    expect($terminal->fresh()->displayKind())->toBe($kind);
})->with([
    'pestaña' => [['display_mode' => 'browser', 'standalone' => false], 'browser'],
    'app instalada' => [['display_mode' => 'standalone', 'standalone' => true], 'app'],
    'kiosko' => [['display_mode' => 'fullscreen', 'standalone' => true], 'app'],
    'reporte viejo, pestaña' => [['standalone' => false], 'browser'],
    'reporte viejo, app' => [['standalone' => true], 'app'],
    'sin dato' => [['battery_level' => 50], null],
]);

it('el motivo «hidden» del Wake Lock y la pestaña oculta aparecen en el diagnóstico de la desconexión', function () {
    $terminal = displayTerminal();
    $terminal->update(['device_report' => ['wake_lock' => false, 'wake_lock_error' => 'hidden', 'display_mode' => 'browser', 'standalone' => false, 'visible' => false]]);

    $hints = implode(' ', $terminal->fresh()->offlineHints());

    expect($hints)->toContain('oculta o minimizada')
        ->and($hints)->toContain('pestaña del navegador');
});

it('el detalle describe el modo de visualización y avisa si la pestaña está oculta', function (array $report, string $expected) {
    $terminal = displayTerminal();
    $terminal->update(['device_report' => $report]);

    expect(TerminalResource::describeInstallMode($terminal->fresh()))->toBe($expected);
})->with([
    'kiosko' => [['display_mode' => 'fullscreen', 'standalone' => true], 'Pantalla completa (modo kiosko)'],
    'app' => [['display_mode' => 'standalone', 'standalone' => true], 'Instalada como app'],
    'pestaña oculta' => [['display_mode' => 'browser', 'standalone' => false, 'visible' => false], 'En el navegador (sin instalar) · pestaña oculta'],
    'reporte viejo' => [['standalone' => false], 'En el navegador (sin instalar)'],
]);

it('el listado muestra el modo y se puede filtrar por pestaña o por app', function () {
    $this->actingAs(User::factory()->create()->givePermissionTo(
        Permission::firstOrCreate(['name' => 'view_any_terminal', 'guard_name' => 'web']),
    ));

    $tab = displayTerminal();
    $tab->update(['device_report' => ['display_mode' => 'browser', 'standalone' => false]]);
    $app = displayTerminal();
    $app->update(['device_report' => ['display_mode' => 'fullscreen', 'standalone' => true]]);
    $unknown = displayTerminal();

    Livewire::test(ListTerminals::class)
        ->assertCanSeeTableRecords([$tab, $app, $unknown])
        ->filterTable('display_kind', 'browser')
        ->assertCanSeeTableRecords([$tab])
        ->assertCanNotSeeTableRecords([$app, $unknown])
        ->filterTable('display_kind', 'app')
        ->assertCanSeeTableRecords([$app])
        ->assertCanNotSeeTableRecords([$tab, $unknown]);
});

it('avisa una sola vez cuando el terminal lleva horas en una pestaña y registra cuando pasa a una ventana propia', function () {
    $terminal = displayTerminal();
    displayReport($terminal, ['display_mode' => 'browser', 'standalone' => false]);
    expect(displayEvaluate($terminal))->toBe([]); // línea base

    displayReport($terminal, ['display_mode' => 'browser', 'standalone' => false]);
    expect(displayEvaluate($terminal))->toBe([]); // empieza a contar

    Carbon::setTestNow(now()->addMinutes(TerminalHealthService::DISPLAY_ALERT_MINUTES - 1));
    displayReport($terminal, ['display_mode' => 'browser', 'standalone' => false]);
    expect(displayEvaluate($terminal))->toBe([]);

    Carbon::setTestNow(now()->addMinutes(2));
    displayReport($terminal, ['display_mode' => 'browser', 'standalone' => false]);
    expect(displayEvaluate($terminal))->toBe(['not_installed']);

    Notification::assertSentTo(User::permission('update_terminal')->get(), TerminalHealthNotification::class, fn ($n) => $n->alert === 'not_installed');

    Carbon::setTestNow(now()->addMinutes(30));
    displayReport($terminal, ['display_mode' => 'browser', 'standalone' => false]);
    expect(displayEvaluate($terminal))->toBe([]);

    displayReport($terminal);
    expect(displayEvaluate($terminal))->toBe([])
        ->and(TerminalEvent::where('terminal_id', $terminal->id)->where('type', 'health_not_installed')->count())->toBe(1)
        ->and(TerminalEvent::where('terminal_id', $terminal->id)->where('type', 'health_installed_ok')->exists())->toBeTrue();
});

it('avisa de la pestaña oculta pasado el tiempo y se reinicia la espera si vuelve a verse', function () {
    $terminal = displayTerminal();
    displayReport($terminal);
    displayEvaluate($terminal);

    displayReport($terminal, ['visible' => false]);
    displayEvaluate($terminal);

    Carbon::setTestNow(now()->addMinutes(TerminalHealthService::DISPLAY_ALERT_MINUTES - 10));
    displayReport($terminal);
    expect(displayEvaluate($terminal))->toBe([]); // volvió a verse: se reinicia

    Carbon::setTestNow(now()->addMinutes(5));
    displayReport($terminal, ['visible' => false]);
    displayEvaluate($terminal);

    Carbon::setTestNow(now()->addMinutes(TerminalHealthService::DISPLAY_ALERT_MINUTES + 1));
    displayReport($terminal, ['visible' => false]);
    expect(displayEvaluate($terminal))->toBe(['tab_hidden'])
        ->and(TerminalEvent::where('terminal_id', $terminal->id)->where('type', 'health_tab_hidden')->count())->toBe(1);
});

it('no avisa del modo con el terminal fuera de su horario de vigilancia ni con un reporte sin el dato', function () {
    $terminal = displayTerminal(['watch_days' => [1, 2, 3, 4, 5, 6], 'watch_from' => '06:00', 'watch_to' => '08:00']);
    displayReport($terminal, ['display_mode' => 'browser', 'standalone' => false]);
    displayEvaluate($terminal); // 10:00 está fuera de 06–08

    Carbon::setTestNow(now()->addMinutes(TerminalHealthService::DISPLAY_ALERT_MINUTES + 5));
    displayReport($terminal, ['display_mode' => 'browser', 'standalone' => false]);
    expect(displayEvaluate($terminal))->toBe([]);

    $sinDato = displayTerminal();
    displayReport($sinDato);
    $sinDato->update(['device_report' => ['cached_employees' => 1, 'app_version' => Terminal::currentAppVersion()]]);
    displayEvaluate($sinDato);

    Carbon::setTestNow(now()->addMinutes(TerminalHealthService::DISPLAY_ALERT_MINUTES + 5));
    $sinDato->update(['last_heartbeat_at' => now(), 'device_report_at' => now()]);
    expect(displayEvaluate($sinDato))->toBe([]);
});
