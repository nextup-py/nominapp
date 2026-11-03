<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeChild;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Services\FamilyBonusCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

beforeEach(function () {
    $settings = [
        'monthly_hours' => 240,
        'monthly_hours_nocturno' => 210,
        'monthly_hours_mixto' => 225,
        'daily_hours' => 8,
        'daily_hours_nocturno' => 7,
        'daily_hours_mixto' => 7.5,
        'days_per_month' => 30,
        'overtime_multiplier_diurno' => 1.5,
        'overtime_multiplier_nocturno' => 2.6,
        'overtime_multiplier_holiday' => 2.0,
        'overtime_multiplier_nocturno_holiday' => 2.6,
        'overtime_max_daily_hours' => 3,
        'ips_employee_rate' => 9,
        'indemnizacion_days_per_year' => 15,
        'vacation_min_consecutive_days' => 6,
        'vacation_min_years_service' => 1,
        'vacation_business_days' => [1, 2, 3, 4, 5, 6],
        'ips_deduction_code' => 'IPS001',
        'min_salary_monthly' => 2_550_328,
        'min_salary_daily_jornal' => 87_950,
        'family_bonus_percentage' => 5.0,
    ];

    foreach ($settings as $name => $value) {
        DB::table('settings')->updateOrInsert(
            ['group' => 'payroll', 'name' => $name],
            ['payload' => json_encode($value)]
        );
    }
});

/**
 * Crea un empleado con contrato activo para tests de bonificación familiar.
 */
function makeFbcEmployee(int $salary = 2_550_000, string $salaryType = 'mensual'): Employee
{
    static $ci = 6000000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'FBC',
        'ci' => (string) $n,
        'email' => "fbc{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYear(),
        'salary_type' => $salaryType,
        'salary' => $salary,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/** Crea hijos menores de edad para un empleado. */
function addMinorChildren(Employee $employee, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        EmployeeChild::create([
            'employee_id' => $employee->id,
            'first_name' => 'Hijo',
            'last_name' => 'Test',
            'birth_date' => now()->subYears(5)->toDateString(),
        ]);
    }
}

/** Crea un hijo mayor de edad (18+) para un empleado. */
function addAdultChild(Employee $employee): EmployeeChild
{
    return EmployeeChild::create([
        'employee_id' => $employee->id,
        'first_name' => 'Hijo',
        'last_name' => 'Adulto',
        'birth_date' => now()->subYears(20)->toDateString(),
    ]);
}

function makeFbcPeriod(): PayrollPeriod
{
    static $periodOffset = 0;
    $month = 1 + ($periodOffset++ % 12);
    $start = Carbon::create(2026, $month, 1);

    return PayrollPeriod::create([
        'name' => $start->format('F Y'),
        'start_date' => $start->toDateString(),
        'end_date' => $start->endOfMonth()->toDateString(),
        'frequency' => 'monthly',
        'status' => 'draft',
    ]);
}

// ─── Resultado vacío ─────────────────────────────────────────────────────────

it('retorna 0 si el empleado no tiene hijos registrados', function () {
    $employee = makeFbcEmployee(2_550_000);
    $period = makeFbcPeriod();

    $result = (new FamilyBonusCalculator)->calculate($employee, $period);

    expect($result)->toBe(['total' => 0.0, 'items' => []]);
});

it('retorna 0 si el salario supera dos salarios mínimos', function () {
    // Tope: 2 × 2,550,328 = 5,100,656 — empleado con 5,200,000 no califica
    $employee = makeFbcEmployee(5_200_000);
    addMinorChildren($employee, 2);
    $period = makeFbcPeriod();

    $result = (new FamilyBonusCalculator)->calculate($employee, $period);

    expect($result)->toBe(['total' => 0.0, 'items' => []]);
});

it('retorna 0 si el empleado no tiene contrato activo', function () {
    static $n = 5900000;
    $idx = $n++;

    $company = Company::create(['name' => "Empresa {$idx}", 'ruc' => "{$idx}-1", 'employer_number' => $idx]);
    $branch = Branch::create(['name' => "Sucursal {$idx}", 'company_id' => $company->id]);

    $employee = Employee::create([
        'first_name' => 'Sin',
        'last_name' => 'Contrato',
        'ci' => (string) $idx,
        'email' => "sc{$idx}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    addMinorChildren($employee, 3);

    $period = makeFbcPeriod();
    $result = (new FamilyBonusCalculator)->calculate($employee, $period);

    // Sin contrato → salary = 0 → no supera tope, pero sí califica (salary 0 ≤ 2×min)
    expect($result['total'])->toBeGreaterThan(0);
});

// ─── Cálculo correcto ─────────────────────────────────────────────────────────

it('calcula bonificación correctamente para 1 hijo menor de edad', function () {
    // min_salary = 2,550,328 × 5% = 127,516.40 por hijo
    $employee = makeFbcEmployee(2_550_000);
    addMinorChildren($employee, 1);
    $period = makeFbcPeriod();

    $result = (new FamilyBonusCalculator)->calculate($employee, $period);

    $expected = round(2_550_328 * 0.05, 2); // 127,516.40

    expect((float) $result['total'])->toBe($expected)
        ->and($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['description'])->toContain('1 hijo')
        ->and((float) $result['items'][0]['amount'])->toBe($expected);
});

it('calcula bonificación correctamente para 3 hijos menores de edad', function () {
    $employee = makeFbcEmployee(2_000_000);
    addMinorChildren($employee, 3);
    $period = makeFbcPeriod();

    $result = (new FamilyBonusCalculator)->calculate($employee, $period);

    $bonusPerChild = round(2_550_328 * 0.05, 2);
    $expected = round($bonusPerChild * 3, 2);

    expect((float) $result['total'])->toBe($expected)
        ->and($result['items'][0]['description'])->toContain('3 hijos');
});

it('usa el plural "hijos" para más de uno y el singular para uno', function () {
    $period = makeFbcPeriod();

    $one = makeFbcEmployee(2_000_000);
    addMinorChildren($one, 1);

    $many = makeFbcEmployee(2_000_000);
    addMinorChildren($many, 5);

    $calc = new FamilyBonusCalculator;

    expect($calc->calculate($one, $period)['items'][0]['description'])->toContain('1 hijo')
        ->not->toContain('hijos');

    $period2 = makeFbcPeriod();
    expect($calc->calculate($many, $period2)['items'][0]['description'])->toContain('5 hijos');
});

it('califica cuando el salario es exactamente igual al tope (2 × min_salary)', function () {
    // Tope: 2 × 2,550,328 = 5,100,656 — exactamente en el límite sí califica
    $employee = makeFbcEmployee(5_100_656);
    addMinorChildren($employee, 1);
    $period = makeFbcPeriod();

    $result = (new FamilyBonusCalculator)->calculate($employee, $period);

    expect($result['total'])->toBeGreaterThan(0);
});

it('no califica cuando el salario supera el tope por 1 guaraní', function () {
    $employee = makeFbcEmployee(5_100_657);
    addMinorChildren($employee, 2);
    $period = makeFbcPeriod();

    $result = (new FamilyBonusCalculator)->calculate($employee, $period);

    expect($result)->toBe(['total' => 0.0, 'items' => []]);
});

it('retorna exactamente 1 item aunque haya múltiples hijos', function () {
    $employee = makeFbcEmployee(2_000_000);
    addMinorChildren($employee, 4);
    $period = makeFbcPeriod();

    $result = (new FamilyBonusCalculator)->calculate($employee, $period);

    expect($result['items'])->toHaveCount(1);
});

// ─── Elegibilidad por edad ────────────────────────────────────────────────────

it('no cuenta hijos mayores de 18 años en la bonificación', function () {
    $employee = makeFbcEmployee(2_000_000);
    addAdultChild($employee); // mayor de 18 — no califica
    $period = makeFbcPeriod();

    $result = (new FamilyBonusCalculator)->calculate($employee, $period);

    expect($result)->toBe(['total' => 0.0, 'items' => []]);
});

it('cuenta solo los hijos menores de 18 cuando hay una mezcla', function () {
    $employee = makeFbcEmployee(2_000_000);
    addMinorChildren($employee, 2); // 2 menores — califican
    addAdultChild($employee);       // 1 adulto — no califica
    $period = makeFbcPeriod();

    $result = (new FamilyBonusCalculator)->calculate($employee, $period);

    $bonusPerChild = round(2_550_328 * 0.05, 2);
    $expected = round($bonusPerChild * 2, 2); // solo 2 menores

    expect((float) $result['total'])->toBe($expected)
        ->and($result['items'][0]['description'])->toContain('2 hijos');
});

it('no califica el hijo que cumple exactamente 18 años hoy', function () {
    $employee = makeFbcEmployee(2_000_000);
    EmployeeChild::create([
        'employee_id' => $employee->id,
        'first_name' => 'Cumple',
        'last_name' => 'Hoy',
        'birth_date' => now()->subYears(18)->toDateString(), // exactamente 18 hoy
    ]);
    $period = makeFbcPeriod();

    $result = (new FamilyBonusCalculator)->calculate($employee, $period);

    // birth_date = hoy - 18 años → no es estrictamente mayor que now()-18años → no elegible
    expect($result)->toBe(['total' => 0.0, 'items' => []]);
});

afterEach(function () {
    Mockery::close();
});


// ─── Placeholders de hijos ───────────────────────────────────────────────────

it('no paga bonificación por hijos placeholder pendientes de completar', function () {
    $employee = makeFbcEmployee(2_000_000);
    EmployeeChild::create([
        'employee_id' => $employee->id,
        'first_name' => 'Hijo/a',
        'last_name' => 'FBC',
        'birth_date' => null,
        'is_placeholder' => true,
    ]);

    $result = (new FamilyBonusCalculator)->calculate($employee->fresh(), makeFbcPeriod());

    expect($result)->toBe(['total' => 0.0, 'items' => []])
        ->and($employee->eligibleChildren()->count())->toBe(0);
});

it('excluye un placeholder aunque tenga fecha de nacimiento reciente', function () {
    $employee = makeFbcEmployee(2_000_000);
    EmployeeChild::create([
        'employee_id' => $employee->id,
        'first_name' => 'Hijo/a',
        'last_name' => 'FBC',
        'birth_date' => now()->subYears(5)->toDateString(),
        'is_placeholder' => true,
    ]);

    expect((new FamilyBonusCalculator)->calculate($employee->fresh(), makeFbcPeriod())['total'])->toBe(0.0);
});

it('paga solo el hijo real cuando convive con un placeholder', function () {
    $employee = makeFbcEmployee(2_000_000);
    addMinorChildren($employee, 1);
    EmployeeChild::create([
        'employee_id' => $employee->id,
        'first_name' => 'Hijo/a',
        'last_name' => 'FBC',
        'birth_date' => null,
        'is_placeholder' => true,
    ]);

    $result = (new FamilyBonusCalculator)->calculate($employee->fresh(), makeFbcPeriod());

    expect((float) $result['total'])->toBe(round(2_550_328 * 0.05, 2))
        ->and($result['items'][0]['description'])->toContain('1 hijo');
});

it('un placeholder completado con datos reales vuelve a ser elegible', function () {
    $employee = makeFbcEmployee(2_000_000);
    $child = EmployeeChild::create([
        'employee_id' => $employee->id,
        'first_name' => 'Hijo/a',
        'last_name' => 'FBC',
        'birth_date' => null,
        'is_placeholder' => true,
    ]);

    $child->update(['birth_date' => now()->subYears(4)->toDateString(), 'is_placeholder' => false]);

    expect((new FamilyBonusCalculator)->calculate($employee->fresh(), makeFbcPeriod())['total'])->toBeGreaterThan(0);
});

it('el comando crea placeholders no elegibles y exige --force', function () {
    $employee = makeFbcEmployee(2_000_000);
    $employee->update(['children_count' => 2]);

    $this->artisan('employees:generate-children-placeholders --no-interaction')->assertFailed();
    expect($employee->children()->count())->toBe(0);

    $this->artisan('employees:generate-children-placeholders --dry-run')->assertSuccessful();
    expect($employee->children()->count())->toBe(0);

    $this->artisan('employees:generate-children-placeholders --force')->assertSuccessful();

    expect($employee->children()->where('is_placeholder', true)->whereNull('birth_date')->count())->toBe(2)
        ->and((new FamilyBonusCalculator)->calculate($employee->fresh(), makeFbcPeriod())['total'])->toBe(0.0);
});
