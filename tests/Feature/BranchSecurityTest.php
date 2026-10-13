<?php

use App\Exports\BranchesExport;
use App\Filament\Resources\BranchResource\Pages\ListBranches;
use App\Filament\Resources\BranchResource\Pages\ViewBranch;
use App\Filament\Resources\BranchResource\RelationManagers\AuditsRelationManager;
use App\Models\AttendanceMarkFailure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function branchAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

function makeSecurityBranch(string $name = 'Sucursal Test'): Branch
{
    $company = Company::factory()->create();

    return Branch::create(['company_id' => $company->id, 'name' => $name]);
}

// ─── Eliminación masiva ─────────────────────────────────────────────────────

it('la eliminación masiva borra solo las sucursales vacías y omite las que tienen datos', function () {
    $this->actingAs(branchAdmin());

    $empty = makeSecurityBranch('Vacía');
    $withEmployees = makeSecurityBranch('Con empleados');
    $withTerminal = makeSecurityBranch('Con terminal');
    $withFailure = makeSecurityBranch('Con falla');

    Employee::factory()->create(['branch_id' => $withEmployees->id]);
    Terminal::create(['name' => 'Terminal X', 'branch_id' => $withTerminal->id]);
    AttendanceMarkFailure::record([
        'mode' => 'terminal',
        'failure_type' => 'sync_conflict',
        'branch_id' => $withFailure->id,
        'failure_message' => 'x',
    ]);

    Livewire::test(ListBranches::class)
        ->callTableBulkAction('delete', [$empty, $withEmployees, $withTerminal, $withFailure])
        ->assertHasNoTableBulkActionErrors();

    expect(Branch::find($empty->id))->toBeNull()
        ->and(Branch::find($withEmployees->id))->not->toBeNull()
        ->and(Branch::find($withTerminal->id))->not->toBeNull()
        ->and(Branch::find($withFailure->id))->not->toBeNull();
});

it('la eliminación masiva no lanza error si ninguna sucursal se puede borrar', function () {
    $this->actingAs(branchAdmin());

    $branch = makeSecurityBranch();
    Employee::factory()->create(['branch_id' => $branch->id]);

    Livewire::test(ListBranches::class)
        ->callTableBulkAction('delete', [$branch])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('No se eliminó ninguna sucursal');

    expect(Branch::find($branch->id))->not->toBeNull();
});

it('la eliminación masiva omite las sucursales si el usuario no tiene permiso de eliminar', function () {
    $permission = Permission::firstOrCreate(['name' => 'view_any_branch', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'Solo ver sucursales', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    $branch = makeSecurityBranch();

    Livewire::test(ListBranches::class)
        ->callTableBulkAction('delete', [$branch]);

    expect(Branch::find($branch->id))->not->toBeNull();
});

// ─── Auditoría ──────────────────────────────────────────────────────────────

it('audita los cambios de la sucursal y los muestra en el historial', function () {
    config(['audit.console' => true]); // los tests corren en consola, donde la auditoría viene apagada

    $branch = makeSecurityBranch('Central');
    $branch->update(['phone' => '0981123456', 'coordinates' => ['lat' => -25.3, 'lng' => -57.6]]);

    $audit = $branch->audits()->where('event', 'updated')->first();

    expect($audit->old_values)->toHaveKeys(['phone', 'coordinates'])
        ->and($audit->new_values['phone'])->toBe('0981123456')
        ->and($branch->audits()->where('event', 'created')->exists())->toBeTrue();

    Livewire::test(AuditsRelationManager::class, ['ownerRecord' => $branch, 'pageClass' => ViewBranch::class])
        ->assertCanSeeTableRecords($branch->audits)
        ->assertSee('Teléfono')
        ->assertSee('-25.3, -57.6');
});

it('no audita los timestamps de la sucursal', function () {
    config(['audit.console' => true]);

    $branch = makeSecurityBranch();
    $branch->touch();

    expect($branch->audits()->where('event', 'updated')->count())->toBe(0);
});

it('el historial de la sucursal muestra el nombre de la empresa al cambiarla', function () {
    config(['audit.console' => true]);

    $branch = makeSecurityBranch();
    $other = Company::factory()->create(['name' => 'Otra Empresa S.A.']);
    $branch->update(['company_id' => $other->id]);

    $audit = $branch->audits()->where('event', 'updated')->first();

    expect($branch->formatAuditFieldsForPresentation('new_values', $audit)->toHtml())->toContain('Otra Empresa S.A.');
});

// ─── Exportación ────────────────────────────────────────────────────────────

it('la exportación limita las sucursales a los ids recibidos', function () {
    $a = makeSecurityBranch('Alfa');
    $b = makeSecurityBranch('Beta');

    $names = (new BranchesExport(null, [$a->id]))->query()->pluck('name')->all();

    expect($names)->toBe(['Alfa']);
    expect((new BranchesExport)->query()->count())->toBe(2);
    expect((new BranchesExport(null, []))->query()->count())->toBe(0);
});

it('la exportación del listado respeta la búsqueda activa', function () {
    $this->actingAs(branchAdmin());

    makeSecurityBranch('Alfa');
    makeSecurityBranch('Beta');

    Livewire::test(ListBranches::class)
        ->searchTable('Alfa')
        ->callAction('export_excel')
        ->assertHasNoActionErrors();
});
