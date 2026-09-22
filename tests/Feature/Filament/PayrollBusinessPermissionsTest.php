<?php

use App\Filament\Resources\PayrollResource\Pages\ViewPayroll;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Crea un empleado con contrato activo, listo para tener recibos de nómina. */
function makePayrollPermEmployee(): Employee
{
    static $ci = 7500000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'Perm',
        'ci' => (string) $n,
        'email' => "perm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'payroll_type' => 'monthly',
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
        'payment_method' => 'transfer',
    ]);

    return $employee->fresh();
}

/** Crea un período de nómina mínimo. */
function makePayrollPermPeriod(): PayrollPeriod
{
    $start = Carbon::now()->startOfMonth();

    return PayrollPeriod::create([
        'name' => $start->format('F Y'),
        'start_date' => $start->toDateString(),
        'end_date' => $start->copy()->endOfMonth()->toDateString(),
        'frequency' => 'monthly',
        'status' => 'processing',
    ]);
}

/** Crea un recibo de nómina en el estado indicado. */
function makePayrollPermRecord(string $status = 'draft'): Payroll
{
    $employee = makePayrollPermEmployee();
    $period = makePayrollPermPeriod();

    return Payroll::create([
        'employee_id' => $employee->id,
        'payroll_period_id' => $period->id,
        'status' => $status,
        'base_salary' => 2_550_000,
        'gross_salary' => 2_550_000,
        'net_salary' => 2_550_000,
        'total_deductions' => 0,
        'total_perceptions' => 0,
    ]);
}

/**
 * Crea un usuario con un Role de prueba que tiene los permisos CRUD de Payroll
 * (necesarios para acceder al recurso vía ViewRecord) más los permisos de negocio indicados.
 */
function actingAsPayrollPermUser(array $businessPermissions): User
{
    static $roleN = 0;
    $roleN++;

    $permissions = array_merge(['view_any_payroll', 'view_payroll'], $businessPermissions);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => "Test Role Payroll {$roleN}", 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('respeta el permiso approve_payroll en la acción Aprobar', function () {
    $payroll = makePayrollPermRecord('draft');

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionHidden('approve');

    $this->actingAs(actingAsPayrollPermUser(['approve_payroll']));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionVisible('approve');
});

it('respeta el permiso disburse_payroll en la acción Marcar Acreditado', function () {
    $payroll = makePayrollPermRecord('approved');

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionHidden('mark_disbursed');

    $this->actingAs(actingAsPayrollPermUser(['disburse_payroll']));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionVisible('mark_disbursed');
});

it('respeta el permiso mark_paid_payroll en la acción Marcar Pagado', function () {
    $payroll = makePayrollPermRecord('disbursed');

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionHidden('mark_paid');

    $this->actingAs(actingAsPayrollPermUser(['mark_paid_payroll']));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionVisible('mark_paid');
});

it('respeta el permiso revert_payroll en las acciones revert_paid, revert_to_approved y unapprove', function () {
    $paid = makePayrollPermRecord('paid');
    $disbursed = makePayrollPermRecord('disbursed'); // disbursement_batch_id es null por defecto
    $approved = makePayrollPermRecord('approved');

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(ViewPayroll::class, ['record' => $paid->getRouteKey()])
        ->assertActionHidden('revert_paid');
    Livewire::test(ViewPayroll::class, ['record' => $disbursed->getRouteKey()])
        ->assertActionHidden('revert_to_approved');
    Livewire::test(ViewPayroll::class, ['record' => $approved->getRouteKey()])
        ->assertActionHidden('unapprove');

    $this->actingAs(actingAsPayrollPermUser(['revert_payroll']));
    Livewire::test(ViewPayroll::class, ['record' => $paid->getRouteKey()])
        ->assertActionVisible('revert_paid');
    Livewire::test(ViewPayroll::class, ['record' => $disbursed->getRouteKey()])
        ->assertActionVisible('revert_to_approved');
    Livewire::test(ViewPayroll::class, ['record' => $approved->getRouteKey()])
        ->assertActionVisible('unapprove');
});

it('respeta el permiso regenerate_payroll en la acción Regenerar', function () {
    $payroll = makePayrollPermRecord('draft');

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionHidden('regenerate');

    $this->actingAs(actingAsPayrollPermUser(['regenerate_payroll']));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionVisible('regenerate');
});
