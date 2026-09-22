<?php

use App\Filament\Resources\AguinaldoPeriodResource\Pages\ViewAguinaldoPeriod;
use App\Models\Aguinaldo;
use App\Models\AguinaldoPeriod;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crea una empresa aislada por ID incremental para pruebas de permisos
 * de negocio de AguinaldoPeriod.
 */
function makeAguinaldoPeriodPermTestCompany(): Company
{
    static $n = 8700000;
    $n++;

    return Company::create([
        'name' => "EmpAguPerPerm {$n}",
        'ruc' => "{$n}-1",
        'employer_number' => $n,
    ]);
}

/**
 * Crea un empleado con contrato activo dentro de la empresa dada.
 */
function makeAguinaldoPeriodPermTestEmployee(Company $company): Employee
{
    static $ci = 8800000;
    $n = $ci++;

    $branch = Branch::create(['name' => "SucAguPerPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepAguPerPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosAguPerPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'AguPerPerm',
        'ci' => (string) $n,
        'email' => "aguperperm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYears(2),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/**
 * Crea un AguinaldoPeriod en el estado indicado.
 */
function makeAguinaldoPeriodPermTest(string $status): AguinaldoPeriod
{
    $company = makeAguinaldoPeriodPermTestCompany();

    return AguinaldoPeriod::create([
        'company_id' => $company->id,
        'year' => 2026,
        'status' => $status,
    ]);
}

/**
 * Crea un usuario autenticado con un rol de prueba que tiene exactamente
 * los permisos indicados (aislado, no depende de los roles sembrados), más
 * los permisos CRUD base necesarios para montar las páginas Filament de
 * AguinaldoPeriod.
 */
function actingAsWithAguinaldoPeriodPerms(array $permissions): User
{
    $basePermissions = ['view_any_aguinaldo_period', 'view_aguinaldo_period'];

    foreach (array_unique(array_merge($basePermissions, $permissions)) as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions(array_unique(array_merge($basePermissions, $permissions)));

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

it('oculta generar aguinaldos en ViewAguinaldoPeriod sin el permiso generate_aguinaldos_period', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('draft');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('generate_aguinaldos');
});

it('muestra generar aguinaldos en ViewAguinaldoPeriod con el permiso generate_aguinaldos_period y estado draft', function () {
    actingAsWithAguinaldoPeriodPerms(['generate_aguinaldos_period']);
    $period = makeAguinaldoPeriodPermTest('draft');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('generate_aguinaldos');
});

it('oculta pagar todos en ViewAguinaldoPeriod sin el permiso mark_paid_aguinaldo', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('processing');
    Aguinaldo::create([
        'aguinaldo_period_id' => $period->id,
        'employee_id' => makeAguinaldoPeriodPermTestEmployee($period->company)->id,
        'total_earned' => 2_550_000,
        'months_worked' => 12,
        'aguinaldo_amount' => 212_500,
        'status' => 'pending',
        'generated_at' => now(),
    ]);

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('mark_all_paid');
});

it('muestra pagar todos en ViewAguinaldoPeriod con el permiso mark_paid_aguinaldo, estado processing y pendientes', function () {
    actingAsWithAguinaldoPeriodPerms(['mark_paid_aguinaldo']);
    $period = makeAguinaldoPeriodPermTest('processing');
    Aguinaldo::create([
        'aguinaldo_period_id' => $period->id,
        'employee_id' => makeAguinaldoPeriodPermTestEmployee($period->company)->id,
        'total_earned' => 2_550_000,
        'months_worked' => 12,
        'aguinaldo_amount' => 212_500,
        'status' => 'pending',
        'generated_at' => now(),
    ]);

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('mark_all_paid');
});

it('oculta enviar al banco en ViewAguinaldoPeriod sin el permiso create_disbursement_batch', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('send_to_bank');
});

it('muestra enviar al banco en ViewAguinaldoPeriod con el permiso create_disbursement_batch y estado processing', function () {
    actingAsWithAguinaldoPeriodPerms(['create_disbursement_batch']);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('send_to_bank');
});

it('oculta cerrar período en ViewAguinaldoPeriod sin el permiso close_aguinaldo_period', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('close_period');
});

it('muestra cerrar período en ViewAguinaldoPeriod con el permiso close_aguinaldo_period y estado processing', function () {
    actingAsWithAguinaldoPeriodPerms(['close_aguinaldo_period']);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('close_period');
});

it('oculta reabrir período en ViewAguinaldoPeriod sin el permiso reopen_aguinaldo_period', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('closed');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('reopen_period');
});

it('muestra reabrir período en ViewAguinaldoPeriod con el permiso reopen_aguinaldo_period y estado closed', function () {
    actingAsWithAguinaldoPeriodPerms(['reopen_aguinaldo_period']);
    $period = makeAguinaldoPeriodPermTest('closed');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('reopen_period');
});

it('oculta eliminar período en ViewAguinaldoPeriod sin el permiso delete_aguinaldo_period', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('force_delete');
});

it('muestra eliminar período en ViewAguinaldoPeriod con el permiso delete_aguinaldo_period y estado processing', function () {
    actingAsWithAguinaldoPeriodPerms(['delete_aguinaldo_period']);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('force_delete');
});
