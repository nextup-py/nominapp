<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\User;
use App\Services\TerminalAttentionBanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/** Terminal activo de una sucursal nueva, vinculado o no. */
function bannerTerminal(string $name, bool $linked, ?string $heartbeat = null, array $attributes = []): Terminal
{
    static $n = 6600000;
    $n++;

    $company = Company::create(['name' => "EmpTB {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucTB {$n}", 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => $name, 'branch_id' => $branch->id, 'last_heartbeat_at' => $heartbeat, ...$attributes]);

    if ($linked) {
        $terminal->claimSanctumToken();
    }

    return $terminal->fresh();
}

/** Usuario con permiso de gestionar terminales (y verlos en el panel). */
function bannerManager(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        Permission::firstOrCreate(['name' => 'update_terminal', 'guard_name' => 'web']),
        Permission::firstOrCreate(['name' => 'view_any_terminal', 'guard_name' => 'web']),
    ]);

    return $user;
}

beforeEach(fn () => TerminalAttentionBanner::forget());

it('lista los terminales sin vincular o sin conexión y no los que están en línea ni los inactivos', function () {
    bannerTerminal('Sin vínculo', linked: false);
    bannerTerminal('Caído', linked: true, heartbeat: now()->subHours(5)->toDateTimeString());
    bannerTerminal('Bien', linked: true, heartbeat: now()->subSeconds(30)->toDateTimeString());
    bannerTerminal('Apagado', linked: false, attributes: ['status' => 'inactive']);

    $names = collect(TerminalAttentionBanner::items())->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['Caído', 'Sin vínculo']);
});

it('no muestra un terminal con un corte de menos de 30 minutos y sí uno más largo', function () {
    bannerTerminal('Corte corto', linked: true, heartbeat: now()->subMinutes(10)->toDateTimeString(), attributes: ['stale_after_minutes' => 5]);
    bannerTerminal('Corte largo', linked: true, heartbeat: now()->subMinutes(45)->toDateTimeString(), attributes: ['stale_after_minutes' => 5]);

    expect(collect(TerminalAttentionBanner::items())->pluck('name')->all())->toBe(['Corte largo']);
});

it('el cartel resume por motivo y deja el detalle desplegable', function () {
    bannerTerminal('Uno', linked: false);
    bannerTerminal('Dos', linked: true, heartbeat: now()->subHours(5)->toDateTimeString());
    bannerTerminal('Tres', linked: true, heartbeat: now()->subHours(6)->toDateTimeString());

    $this->actingAs(bannerManager());
    $html = view('filament.hooks.terminal-alert-banner')->render();

    expect($html)->toContain('3 terminales necesitan atención')->toContain('2 sin conexión · 1 sin vincular')->toContain('Ver el detalle');
});

it('no cuenta un terminal fuera de su horario de vigilancia', function () {
    $terminal = bannerTerminal('De noche', linked: true, heartbeat: now()->subHours(5)->toDateTimeString());
    $terminal->update(['watch_from' => now()->addHours(3)->format('H:i:s'), 'watch_to' => now()->addHours(5)->format('H:i:s')]);

    expect(TerminalAttentionBanner::items())->toBe([]);
});

it('el cartel se muestra a quien gestiona terminales y no a los demás', function () {
    bannerTerminal('Terminal Roto', linked: false);

    $this->actingAs(bannerManager());
    expect(view('filament.hooks.terminal-alert-banner')->render())->toContain('Terminal Roto')->toContain('sin vincular');

    $this->actingAs(User::factory()->create());
    expect(trim(view('filament.hooks.terminal-alert-banner')->render()))->toBe('');
});

it('sin terminales con problemas no hay cartel', function () {
    bannerTerminal('Bien', linked: true, heartbeat: now()->subSeconds(30)->toDateTimeString());

    $this->actingAs(bannerManager());

    expect(trim(view('filament.hooks.terminal-alert-banner')->render()))->toBe('');
});

it('cuenta los códigos de vinculación pendientes de un terminal sin vincular y el cartel invita a aprobarlos', function () {
    $terminal = bannerTerminal('Terminal Luque Test', false);
    $terminal->pairingRequests()->create([
        'code' => 'ABCDEF',
        'poll_secret_hash' => hash('sha256', 'x'),
        'status' => 'pending',
        'expires_at' => now()->addMinutes(8),
        'ip_address' => '127.0.0.1',
    ]);
    TerminalAttentionBanner::forget();

    $item = collect(TerminalAttentionBanner::items())->firstWhere('id', $terminal->id);

    expect($item['status'])->toBe('unlinked')
        ->and($item['pending'])->toBe(1);

    $this->actingAs(bannerManager());
    $html = view('filament.hooks.terminal-alert-banner')->render();

    expect($html)->toContain('Esperan aprobación')->toContain('Aprobar ahora');
});
