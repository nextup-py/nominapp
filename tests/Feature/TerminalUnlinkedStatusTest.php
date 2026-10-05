<?php

use App\Filament\Resources\BranchResource\Pages\ViewBranch;
use App\Filament\Resources\BranchResource\RelationManagers\TerminalsRelationManager;
use App\Filament\Resources\TerminalResource\Pages\ListTerminals;
use App\Filament\Resources\TerminalResource\Pages\ViewTerminal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeUnlinkedStatusTerminal(string $name, bool $linked, ?Branch $branch = null): Terminal
{
    static $n = 7800000;
    $n++;

    if (! $branch) {
        $company = Company::create(['name' => "Empresa Unl {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
        $branch = Branch::create(['name' => "Sucursal Unl {$n}", 'company_id' => $company->id]);
    }

    $terminal = Terminal::create(['name' => $name, 'branch_id' => $branch->id]);

    if ($linked) {
        $terminal->createToken('kiosk:'.$terminal->code, [Terminal::SYNC_ABILITY]);
    }

    return $terminal;
}

function actAsSuperAdminForUnlinked(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

it('el listado muestra el badge "Sin vincular" solo en terminales sin token vigente', function () {
    actAsSuperAdminForUnlinked();
    $unlinked = makeUnlinkedStatusTerminal('Terminal Sin Token', linked: false);
    $linked = makeUnlinkedStatusTerminal('Terminal Con Token', linked: true);
    $linked->update(['last_heartbeat_at' => now()]);

    Livewire::test(ListTerminals::class)
        ->assertCanSeeTableRecords([$unlinked, $linked])
        ->assertTableColumnFormattedStateSet('connectivity_status', 'Sin vincular', $unlinked)
        ->assertTableColumnFormattedStateSet('connectivity_status', 'En línea', $linked);
});

it('el filtro de conectividad separa sin vincular de los demás estados', function () {
    actAsSuperAdminForUnlinked();
    $settings = app(GeneralSettings::class);
    $settings->terminal_stale_threshold_hours = 2;
    $settings->save();

    $unlinked = makeUnlinkedStatusTerminal('T Sin Vincular', linked: false);
    // Un terminal revocado que tuvo heartbeat reciente sigue siendo "sin vincular".
    $revoked = makeUnlinkedStatusTerminal('T Revocado', linked: false);
    $revoked->update(['last_heartbeat_at' => now()]);
    $never = makeUnlinkedStatusTerminal('T Nunca Conectado', linked: true);
    $online = makeUnlinkedStatusTerminal('T En Linea', linked: true);
    $online->update(['last_heartbeat_at' => now()->subMinutes(10)]);
    $stale = makeUnlinkedStatusTerminal('T Desconectado', linked: true);
    $stale->update(['last_heartbeat_at' => now()->subHours(5)]);

    Livewire::test(ListTerminals::class)
        ->filterTable('connectivity_status', 'unlinked')
        ->assertCanSeeTableRecords([$unlinked, $revoked])
        ->assertCanNotSeeTableRecords([$never, $online, $stale])
        ->filterTable('connectivity_status', 'never_connected')
        ->assertCanSeeTableRecords([$never])
        ->assertCanNotSeeTableRecords([$unlinked, $revoked, $online, $stale])
        ->filterTable('connectivity_status', 'online')
        ->assertCanSeeTableRecords([$online])
        ->assertCanNotSeeTableRecords([$unlinked, $revoked, $never, $stale])
        ->filterTable('connectivity_status', 'stale')
        ->assertCanSeeTableRecords([$stale])
        ->assertCanNotSeeTableRecords([$unlinked, $revoked, $never, $online]);
});

it('la vista del terminal muestra "Sin vincular" cuando no tiene token', function () {
    actAsSuperAdminForUnlinked();
    $terminal = makeUnlinkedStatusTerminal('Terminal Vista', linked: false);

    Livewire::test(ViewTerminal::class, ['record' => $terminal->getKey()])
        ->assertSee('Sin vincular');
});

it('el RelationManager de la sucursal muestra el estado "Sin vincular"', function () {
    actAsSuperAdminForUnlinked();
    $terminal = makeUnlinkedStatusTerminal('Terminal Branch', linked: false);

    Livewire::test(TerminalsRelationManager::class, [
        'ownerRecord' => $terminal->branch,
        'pageClass' => ViewBranch::class,
    ])->assertTableColumnFormattedStateSet('connectivity_status', 'Sin vincular', $terminal);
});

it('la página del terminal incluye la pantalla bloqueante "Terminal sin vincular"', function () {
    $terminal = makeUnlinkedStatusTerminal('Terminal Pantalla', linked: false);

    $this->get(route('terminal.show', $terminal->code))
        ->assertSuccessful()
        ->assertSee('id="unlinkedScreen"', false)
        ->assertSee('Terminal sin vincular')
        ->assertSee('Pedí un nuevo enlace de configuración al administrador.')
        ->assertSee('id="btnUnlinkedReload"', false);
});

it('el listado no consulta tokens por cada terminal (sin N+1)', function () {
    actAsSuperAdminForUnlinked();
    foreach (range(1, 4) as $i) {
        makeUnlinkedStatusTerminal("Terminal N+1 {$i}", linked: $i % 2 === 0);
    }

    $tokenQueries = 0;
    DB::listen(function ($query) use (&$tokenQueries) {
        if (str_contains($query->sql, 'personal_access_tokens')) {
            $tokenQueries++;
        }
    });

    Livewire::test(ListTerminals::class)->assertCountTableRecords(4);

    // Solo la subconsulta EXISTS de la query del listado — ni el badge ni las acciones de fila
    // (ej. "Revocar token") consultan tokens por terminal.
    expect($tokenQueries)->toBe(1);
});
