<?php

use App\Models\Aguinaldo;
use App\Models\AguinaldoItem;
use App\Models\AguinaldoPeriod;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Services\AguinaldoPDFGenerator;
use App\Services\AguinaldoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeAguService(): AguinaldoService
{
    $pdf = Mockery::mock(AguinaldoPDFGenerator::class);
    $pdf->shouldReceive('generate')->andReturn('mock/aguinaldo.pdf');

    return new AguinaldoService($pdf);
}

function makeAguCompany(): Company
{
    static $n = 8000000;

    return Company::create([
        'name' => "Empresa {$n}",
        'ruc' => "{$n}-1",
        'employer_number' => $n++,
    ]);
}

function makeAguEmployee(Company $company, string $status = 'active'): Employee
{
    static $ci = 8100000;
    $n = $ci++;

    $branch = Branch::create(['name' => "Sucursal {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'Agu',
        'ci' => (string) $n,
        'email' => "agu{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => $status,
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYears(2),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

function makeAguPeriod(Company $company, int $year = 2025): AguinaldoPeriod
{
    return AguinaldoPeriod::create([
        'company_id' => $company->id,
        'year' => $year,
        'status' => 'draft',
    ]);
}

function makeAguinaldoPayPeriod(int $year, int $month): PayrollPeriod
{
    $start = Carbon::create($year, $month, 1);

    return PayrollPeriod::create([
        'name' => $start->format('F Y'),
        'start_date' => $start->toDateString(),
        'end_date' => $start->endOfMonth()->toDateString(),
        'frequency' => 'monthly',
        'status' => 'closed',
    ]);
}

function makePayroll(Employee $employee, PayrollPeriod $period, float $baseSalary, float $perceptions = 0): Payroll
{
    return Payroll::create([
        'employee_id' => $employee->id,
        'payroll_period_id' => $period->id,
        'base_salary' => $baseSalary,
        'total_perceptions' => $perceptions,
        'ips_perceptions' => $perceptions,
        'gross_salary' => $baseSalary + $perceptions,
        'total_deductions' => 0,
        'net_salary' => $baseSalary + $perceptions,
        'status' => 'approved',
    ]);
}

// ─── generateForPeriod ───────────────────────────────────────────────────────

it('retorna 0 si no hay empleados en la empresa', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);

    $count = makeAguService()->generateForPeriod($period);

    expect($count)->toBe(0);
});

it('retorna 0 si los empleados no tienen nóminas en el año', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    // Sin nóminas en 2025
    $count = makeAguService()->generateForPeriod($period);

    expect($count)->toBe(0)
        ->and(Aguinaldo::count())->toBe(0);
});

it('genera aguinaldo correctamente para un empleado con nóminas', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    $payPeriod = makeAguinaldoPayPeriod(2025, 6);
    makePayroll($employee, $payPeriod, 2_550_000);

    $count = makeAguService()->generateForPeriod($period);

    expect($count)->toBe(1)
        ->and(Aguinaldo::count())->toBe(1);

    $aguinaldo = Aguinaldo::first();
    expect($aguinaldo->employee_id)->toBe($employee->id)
        ->and($aguinaldo->status)->toBe('pending')
        ->and((int) $aguinaldo->months_worked)->toBe(1);
});

it('calcula aguinaldo_amount como total_earned dividido 12', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    // 3 nóminas: 2,550,000 + 200,000 perceptions cada una
    foreach ([1, 2, 3] as $month) {
        $payPeriod = makeAguinaldoPayPeriod(2025, $month);
        makePayroll($employee, $payPeriod, 2_550_000, 200_000);
    }

    makeAguService()->generateForPeriod($period);

    $aguinaldo = Aguinaldo::first();
    $expectedTotal = 3 * (2_550_000 + 200_000); // 8,250,000
    $expectedAmount = round($expectedTotal / 12, 0); // 687,500

    expect((float) $aguinaldo->total_earned)->toBe((float) $expectedTotal)
        ->and((float) $aguinaldo->aguinaldo_amount)->toBe($expectedAmount);
});

it('redondea aguinaldo_amount a 0 decimales (Guaraníes sin fracciones)', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    // 1,000,000 / 12 = 83,333.33... → debe truncar a 0 decimales, no a 2
    $payPeriod = makeAguinaldoPayPeriod(2025, 6);
    makePayroll($employee, $payPeriod, 1_000_000);

    makeAguService()->generateForPeriod($period);

    $aguinaldo = Aguinaldo::first();
    expect((float) $aguinaldo->aguinaldo_amount)->toBe(83_333.0);
});

it('crea un AguinaldoItem por cada nómina del año', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    foreach ([1, 2, 3, 4] as $month) {
        makePayroll($employee, makeAguinaldoPayPeriod(2025, $month), 2_550_000);
    }

    makeAguService()->generateForPeriod($period);

    expect(AguinaldoItem::count())->toBe(4);
});

it('no duplica aguinaldo si el empleado ya tiene uno en el período', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    makePayroll($employee, makeAguinaldoPayPeriod(2025, 6), 2_550_000);

    $service = makeAguService();
    $service->generateForPeriod($period);
    $service->generateForPeriod($period); // segunda vez

    expect(Aguinaldo::count())->toBe(1);
});

it('solo incluye empleados activos (incluso con contrato suspendido), no inactivos', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);

    $active = makeAguEmployee($company, 'active');
    $withSuspendedContract = makeAguEmployee($company, 'active');  // activo, contrato suspendido
    $inactive = makeAguEmployee($company, 'inactive');

    // Suspender el contrato del segundo empleado activo
    $withSuspendedContract->contracts()->update(['status' => 'suspended']);

    $payPeriod = makeAguinaldoPayPeriod(2025, 6);
    makePayroll($active, $payPeriod, 2_550_000);
    makePayroll($withSuspendedContract, $payPeriod, 2_550_000);
    makePayroll($inactive, $payPeriod, 2_550_000);

    $count = makeAguService()->generateForPeriod($period);

    expect($count)->toBe(2)
        ->and(Aguinaldo::count())->toBe(2);
});

it('solo procesa nóminas del año del período, no de otros años', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    makePayroll($employee, makeAguinaldoPayPeriod(2024, 12), 2_550_000); // año anterior
    makePayroll($employee, makeAguinaldoPayPeriod(2025, 6), 2_550_000); // año correcto

    makeAguService()->generateForPeriod($period);

    $aguinaldo = Aguinaldo::first();
    expect((int) $aguinaldo->months_worked)->toBe(1); // solo 1 nómina del 2025
});

// ─── Separación de horas extra ───────────────────────────────────────────────

it('separa horas extra de percepciones ordinarias en los items', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    $payPeriod = makeAguinaldoPayPeriod(2025, 6);
    $payroll = makePayroll($employee, $payPeriod, 2_550_000, 350_000);

    // 200,000 de horas extra — identificadas por perception_type, no por descripción
    PayrollItem::create(['payroll_id' => $payroll->id, 'type' => 'perception', 'perception_type' => 'extra_hours', 'description' => 'Horas Extras Diurnas', 'amount' => 200_000]);
    // 150,000 de bono ordinario — sin perception_type extra_hours
    PayrollItem::create(['payroll_id' => $payroll->id, 'type' => 'perception', 'perception_type' => 'salary', 'description' => 'Bono de Productividad', 'amount' => 150_000]);

    makeAguService()->generateForPeriod($period);

    $item = AguinaldoItem::first();
    expect((float) $item->extra_hours)->toBe(200_000.0)
        ->and((float) $item->perceptions)->toBe(150_000.0); // 350k - 200k
});

// ─── regenerateForEmployee ───────────────────────────────────────────────────

it('lanza excepción si el empleado no tiene nóminas en el año', function () {
    $company = makeAguCompany();
    $aguPeriod = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    $aguinaldo = Aguinaldo::create([
        'aguinaldo_period_id' => $aguPeriod->id,
        'employee_id' => $employee->id,
        'total_earned' => 0,
        'months_worked' => 0,
        'aguinaldo_amount' => 0,
        'status' => 'pending',
        'generated_at' => now(),
    ]);

    expect(fn () => makeAguService()->regenerateForEmployee($aguinaldo))
        ->toThrow(RuntimeException::class);
});

it('regenera correctamente el aguinaldo con nuevos montos', function () {
    $company = makeAguCompany();
    $aguPeriod = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    $payPeriod = makeAguinaldoPayPeriod(2025, 6);
    makePayroll($employee, $payPeriod, 2_550_000);

    // Crear aguinaldo inicial con monto incorrecto
    $aguinaldo = Aguinaldo::create([
        'aguinaldo_period_id' => $aguPeriod->id,
        'employee_id' => $employee->id,
        'total_earned' => 999,
        'months_worked' => 0,
        'aguinaldo_amount' => 999,
        'status' => 'pending',
        'generated_at' => now(),
    ]);

    makeAguService()->regenerateForEmployee($aguinaldo);

    $aguinaldo->refresh();
    expect((float) $aguinaldo->total_earned)->toBe(2_550_000.0)
        ->and((float) $aguinaldo->aguinaldo_amount)->toBe(round(2_550_000 / 12, 0))
        ->and((int) $aguinaldo->months_worked)->toBe(1);
});

afterEach(function () {
    Mockery::close();
});

// ─── Nóminas en borrador ─────────────────────────────────────────────────────

it('ignora nóminas en borrador al generar el aguinaldo', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    makePayroll($employee, makeAguinaldoPayPeriod(2025, 5), 2_400_000);
    makePayroll($employee, makeAguinaldoPayPeriod(2025, 6), 9_999_999)->update(['status' => 'draft']);

    makeAguService()->generateForPeriod($period);

    $aguinaldo = Aguinaldo::first();
    expect((int) $aguinaldo->months_worked)->toBe(1)
        ->and((float) $aguinaldo->total_earned)->toBe(2_400_000.0)
        ->and(AguinaldoItem::count())->toBe(1);
});

it('no genera aguinaldo si todas las nóminas del año están en borrador', function () {
    $company = makeAguCompany();
    $period = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    makePayroll($employee, makeAguinaldoPayPeriod(2025, 6), 2_550_000)->update(['status' => 'draft']);

    expect(makeAguService()->generateForPeriod($period))->toBe(0);
});

it('ignora nóminas en borrador al regenerar y en la provisión', function () {
    $company = makeAguCompany();
    $aguPeriod = makeAguPeriod($company, 2025);
    $employee = makeAguEmployee($company);

    makePayroll($employee, makeAguinaldoPayPeriod(2025, 5), 2_400_000);
    makePayroll($employee, makeAguinaldoPayPeriod(2025, 6), 9_999_999)->update(['status' => 'draft']);

    $aguinaldo = Aguinaldo::create([
        'aguinaldo_period_id' => $aguPeriod->id,
        'employee_id' => $employee->id,
        'total_earned' => 0,
        'months_worked' => 0,
        'aguinaldo_amount' => 0,
        'status' => 'pending',
        'generated_at' => now(),
    ]);

    makeAguService()->regenerateForEmployee($aguinaldo);

    expect((float) $aguinaldo->fresh()->total_earned)->toBe(2_400_000.0);

    $row = makeAguService()->provisionQuery($aguPeriod, 12)->first();
    expect((int) $row->months_worked)->toBe(1)
        ->and((float) $row->total_earned)->toBe(2_400_000.0);
});
