<?php

use App\Models\Advance;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\User;
use App\Services\PayrollService;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Referencia salarial de adelantos para jornaleros: días presentes × jornal más el descanso
 * semanal remunerado devengado, con la misma regla (RestDayCalculator) que usa la nómina.
 *
 * Calendario de referencia (marzo 2026): lun 2, lun 16, lun 30. "Hoy" se fija en 2026-03-31.
 */
beforeEach(function () {
    $settings = [
        'monthly_hours' => 240,
        'days_per_month' => 30,
        'ips_employee_rate' => 9,
        'ips_deduction_code' => 'IPS001',
        'min_salary_monthly' => 2_550_328,
        'min_salary_daily_jornal' => 87_950,
        'vacation_business_days' => [1, 2, 3, 4, 5, 6],
        'family_bonus_percentage' => 5.0,
        'advance_max_percent' => 50,
        'advance_max_per_period' => 0,
    ];

    foreach ($settings as $name => $value) {
        DB::table('settings')->updateOrInsert(
            ['group' => 'payroll', 'name' => $name],
            ['payload' => json_encode($value)]
        );
    }

    Carbon::setTestNow('2026-03-31 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Crea un jornalero con contrato activo y el tipo de nómina indicado. */
function makeRestAdvEmployee(float $dailyRate = 100_000, string $payrollType = 'monthly'): Employee
{
    static $n = 9300000;
    $n++;

    $company = Company::create(['name' => "EmpRA {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucRA {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepRA {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosRA {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Jornal',
        'last_name' => 'Descanso',
        'ci' => (string) $n,
        'birth_date' => '1990-01-01',
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => '2025-01-01',
        'salary_type' => 'jornal',
        'salary' => $dailyRate,
        'payroll_type' => $payrollType,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/** Crea el período que contiene "hoy" (2026-03-31). */
function makeRestAdvPeriod(string $frequency = 'monthly', string $start = '2026-03-01', string $end = '2026-03-31'): PayrollPeriod
{
    return PayrollPeriod::create([
        'name' => "Período {$frequency} {$start}",
        'start_date' => $start,
        'end_date' => $end,
        'frequency' => $frequency,
        'status' => 'draft',
    ]);
}

/** @param  string[]  $dates */
function markRestAdvPresent(Employee $employee, array $dates): void
{
    foreach ($dates as $date) {
        AttendanceDay::create(['employee_id' => $employee->id, 'date' => $date, 'status' => 'present']);
    }
}

it('suma el descanso semanal a los días presentes por el jornal', function () {
    $employee = makeRestAdvEmployee(100_000);
    makeRestAdvPeriod();
    markRestAdvPresent($employee, ['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06']);

    // 5 × 100.000 + (5 × 100.000 / 6)
    expect($employee->getAdvanceReferenceSalary())->toBe(500_000 + round(5 * 100_000 / 6, 2));
});

it('devenga el descanso de forma proporcional por semana ISO', function () {
    $employee = makeRestAdvEmployee(120_000);
    makeRestAdvPeriod();
    // Semana 10: 6 días (descanso completo) · semana 11: 2 días (parcial)
    markRestAdvPresent($employee, [
        '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-07',
        '2026-03-09', '2026-03-10',
    ]);

    $expected = 8 * 120_000 + 120_000 + round(2 * 120_000 / 6, 2);

    expect($employee->getAdvanceReferenceSalary())->toBe($expected);
});

it('sin días presentes o sin período vigente no hay referencia (el descanso no se suma solo)', function () {
    $employee = makeRestAdvEmployee(100_000);

    expect($employee->getAdvanceReferenceSalary())->toBeNull();

    makeRestAdvPeriod();

    expect($employee->getAdvanceReferenceSalary())->toBeNull();
});

it('encuentra el período vigente también el último día del mismo (regresión: end_date vs hora)', function () {
    $employee = makeRestAdvEmployee(100_000);
    makeRestAdvPeriod(); // termina 2026-03-31, el "hoy" fijado con hora 10:00
    markRestAdvPresent($employee, ['2026-03-02']);

    expect($employee->getAdvanceReferenceSalary())->not->toBeNull();
});

it('un empleado mensual no cambia: su referencia sigue siendo el salario base', function () {
    $employee = makeRestAdvEmployee(100_000);
    $employee->activeContract->update(['salary_type' => 'mensual', 'salary' => 3_000_000]);

    expect($employee->fresh()->getAdvanceReferenceSalary())->toBe(3_000_000.0);
});

it('coincide con el salario devengado que paga la nómina (mensual)', function () {
    $employee = makeRestAdvEmployee(100_000);
    $period = makeRestAdvPeriod();
    markRestAdvPresent($employee, ['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-09', '2026-03-10']);

    $reference = $employee->getAdvanceReferenceSalary();
    $payroll = app(PayrollService::class)->generateForEmployee($employee->fresh(), $period);

    expect((float) $payroll->gross_salary)->toBe($reference);
});

it('coincide con la nómina en una semana partida por el borde del período (quincenal)', function () {
    $employee = makeRestAdvEmployee(100_000, 'biweekly');
    $period = makeRestAdvPeriod('biweekly', '2026-03-16', '2026-03-31');
    // Semana 12 (16-22): 3 días dentro · semana 14 (30-05/04): solo 30 y 31 caen dentro
    markRestAdvPresent($employee, ['2026-03-18', '2026-03-19', '2026-03-20', '2026-03-30', '2026-03-31']);

    $reference = $employee->getAdvanceReferenceSalary();
    $payroll = app(PayrollService::class)->generateForEmployee($employee->fresh(), $period);

    $expected = 5 * 100_000 + round(3 * 100_000 / 6, 2) + round(2 * 100_000 / 6, 2);

    expect($reference)->toBe($expected)
        ->and((float) $payroll->gross_salary)->toBe($reference);
});

it('coincide con la nómina en un período semanal', function () {
    $employee = makeRestAdvEmployee(90_000, 'weekly');
    $period = makeRestAdvPeriod('weekly', '2026-03-30', '2026-04-05');
    markRestAdvPresent($employee, ['2026-03-30', '2026-03-31']);

    $reference = $employee->getAdvanceReferenceSalary();
    $payroll = app(PayrollService::class)->generateForEmployee($employee->fresh(), $period);

    expect($reference)->toBe(2 * 90_000 + round(2 * 90_000 / 6, 2))
        ->and((float) $payroll->gross_salary)->toBe($reference);
});

it('el monto máximo de adelanto usa el porcentaje sobre la referencia con descanso', function () {
    $employee = makeRestAdvEmployee(100_000);
    makeRestAdvPeriod();
    markRestAdvPresent($employee, ['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-07']);

    $reference = 600_000 + 100_000;

    expect((int) app(PayrollSettings::class)->advance_max_percent)->toBe(50)
        ->and($employee->getMaxAdvanceAmount())->toBe(round($reference * 50 / 100, 2));
});

it('el tope de adelantos del jornalero admite hasta la referencia con descanso y bloquea lo que la excede', function () {
    $employee = makeRestAdvEmployee(100_000);
    makeRestAdvPeriod();
    markRestAdvPresent($employee, ['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06']);
    $admin = User::factory()->create();

    // Referencia: 500.000 + 83.333,33 = 583.333,33. Sin descanso el tope sería 500.000.
    $ok = Advance::create(['employee_id' => $employee->id, 'amount' => 580_000, 'status' => 'pending', 'payment_method' => 'cash']);
    expect($ok->approve($admin->id)['success'])->toBeTrue();

    $over = Advance::create(['employee_id' => $employee->id, 'amount' => 10_000, 'status' => 'pending', 'payment_method' => 'cash']);
    $result = $over->approve($admin->id);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('salario devengado del jornalero');
});
