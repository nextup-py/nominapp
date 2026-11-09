<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Deduction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDeduction;
use App\Models\MerchandiseWithdrawal;
use App\Models\MerchandiseWithdrawalInstallment;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Services\MerchandiseInstallmentCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Crea un empleado mensual con contrato activo.
 */
function makeMerchEmployee(): Employee
{
    static $ci = 7000000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpM {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucM {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepM {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosM {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'Merch',
        'ci' => (string) $n,
        'email' => "merch{$n}@test.com",
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
    ]);

    return $employee->fresh();
}

/**
 * Crea un período mensual de 2026.
 */
function makeMerchPeriod(int $month): PayrollPeriod
{
    $start = Carbon::create(2026, $month, 1);

    return PayrollPeriod::create([
        'name' => $start->format('F Y'),
        'start_date' => $start->toDateString(),
        'end_date' => $start->copy()->endOfMonth()->toDateString(),
        'frequency' => 'monthly',
        'status' => 'draft',
    ]);
}

/**
 * Crea la deducción MER001 y un retiro aprobado con cuotas en las fechas dadas.
 *
 * @param  array<int, string>  $dueDates
 * @return array{0: MerchandiseWithdrawal, 1: array<int, MerchandiseWithdrawalInstallment>}
 */
function makeMerchandiseWithdrawal($employee, array $dueDates, int $amount = 100_000): array
{
    Deduction::firstOrCreate(['code' => 'MER001'], [
        'name' => 'Cuota de Mercadería',
        'type' => 'loan',
        'calculation' => 'fixed',
        'is_mandatory' => false,
        'is_active' => true,
        'affects_irp' => false,
    ]);

    $withdrawal = MerchandiseWithdrawal::create([
        'employee_id' => $employee->id,
        'total_amount' => $amount * count($dueDates),
        'installments_count' => count($dueDates),
        'installment_amount' => $amount,
        'outstanding_balance' => $amount * count($dueDates),
        'status' => 'approved',
        'approved_at' => Carbon::now()->subMonths(6),
    ]);

    $installments = [];
    foreach ($dueDates as $i => $date) {
        $installments[] = MerchandiseWithdrawalInstallment::create([
            'merchandise_withdrawal_id' => $withdrawal->id,
            'installment_number' => $i + 1,
            'amount' => $amount,
            'due_date' => $date,
            'status' => 'pending',
        ]);
    }

    return [$withdrawal, $installments];
}

it('cobra en una sola nómina todas las cuotas atrasadas de meses sin generar', function () {
    $employee = makeMerchEmployee();
    $period = makeMerchPeriod(6);
    [, $installments] = makeMerchandiseWithdrawal($employee, ['2026-04-30', '2026-05-30', '2026-06-29', '2026-07-29']);

    $result = app(MerchandiseInstallmentCalculator::class)->calculate($employee, $period);

    expect($result['installments']->pluck('id')->all())->toBe(collect($installments)->take(3)->pluck('id')->all());

    $deduction = EmployeeDeduction::find($installments[0]->fresh()->employee_deduction_id);
    expect($deduction->start_date->toDateString())->toBe('2026-04-30')
        ->and($deduction->end_date->toDateString())->toBe('2026-06-30')
        ->and($installments[3]->fresh()->employee_deduction_id)->toBeNull();
});

it('no duplica EmployeeDeduction de cuotas atrasadas al regenerar', function () {
    $employee = makeMerchEmployee();
    $period = makeMerchPeriod(6);
    makeMerchandiseWithdrawal($employee, ['2026-04-30', '2026-06-29']);

    $calculator = app(MerchandiseInstallmentCalculator::class);
    $calculator->calculate($employee, $period);
    $calculator->calculate($employee, $period);

    expect(EmployeeDeduction::count())->toBe(2);
});

it('revierte las cuotas atrasadas y el saldo al eliminar la nómina', function () {
    $employee = makeMerchEmployee();
    $period = makeMerchPeriod(6);
    [$withdrawal, $installments] = makeMerchandiseWithdrawal($employee, ['2026-05-30', '2026-06-29']);

    $calculator = app(MerchandiseInstallmentCalculator::class);
    $result = $calculator->calculate($employee, $period);

    $payroll = Payroll::create([
        'employee_id' => $employee->id,
        'payroll_period_id' => $period->id,
        'base_salary' => 2_550_000,
        'gross_salary' => 2_550_000,
        'net_salary' => 2_350_000,
        'status' => 'draft',
    ]);
    $calculator->markInstallmentsAsPaid($result['installments']->pluck('id')->all(), $payroll->id);

    expect($withdrawal->fresh()->status)->toBe('paid');

    $payroll->delete();

    expect($installments[0]->fresh()->status)->toBe('pending')
        ->and($installments[0]->fresh()->payroll_id)->toBeNull()
        ->and(EmployeeDeduction::count())->toBe(0)
        ->and($withdrawal->fresh()->status)->toBe('approved')
        ->and((float) $withdrawal->fresh()->outstanding_balance)->toBe(200_000.0);
});
