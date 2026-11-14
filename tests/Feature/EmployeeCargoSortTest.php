<?php

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
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
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regresión: ordenar la lista de Empleados por «Cargo» fallaba con «Subquery returns more than 1 row»
 * cuando un empleado tenía más de un contrato (uno vencido y otro vigente, o renovaciones).
 */

/** Empleado con un contrato vencido anterior (cargo viejo) y uno vigente (cargo actual). */
function employeeWithTwoContracts(string $lastName, string $oldPosition, string $currentPosition): Employee
{
    static $n = 5300000;
    $n++;

    $company = Company::firstOrCreate(['ruc' => '5300000-1'], ['name' => 'EmpCargo', 'employer_number' => 5300000]);
    $branch = Branch::firstOrCreate(['name' => 'SucCargo', 'company_id' => $company->id]);
    $department = Department::firstOrCreate(['name' => 'DepCargo', 'company_id' => $company->id]);
    $old = Position::firstOrCreate(['name' => $oldPosition, 'department_id' => $department->id]);
    $current = Position::firstOrCreate(['name' => $currentPosition, 'department_id' => $department->id]);

    $employee = Employee::create(['first_name' => 'Test', 'last_name' => $lastName, 'ci' => (string) $n, 'email' => "cargo{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active']);

    $base = [
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'salary_type' => 'mensual',
        'salary' => 3_044_000,
        'payroll_type' => 'monthly',
        'payment_method' => 'cash',
        'work_modality' => 'presencial',
        'department_id' => $department->id,
    ];

    Contract::create($base + [
        'start_date' => Carbon::today()->subDays(400)->toDateString(),
        'position_id' => $old->id,
        'status' => 'expired',
    ]);
    Contract::create($base + [
        'start_date' => Carbon::today()->subDays(60)->toDateString(),
        'position_id' => $current->id,
        'status' => 'active',
    ]);

    $employee->update(['status' => 'active']);

    return $employee;
}

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

it('ordena por cargo sin error cuando un empleado tiene varios contratos', function (string $direction) {
    employeeWithTwoContracts('Uno', 'Zeta antiguo', 'Beta');
    employeeWithTwoContracts('Dos', 'Alfa antiguo', 'Gamma');

    Livewire::test(ListEmployees::class)
        ->sortTable('activeContract.position.name', $direction)
        ->assertSuccessful();
})->with(['asc', 'desc']);

it('ordena por el cargo del contrato vigente y no por el de un contrato anterior', function () {
    $beta = employeeWithTwoContracts('Uno', 'Zeta antiguo', 'Beta');
    $gamma = employeeWithTwoContracts('Dos', 'Alfa antiguo', 'Gamma');

    Livewire::test(ListEmployees::class)
        ->sortTable('activeContract.position.name', 'asc')
        ->assertCanSeeTableRecords([$beta, $gamma], inOrder: true)
        ->sortTable('activeContract.position.name', 'desc')
        ->assertCanSeeTableRecords([$gamma, $beta], inOrder: true);
});
