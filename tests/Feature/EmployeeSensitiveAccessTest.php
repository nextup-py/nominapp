<?php

use App\Exports\EmployeeReportExport;
use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Resources\EmployeeResource\RelationManagers\BankAccountsRelationManager;
use App\Filament\Resources\EmployeeResource\RelationManagers\LeavesRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeChild;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeLeave;
use App\Models\EmployeePerception;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function sensitiveEmployee(): Employee
{
    static $n = 4400000;
    $n++;
    $company = Company::create(['name' => "EmpSA {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucSA {$n}", 'company_id' => $company->id]);

    return Employee::create(['first_name' => 'Sara', 'last_name' => "Sensible{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);
}

function sensitiveUser(array $permissions): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Rol '.implode(',', $permissions), 'guard_name' => 'web']));
    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    return $user;
}

it('las pestañas sin permisos propios se atan a los permisos de Empleados', function (string $model) {
    $readOnly = sensitiveUser(['view_any_employee', 'view_employee']);
    $editor = sensitiveUser(['view_any_employee', 'view_employee', 'update_employee']);
    $support = sensitiveUser(['view_any_employee']);
    $record = new $model;

    expect(Gate::forUser($readOnly)->allows('viewAny', $model))->toBeTrue()
        ->and(Gate::forUser($readOnly)->allows('create', $model))->toBeFalse()
        ->and(Gate::forUser($readOnly)->allows('update', $record))->toBeFalse()
        ->and(Gate::forUser($readOnly)->allows('delete', $record))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('create', $model))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('delete', $record))->toBeTrue()
        ->and(Gate::forUser($support)->allows('view', $record))->toBeFalse();
})->with([
    EmployeeAddress::class,
    EmployeeBankAccount::class,
    EmployeeChild::class,
    EmployeeDeduction::class,
    EmployeePerception::class,
]);

it('aprobar una licencia desde la ficha exige el permiso de aprobar', function () {
    $employee = sensitiveEmployee();
    $leave = EmployeeLeave::create(['employee_id' => $employee->id, 'type' => 'other', 'start_date' => '2026-11-02', 'end_date' => '2026-11-03', 'generates_deduction' => false, 'status' => 'pending']);
    $params = ['ownerRecord' => $employee, 'pageClass' => ViewEmployee::class];

    Livewire::actingAs(sensitiveUser(['view_any_employee', 'view_employee', 'view_any_employee_leave', 'view_employee_leave', 'update_employee_leave']))
        ->test(LeavesRelationManager::class, $params)
        ->assertTableActionHidden('approve', $leave)
        ->assertTableActionHidden('reject', $leave);

    Livewire::actingAs(sensitiveUser(['view_any_employee', 'view_employee', 'view_any_employee_leave', 'view_employee_leave', 'approve_employee_leave', 'reject_employee_leave']))
        ->test(LeavesRelationManager::class, $params)
        ->assertTableActionVisible('approve', $leave)
        ->assertTableActionVisible('reject', $leave);
});

it('marcar una cuenta como principal exige poder editar empleados', function () {
    $employee = sensitiveEmployee();
    $account = EmployeeBankAccount::create(['employee_id' => $employee->id, 'bank' => 'Itaú', 'account_number' => '123456', 'account_type' => 'savings', 'holder_name' => 'Sara', 'is_primary' => false, 'status' => 'active']);
    $params = ['ownerRecord' => $employee, 'pageClass' => ViewEmployee::class];

    Livewire::actingAs(sensitiveUser(['view_any_employee', 'view_employee']))
        ->test(BankAccountsRelationManager::class, $params)
        ->assertTableActionHidden('mark_primary', $account);
});

it('el reporte de empleados oculta el salario a quien no ve contratos', function () {
    $this->actingAs(sensitiveUser(['view_any_employee']));
    expect(EmployeeReportExport::canSeeSalary())->toBeFalse()
        ->and(EmployeeReportExport::visibleColumns(['ci', 'salary', 'phone']))->toBe(['ci', 'phone']);

    $this->actingAs(sensitiveUser(['view_any_employee', 'view_any_contract']));
    expect(EmployeeReportExport::canSeeSalary())->toBeTrue()
        ->and(EmployeeReportExport::visibleColumns(['ci', 'salary']))->toBe(['ci', 'salary']);
});
