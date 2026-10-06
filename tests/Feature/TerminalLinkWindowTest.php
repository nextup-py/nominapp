<?php

use App\Filament\Resources\TerminalResource;
use App\Filament\Resources\TerminalResource\Pages\ListTerminals;
use App\Filament\Resources\TerminalResource\Pages\ViewTerminal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TerminalPairingRequest;
use App\Models\User;
use App\Notifications\TerminalPairingRequestedNotification;
use App\Services\TerminalPairingService;
use Filament\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeWindowTerminal(array $attributes = []): Terminal
{
    static $n = 8100000;
    $n++;

    $company = Company::create(['name' => "Empresa Win {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Win {$n}", 'company_id' => $company->id]);

    return Terminal::create(['name' => "Terminal Win {$n}", 'branch_id' => $branch->id, ...$attributes]);
}

function windowSuperAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

beforeEach(fn () => Notification::fake());

// ─── Ventana de vinculación (modelo) ────────────────────────────────────────

it('abrir la ventana la deja vigente por 15 minutos, registra quién la abrió y deja evento', function () {
    $terminal = makeWindowTerminal();
    $admin = windowSuperAdmin();

    $terminal->openLinkWindow($admin);
    $terminal->refresh();

    expect($terminal->hasOpenLinkWindow())->toBeTrue()
        ->and($terminal->link_window_opened_by_id)->toBe($admin->id)
        ->and($terminal->link_window_until->between(now()->addMinutes(14), now()->addMinutes(15)->addSecond()))->toBeTrue()
        ->and($terminal->events()->where('type', 'link_window_opened')->first()->actor_id)->toBe($admin->id);
});

it('una ventana vencida ya no cuenta como abierta', function () {
    $terminal = makeWindowTerminal(['link_window_until' => now()->subMinute()]);

    expect($terminal->hasOpenLinkWindow())->toBeFalse();
});

it('cerrar la ventana a mano la cierra y deja evento; cerrar sin ventana no hace nada', function () {
    $terminal = makeWindowTerminal();
    $admin = windowSuperAdmin();

    $terminal->closeLinkWindow($admin->id);
    expect($terminal->events()->where('type', 'link_window_closed')->exists())->toBeFalse();

    $terminal->openLinkWindow($admin);
    $terminal->closeLinkWindow($admin->id, 'manual');

    expect($terminal->fresh()->hasOpenLinkWindow())->toBeFalse()
        ->and($terminal->events()->where('type', 'link_window_closed')->first()->payload['reason'])->toBe('manual');
});

// ─── Auto-aprobación de solicitudes ─────────────────────────────────────────

it('una solicitud creada con la ventana abierta se aprueba sola a nombre de quien la abrió, sin avisar a los admins', function () {
    $terminal = makeWindowTerminal();
    $admin = windowSuperAdmin();
    $terminal->openLinkWindow($admin);

    $result = app(TerminalPairingService::class)->request($terminal->fresh(), '10.0.0.5', 'UA', null);
    $request = $result['request']->fresh();

    expect($request->status)->toBe(TerminalPairingRequest::STATUS_APPROVED)
        ->and($request->auto_approved)->toBeTrue()
        ->and($request->approved_by_id)->toBe($admin->id)
        ->and($request->approved_at)->not->toBeNull()
        ->and($terminal->events()->where('type', 'pairing_auto_approved')->exists())->toBeTrue();

    Notification::assertNothingSent();
});

it('la ventana se cierra con la primera solicitud: la segunda necesita aprobación manual', function () {
    $terminal = makeWindowTerminal();
    $terminal->openLinkWindow(windowSuperAdmin());

    $first = app(TerminalPairingService::class)->request($terminal->fresh(), null, null, null)['request'];
    $second = app(TerminalPairingService::class)->request($terminal->fresh(), null, null, null)['request'];

    expect($first->fresh()->auto_approved)->toBeTrue()
        ->and($second->fresh()->status)->toBe(TerminalPairingRequest::STATUS_PENDING)
        ->and($second->fresh()->auto_approved)->toBeFalse()
        ->and($terminal->fresh()->hasOpenLinkWindow())->toBeFalse()
        ->and($terminal->events()->where('type', 'link_window_closed')->first()->payload['reason'])->toBe('auto_approved');
});

it('sin ventana abierta (o vencida) la solicitud queda pendiente y avisa a los admins', function () {
    $terminal = makeWindowTerminal(['link_window_until' => now()->subMinute()]);
    $manager = User::factory()->create();
    $manager->givePermissionTo(Permission::firstOrCreate(['name' => 'update_terminal', 'guard_name' => 'web']));

    $request = app(TerminalPairingService::class)->request($terminal, null, null, null)['request'];

    expect($request->fresh()->status)->toBe(TerminalPairingRequest::STATUS_PENDING)
        ->and($request->fresh()->auto_approved)->toBeFalse();

    Notification::assertSentTo($manager, TerminalPairingRequestedNotification::class);
});

it('el dispositivo recibe el token en el siguiente poll tras la auto-aprobación y la bitácora queda completa', function () {
    $terminal = makeWindowTerminal();
    $admin = windowSuperAdmin();
    $terminal->openLinkWindow($admin);

    $secret = app(TerminalPairingService::class)->request($terminal->fresh(), '10.0.0.9', 'Mozilla/5.0 (Linux; Android 13)', null)['poll_secret'];

    $this->postJson('/api/v1/terminal-pairing/status', [], ['Authorization' => "Bearer {$secret}"])
        ->assertOk()
        ->assertJson(['status' => 'claimed'])
        ->assertJsonStructure(['token']);

    expect($terminal->events()->pluck('type')->all())->toEqual([
        'link_window_opened', 'pairing_requested', 'pairing_auto_approved', 'link_window_closed', 'linked', 'pairing_claimed',
    ]);
});

it('crear la solicitud por la API con la ventana abierta responde 201 y deja la solicitud aprobada', function () {
    $terminal = makeWindowTerminal();
    $terminal->openLinkWindow(windowSuperAdmin());

    $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code])->assertCreated();

    expect($terminal->pairingRequests()->first()->status)->toBe(TerminalPairingRequest::STATUS_APPROVED);
});

// ─── Acciones de Filament ───────────────────────────────────────────────────

/** Acciones del encabezado del detalle, aplanando el grupo "Más acciones". */
function flatHeaderActions($test)
{
    return collect($test->instance()->getCachedHeaderActions())
        ->flatMap(fn ($a) => $a instanceof ActionGroup ? $a->getFlatActions() : [$a]);
}

it('abrir la ventana desde el detalle pide confirmación, la abre a nombre del usuario y habilita "Cerrar ventana"', function () {
    $admin = windowSuperAdmin();
    $this->actingAs($admin);
    $terminal = makeWindowTerminal();

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])
        ->callAction('open_link_window')
        ->assertHasNoActionErrors();

    expect($terminal->fresh()->hasOpenLinkWindow())->toBeTrue()
        ->and($terminal->fresh()->link_window_opened_by_id)->toBe($admin->id);

    $test = Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()]);
    $actions = flatHeaderActions($test);
    expect($actions->first(fn ($a) => $a->getName() === 'open_link_window')->isVisible())->toBeFalse()
        ->and($actions->first(fn ($a) => $a->getName() === 'close_link_window')->isVisible())->toBeTrue();

    $test->callAction('close_link_window');
    expect($terminal->fresh()->hasOpenLinkWindow())->toBeFalse();
});

it('quien no tiene el permiso de gestionar terminales no ve las acciones de ventana', function () {
    $user = User::factory()->create();
    foreach (['view_any_terminal', 'view_terminal'] as $ability) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $ability, 'guard_name' => 'web']));
    }
    $this->actingAs($user);
    $terminal = makeWindowTerminal();
    $terminal->openLinkWindow(windowSuperAdmin());

    $actions = flatHeaderActions(Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()]));

    expect($actions->first(fn ($a) => $a->getName() === 'open_link_window')->isVisible())->toBeFalse()
        ->and($actions->first(fn ($a) => $a->getName() === 'close_link_window')->isVisible())->toBeFalse();
});

it('no se ofrece abrir la ventana en un terminal inactivo', function () {
    $this->actingAs(windowSuperAdmin());
    $terminal = makeWindowTerminal(['status' => 'inactive']);

    $action = flatHeaderActions(Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()]))
        ->first(fn ($a) => $a->getName() === 'open_link_window');

    expect($action->isVisible())->toBeFalse();
});

it('la acción masiva abre la ventana solo en terminales activos y sin ventana, y registra al usuario', function () {
    $admin = windowSuperAdmin();
    $this->actingAs($admin);
    $a = makeWindowTerminal();
    $b = makeWindowTerminal();
    $inactive = makeWindowTerminal(['status' => 'inactive']);
    $already = makeWindowTerminal();
    $already->openLinkWindow(windowSuperAdmin());
    $alreadyUntil = $already->fresh()->link_window_until;

    Livewire::test(ListTerminals::class)
        ->callTableBulkAction('open_link_window_bulk', [$a, $b, $inactive, $already])
        ->assertHasNoTableBulkActionErrors();

    expect($a->fresh()->hasOpenLinkWindow())->toBeTrue()
        ->and($a->fresh()->link_window_opened_by_id)->toBe($admin->id)
        ->and($b->fresh()->hasOpenLinkWindow())->toBeTrue()
        ->and($inactive->fresh()->hasOpenLinkWindow())->toBeFalse()
        ->and($already->fresh()->link_window_until->equalTo($alreadyUntil))->toBeTrue();
});

// ─── Detalle: sección "Dispositivo vinculado" ───────────────────────────────

it('el detalle muestra "Dispositivo vinculado" solo con un token vigente y dice cómo se vinculó', function () {
    $this->actingAs(windowSuperAdmin());
    $terminal = makeWindowTerminal();

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])->assertDontSee('Dispositivo vinculado');

    $terminal->claimSanctumToken('Mozilla/5.0 (Linux; Android 13)', null, '10.0.0.7', 'setup');

    Livewire::test(ViewTerminal::class, ['record' => $terminal->fresh()->getKey()])
        ->assertSee('Dispositivo vinculado')
        ->assertSee('Enlace de configuración')
        ->assertSee('10.0.0.7');
});

it('describeLinkOrigin() distingue enlace, código aprobado y ventana de vinculación', function () {
    $terminal = makeWindowTerminal();
    $admin = User::factory()->create(['name' => 'Ana Admin']);

    $terminal->claimSanctumToken(null, null, null, 'setup');
    expect(TerminalResource::describeLinkOrigin($terminal))->toBe('Enlace de configuración');

    $approved = $terminal->pairingRequests()->create([
        'code' => 'ABC234', 'poll_secret_hash' => str_repeat('a', 64), 'status' => 'claimed',
        'expires_at' => now()->addMinutes(10), 'approved_by_id' => $admin->id, 'claimed_at' => now(),
    ]);
    $terminal->claimSanctumToken(null, null, null, 'pairing');
    expect(TerminalResource::describeLinkOrigin($terminal))->toBe('Código de emparejamiento (aprobó Ana Admin)');

    $approved->update(['auto_approved' => true]);
    expect(TerminalResource::describeLinkOrigin($terminal))->toBe('Ventana de vinculación (abierta por Ana Admin)');
});

it('vincular por código invalida un enlace de configuración pendiente; por enlace lo conserva consumido', function () {
    $terminal = makeWindowTerminal();
    $terminal->generateSetupToken();
    $terminal->claimSanctumToken(null, null, null, 'pairing');
    expect($terminal->fresh()->setup_token)->toBeNull();

    $token = $terminal->generateSetupToken();
    $terminal->claimSanctumToken(null, null, null, 'setup');
    expect($terminal->fresh()->setupTokenState($token))->toBe('consumed');
});

// ─── Hoja de instalación (PDF) ──────────────────────────────────────────────

it('la hoja de instalación se sirve como PDF inline sin secretos y exige sesión', function () {
    $terminal = makeWindowTerminal();
    $terminal->generateSetupToken();

    $this->get(route('terminals.install-sheet', $terminal))->assertRedirect();

    $this->actingAs(windowSuperAdmin());
    $response = $this->get(route('terminals.install-sheet', $terminal));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('la hoja de instalación se niega a quien no puede ver terminales', function () {
    $terminal = makeWindowTerminal();
    $this->actingAs(User::factory()->create());

    $this->get(route('terminals.install-sheet', $terminal))->assertForbidden();
});

it('la hoja de instalación no incluye el token del enlace en el HTML renderizado', function () {
    $terminal = makeWindowTerminal();
    $token = $terminal->generateSetupToken();

    $html = view('pdf.terminal-install-sheet', [
        'terminal' => $terminal->load('branch.company'),
        'qrDataUri' => 'data:image/svg+xml;base64,AAAA',
        'companyLogo' => null,
        'companyName' => 'X',
        'windowMinutes' => 15,
    ])->render();

    expect($html)->not->toContain($token)->and($html)->toContain($terminal->url);
});
