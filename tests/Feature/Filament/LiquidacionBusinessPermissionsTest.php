<?php

use App\Filament\Resources\LiquidacionResource\Pages\ListLiquidaciones;
use App\Filament\Resources\LiquidacionResource\Pages\ViewLiquidacion;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Liquidacion;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crea un empleado con contrato activo, aislado por CI incremental,
 * para pruebas de permisos de negocio de Liquidación.
 */
function makeLiquidacionPermTestEmployee(): Employee
{
    static $ci = 9500000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpLiqPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucLiqPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepLiqPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosLiqPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'LiqPerm',
        'ci' => (string) $n,
        'email' => "liqperm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYears(3),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/**
 * Crea una Liquidación en el estado indicado, siguiendo los campos
 * requeridos reales (ver tests/Feature/LiquidacionServiceTest.php::makeLiquidacion).
 */
function makeLiquidacionPermTest(string $status): Liquidacion
{
    $employee = makeLiquidacionPermTestEmployee();

    return Liquidacion::create([
        'employee_id' => $employee->id,
        'termination_date' => Carbon::create(2026, 3, 15)->toDateString(),
        'termination_type' => 'unjustified_dismissal',
        'preaviso_otorgado' => false,
        'hire_date' => Carbon::now()->subYears(3)->toDateString(),
        'base_salary' => 2_550_000,
        'daily_salary' => round(2_550_000 / 30, 2),
        'salary_type' => 'mensual',
        'status' => $status,
    ]);
}

/**
 * Crea un usuario autenticado con un rol de prueba que tiene exactamente
 * los permisos indicados (aislado, no depende de los roles sembrados).
 */
function actingAsWithLiquidacionPerms(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

it('oculta calcular en ViewLiquidacion sin el permiso calculate_liquidacion', function () {
    actingAsWithLiquidacionPerms(['view_any_liquidacion', 'view_liquidacion']);
    $liquidacion = makeLiquidacionPermTest('draft');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionHidden('calculate');
});

it('muestra calcular en ViewLiquidacion con el permiso calculate_liquidacion y estado draft', function () {
    actingAsWithLiquidacionPerms(['view_any_liquidacion', 'view_liquidacion', 'calculate_liquidacion']);
    $liquidacion = makeLiquidacionPermTest('draft');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionVisible('calculate');
});

it('oculta recalcular en ViewLiquidacion sin el permiso calculate_liquidacion', function () {
    actingAsWithLiquidacionPerms(['view_any_liquidacion', 'view_liquidacion']);
    $liquidacion = makeLiquidacionPermTest('calculated');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionHidden('recalculate');
});

it('muestra recalcular en ViewLiquidacion con el permiso calculate_liquidacion y estado calculated', function () {
    actingAsWithLiquidacionPerms(['view_any_liquidacion', 'view_liquidacion', 'calculate_liquidacion']);
    $liquidacion = makeLiquidacionPermTest('calculated');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionVisible('recalculate');
});

it('oculta cerrar en ViewLiquidacion sin el permiso close_liquidacion', function () {
    actingAsWithLiquidacionPerms(['view_any_liquidacion', 'view_liquidacion']);
    $liquidacion = makeLiquidacionPermTest('calculated');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionHidden('close');
});

it('muestra cerrar en ViewLiquidacion con el permiso close_liquidacion y estado calculated', function () {
    actingAsWithLiquidacionPerms(['view_any_liquidacion', 'view_liquidacion', 'close_liquidacion']);
    $liquidacion = makeLiquidacionPermTest('calculated');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionVisible('close');
});

it('oculta exportar en ListLiquidaciones sin el permiso export_liquidacion', function () {
    actingAsWithLiquidacionPerms(['view_any_liquidacion']);

    Livewire::test(ListLiquidaciones::class)
        ->assertActionHidden('export_excel');
});

it('muestra exportar en ListLiquidaciones con el permiso export_liquidacion', function () {
    actingAsWithLiquidacionPerms(['view_any_liquidacion', 'export_liquidacion']);

    Livewire::test(ListLiquidaciones::class)
        ->assertActionVisible('export_excel');
});
