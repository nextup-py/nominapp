<?php

use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Liquidacion;
use App\Models\Position;
use App\Services\ContractConsistencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Empleado con un contrato vigente; `$status` es el estado del empleado.
 */
function staleContractEmployee(string $status): Employee
{
    static $n = 8800000;
    $n++;

    $company = Company::create(['name' => "EmpSC {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucSC {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Baja{$n}", 'ci' => (string) $n, 'email' => "sc{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active']);
    $department = Department::create(['name' => "DepSC {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosSC {$n}", 'department_id' => $department->id]);

    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear()->toDateString(),
        'salary_type' => 'mensual', 'salary' => 3_044_000, 'payroll_type' => 'monthly', 'payment_method' => 'cash',
        'work_modality' => 'presencial', 'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
    ]);

    $employee->update(['status' => $status]);

    return $employee;
}

it('detecta los contratos vigentes de empleados inactivos y los clasifica', function () {
    $sinLiquidacion = staleContractEmployee('inactive');
    $conLiquidacion = staleContractEmployee('inactive');
    $activo = staleContractEmployee('active');

    Liquidacion::unguarded(fn () => Liquidacion::create([
        'employee_id' => $conLiquidacion->id, 'status' => 'calculated', 'termination_date' => today(), 'termination_type' => 'resignation',
        'hire_date' => today()->subYear(), 'base_salary' => 3_044_000, 'daily_salary' => 101_467, 'salary_type' => 'mensual',
    ]));

    $cases = app(ContractConsistencyService::class)->staleContracts()->mapWithKeys(fn ($r) => [$r['contract']->employee_id => $r['case']]);

    expect($cases->get($sinLiquidacion->id))->toBe(ContractConsistencyService::CASE_NO_LIQUIDATION)
        ->and($cases->get($conLiquidacion->id))->toBe(ContractConsistencyService::CASE_PENDING_LIQUIDATION)
        ->and($cases->has($activo->id))->toBeFalse();
});

it('el comando cierra con la última asistencia y respeta dry-run y liquidaciones abiertas', function () {
    $employee = staleContractEmployee('inactive');
    AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-03-18', 'status' => 'present']);

    $this->artisan('contracts:close-stale', ['--dry-run' => true])->assertSuccessful();
    expect($employee->contracts()->first()->status)->toBe('active');

    $this->artisan('contracts:close-stale')->assertSuccessful();
    $contract = $employee->contracts()->first();

    expect($contract->status)->toBe('terminated')
        ->and($contract->terminated_at->toDateString())->toBe('2026-03-18')
        ->and($contract->notes)->toContain('última asistencia');
});
