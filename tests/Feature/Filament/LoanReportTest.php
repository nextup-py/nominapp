<?php

use App\Exports\LoanReportExport;
use App\Filament\Pages\LoanReport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('carga sin error 500 cuando hay un préstamo otorgado por un usuario', function () {
    $company = Company::create(['name' => 'Empresa Reporte Préstamos', 'ruc' => '80099999-1', 'employer_number' => 99999]);
    $branch = Branch::create(['name' => 'Sucursal Reporte Préstamos', 'company_id' => $company->id]);
    $department = Department::create(['name' => 'Depto Reporte Préstamos', 'company_id' => $company->id]);
    $position = Position::create(['name' => 'Cargo Reporte Préstamos', 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'ReportePrestamos',
        'ci' => '9999999',
        'email' => 'reporte-prestamos@test.com',
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 3_000_000,
        'payroll_type' => 'monthly',
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $grantedBy = User::factory()->create(['name' => 'Aprobador De Prueba']);

    $loan = Loan::create([
        'employee_id' => $employee->id,
        'amount' => 1_000_000,
        'interest_rate' => 0,
        'installments_count' => 4,
        'installment_amount' => 250_000,
        'status' => 'pending',
        'reason' => 'personal',
    ]);
    $loan->activate($grantedBy->id);

    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $viewer = User::factory()->create();
    $viewer->assignRole($role);
    test()->actingAs($viewer);

    Livewire::test(LoanReport::class)->assertSuccessful();

    // La columna "Aprobado por" está toggled-hidden por defecto en la tabla,
    // así que se verifica el valor directamente sobre la query del reporte
    // en lugar de depender de que la columna esté visible en el DOM.
    $reportQuery = (new ReflectionMethod(LoanReport::class, 'buildQuery'))
        ->invoke(new LoanReport);

    expect($reportQuery->where('loans.id', $loan->id)->value('granted_by_name'))
        ->toBe('Aprobador De Prueba');
});

it('exporta a Excel sin error cuando hay un préstamo otorgado por un usuario', function () {
    $company = Company::create(['name' => 'Empresa Export Préstamos', 'ruc' => '80099998-1', 'employer_number' => 99998]);
    $branch = Branch::create(['name' => 'Sucursal Export Préstamos', 'company_id' => $company->id]);
    $department = Department::create(['name' => 'Depto Export Préstamos', 'company_id' => $company->id]);
    $position = Position::create(['name' => 'Cargo Export Préstamos', 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'ExportPrestamos',
        'ci' => '9999998',
        'email' => 'export-prestamos@test.com',
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 3_000_000,
        'payroll_type' => 'monthly',
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $grantedBy = User::factory()->create(['name' => 'Aprobador Export']);

    $loan = Loan::create([
        'employee_id' => $employee->id,
        'amount' => 1_000_000,
        'interest_rate' => 0,
        'installments_count' => 4,
        'installment_amount' => 250_000,
        'status' => 'pending',
        'reason' => 'personal',
    ]);
    $loan->activate($grantedBy->id);

    $rows = (new LoanReportExport)->query()->where('loans.id', $loan->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->granted_by_name)->toBe('Aprobador Export');
});
