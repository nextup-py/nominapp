<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\TerminalEvent;
use App\Models\User;
use App\Notifications\TerminalLinkLostNotification;
use App\Support\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/** Terminal que perdió el vínculo en el dispositivo: el servidor conserva su token pero el navegador pide uno nuevo. */
function lostLinkTerminal(array $attributes = []): Terminal
{
    static $n = 7950000;
    $n++;

    $company = Company::create(['name' => "Empresa LL {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal LL {$n}", 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => "Terminal LL {$n}", 'branch_id' => $branch->id, ...$attributes]);
    $terminal->createToken('kiosk:'.$terminal->code, [Terminal::SYNC_ABILITY]);

    return $terminal->fresh();
}

function lostLinkManager(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::firstOrCreate(['name' => 'update_terminal', 'guard_name' => 'web']));

    return $user;
}

beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('terminal-pairing-create');
});

it('registra link_lost con el motivo y el estado del almacenamiento cuando el terminal lleva callado y sigue con acceso vigente', function () {
    $terminal = lostLinkTerminal(['last_heartbeat_at' => now()->subMinutes(10)]);

    $this->postJson('/api/v1/terminal-pairing', [
        'terminal_code' => $terminal->code,
        'context' => ['reason' => 'no_token', 'had_marker' => true, 'storage_available' => false, 'storage_persisted' => false, 'storage_quota_mb' => 120],
    ])->assertCreated();

    $event = TerminalEvent::where('type', 'link_lost')->sole();

    expect($event->terminal_id)->toBe($terminal->id)
        ->and($event->payload)->toMatchArray(['lost_reason' => 'no_token', 'had_marker' => true, 'storage_available' => false, 'storage_quota_mb' => 120])
        ->and($event->payload['seconds_since_heartbeat'])->toBeGreaterThanOrEqual(600)
        ->and($event->detail)->toContain('El navegador no conserva datos')
        ->and(TerminalEvent::getTypeLabels())->toHaveKey('link_lost')
        ->and(TerminalEvent::getTypeColors())->toHaveKey('link_lost');
});

it('avisa por la campanita a quienes administran terminales', function () {
    $manager = lostLinkManager();
    $other = User::factory()->create();
    $terminal = lostLinkTerminal();

    $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code, 'context' => ['reason' => 'no_token', 'storage_available' => false]])->assertCreated();

    Notification::assertSentTo($manager, TerminalLinkLostNotification::class);
    Notification::assertNotSentTo($other, TerminalLinkLostNotification::class);
});

it('no es una pérdida de vínculo si el terminal sigue latiendo: quien pide es otro dispositivo', function () {
    $terminal = lostLinkTerminal(['last_heartbeat_at' => now()->subSeconds(40)]);

    $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code, 'context' => ['reason' => 'no_token']])->assertCreated();

    expect(TerminalEvent::where('type', 'link_lost')->exists())->toBeFalse()
        ->and(TerminalEvent::where('type', 'pairing_requested')->exists())->toBeTrue();
    Notification::assertNothingSent();
});

it('sin acceso vigente (nunca vinculado o revocado) no es pérdida de vínculo', function () {
    $terminal = lostLinkTerminal();
    $terminal->tokens()->delete();

    $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code])->assertCreated();

    expect(TerminalEvent::where('type', 'link_lost')->exists())->toBeFalse();
    Notification::assertNotSentTo(lostLinkManager(), TerminalLinkLostNotification::class);
});

it('un dispositivo en bucle no llena la bitácora ni la campanita: una pérdida cada 30 minutos', function () {
    $manager = lostLinkManager();
    $terminal = lostLinkTerminal();

    foreach (range(1, 3) as $i) {
        RateLimiter::clear('terminal-pairing-create');
        $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code, 'context' => ['reason' => 'no_token']])->assertCreated();
    }

    expect(TerminalEvent::where('type', 'link_lost')->count())->toBe(1);
    Notification::assertSentToTimes($manager, TerminalLinkLostNotification::class, 1);

    $this->travel(31)->minutes();
    RateLimiter::clear('terminal-pairing-create');
    $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code, 'context' => ['reason' => 'no_token']])->assertCreated();

    expect(TerminalEvent::where('type', 'link_lost')->count())->toBe(2);
});

it('el contexto es opcional (cliente viejo) y se valida', function () {
    $terminal = lostLinkTerminal();

    $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code])->assertCreated();
    expect(TerminalEvent::where('type', 'link_lost')->sole()->payload)->not->toHaveKey('lost_reason');

    RateLimiter::clear('terminal-pairing-create');
    $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code, 'context' => ['reason' => 'cualquiera']])->assertUnprocessable();
    $this->postJson('/api/v1/terminal-pairing', ['terminal_code' => $terminal->code, 'context' => ['storage_quota_mb' => 'mucho']])->assertUnprocessable();
});

it('el aviso está en el catálogo de «Mis notificaciones» y va por correo cuando el correo está configurado', function () {
    $entry = NotificationCatalog::all()['terminal.link_lost'];

    expect($entry['permission'])->toBe('update_terminal')
        ->and($entry['category'])->toBe('terminal_health')
        ->and($entry['mandatory'])->toBeFalse();
});
