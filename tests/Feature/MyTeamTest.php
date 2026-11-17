<?php

use App\Filament\Pages\MyTeam;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\LiveAttendanceMonitor;
use App\Services\TeamScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Sucursal nueva con empresa propia para aislar los casos. */
function teamBranch(string $name): Branch
{
    static $n = 1;
    $company = Company::create(['name' => "Emp Team {$n}", 'ruc' => "{$n}10-1", 'employer_number' => 7700000 + $n]);
    $n++;

    return Branch::create(['name' => $name, 'company_id' => $company->id]);
}

function teamEmployee(string $first, Branch $branch, ?Employee $boss = null, string $status = 'active'): Employee
{
    static $ci = 3000000;

    return Employee::create([
        'first_name' => $first, 'last_name' => 'Equipo', 'ci' => (string) $ci++, 'branch_id' => $branch->id,
        'status' => $status, 'reports_to_id' => $boss?->id,
    ]);
}

/** Usuario vinculado a una ficha, con los permisos de equipo indicados. */
function teamUser(?Employee $employee, array $permissions): User
{
    $user = User::factory()->create(['employee_id' => $employee?->id]);
    $user->assignRole(Role::firstOrCreate(['name' => 'Jefe', 'guard_name' => 'web']));
    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    return $user;
}

it('view_team incluye subordinados directos e indirectos y excluye al resto', function () {
    $branch = teamBranch('Central');
    $boss = teamEmployee('Jefe', $branch);
    $direct = teamEmployee('Directo', $branch, $boss);
    $indirect = teamEmployee('Indirecto', $branch, $direct);
    $other = teamEmployee('Ajeno', $branch);

    $ids = app(TeamScopeService::class)->employeeIds(teamUser($boss, ['view_team']));

    expect($ids)->toEqualCanonicalizing([$direct->id, $indirect->id])
        ->and($ids)->not->toContain($other->id, $boss->id);
});

it('view_branch_team suma a toda la sucursal activa sin duplicados ni al propio usuario', function () {
    $branch = teamBranch('Norte');
    $boss = teamEmployee('Super', $branch);
    $sub = teamEmployee('Sub', $branch, $boss);
    $peer = teamEmployee('Par', $branch);
    $inactive = teamEmployee('Baja', $branch, null, 'inactive');
    $elsewhere = teamEmployee('Otra', teamBranch('Sur'));

    $ids = app(TeamScopeService::class)->employeeIds(teamUser($boss, ['view_team', 'view_branch_team']));

    expect($ids)->toEqualCanonicalizing([$sub->id, $peer->id])
        ->and($ids)->not->toContain($inactive->id, $elsewhere->id, $boss->id);
});

it('sin ficha vinculada no hay equipo y la página lo explica', function () {
    $user = teamUser(null, ['view_team']);

    expect(app(TeamScopeService::class)->employeeIds($user))->toBe([]);

    Livewire::actingAs($user)->test(MyTeam::class)->assertSee('no está vinculado a una ficha');
});

it('un ciclo de superiores no cuelga el cálculo', function () {
    $branch = teamBranch('Ciclo');
    $a = teamEmployee('A', $branch);
    $b = teamEmployee('B', $branch, $a);
    $a->update(['reports_to_id' => $b->id]);

    expect(app(TeamScopeService::class)->employeeIds(teamUser($a, ['view_team'])))->toBe([$b->id]);
});

it('la página muestra solo a su equipo y exige alguno de los permisos', function () {
    $branch = teamBranch('Pagina');
    $boss = teamEmployee('Capataz', $branch);
    teamEmployee('Visible', $branch, $boss);
    teamEmployee('Oculto', $branch);

    Livewire::actingAs(teamUser($boss, ['view_team']))->test(MyTeam::class)
        ->assertSee('Visible Equipo')
        ->assertDontSee('Oculto Equipo');

    $this->actingAs(teamUser($boss, []));
    expect(MyTeam::canAccess())->toBeFalse();
});

it('el monitor acotado por employee_ids vacío no trae a nadie', function () {
    $branch = teamBranch('Vacio');
    teamEmployee('Alguien', $branch);

    expect(LiveAttendanceMonitor::snapshot(['employee_ids' => []])['total'])->toBe(0);
});
