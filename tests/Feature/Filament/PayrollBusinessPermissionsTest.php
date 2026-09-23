<?php

use App\Filament\Resources\PayrollPeriodResource\Pages\ViewPayrollPeriod;
use App\Filament\Resources\PayrollPeriodResource\RelationManagers\PayrollsRelationManager;
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

/*
|--------------------------------------------------------------------------
| PayrollsRelationManager (superficie primaria dentro de ViewPayrollPeriod)
|--------------------------------------------------------------------------
|
| Duplica la misma matriz de permisos que ViewPayroll/PayrollResource, ya
| que esta RelationManager reimplementa las mismas transiciones de estado
| de forma independiente (hallazgo C2 del review final de rama completa).
*/

it('respeta el permiso approve_payroll en la fila Aprobar de PayrollsRelationManager', function () {
    $payroll = makePayrollPermRecord('draft');
    $payroll->update(['payment_method' => 'cash']);
    $period = $payroll->period;

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionHidden('approve', $payroll);

    $this->actingAs(actingAsPayrollPermUser(['approve_payroll']));
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionVisible('approve', $payroll);
});

it('respeta el permiso disburse_payroll en la fila Marcar Acreditado de PayrollsRelationManager', function () {
    $payroll = makePayrollPermRecord('approved');
    $payroll->update(['payment_method' => 'transfer']);
    $period = $payroll->period;

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionHidden('mark_disbursed', $payroll);

    $this->actingAs(actingAsPayrollPermUser(['disburse_payroll']));
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionVisible('mark_disbursed', $payroll);
});

it('respeta el permiso mark_paid_payroll en la fila Marcar Pagado de PayrollsRelationManager', function () {
    $payroll = makePayrollPermRecord('disbursed');
    $payroll->update(['payment_method' => 'transfer']);
    $period = $payroll->period;

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionHidden('mark_paid', $payroll);

    $this->actingAs(actingAsPayrollPermUser(['mark_paid_payroll']));
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionVisible('mark_paid', $payroll);
});

it('respeta el permiso revert_payroll en revert_disbursed, revert_paid y unapprove de PayrollsRelationManager', function () {
    $disbursed = makePayrollPermRecord('disbursed');
    $disbursed->update(['payment_method' => 'transfer']);
    $paid = makePayrollPermRecord('paid');
    $paid->update(['payment_method' => 'transfer']);
    $approved = makePayrollPermRecord('approved');
    $approved->update(['payment_method' => 'cash']);

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $disbursed->period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionHidden('revert_disbursed', $disbursed);
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $paid->period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionHidden('revert_paid', $paid);
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $approved->period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionHidden('unapprove', $approved);

    $this->actingAs(actingAsPayrollPermUser(['revert_payroll']));
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $disbursed->period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionVisible('revert_disbursed', $disbursed);
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $paid->period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionVisible('revert_paid', $paid);
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $approved->period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionVisible('unapprove', $approved);
});

it('respeta el permiso regenerate_payroll en la fila Regenerar de PayrollsRelationManager', function () {
    $payroll = makePayrollPermRecord('draft');
    $payroll->update(['payment_method' => 'cash']);
    $period = $payroll->period;

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionHidden('regenerate', $payroll);

    $this->actingAs(actingAsPayrollPermUser(['regenerate_payroll']));
    Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $period, 'pageClass' => ViewPayrollPeriod::class])
        ->assertTableActionVisible('regenerate', $payroll);
});

it('respeta los permisos de negocio en las bulk actions de PayrollsRelationManager', function () {
    $period = makePayrollPermPeriod();

    $bulkActionsToPermissions = [
        'approve_selected' => 'approve_payroll',
        'mark_disbursed_selected' => 'disburse_payroll',
        'mark_paid_selected' => 'mark_paid_payroll',
        'revert_paid_selected' => 'revert_payroll',
        'unapprove_selected' => 'revert_payroll',
        'download_pdfs' => 'export_payroll',
    ];

    $this->actingAs(actingAsPayrollPermUser([]));
    $livewire = Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $period, 'pageClass' => ViewPayrollPeriod::class]);
    foreach (array_keys($bulkActionsToPermissions) as $name) {
        expect($livewire->instance()->getTable()->getBulkAction($name)->isVisible())
            ->toBeFalse("Se esperaba que la bulk action [{$name}] estuviera oculta sin el permiso correspondiente.");
    }

    foreach ($bulkActionsToPermissions as $name => $permission) {
        $this->actingAs(actingAsPayrollPermUser([$permission]));
        $livewire = Livewire::test(PayrollsRelationManager::class, ['ownerRecord' => $period, 'pageClass' => ViewPayrollPeriod::class]);
        expect($livewire->instance()->getTable()->getBulkAction($name)->isVisible())
            ->toBeTrue("Se esperaba que la bulk action [{$name}] fuera visible con el permiso [{$permission}].");
    }
});
