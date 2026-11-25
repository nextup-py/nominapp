<?php

use App\Exports\EmployeeReportExport;
use App\Filament\Pages\EmployeeReport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Empleado con la cadena de contratos indicada (el último es el vigente).
 *
 * @param  list<array{start: string, status: string, seniority_from?: string}>  $contracts
 */
function employeeWithContracts(array $contracts): Employee
{
    static $n = 5500000;
    $n++;
    $company = Company::create(['name' => "AntSA {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "AntSuc {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "AntDep {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "AntCargo {$n}", 'department_id' => $department->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Antig{$n}", 'ci' => (string) $n, 'birth_date' => '1990-01-01', 'branch_id' => $branch->id, 'status' => 'active']);

    foreach ($contracts as $contract) {
        Contract::create([
            'employee_id' => $employee->id,
            'type' => 'plazo_fijo',
            'start_date' => $contract['start'],
            'end_date' => '2030-12-31',
            'salary_type' => 'mensual',
            'salary' => 3000000,
            'payroll_type' => 'monthly',
            'position_id' => $position->id,
            'department_id' => $department->id,
            'status' => $contract['status'],
            'seniority_from' => $contract['seniority_from'] ?? null,
        ]);
    }

    return $employee;
}

/** Fecha de ingreso que devuelve el export para el empleado, con o sin antigüedad original. */
function reportedHireDate(Employee $employee, bool $original): ?string
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    test()->actingAs($user);

    $row = (new EmployeeReportExport(originalSeniority: $original))->query()->where('employees.id', $employee->id)->first();

    return $row?->report_hire_date ? substr((string) $row->report_hire_date, 0, 10) : null;
}

it('por defecto el reporte toma el inicio del contrato vigente', function () {
    $employee = employeeWithContracts([
        ['start' => '2022-03-01', 'status' => 'renewed'],
        ['start' => '2024-03-01', 'status' => 'active'],
    ]);

    expect(reportedHireDate($employee, false))->toBe('2024-03-01');
});

it('con antigüedad original una renovación conserva el inicio de la relación laboral', function () {
    $employee = employeeWithContracts([
        ['start' => '2022-03-01', 'status' => 'renewed'],
        ['start' => '2023-03-01', 'status' => 'renewed'],
        ['start' => '2024-03-01', 'status' => 'active'],
    ]);

    expect(reportedHireDate($employee, true))->toBe('2022-03-01');
});

it('un contrato que venció sin baja y se reemplazó sigue siendo la misma relación', function () {
    $employee = employeeWithContracts([
        ['start' => '2021-06-01', 'status' => 'expired'],
        ['start' => '2022-06-01', 'status' => 'active'],
    ]);

    expect(reportedHireDate($employee, true))->toBe('2021-06-01');
});

it('una terminación corta la cadena: tras una baja la antigüedad empieza en el reingreso', function () {
    $employee = employeeWithContracts([
        ['start' => '2019-01-01', 'status' => 'terminated'],
        ['start' => '2023-01-01', 'status' => 'renewed'],
        ['start' => '2024-01-01', 'status' => 'active'],
    ]);

    expect(reportedHireDate($employee, true))->toBe('2023-01-01');
});

it('la antigüedad acumulada a mano en un reingreso prevalece', function () {
    $employee = employeeWithContracts([
        ['start' => '2019-01-01', 'status' => 'terminated'],
        ['start' => '2023-01-01', 'status' => 'active', 'seniority_from' => '2019-01-01'],
    ]);

    expect(reportedHireDate($employee, true))->toBe('2019-01-01');
});

it('un contrato único queda igual con o sin el filtro', function () {
    $employee = employeeWithContracts([['start' => '2024-05-01', 'status' => 'active']]);

    expect(reportedHireDate($employee, true))->toBe('2024-05-01')
        ->and(reportedHireDate($employee, false))->toBe('2024-05-01');
});

it('la pantalla del reporte muestra el ingreso original al activar el filtro', function () {
    $employee = employeeWithContracts([
        ['start' => '2022-03-01', 'status' => 'renewed'],
        ['start' => '2024-03-01', 'status' => 'active'],
    ]);
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    Livewire::actingAs($user)->test(EmployeeReport::class)
        ->filterTable('original_seniority', true)
        ->assertSee('01/03/2022')
        ->assertDontSee('01/03/2024');
});

it('el PDF acepta el parámetro de antigüedad original', function () {
    employeeWithContracts([
        ['start' => '2022-03-01', 'status' => 'renewed'],
        ['start' => '2024-03-01', 'status' => 'active'],
    ]);
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    $this->actingAs($user)->get(route('employees.report.pdf', ['originalSeniority' => 1, 'columns' => 'ci,hire_date']))->assertOk();
});
