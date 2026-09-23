<?php

use App\Filament\Resources\AguinaldoResource\Pages\ListAguinaldos;
use App\Filament\Resources\AguinaldoResource\Pages\ViewAguinaldo;
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
 * de negocio de Aguinaldo.
 */
function makeAguinaldoPermTestCompany(): Company
{
    static $n = 8500000;
    $n++;

    return Company::create([
        'name' => "EmpAguPerm {$n}",
        'ruc' => "{$n}-1",
        'employer_number' => $n,
    ]);
}

/**
 * Crea un empleado con contrato activo dentro de la empresa dada.
 */
function makeAguinaldoPermTestEmployee(Company $company): Employee
{
    static $ci = 8600000;
    $n = $ci++;

    $branch = Branch::create(['name' => "SucAguPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepAguPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosAguPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'AguPerm',
        'ci' => (string) $n,
        'email' => "aguperm{$n}@test.com",
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
 * Crea un Aguinaldo en el estado indicado, dentro de un período 'processing',
 * siguiendo los campos requeridos reales (ver tests/Feature/AguinaldoServiceTest.php).
 */
function makeAguinaldoPermTest(string $status): Aguinaldo
{
    $company = makeAguinaldoPermTestCompany();
    $employee = makeAguinaldoPermTestEmployee($company);

    $period = AguinaldoPeriod::create([
        'company_id' => $company->id,
        'year' => 2026,
        'status' => 'processing',
    ]);

    return Aguinaldo::create([
        'aguinaldo_period_id' => $period->id,
        'employee_id' => $employee->id,
        'total_earned' => 2_550_000,
        'months_worked' => 12,
        'aguinaldo_amount' => 212_500,
        'status' => $status,
        'generated_at' => now(),
    ]);
}

/**
 * Crea un usuario autenticado con un rol de prueba que tiene exactamente
 * los permisos indicados (aislado, no depende de los roles sembrados), más
 * los permisos CRUD base necesarios para montar las páginas Filament de Aguinaldo.
 */
function actingAsWithAguinaldoPerms(array $permissions): User
{
    $basePermissions = ['view_any_aguinaldo', 'view_aguinaldo'];

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

it('oculta marcar pagado en ViewAguinaldo sin el permiso mark_paid_aguinaldo', function () {
    actingAsWithAguinaldoPerms([]);
    $aguinaldo = makeAguinaldoPermTest('pending');

    Livewire::test(ViewAguinaldo::class, ['record' => $aguinaldo->getRouteKey()])
        ->assertActionHidden('mark_paid');
});

it('muestra marcar pagado en ViewAguinaldo con el permiso mark_paid_aguinaldo y estado pending', function () {
    actingAsWithAguinaldoPerms(['mark_paid_aguinaldo']);
    $aguinaldo = makeAguinaldoPermTest('pending');

    Livewire::test(ViewAguinaldo::class, ['record' => $aguinaldo->getRouteKey()])
        ->assertActionVisible('mark_paid');
});

it('oculta marcar pendiente en ViewAguinaldo sin el permiso mark_paid_aguinaldo', function () {
    actingAsWithAguinaldoPerms([]);
    $aguinaldo = makeAguinaldoPermTest('paid');

    Livewire::test(ViewAguinaldo::class, ['record' => $aguinaldo->getRouteKey()])
        ->assertActionHidden('unmark_paid');
});

it('muestra marcar pendiente en ViewAguinaldo con el permiso mark_paid_aguinaldo y estado paid', function () {
    actingAsWithAguinaldoPerms(['mark_paid_aguinaldo']);
    $aguinaldo = makeAguinaldoPermTest('paid');

    Livewire::test(ViewAguinaldo::class, ['record' => $aguinaldo->getRouteKey()])
        ->assertActionVisible('unmark_paid');
});

it('oculta marcar pagado en la tabla de ListAguinaldos sin el permiso mark_paid_aguinaldo', function () {
    actingAsWithAguinaldoPerms([]);
    $aguinaldo = makeAguinaldoPermTest('pending');

    Livewire::test(ListAguinaldos::class)
        ->assertTableActionHidden('mark_paid', $aguinaldo);
});

it('muestra marcar pagado en la tabla de ListAguinaldos con el permiso mark_paid_aguinaldo', function () {
    actingAsWithAguinaldoPerms(['mark_paid_aguinaldo']);
    $aguinaldo = makeAguinaldoPermTest('pending');

    Livewire::test(ListAguinaldos::class)
        ->assertTableActionVisible('mark_paid', $aguinaldo);
});

it('oculta marcar pendiente en la tabla de ListAguinaldos sin el permiso mark_paid_aguinaldo', function () {
    actingAsWithAguinaldoPerms([]);
    $aguinaldo = makeAguinaldoPermTest('paid');

    Livewire::test(ListAguinaldos::class)
        ->assertTableActionHidden('unmark_paid', $aguinaldo);
});

it('muestra marcar pendiente en la tabla de ListAguinaldos con el permiso mark_paid_aguinaldo', function () {
    actingAsWithAguinaldoPerms(['mark_paid_aguinaldo']);
    $aguinaldo = makeAguinaldoPermTest('paid');

    Livewire::test(ListAguinaldos::class)
        ->assertTableActionVisible('unmark_paid', $aguinaldo);
});

it('respeta el permiso mark_paid_aguinaldo en las bulk actions bulk_mark_paid y bulk_unmark_paid de ListAguinaldos', function () {
    actingAsWithAguinaldoPerms([]);
    $livewire = Livewire::test(ListAguinaldos::class);
    expect($livewire->instance()->getTable()->getBulkAction('bulk_mark_paid')->isVisible())->toBeFalse();
    expect($livewire->instance()->getTable()->getBulkAction('bulk_unmark_paid')->isVisible())->toBeFalse();

    actingAsWithAguinaldoPerms(['mark_paid_aguinaldo']);
    $livewire = Livewire::test(ListAguinaldos::class);
    expect($livewire->instance()->getTable()->getBulkAction('bulk_mark_paid')->isVisible())->toBeTrue();
    expect($livewire->instance()->getTable()->getBulkAction('bulk_unmark_paid')->isVisible())->toBeTrue();
});

it('oculta exportar en ListAguinaldos sin el permiso export_aguinaldo', function () {
    actingAsWithAguinaldoPerms([]);

    Livewire::test(ListAguinaldos::class)
        ->assertActionHidden('export_excel');
});

it('muestra exportar en ListAguinaldos con el permiso export_aguinaldo', function () {
    actingAsWithAguinaldoPerms(['export_aguinaldo']);

    Livewire::test(ListAguinaldos::class)
        ->assertActionVisible('export_excel');
});
