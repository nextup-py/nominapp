<?php

use App\Filament\Resources\ContractResource\Pages\ListContracts;
use App\Filament\Resources\ContractResource\Pages\ViewContract;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();

    foreach (['activate_contract', 'renew_contract', 'suspend_contract', 'reactivate_contract', 'terminate_contract'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
});

/** @return array{employee: Employee, department: Department, position: Position} */
function makeContractTestSetup(): array
{
    static $ci = 9400000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpContract {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucContract {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepContract {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosContract {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Contract',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'email' => "contract{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    return ['employee' => $employee, 'department' => $department, 'position' => $position];
}

function makeTestContract(string $status, string $type = 'indefinido'): Contract
{
    $setup = makeContractTestSetup();

    return Contract::create([
        'employee_id' => $setup['employee']->id,
        'type' => $type,
        'start_date' => Carbon::now()->subMonths(6),
        'end_date' => $type === 'plazo_fijo' ? Carbon::now()->addMonths(6) : null,
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'payroll_type' => 'monthly',
        'position_id' => $setup['position']->id,
        'department_id' => $setup['department']->id,
        'status' => $status,
    ]);
}

it('oculta activate sin activate_contract y la muestra con él', function () {
    $contract = makeTestContract('draft');

    $roleWithout = Role::create(['name' => 'Sin Activar Contratos', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_contract', 'view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('activate');

    $roleWith = Role::create(['name' => 'Con Activar Contratos', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_contract', 'view_any_contract', 'activate_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible('activate');
});

it('oculta bulk_activate sin activate_contract y la muestra con él', function () {
    makeTestContract('draft');

    $roleWithout = Role::create(['name' => 'Sin Bulk Activar', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionHidden('bulk_activate');

    $roleWith = Role::create(['name' => 'Con Bulk Activar', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_contract', 'activate_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionVisible('bulk_activate');
});

it('oculta renew sin renew_contract y la muestra con él', function () {
    $contract = makeTestContract('active', 'plazo_fijo');

    $roleWithout = Role::create(['name' => 'Sin Renovar Contratos', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_contract', 'view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('renew');

    $roleWith = Role::create(['name' => 'Con Renovar Contratos', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_contract', 'view_any_contract', 'renew_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible('renew');
});

it('oculta suspend sin suspend_contract y la muestra con él', function () {
    $contract = makeTestContract('active');

    $roleWithout = Role::create(['name' => 'Sin Suspender Contratos', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_contract', 'view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('suspend');

    $roleWith = Role::create(['name' => 'Con Suspender Contratos', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_contract', 'view_any_contract', 'suspend_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible('suspend');
});

it('oculta bulk_suspend sin suspend_contract y la muestra con él', function () {
    makeTestContract('active');

    $roleWithout = Role::create(['name' => 'Sin Bulk Suspender', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionHidden('bulk_suspend');

    $roleWith = Role::create(['name' => 'Con Bulk Suspender', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_contract', 'suspend_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionVisible('bulk_suspend');
});

it('oculta reactivate sin reactivate_contract y la muestra con él', function () {
    $contract = makeTestContract('suspended');

    $roleWithout = Role::create(['name' => 'Sin Reactivar Contratos', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_contract', 'view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('reactivate');

    $roleWith = Role::create(['name' => 'Con Reactivar Contratos', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_contract', 'view_any_contract', 'reactivate_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible('reactivate');
});

it('oculta terminate sin terminate_contract y la muestra con él', function () {
    $contract = makeTestContract('active');

    $roleWithout = Role::create(['name' => 'Sin Terminar Contratos', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_contract', 'view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('terminate');

    $roleWith = Role::create(['name' => 'Con Terminar Contratos', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_contract', 'view_any_contract', 'terminate_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible('terminate');
});

it('oculta bulk_terminate sin terminate_contract y la muestra con él', function () {
    makeTestContract('active');

    $roleWithout = Role::create(['name' => 'Sin Bulk Terminar', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionHidden('bulk_terminate');

    $roleWith = Role::create(['name' => 'Con Bulk Terminar', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_contract', 'terminate_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionVisible('bulk_terminate');
});
