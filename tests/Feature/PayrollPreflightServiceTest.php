<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Deduction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeDeduction;
use App\Models\EmployeePerception;
use App\Models\PayrollPeriod;
use App\Models\Perception;
use App\Models\Position;
use App\Services\PayrollPreflightService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $settings = [
        'ips_deduction_code' => 'IPS001',
        'min_salary_monthly' => 3_044_000,
        'min_salary_daily_jornal' => 101_467,
    ];

    foreach ($settings as $name => $value) {
        DB::table('settings')->updateOrInsert(
            ['group' => 'payroll', 'name' => $name],
            ['payload' => json_encode($value)]
        );
    }
});

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Crea una empresa con sucursal, departamento y cargo.
 *
 * @return array{company: Company, branch: Branch, department: Department, position: Position}
 */
function pfCompany(): array
{
    static $n = 9100000;
    $n++;

    $company = Company::create(['name' => "EmpPf {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucPf {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepPf {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosPf {$n}", 'department_id' => $department->id]);

    return compact('company', 'branch', 'department', 'position');
}

/**
 * Crea un empleado activo de la empresa dada, con contrato vigente opcional.
 *
 * @param  array{company: Company, branch: Branch, department: Department, position: Position}  $ctx
 */
function pfEmployee(array $ctx, bool $withContract = true, int $salary = 3_500_000, string $paymentMethod = 'cash', string $salaryType = 'mensual'): Employee
{
    static $ci = 5100000;
    $n = $ci++;

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'Preflight',
        'ci' => (string) $n,
        'email' => "pf{$n}@test.com",
        'branch_id' => $ctx['branch']->id,
        'status' => 'active',
    ]);

    if ($withContract) {
        Contract::create([
            'employee_id' => $employee->id,
            'type' => 'indefinido',
            'start_date' => Carbon::create(2025, 1, 1),
            'salary_type' => $salaryType,
            'salary' => $salary,
            'payroll_type' => 'monthly',
            'payment_method' => $paymentMethod,
            'position_id' => $ctx['position']->id,
            'department_id' => $ctx['department']->id,
            'status' => 'active',
        ]);
    }

    return $employee->fresh();
}

/**
 * Período mensual de noviembre de 2026 de la empresa dada.
 */
function pfPeriod(Company $company): PayrollPeriod
{
    return PayrollPeriod::create([
        'name' => 'Noviembre 2026',
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'frequency' => 'monthly',
        'status' => 'draft',
        'company_id' => $company->id,
    ]);
}

/**
 * Asigna el descuento de IPS (código IPS001) al empleado.
 */
function pfAssignIps(Employee $employee): void
{
    $ips = Deduction::firstOrCreate(
        ['code' => 'IPS001'],
        ['name' => 'Aporte Obrero IPS', 'calculation' => 'percentage', 'percent' => 9.00, 'is_active' => true],
    );

    EmployeeDeduction::create([
        'employee_id' => $employee->id,
        'deduction_id' => $ips->id,
        'start_date' => '2026-01-01',
    ]);
}

/**
 * Devuelve los avisos del servicio indexados por clave, con su cantidad.
 *
 * @return array<string, int>
 */
function pfChecks(PayrollPeriod $period): array
{
    return collect(app(PayrollPreflightService::class)->check($period))->pluck('count', 'key')->all();
}

// ─── Tests ──────────────────────────────────────────────────────────────────

it('no avisa nada cuando todo está en orden', function () {
    $ctx = pfCompany();
    $employee = pfEmployee($ctx);
    pfAssignIps($employee);

    expect(pfChecks(pfPeriod($ctx['company'])))->toBe([]);
});

it('avisa de los empleados activos sin contrato vigente', function () {
    $ctx = pfCompany();
    pfEmployee($ctx, withContract: false);

    expect(pfChecks(pfPeriod($ctx['company'])))->toHaveKey('no_contract', 1);
});

it('avisa de los empleados con contrato sin descuento de IPS y no del que sí lo tiene', function () {
    $ctx = pfCompany();
    pfAssignIps(pfEmployee($ctx));
    pfEmployee($ctx);

    expect(pfChecks(pfPeriod($ctx['company'])))->toHaveKey('no_ips', 1);
});

it('no considera vigente un descuento de IPS que terminó antes del período', function () {
    $ctx = pfCompany();
    $employee = pfEmployee($ctx);
    pfAssignIps($employee);
    EmployeeDeduction::where('employee_id', $employee->id)->update(['end_date' => '2026-06-30']);

    expect(pfChecks(pfPeriod($ctx['company'])))->toHaveKey('no_ips', 1);
});

it('avisa de las percepciones de tipo Otro que no aportan al IPS y no de las que sí aportan', function () {
    $ctx = pfCompany();
    $employee = pfEmployee($ctx);
    pfAssignIps($employee);

    $sinAporte = Perception::create(['name' => 'Plus encargada', 'code' => 'PLUS1', 'type' => 'other', 'affects_ips' => false, 'calculation' => 'fixed', 'amount' => 800_000, 'is_active' => true]);
    $conAporte = Perception::create(['name' => 'Plus gestión', 'code' => 'PLUS2', 'type' => 'other', 'affects_ips' => true, 'calculation' => 'fixed', 'amount' => 115_000, 'is_active' => true]);
    $viaticos = Perception::create(['name' => 'Viáticos', 'code' => 'VIA1', 'type' => 'viaticos', 'calculation' => 'fixed', 'amount' => 300_000, 'is_active' => true]);

    foreach ([$sinAporte, $conAporte, $viaticos] as $perception) {
        EmployeePerception::create(['employee_id' => $employee->id, 'perception_id' => $perception->id, 'start_date' => '2026-01-01']);
    }

    expect(pfChecks(pfPeriod($ctx['company'])))->toBe(['plus_without_ips' => 1]);
});

it('avisa de los contratos con salario bajo el mínimo legal', function () {
    $ctx = pfCompany();
    pfAssignIps(pfEmployee($ctx, salary: 2_800_000));
    pfAssignIps(pfEmployee($ctx, salary: 3_044_000));

    expect(pfChecks(pfPeriod($ctx['company'])))->toBe(['below_minimum' => 1]);
});

it('avisa de los pagos por débito sin cuenta principal activa', function () {
    $ctx = pfCompany();
    $sinCuenta = pfEmployee($ctx, paymentMethod: 'debit');
    $conCuenta = pfEmployee($ctx, paymentMethod: 'debit');
    pfAssignIps($sinCuenta);
    pfAssignIps($conCuenta);

    EmployeeBankAccount::create([
        'employee_id' => $conCuenta->id,
        'bank' => 'banco_itau',
        'account_number' => '1234567',
        'account_type' => 'savings',
        'holder_name' => 'Test Preflight',
        'is_primary' => true,
        'status' => 'active',
    ]);

    expect(pfChecks(pfPeriod($ctx['company'])))->toBe(['no_bank_account' => 1]);
});

it('solo considera a los empleados de la empresa del período', function () {
    $ctx = pfCompany();
    $other = pfCompany();
    pfEmployee($other, withContract: false);
    pfAssignIps(pfEmployee($ctx));

    expect(pfChecks(pfPeriod($ctx['company'])))->toBe([]);
});
