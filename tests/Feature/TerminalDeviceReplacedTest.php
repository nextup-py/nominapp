<?php

use App\Filament\Actions\TerminalPairingActions;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TerminalEvent;
use App\Models\User;
use App\Notifications\TerminalDeviceReplacedNotification;
use App\Services\TerminalPairingService;
use App\Support\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/** Terminal con un dispositivo ya vinculado (token vigente), como el terminal 1 de Casa Central. */
function replacedTerminal(array $attributes = []): Terminal
{
    static $n = 7960000;
    $n++;

    $company = Company::create(['name' => "Empresa DR {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal DR {$n}", 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => "Terminal DR {$n}", 'branch_id' => $branch->id, 'linked_ip' => '10.0.0.1', ...$attributes]);
    $terminal->createToken('kiosk:'.$terminal->code, [Terminal::SYNC_ABILITY]);

    return $terminal->fresh();
}

function replacedManager(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::firstOrCreate(['name' => 'update_terminal', 'guard_name' => 'web']));

    return $user;
}

beforeEach(fn () => Notification::fake());

it('un dispositivo nuevo que reemplaza a uno en línea queda en la bitácora y avisa por la campanita', function () {
    $manager = replacedManager();
    $terminal = replacedTerminal(['last_heartbeat_at' => now()->subSeconds(30)]);

    $terminal->claimSanctumToken('Mozilla/5.0 (Windows NT 10.0)', null, '10.0.0.9', 'pairing');

    $event = TerminalEvent::where('type', 'device_replaced')->sole();

    expect($event->payload)->toMatchArray(['ip' => '10.0.0.9', 'previous_ip' => '10.0.0.1', 'previous_online' => true])
        ->and($event->detail)->toContain('Dispositivo anterior: IP 10.0.0.1')->toContain('El anterior estaba en línea')
        ->and($terminal->fresh()->tokens()->count())->toBe(1);

    Notification::assertSentTo($manager, TerminalDeviceReplacedNotification::class);
});

it('si el anterior no estaba en línea queda en la bitácora pero no avisa', function () {
    $manager = replacedManager();
    $terminal = replacedTerminal(['last_heartbeat_at' => now()->subHours(3)]);

    $terminal->claimSanctumToken(null, null, '10.0.0.9', 'pairing');

    expect(TerminalEvent::where('type', 'device_replaced')->sole()->payload['previous_online'])->toBeFalse();
    Notification::assertNotSentTo($manager, TerminalDeviceReplacedNotification::class);
});

it('el primer vínculo (sin dispositivo anterior) no es un reemplazo', function () {
    $terminal = replacedTerminal();
    $terminal->tokens()->delete();

    $terminal->fresh()->claimSanctumToken(null, null, '10.0.0.9', 'setup');

    expect(TerminalEvent::where('type', 'device_replaced')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('no le avisa a quien dejó listo el vínculo', function () {
    $approver = replacedManager();
    $other = replacedManager();
    $terminal = replacedTerminal(['last_heartbeat_at' => now()->subSeconds(20), 'link_window_opened_by_id' => $approver->id]);

    $terminal->claimSanctumToken(null, null, '10.0.0.9', 'pairing');

    Notification::assertNotSentTo($approver, TerminalDeviceReplacedNotification::class);
    Notification::assertSentTo($other, TerminalDeviceReplacedNotification::class);
});

it('el modal de aprobar muestra el dispositivo actual y advierte con fuerza si está en línea', function () {
    $terminal = replacedTerminal(['last_heartbeat_at' => now()->subSeconds(30), 'device_brand' => 'Samsung', 'device_model' => 'A22']);
    $request = app(TerminalPairingService::class)->request($terminal, '10.0.0.9', 'Mozilla/5.0', null)['request']->load('terminal.branch');

    $html = TerminalPairingActions::describe($request)->toHtml();

    expect($html)->toContain('Dispositivo vinculado hoy:')->toContain('Samsung A22')->toContain('IP 10.0.0.1')
        ->and($html)->toContain('este terminal está en línea en otro dispositivo')
        ->and($html)->not->toContain('⚠');
});

it('si el dispositivo actual no está en línea, el modal avisa del reemplazo sin la advertencia fuerte', function () {
    $terminal = replacedTerminal(['last_heartbeat_at' => now()->subDay()]);
    $request = app(TerminalPairingService::class)->request($terminal, '10.0.0.9', 'Mozilla/5.0', null)['request']->load('terminal.branch');

    $html = TerminalPairingActions::describe($request)->toHtml();

    expect($html)->toContain('Reemplazará al dispositivo vinculado actualmente')->not->toContain('está en línea en otro dispositivo');
});

it('un terminal sin vincular antes no menciona un dispositivo actual en el modal', function () {
    $terminal = replacedTerminal();
    $terminal->tokens()->delete();
    $request = app(TerminalPairingService::class)->request($terminal->fresh(), '10.0.0.9', 'Mozilla/5.0', null)['request']->load('terminal.branch');

    expect(TerminalPairingActions::describe($request)->toHtml())->not->toContain('Dispositivo vinculado hoy');
});

it('el aviso está en el catálogo y el resumen del dispositivo actual indica que no hay latido', function () {
    expect(NotificationCatalog::all())->toHaveKey('terminal.device_replaced');

    $terminal = replacedTerminal(['device_brand' => 'Samsung', 'device_model' => 'A22']);

    expect($terminal->currentDeviceSummary())->toContain('Samsung A22')->toContain('último latido nunca')
        ->and($terminal->isReportingNow())->toBeFalse();
});
