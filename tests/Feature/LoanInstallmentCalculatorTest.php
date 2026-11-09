<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Deduction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDeduction;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Services\LoanInstallmentCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Crea las deducciones PRE001 y ADE001 necesarias para el calculador.
 */
function seedLoanDeductions(): void
{
    Deduction::firstOrCreate(['code' => 'PRE001'], [
        'name' => 'Cuota de Préstamo',
        'type' => 'loan',
        'calculation' => 'fixed',
        'is_mandatory' => false,
        'is_active' => true,
        'affects_irp' => false,
    ]);

    Deduction::firstOrCreate(['code' => 'ADE001'], [
        'name' => 'Cuota de Adelanto',
        'type' => 'loan',
        'calculation' => 'fixed',
        'is_mandatory' => false,
        'is_active' => true,
        'affects_irp' => false,
    ]);
}

/**
 * Crea un empleado con contrato activo para tests de LoanInstallmentCalculator.
 */
function makeLoanEmployee(): Employee
{
    static $ci = 6000000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpL {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucL {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepL {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosL {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'Loan',
        'ci' => (string) $n,
        'email' => "loan{$n}@test.com",
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
 * Crea un PayrollPeriod mensual para el mes indicado de 2026.
 */
function makeLoanPeriod(int $month = 3): PayrollPeriod
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
 * Crea un Loan para el empleado.
 *
 * @param  string  $status  'pending' | 'approved' | 'disbursed' | 'paid' | 'rejected' | 'cancelled'
 */
function makeLoan(
    Employee $employee,
    int $installmentsCount = 6,
    int $installmentAmount = 500_000,
    string $status = 'disbursed',
): Loan {
    return Loan::create([
        'employee_id' => $employee->id,
        'amount' => $installmentsCount * $installmentAmount,
        'installments_count' => $installmentsCount,
        'installment_amount' => $installmentAmount,
        'status' => $status,
        'granted_at' => Carbon::now()->subMonth(),
    ]);
}

/**
 * Crea una LoanInstallment para el préstamo dado.
 */
function makeLoanInstallment(
    Loan $loan,
    int $number,
    string $dueDate,
    string $status = 'pending',
    int $amount = 500_000,
    int $capitalAmount = 500_000,
    int $interestAmount = 0,
): LoanInstallment {
    return LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => $number,
        'amount' => $amount,
        'capital_amount' => $capitalAmount,
        'interest_amount' => $interestAmount,
        'due_date' => $dueDate,
        'status' => $status,
    ]);
}

// ─── calculate() ─────────────────────────────────────────────────────────────

it('retorna installments vacía si el empleado no tiene préstamos activos', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod();

    $result = app(LoanInstallmentCalculator::class)->calculate($employee, $period);

    expect($result['installments'])->toBeEmpty();
    expect(EmployeeDeduction::count())->toBe(0);
});

it('crea EmployeeDeduction para cuota pendiente con due_date dentro del período', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod(month: 3);
    $loan = makeLoan($employee);
    $inst = makeLoanInstallment($loan, 1, '2026-03-15');

    $result = app(LoanInstallmentCalculator::class)->calculate($employee, $period);

    expect($result['installments'])->toHaveCount(1);

    $ed = EmployeeDeduction::first();
    expect($ed)->not->toBeNull()
        ->and((float) $ed->custom_amount)->toBe(500_000.0)
        ->and($ed->start_date->toDateString())->toBe('2026-03-15')
        ->and($ed->end_date->toDateString())->toBe('2026-03-15');

    // La cuota debe referenciar el EmployeeDeduction creado
    expect($inst->fresh()->employee_deduction_id)->toBe($ed->id);
});

it('usa descripción "Préstamo" en las notas de EmployeeDeduction', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod();
    $loan = makeLoan($employee, installmentsCount: 3);
    makeLoanInstallment($loan, 1, '2026-03-15');

    app(LoanInstallmentCalculator::class)->calculate($employee, $period);

    $ed = EmployeeDeduction::first();
    expect($ed->notes)->toContain('Préstamo')
        ->and($ed->notes)->toContain('1/3');
});

it('excluye cuotas ya pagadas', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod();
    $loan = makeLoan($employee);
    makeLoanInstallment($loan, 1, '2026-03-15', status: 'paid');

    $result = app(LoanInstallmentCalculator::class)->calculate($employee, $period);

    expect($result['installments'])->toBeEmpty();
    expect(EmployeeDeduction::count())->toBe(0);
});

it('excluye cuotas con due_date posterior al fin del período', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod(month: 3); // 2026-03-01 – 2026-03-31
    $loan = makeLoan($employee);

    makeLoanInstallment($loan, 1, '2026-04-01'); // después del período

    $result = app(LoanInstallmentCalculator::class)->calculate($employee, $period);

    expect($result['installments'])->toBeEmpty();
    expect(EmployeeDeduction::count())->toBe(0);
});

it('cobra en el período actual la cuota atrasada de un mes sin nómina', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod(month: 3);
    $loan = makeLoan($employee);

    $overdue = makeLoanInstallment($loan, 1, '2026-02-28', amount: 300_000);
    makeLoanInstallment($loan, 2, '2026-03-15', amount: 300_000);

    $result = app(LoanInstallmentCalculator::class)->calculate($employee, $period);

    expect($result['installments'])->toHaveCount(2);

    $deduction = EmployeeDeduction::find($overdue->fresh()->employee_deduction_id);
    expect((float) $deduction->custom_amount)->toBe(300_000.0)
        ->and($deduction->start_date->toDateString())->toBe('2026-02-28')
        ->and($deduction->end_date->toDateString())->toBe('2026-03-31');

    // DeductionCalculator la recoge dentro del período
    expect($employee->employeeDeductions()->forPeriod($period->start_date, $period->end_date)->count())->toBe(2);
});

it('arrastra las atrasadas que superan el tope del salario, las más antiguas primero', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee(); // tope 25 % de 2.550.000 = 637.500
    $period = makeLoanPeriod(month: 3);
    $loan = makeLoan($employee);

    $oldest = makeLoanInstallment($loan, 1, '2026-01-31', amount: 300_000);
    $middle = makeLoanInstallment($loan, 2, '2026-02-28', amount: 300_000);
    $current = makeLoanInstallment($loan, 3, '2026-03-15', amount: 300_000);

    $result = app(LoanInstallmentCalculator::class)->calculate($employee, $period);

    expect($result['installments']->pluck('id')->all())->toBe([$oldest->id, $current->id])
        ->and($middle->fresh()->employee_deduction_id)->toBeNull()
        ->and($middle->fresh()->status)->toBe('pending');
});

it('no duplica EmployeeDeduction de cuotas atrasadas al regenerar', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod(month: 3);
    $loan = makeLoan($employee);

    $overdue = makeLoanInstallment($loan, 1, '2026-02-28', amount: 300_000);

    $calculator = app(LoanInstallmentCalculator::class);
    $calculator->calculate($employee, $period);
    $firstId = $overdue->fresh()->employee_deduction_id;
    $calculator->calculate($employee, $period);

    expect(EmployeeDeduction::count())->toBe(1)
        ->and($overdue->fresh()->employee_deduction_id)->toBe($firstId);
});

it('revierte las cuotas atrasadas al eliminar la nómina que las cobró', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod(month: 3);
    $loan = makeLoan($employee, 2, 300_000);
    $loan->update(['outstanding_balance' => 600_000]);

    $overdue = makeLoanInstallment($loan, 1, '2026-02-28', amount: 300_000, capitalAmount: 300_000);
    makeLoanInstallment($loan, 2, '2026-03-15', amount: 300_000, capitalAmount: 300_000);

    $calculator = app(LoanInstallmentCalculator::class);
    $result = $calculator->calculate($employee, $period);

    $payroll = Payroll::create([
        'employee_id' => $employee->id,
        'payroll_period_id' => $period->id,
        'base_salary' => 2_550_000,
        'gross_salary' => 2_550_000,
        'net_salary' => 1_950_000,
        'status' => 'draft',
    ]);
    $calculator->markInstallmentsAsPaid($result['installments']->pluck('id')->all(), $payroll->id);

    expect($loan->fresh()->status)->toBe('paid')
        ->and($overdue->fresh()->payroll_id)->toBe($payroll->id);

    $payroll->delete();

    expect($overdue->fresh()->status)->toBe('pending')
        ->and($overdue->fresh()->payroll_id)->toBeNull()
        ->and($overdue->fresh()->employee_deduction_id)->toBeNull()
        ->and(EmployeeDeduction::count())->toBe(0)
        ->and($loan->fresh()->status)->toBe('disbursed')
        ->and((float) $loan->fresh()->outstanding_balance)->toBe(600_000.0);
});

it('excluye cuotas de préstamos no aprobados (cancelled, paid, rejected)', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod();
    $cancelled = makeLoan($employee, status: 'cancelled');
    $paid = makeLoan($employee, status: 'paid');

    makeLoanInstallment($cancelled, 1, '2026-03-15');
    makeLoanInstallment($paid, 1, '2026-03-15');

    $result = app(LoanInstallmentCalculator::class)->calculate($employee, $period);

    expect($result['installments'])->toBeEmpty();
    expect(EmployeeDeduction::count())->toBe(0);
});

it('excluye cuotas de préstamos rechazados', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod();
    $rejected = makeLoan($employee, status: 'rejected');
    makeLoanInstallment($rejected, 1, '2026-03-15');

    $result = app(LoanInstallmentCalculator::class)->calculate($employee, $period);

    expect($result['installments'])->toBeEmpty();
    expect(EmployeeDeduction::count())->toBe(0);
});

it('excluye cuotas de préstamos de otro empleado', function () {
    seedLoanDeductions();
    $employee1 = makeLoanEmployee();
    $employee2 = makeLoanEmployee();
    $period = makeLoanPeriod();
    $loan = makeLoan($employee2);
    makeLoanInstallment($loan, 1, '2026-03-15');

    $result = app(LoanInstallmentCalculator::class)->calculate($employee1, $period);

    expect($result['installments'])->toBeEmpty();
    expect(EmployeeDeduction::count())->toBe(0);
});

it('crea un EmployeeDeduction por cuota cuando hay múltiples cuotas en el período', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod();

    $loan1 = makeLoan($employee, installmentAmount: 300_000);
    $loan2 = makeLoan($employee, installmentAmount: 200_000);

    makeLoanInstallment($loan1, 1, '2026-03-10', amount: 300_000);
    makeLoanInstallment($loan2, 1, '2026-03-20', amount: 200_000);

    $result = app(LoanInstallmentCalculator::class)->calculate($employee, $period);

    expect($result['installments'])->toHaveCount(2);
    expect(EmployeeDeduction::count())->toBe(2);

    $amounts = EmployeeDeduction::pluck('custom_amount')->map(fn ($a) => (float) $a)->sort()->values();
    expect($amounts[0])->toBe(200_000.0)
        ->and($amounts[1])->toBe(300_000.0);
});

it('es idempotente: una segunda llamada no duplica EmployeeDeduction', function () {
    seedLoanDeductions();
    $employee = makeLoanEmployee();
    $period = makeLoanPeriod();
    $loan = makeLoan($employee);
    makeLoanInstallment($loan, 1, '2026-03-15');

    $calc = app(LoanInstallmentCalculator::class);
    $calc->calculate($employee, $period);
    $calc->calculate($employee, $period); // segunda llamada (regeneración)

    expect(EmployeeDeduction::count())->toBe(1);
});

// ─── markInstallmentsAsPaid() ────────────────────────────────────────────────

it('retorna 0 si la lista de IDs está vacía', function () {
    $count = app(LoanInstallmentCalculator::class)->markInstallmentsAsPaid([]);

    expect($count)->toBe(0);
});

it('marca cuotas como pagadas y retorna el número de cuotas procesadas', function () {
    $employee = makeLoanEmployee();
    $loan = makeLoan($employee);
    $inst1 = makeLoanInstallment($loan, 1, '2026-03-15');
    $inst2 = makeLoanInstallment($loan, 2, '2026-04-15');

    $count = app(LoanInstallmentCalculator::class)->markInstallmentsAsPaid([$inst1->id, $inst2->id]);

    expect($count)->toBe(2)
        ->and($inst1->fresh()->status)->toBe('paid')
        ->and($inst2->fresh()->status)->toBe('paid')
        ->and($inst1->fresh()->paid_at)->not->toBeNull();
});

it('ignora cuotas que ya están pagadas al marcar', function () {
    $employee = makeLoanEmployee();
    $loan = makeLoan($employee);
    $pending = makeLoanInstallment($loan, 1, '2026-03-15', status: 'pending');
    $alreadyPaid = makeLoanInstallment($loan, 2, '2026-04-15', status: 'paid');

    $count = app(LoanInstallmentCalculator::class)->markInstallmentsAsPaid([$pending->id, $alreadyPaid->id]);

    // Solo cuenta la cuota que estaba pendiente
    expect($count)->toBe(1);
});

it('cierra el préstamo cuando todas sus cuotas quedan pagadas', function () {
    $employee = makeLoanEmployee();
    $loan = makeLoan($employee, installmentsCount: 2);
    makeLoanInstallment($loan, 1, '2026-02-15', status: 'paid');
    $inst2 = makeLoanInstallment($loan, 2, '2026-03-15', status: 'pending');

    app(LoanInstallmentCalculator::class)->markInstallmentsAsPaid([$inst2->id]);

    expect($loan->fresh()->status)->toBe('paid');
});

it('no cierra el préstamo si aún quedan cuotas pendientes', function () {
    $employee = makeLoanEmployee();
    $loan = makeLoan($employee, installmentsCount: 3);
    $inst1 = makeLoanInstallment($loan, 1, '2026-03-15');
    makeLoanInstallment($loan, 2, '2026-04-15');
    makeLoanInstallment($loan, 3, '2026-05-15');

    app(LoanInstallmentCalculator::class)->markInstallmentsAsPaid([$inst1->id]);

    expect($loan->fresh()->status)->toBe('disbursed');
});
