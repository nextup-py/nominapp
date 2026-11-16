<?php

use App\Filament\Actions\TerminalPairingActions;
use App\Filament\Resources\TerminalResource;
use App\Filament\Resources\TerminalResource\Pages\ViewTerminal;
use App\Filament\Resources\TerminalResource\RelationManagers\DevicesRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TerminalDevice;
use App\Models\TerminalEvent;
use App\Models\User;
use App\Services\DeviceHintsParser;
use App\Services\TerminalPairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const WIN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const ANDROID_UA = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
const DEVICE_A = '3f2b8c1e-aaaa-bbbb-cccc-111111111111';
const DEVICE_B = '9d4e7a2f-dddd-eeee-ffff-222222222222';

function historyTerminal(): Terminal
{
    static $n = 7970000;
    $n++;

    $company = Company::create(['name' => "Empresa HD {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal HD {$n}", 'company_id' => $company->id]);

    return Terminal::create(['name' => "Terminal HD {$n}", 'branch_id' => $branch->id]);
}

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('terminal-pairing-create');
});

it('describe() distingue sistema y navegador del User-Agent', function () {
    expect(DeviceHintsParser::describe(WIN_UA))->toBe(['os' => 'Windows', 'browser' => 'Chrome 120'])
        ->and(DeviceHintsParser::describe(ANDROID_UA))->toBe(['os' => 'Android 10', 'browser' => 'Chrome 120'])
        ->and(DeviceHintsParser::describe('Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'))->toBe(['os' => 'iOS 18', 'browser' => 'Safari 18'])
        ->and(DeviceHintsParser::describe(WIN_UA.' Edg/120.0.0.0')['browser'])->toBe('Edge 120')
        ->and(DeviceHintsParser::describe(null))->toBe(['os' => null, 'browser' => null]);
});

it('vincular crea el registro activo con identificador, IP, sistema y navegador', function () {
    $terminal = historyTerminal();

    $terminal->claimSanctumToken(WIN_UA, null, '10.0.0.1', 'pairing', DEVICE_A);

    $device = $terminal->activeDevice;

    expect($device->device_id)->toBe(DEVICE_A)
        ->and($device->ip_address)->toBe('10.0.0.1')
        ->and($device->os)->toBe('Windows')
        ->and($device->browser)->toBe('Chrome 120')
        ->and($device->linked_via)->toBe('pairing')
        ->and($device->isActive())->toBeTrue()
        ->and($terminal->devices()->count())->toBe(1);
});

it('vincular otro dispositivo cierra el anterior como reemplazado y los encadena', function () {
    $terminal = historyTerminal();
    $terminal->claimSanctumToken(WIN_UA, null, '10.0.0.1', 'pairing', DEVICE_A);
    $terminal->update(['last_heartbeat_at' => now()->subMinutes(2)]);

    $terminal->fresh()->claimSanctumToken(ANDROID_UA, null, '10.0.0.2', 'pairing', DEVICE_B);

    $old = TerminalDevice::where('device_id', DEVICE_A)->first();
    $new = TerminalDevice::where('device_id', DEVICE_B)->first();

    expect($old->isActive())->toBeFalse()
        ->and($old->unlink_reason)->toBe('replaced')
        ->and($old->replaced_by_id)->toBe($new->id)
        ->and($old->last_seen_at)->not->toBeNull()
        ->and($new->isActive())->toBeTrue()
        ->and($terminal->fresh()->devices()->whereNull('unlinked_at')->count())->toBe(1)
        ->and(TerminalEvent::where('type', 'device_replaced')->sole()->payload)->toMatchArray(['device_id' => substr(DEVICE_B, 0, 8), 'previous_device_id' => substr(DEVICE_A, 0, 8)]);
});

it('desvincular desde el panel cierra el registro con el motivo y el usuario', function () {
    $user = User::factory()->create();
    $terminal = historyTerminal();
    $terminal->claimSanctumToken(WIN_UA, null, '10.0.0.1', 'pairing', DEVICE_A);

    $this->actingAs($user);
    $terminal->fresh()->revokeSyncTokens();

    $device = TerminalDevice::sole();
    expect($device->unlink_reason)->toBe('revoked')->and($device->unlinked_by_id)->toBe($user->id)->and($terminal->fresh()->activeDevice)->toBeNull();
});

it('desactivar el terminal cierra el registro como desactivado', function () {
    $terminal = historyTerminal();
    $terminal->claimSanctumToken(WIN_UA, null, '10.0.0.1', 'pairing', DEVICE_A);

    $terminal->fresh()->update(['status' => 'inactive']);

    expect(TerminalDevice::sole()->unlink_reason)->toBe('deactivated');
});

it('el identificador viaja por el código de emparejamiento hasta el historial', function () {
    $terminal = historyTerminal();
    $approver = User::factory()->create();

    $created = app(TerminalPairingService::class)->request($terminal, '10.0.0.7', WIN_UA, null, ['device_id' => DEVICE_A]);
    app(TerminalPairingService::class)->approve($created['request'], $approver, $created['request']->code);
    $result = app(TerminalPairingService::class)->poll($created['poll_secret'], '10.0.0.7', WIN_UA);

    expect($result['status'])->toBe('claimed')->and(TerminalDevice::sole()->device_id)->toBe(DEVICE_A);
});

it('la solicitud sabe si es el mismo dispositivo que está vinculado, otro, o no se puede comparar', function () {
    $terminal = historyTerminal();
    $terminal->claimSanctumToken(WIN_UA, null, '10.0.0.1', 'pairing', DEVICE_A);
    $terminal = $terminal->fresh();
    $service = app(TerminalPairingService::class);

    $same = $service->request($terminal, '10.0.0.1', WIN_UA, null, ['device_id' => DEVICE_A])['request'];
    $other = $service->request($terminal, '10.0.0.2', ANDROID_UA, null, ['device_id' => DEVICE_B])['request'];
    $unknown = $service->request($terminal, '10.0.0.3', ANDROID_UA, null, [])['request'];

    expect($same->same_device)->toBeTrue()->and($other->same_device)->toBeFalse()->and($unknown->same_device)->toBeNull();

    $html = fn ($r) => TerminalPairingActions::describe($r->load('terminal.branch'))->toHtml();
    expect($html($same))->toContain('Es el mismo dispositivo que está vinculado')
        ->and($html($other))->toContain('Es otro dispositivo distinto del que está vinculado')
        ->and($html($unknown))->not->toContain('mismo dispositivo')->not->toContain('otro dispositivo distinto');
});

it('el mismo dispositivo pidiendo vínculo es una pérdida segura aunque el terminal siga latiendo; otro no lo es', function () {
    $terminal = historyTerminal();
    $terminal->claimSanctumToken(WIN_UA, null, '10.0.0.1', 'pairing', DEVICE_A);
    $terminal->update(['last_heartbeat_at' => now()->subSeconds(20)]);
    $service = app(TerminalPairingService::class);

    $service->request($terminal->fresh(), '10.0.0.2', ANDROID_UA, null, ['device_id' => DEVICE_B]);
    expect(TerminalEvent::where('type', 'link_lost')->exists())->toBeFalse();

    $service->request($terminal->fresh(), '10.0.0.1', WIN_UA, null, ['device_id' => DEVICE_A]);
    expect(TerminalEvent::where('type', 'link_lost')->sole()->payload['same_device'])->toBeTrue();
});

it('el heartbeat aprende el identificador de un dispositivo vinculado antes de que existiera y guarda los datos del equipo', function () {
    $terminal = historyTerminal();
    $terminal->claimSanctumToken(WIN_UA, null, '10.0.0.1', 'pairing');
    expect($terminal->activeDevice->device_id)->toBeNull();

    Sanctum::actingAs($terminal->fresh(), [Terminal::SYNC_ABILITY]);
    $this->postJson('/api/v1/terminal/heartbeat', ['device' => [
        'device_id' => DEVICE_A, 'device_memory_gb' => 8, 'cpu_cores' => 4, 'connection_type' => '4g',
        'screen' => '1366x768', 'language' => 'es-PY', 'timezone' => 'America/Asuncion', 'platform' => 'Windows',
    ]])->assertOk();

    $terminal = $terminal->fresh();
    expect($terminal->activeDevice->device_id)->toBe(DEVICE_A)
        ->and($terminal->device_report)->toMatchArray(['device_memory_gb' => 8, 'cpu_cores' => 4, 'connection_type' => '4g', 'screen' => '1366x768', 'timezone' => 'America/Asuncion'])
        ->and(TerminalResource::describeHardware($terminal))->toContain('8 GB')->toContain('4 núcleos')
        ->and(TerminalResource::describeConnection($terminal))->toContain('Conexión 4G')->toContain('Pantalla 1366x768')
        ->and(TerminalResource::describeSystem($terminal))->toBe('Windows · Chrome 120');
});

it('el heartbeat no pisa un identificador ya conocido y valida los datos del equipo', function () {
    $terminal = historyTerminal();
    $terminal->claimSanctumToken(WIN_UA, null, '10.0.0.1', 'pairing', DEVICE_A);
    Sanctum::actingAs($terminal->fresh(), [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/terminal/heartbeat', ['device' => ['device_id' => DEVICE_B]])->assertOk();
    expect($terminal->fresh()->activeDevice->device_id)->toBe(DEVICE_A);

    $this->postJson('/api/v1/terminal/heartbeat', ['device' => ['connection_type' => 'wifi']])->assertUnprocessable();
    $this->postJson('/api/v1/terminal/heartbeat', ['device' => ['screen' => '<script>']])->assertUnprocessable();
    $this->postJson('/api/v1/terminal/heartbeat', ['device' => ['device_id' => 'a b c']])->assertUnprocessable();
});

it('el enlace de configuración también registra el identificador', function () {
    $terminal = historyTerminal();
    $token = $terminal->generateSetupToken(60);

    $this->postJson("/terminal/{$terminal->code}/setup/claim", ['token' => $token, 'device_id' => DEVICE_A])->assertOk();

    expect(TerminalDevice::sole()->device_id)->toBe(DEVICE_A)->and(TerminalDevice::sole()->linked_via)->toBe('setup');
});

it('la pestaña Dispositivos muestra el historial y quién reemplazó a quién', function () {
    $terminal = historyTerminal();
    $terminal->claimSanctumToken(WIN_UA, null, '10.0.0.1', 'pairing', DEVICE_A);
    $terminal->fresh()->claimSanctumToken(ANDROID_UA, null, '10.0.0.2', 'pairing', DEVICE_B);

    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    Livewire::actingAs($user)
        ->test(DevicesRelationManager::class, ['ownerRecord' => $terminal->fresh(), 'pageClass' => ViewTerminal::class])
        ->assertSuccessful()
        ->assertCanSeeTableRecords($terminal->devices)
        ->assertSee('Vinculado ahora')
        ->assertSee('Reemplazado por otro dispositivo')
        ->assertSee('Windows')
        ->assertSee('Android 10')
        ->assertSee(substr(DEVICE_B, 0, 8));
});

it('el terminal incluye la pestaña Dispositivos', function () {
    expect(TerminalResource::getRelations())->toContain(DevicesRelationManager::class);
});

it('la migración rellena un registro activo para los terminales que ya tenían acceso vigente, sin duplicar', function () {
    $withToken = historyTerminal();
    $withToken->forceFill(['user_agent' => WIN_UA, 'linked_ip' => '10.0.0.5', 'linked_at' => now()->subDays(3), 'device_brand' => 'Samsung', 'device_model' => 'A22'])->save();
    $withToken->createToken('kiosk:'.$withToken->code, [Terminal::SYNC_ABILITY]);
    $withoutToken = historyTerminal();

    $migration = require base_path('database/migrations/2026_11_16_500000_create_terminal_devices_table.php');
    $migration->up();
    $migration->up();

    $device = TerminalDevice::sole();
    expect($device->terminal_id)->toBe($withToken->id)
        ->and($device->ip_address)->toBe('10.0.0.5')
        ->and($device->os)->toBe('Windows')
        ->and($device->browser)->toBe('Chrome 120')
        ->and($device->deviceLabel())->toBe('Samsung A22')
        ->and($device->isActive())->toBeTrue()
        ->and($device->device_id)->toBeNull()
        ->and(TerminalDevice::where('terminal_id', $withoutToken->id)->exists())->toBeFalse();
});
