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
