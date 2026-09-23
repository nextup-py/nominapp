<?php

use App\Filament\Resources\PayrollPeriodResource\Pages\EditPayrollPeriod;
use App\Filament\Resources\PayrollPeriodResource\Pages\ViewPayrollPeriod;
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
function makePeriodPermEmployee(): Employee
{
    static $ci = 7800000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpPeriodPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucPeriodPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepPeriodPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosPeriodPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'PeriodPerm',
        'ci' => (string) $n,
        'email' => "periodperm{$n}@test.com",
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

/** Crea un período de nómina en el estado indicado. */
function makePeriodPermPeriod(string $status = 'processing'): PayrollPeriod
{
    static $offset = 0;
    $offset++;
    $start = Carbon::now()->startOfMonth()->addMonths($offset);

    return PayrollPeriod::create([
        'name' => $start->format('F Y'),
        'start_date' => $start->toDateString(),
        'end_date' => $start->copy()->endOfMonth()->toDateString(),
        'frequency' => 'monthly',
        'status' => $status,
    ]);
}

/** Crea un recibo de nómina dentro de un período, en el estado indicado. */
function makePeriodPermPayroll(PayrollPeriod $period, string $status = 'draft'): Payroll
{
    $employee = makePeriodPermEmployee();

    return Payroll::create([
        'employee_id' => $employee->id,
        'payroll_period_id' => $period->id,
        'status' => $status,
        'base_salary' => 2_550_000,
        'gross_salary' => 2_550_000,
        'net_salary' => 2_550_000,
        'total_deductions' => 0,
        'total_perceptions' => 0,
        'payment_method' => $status === 'approved' ? 'cash' : 'transfer',
    ]);
}

/**
 * Crea un usuario con un Role de prueba que tiene los permisos de negocio indicados,
 * más los permisos CRUD view_any/view/update de PayrollPeriod (requeridos por BasePolicy
 * para acceder a ViewRecord/EditRecord — este último exige además `update_payroll_period`
 * vía `canEdit()`) — constantes en ambos escenarios (hidden/visible) para que nunca sean
 * ellos quienes determinen el resultado de la aserción.
 */
function actingAsPeriodPermUser(array $businessPermissions): User
{
    static $roleN = 0;
    $roleN++;

    $permissions = array_merge(
        ['view_any_payroll_period', 'view_payroll_period', 'update_payroll_period'],
        $businessPermissions
    );

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => "Test Role Period {$roleN}", 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('respeta el permiso generate_payrolls_period en generate_payrolls y regenerate_payrolls (Ver y Editar)', function () {
    $period = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($period, 'approved'); // hace visible a regenerate_payrolls

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('generate_payrolls');
    Livewire::test(EditPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('generate_payrolls')
        ->assertActionHidden('regenerate_payrolls');

    $this->actingAs(actingAsPeriodPermUser(['generate_payrolls_period']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('generate_payrolls');
    Livewire::test(EditPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('generate_payrolls')
        ->assertActionVisible('regenerate_payrolls');
});

it('respeta el permiso close_payroll_period en close_period (Ver y Editar)', function () {
    $viewPeriod = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($viewPeriod, 'paid'); // hace visible a close_period en ViewPayrollPeriod (payrolls()->exists())

    $editPeriod = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($editPeriod, 'paid'); // hace visible a close_period en EditPayrollPeriod (todos pagados)

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $viewPeriod->getRouteKey()])
        ->assertActionHidden('close_period');
    Livewire::test(EditPayrollPeriod::class, ['record' => $editPeriod->getRouteKey()])
        ->assertActionHidden('close_period');

    $this->actingAs(actingAsPeriodPermUser(['close_payroll_period']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $viewPeriod->getRouteKey()])
        ->assertActionVisible('close_period');
    Livewire::test(EditPayrollPeriod::class, ['record' => $editPeriod->getRouteKey()])
        ->assertActionVisible('close_period');
});

it('respeta el permiso reopen_payroll_period en reopen_period (Ver y Editar)', function () {
    $period = makePeriodPermPeriod('closed');

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('reopen_period');
    Livewire::test(EditPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('reopen_period');

    $this->actingAs(actingAsPeriodPermUser(['reopen_payroll_period']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('reopen_period');
    Livewire::test(EditPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('reopen_period');
});

it('respeta el permiso approve_payroll (reutilizado) en approve_all_payrolls', function () {
    $period = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($period, 'draft');

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('approve_all_payrolls');

    $this->actingAs(actingAsPeriodPermUser(['approve_payroll']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('approve_all_payrolls');
});

it('respeta el permiso mark_paid_payroll (reutilizado) en mark_cash_paid', function () {
    $period = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($period, 'approved'); // payment_method 'cash' por el helper

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('mark_cash_paid');

    $this->actingAs(actingAsPeriodPermUser(['mark_paid_payroll']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('mark_cash_paid');
});

it('respeta el permiso create_disbursement_batch (reutilizado) en create_payroll_batch', function () {
    $period = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($period, 'approved'); // payment_method 'cash' por el helper — no hace falta transfer para este chequeo de visibilidad

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('create_payroll_batch');

    $this->actingAs(actingAsPeriodPermUser(['create_disbursement_batch']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('create_payroll_batch');
});
