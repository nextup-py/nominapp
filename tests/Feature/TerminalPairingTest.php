<?php

use App\Exceptions\TerminalPairingException;
use App\Filament\Actions\TerminalPairingActions;
use App\Filament\Pages\TerminalPairingInbox;
use App\Filament\Resources\TerminalResource\Pages\ViewTerminal;
use App\Filament\Resources\TerminalResource\RelationManagers\PairingRequestsRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TerminalEvent;
use App\Models\TerminalPairingRequest;
use App\Models\User;
use App\Notifications\TerminalPairingRequestedNotification;
use App\Services\TerminalPairingService;
use App\Settings\ModuleSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makePairingTerminal(array $attributes = []): Terminal
{
    static $n = 7900000;
    $n++;

    $company = Company::create(['name' => "Empresa Pair {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Pair {$n}", 'company_id' => $company->id]);

    return Terminal::create(['name' => "Terminal Pair {$n}", 'branch_id' => $branch->id, ...$attributes]);
}

function makePairingManager(): User
{
    $permission = Permission::firstOrCreate(['name' => 'update_terminal', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->givePermissionTo($permission);

    return $user;
}

function makePairingSuperAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

/** @return array{request: TerminalPairingRequest, secret: string} */
function requestPairing(Terminal $terminal, ?string $hint = null): array
{
    $result = app(TerminalPairingService::class)->request($terminal, '10.0.0.5', 'Mozilla/5.0 (Linux; Android 13) Chrome/120', $hint);

    return ['request' => $result['request'], 'secret' => $result['poll_secret']];
}

function pollPairing(string $secret)
{
    return test()->postJson('/api/v1/terminal-pairing/status', [], ['Authorization' => "Bearer {$secret}"]);
}

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('terminal-pairing-create');
});

// ─── Crear solicitud ────────────────────────────────────────────────────────

it('crea una solicitud: código sin caracteres ambiguos, secreto solo como hash y vencimiento a 10 minutos', function () {
    $terminal = makePairingTerminal();

    $response = $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code, 'device_model_hint' => 'Pixel 7']);

    $response->assertCreated()->assertJson(['ok' => true, 'poll_interval_seconds' => 3]);
    $code = $response->json('pairing_code');
    $secret = $response->json('poll_secret');

    expect($code)->toMatch('/^['.TerminalPairingRequest::CODE_ALPHABET.']{6}$/')
        ->and($secret)->toHaveLength(40);

    $stored = TerminalPairingRequest::firstOrFail();
    expect($stored->poll_secret_hash)->toBe(hash('sha256', $secret))
        ->and($stored->getAttributes())->not->toContain($secret)
        ->and($stored->status)->toBe('pending')
        ->and($stored->device_model_hint)->toBe('Pixel 7')
        ->and($stored->expires_at->between(now()->addMinutes(9), now()->addMinutes(10)->addSecond()))->toBeTrue()
        ->and(TerminalEvent::where('type', 'pairing_requested')->count())->toBe(1);
});

it('un código inexistente devuelve 404 y un terminal desactivado 403 con code estable', function () {
    $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => 'noexiste'])
        ->assertNotFound()->assertJson(['code' => 'terminal_not_found']);

    $inactive = makePairingTerminal(['status' => 'inactive']);
    $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $inactive->code])
        ->assertForbidden()->assertJson(['code' => 'terminal_inactive']);

    expect(TerminalPairingRequest::count())->toBe(0);
});

it('exige el código del terminal', function () {
    $this->postJson('/api/v1/terminal-pairing', [])->assertUnprocessable();
});

it('marca que reemplazaría al dispositivo actual cuando ya hay un token vigente', function () {
    $terminal = makePairingTerminal();
    $first = requestPairing($terminal)['request'];
    $terminal->createToken('kiosk:'.$terminal->code, [Terminal::SYNC_ABILITY]);
    $second = requestPairing($terminal->fresh())['request'];

    expect($first->replaces_active_device)->toBeFalse()
        ->and($second->replaces_active_device)->toBeTrue();
});

it('mantiene como máximo 3 solicitudes pendientes por terminal venciendo la más antigua', function () {
    $terminal = makePairingTerminal();

    $requests = collect(range(1, 4))->map(function ($i) use ($terminal) {
        $this->travel(1)->seconds();

        return requestPairing($terminal)['request'];
    });

    expect($terminal->pairingRequests()->pending()->count())->toBe(3)
        ->and($requests->first()->fresh()->status)->toBe('expired')
        ->and($requests->last()->fresh()->status)->toBe('pending');
});

it('limita la creación de solicitudes por IP', function () {
    $terminal = makePairingTerminal();

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code])->assertCreated();
    }

    $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code])->assertStatus(429);
});

it('el limitador de la creación no consume el bucket del polling', function () {
    $terminal = makePairingTerminal();
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code])->assertCreated();
    }

    pollPairing('cualquier-secreto')->assertNotFound();
});

// ─── Polling ────────────────────────────────────────────────────────────────

it('el polling exige el secreto en el header Authorization y no acepta query string', function () {
    $terminal = makePairingTerminal();
    ['secret' => $secret] = requestPairing($terminal);

    $this->postJson('/api/v1/terminal-pairing/status')->assertUnprocessable();
    $this->postJson("/api/v1/terminal-pairing/status?poll_secret={$secret}")->assertUnprocessable();
    $this->postJson('/api/v1/terminal-pairing/status', ['poll_secret' => $secret])->assertUnprocessable();
    pollPairing('secreto-equivocado')->assertNotFound()->assertJson(['status' => 'invalid']);
});

it('pendiente: el polling no entrega token', function () {
    $terminal = makePairingTerminal();
    ['secret' => $secret] = requestPairing($terminal);

    pollPairing($secret)->assertOk()->assertJson(['status' => 'pending'])->assertJsonMissing(['token']);
});

it('aprobada: el polling entrega el token una sola vez y revoca el token anterior', function () {
    $terminal = makePairingTerminal();
    $oldToken = $terminal->createToken('kiosk:'.$terminal->code, [Terminal::SYNC_ABILITY]);
    ['request' => $request, 'secret' => $secret] = requestPairing($terminal);
    $admin = makePairingManager();

    app(TerminalPairingService::class)->approve($request, $admin, $request->code);

    $first = pollPairing($secret)->assertOk()->assertJson(['status' => 'claimed', 'terminal' => ['id' => $terminal->id, 'code' => $terminal->code]]);
    $token = $first->json('token');
    expect($token)->not->toBeEmpty();

    $second = pollPairing($secret)->assertOk()->assertJson(['status' => 'claimed']);
    expect($second->json('token'))->toBeNull();

    $terminal->refresh();
    expect($terminal->tokens()->count())->toBe(1)
        ->and($terminal->tokens()->first()->id)->not->toBe($oldToken->accessToken->id)
        ->and($terminal->linked_at)->not->toBeNull()
        ->and($request->fresh()->status)->toBe('claimed')
        ->and($request->fresh()->claimed_at)->not->toBeNull();

    // El token emitido sirve contra la API de sincronización.
    $this->withToken($token)->postJson('/api/v1/terminal/heartbeat')->assertOk();
});

it('deja la bitácora de aprobación, vinculación y quién aprobó', function () {
    $terminal = makePairingTerminal();
    ['request' => $request, 'secret' => $secret] = requestPairing($terminal);
    $admin = makePairingManager();

    app(TerminalPairingService::class)->approve($request, $admin, $request->code);
    pollPairing($secret)->assertOk();

    $events = $terminal->events()->orderBy('id')->get();
    expect($events->pluck('type')->all())->toContain('pairing_requested', 'pairing_approved', 'linked', 'pairing_claimed')
        ->and($events->firstWhere('type', 'pairing_approved')->actor_id)->toBe($admin->id)
        ->and($events->firstWhere('type', 'linked')->payload['via'])->toBe('pairing')
        ->and($request->fresh()->approved_by_id)->toBe($admin->id);
});

it('rechazada: el polling lo informa y no entrega token', function () {
    $terminal = makePairingTerminal();
    ['request' => $request, 'secret' => $secret] = requestPairing($terminal);

    app(TerminalPairingService::class)->deny($request, makePairingManager());

    pollPairing($secret)->assertOk()->assertJson(['status' => 'denied'])->assertJsonMissing(['token']);
    expect($terminal->fresh()->tokens()->count())->toBe(0);
});

it('un terminal desactivado no recibe token aunque la solicitud estuviera aprobada', function () {
    $terminal = makePairingTerminal();
    ['request' => $request, 'secret' => $secret] = requestPairing($terminal);
    app(TerminalPairingService::class)->approve($request, makePairingManager(), $request->code);

    $terminal->update(['status' => 'inactive']);

    pollPairing($secret)->assertForbidden()->assertJson(['code' => 'terminal_inactive']);
    expect($terminal->fresh()->tokens()->count())->toBe(0);
});

// ─── Aprobación ─────────────────────────────────────────────────────────────

it('aprobar exige el código correcto y lo normaliza (minúsculas, guiones, espacios)', function () {
    $terminal = makePairingTerminal();
    ['request' => $request] = requestPairing($terminal);
    $service = app(TerminalPairingService::class);
    $admin = makePairingManager();

    expect(fn () => $service->approve($request, $admin, 'AAAAAA'))->toThrow(TerminalPairingException::class);
    expect($request->fresh()->status)->toBe('pending');

    $typed = strtolower(substr($request->code, 0, 3).' - '.substr($request->code, 3));
    $service->approve($request, $admin, $typed);

    expect($request->fresh()->status)->toBe('approved');
});

it('una solicitud vencida no se puede aprobar y el polling la marca como vencida', function () {
    $terminal = makePairingTerminal();
    ['request' => $request, 'secret' => $secret] = requestPairing($terminal);

    $this->travel(11)->minutes();

    expect(fn () => app(TerminalPairingService::class)->approve($request, makePairingManager(), $request->code))
        ->toThrow(TerminalPairingException::class);

    pollPairing($secret)->assertOk()->assertJson(['status' => 'expired']);
    expect($request->fresh()->status)->toBe('expired');
});

it('no se puede aprobar dos veces la misma solicitud', function () {
    $terminal = makePairingTerminal();
    ['request' => $request] = requestPairing($terminal);
    $service = app(TerminalPairingService::class);
    $admin = makePairingManager();

    $service->approve($request, $admin, $request->code);

    expect(fn () => $service->approve($request, $admin, $request->code))->toThrow(TerminalPairingException::class);
});

it('el comando terminals:expire-pairing vence las pendientes cuyo plazo pasó', function () {
    $terminal = makePairingTerminal();
    $old = requestPairing($terminal)['request'];
    $this->travel(11)->minutes();
    $fresh = requestPairing($terminal)['request'];

    $this->artisan('terminals:expire-pairing')->assertSuccessful();

    expect($old->fresh()->status)->toBe('expired')
        ->and($fresh->fresh()->status)->toBe('pending');
});

// ─── Notificaciones y permisos ──────────────────────────────────────────────

it('notifica solo a quienes tienen permiso sobre terminales y sin incluir el código', function () {
    $manager = makePairingManager();
    $superAdmin = makePairingSuperAdmin();
    $other = User::factory()->create();
    $terminal = makePairingTerminal();

    $request = requestPairing($terminal)['request'];

    Notification::assertSentTo($manager, TerminalPairingRequestedNotification::class);
    Notification::assertSentTo($superAdmin, TerminalPairingRequestedNotification::class);
    Notification::assertNotSentTo($other, TerminalPairingRequestedNotification::class);

    $data = (new TerminalPairingRequestedNotification($request->load('terminal.branch')))->toDatabase($manager);
    expect($data['format'])->toBe('filament')
        ->and(json_encode($data))->not->toContain($request->code);
});

it('la bandeja solo es accesible con el permiso de gestionar terminales', function () {
    $this->actingAs(User::factory()->create())->get(TerminalPairingInbox::getUrl())->assertForbidden();

    expect(TerminalPairingInbox::canAccess())->toBeFalse();

    $this->actingAs(makePairingManager());
    expect(TerminalPairingInbox::canAccess())->toBeTrue();
});

it('desde la bandeja se aprueba con el código correcto y no con uno equivocado', function () {
    $terminal = makePairingTerminal();
    ['request' => $request] = requestPairing($terminal);
    $this->actingAs(makePairingSuperAdmin());

    Livewire::test(TerminalPairingInbox::class)
        ->assertCanSeeTableRecords([$request])
        ->callTableAction('approve_pairing', $request, ['code' => 'ZZZZZZ']);
    expect($request->fresh()->status)->toBe('pending');

    Livewire::test(TerminalPairingInbox::class)
        ->callTableAction('approve_pairing', $request, ['code' => $request->code])
        ->assertHasNoTableActionErrors();
    expect($request->fresh()->status)->toBe('approved');
});

it('desde la bandeja se rechaza una solicitud', function () {
    $terminal = makePairingTerminal();
    ['request' => $request] = requestPairing($terminal);
    $this->actingAs(makePairingSuperAdmin());

    Livewire::test(TerminalPairingInbox::class)->callTableAction('deny_pairing', $request);

    expect($request->fresh()->status)->toBe('denied');
});

it('el RelationManager de la vista del terminal lista y resuelve sus solicitudes', function () {
    $terminal = makePairingTerminal();
    ['request' => $request] = requestPairing($terminal);
    $this->actingAs(makePairingSuperAdmin());

    Livewire::test(PairingRequestsRelationManager::class, ['ownerRecord' => $terminal, 'pageClass' => ViewTerminal::class])
        ->assertCanSeeTableRecords([$request])
        ->callTableAction('approve_pairing', $request, ['code' => $request->code]);

    expect($request->fresh()->status)->toBe('approved');
});

// ─── Errores de la API con código estable ───────────────────────────────────

it('la API del terminal responde 401 con code token_revoked cuando el token no es válido', function () {
    $this->withToken('token-inexistente')->postJson('/api/v1/terminal/heartbeat')
        ->assertUnauthorized()->assertJson(['code' => 'token_revoked']);
});

it('la API del terminal responde 403 con code terminal_inactive cuando el terminal está desactivado', function () {
    $terminal = makePairingTerminal();
    $terminal->forceFill(['status' => 'inactive'])->saveQuietly();
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $this->postJson('/api/v1/terminal/heartbeat')->assertForbidden()->assertJson(['code' => 'terminal_inactive']);
});

it('revocar el token registra el evento con el usuario que lo hizo', function () {
    $terminal = makePairingTerminal();
    $terminal->createToken('kiosk:'.$terminal->code, [Terminal::SYNC_ABILITY]);
    $admin = makePairingManager();
    $this->actingAs($admin);

    $terminal->revokeSyncTokens();

    $event = $terminal->events()->where('type', 'revoked')->firstOrFail();
    expect($event->actor_id)->toBe($admin->id)
        ->and($terminal->tokens()->count())->toBe(0);
});

it('el RelationManager de solicitudes es visible para quien gestiona terminales sin ser Super Admin (el modelo no tiene Policy propia)', function () {
    $terminal = makePairingTerminal();
    $this->actingAs(makePairingManager());

    expect(PairingRequestsRelationManager::canViewForRecord($terminal, ViewTerminal::class))->toBeTrue();
});

it('la bandeja respeta el flag del módulo de marcación biométrica igual que TerminalResource', function () {
    $this->actingAs(makePairingSuperAdmin());
    expect(TerminalPairingInbox::canAccess())->toBeTrue();

    $modules = app(ModuleSettings::class);
    $modules->biometric_attendance_enabled = false;
    $modules->save();

    expect(TerminalPairingInbox::canAccess())->toBeFalse();
});

it('el texto del modal de aprobación escapa lo que reporta el dispositivo', function () {
    $terminal = makePairingTerminal();
    $result = app(TerminalPairingService::class)->request($terminal, '10.0.0.5', '<script>alert(1)</script> Mozilla/5.0', '<b>Pixel</b>');

    $html = TerminalPairingActions::describe($result['request']->load('terminal.branch'))->toHtml();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('&lt;b&gt;Pixel&lt;/b&gt;')
        ->and($html)->not->toContain('<script>')
        ->and($html)->toContain('<br>');
});
