<?php

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Liquidacion;
use App\Models\Loan;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\User;
use App\Models\Warning;
use App\Models\WarningSuspensionDay;
use App\Services\LiquidacionPDFGenerator;
use App\Services\LiquidacionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeLiqService(): LiquidacionService
{
    $pdf = Mockery::mock(LiquidacionPDFGenerator::class);
    $pdf->shouldReceive('generate')->andReturn('mock/liquidacion.pdf');

    return new LiquidacionService($pdf);
}

function seedLiqSettings(): void
{
    $settings = [
        'indemnizacion_days_per_year' => 15,
        'ips_employee_rate' => 9,
        'ips_deduction_code' => 'IPS001',
        'overtime_multiplier_nocturno_holiday' => 2.6,
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
}

function makeLiqEmployee(
    string $salaryType = 'mensual',
    int $salary = 2_550_000,
    ?Carbon $startDate = null,
): Employee {
    static $ci = 9000000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'Liq',
        'ci' => (string) $n,
        'email' => "liq{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => $startDate ?? Carbon::now()->subYears(3),
        'salary_type' => $salaryType,
        'salary' => $salary,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

function makeLiquidacion(Employee $employee, array $overrides = []): Liquidacion
{
    $hireDate = $employee->hire_date ?? Carbon::now()->subYears(3);
    $terminationDate = Carbon::create(2026, 3, 15);
    $baseSalary = (float) ($employee->base_salary ?? $employee->daily_rate ?? 2_550_000);
    $salaryType = $employee->activeContract?->salary_type ?? 'mensual';
    $dailySalary = $salaryType === 'jornal' ? $baseSalary : round($baseSalary / 30, 2);

    return Liquidacion::create(array_merge([
        'employee_id' => $employee->id,
        'termination_date' => $terminationDate->toDateString(),
        'termination_type' => 'unjustified_dismissal',
        'preaviso_otorgado' => false,
        'hire_date' => $hireDate->toDateString(),
        'base_salary' => $baseSalary,
        'daily_salary' => $dailySalary,
        'salary_type' => $salaryType,
        'status' => 'draft',
    ], $overrides));
}

function makeLiqPayPeriod(int $year, int $month): PayrollPeriod
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

function makeLiqBiweeklyPayPeriod(int $year, int $month, int $startDay, int $endDay): PayrollPeriod
{
    $start = Carbon::create($year, $month, $startDay);
    $end = Carbon::create($year, $month, $endDay);

    return PayrollPeriod::create([
        'name' => $start->format('F Y')." ({$startDay}-{$endDay})",
        'start_date' => $start->toDateString(),
        'end_date' => $end->toDateString(),
        'frequency' => 'biweekly',
        'status' => 'closed',
    ]);
}

function makeLiqPayroll(Employee $employee, PayrollPeriod $period, float $baseSalary, float $perceptions = 0): Payroll
{
    return Payroll::create([
        'employee_id' => $employee->id,
        'payroll_period_id' => $period->id,
        'base_salary' => $baseSalary,
        'total_perceptions' => $perceptions,
        'gross_salary' => $baseSalary + $perceptions,
        'total_deductions' => 0,
        'net_salary' => $baseSalary + $perceptions,
        'status' => 'approved',
    ]);
}

// ─── calculate — estado y estructura ─────────────────────────────────────────

it('calculate cambia el estado a calculated y genera pdf', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee);

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->status)->toBe('calculated')
        ->and($result->pdf_path)->toBe('mock/liquidacion.pdf')
        ->and($result->calculated_at)->not->toBeNull();
});

it('calculate elimina items previos antes de recalcular', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee);
    $service = makeLiqService();

    $service->calculate($liquidacion);
    $countFirst = $liquidacion->fresh()->items()->count();

    $service->calculate($liquidacion->fresh());
    $countSecond = $liquidacion->fresh()->items()->count();

    expect($countSecond)->toBe($countFirst);
});

// ─── Preaviso ─────────────────────────────────────────────────────────────────

it('calcula preaviso para despido injustificado sin preaviso otorgado', function () {
    seedLiqSettings();
    // 3 años → 45 días de preaviso (tier 1-5)
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee, [
        'termination_type' => 'unjustified_dismissal',
        'preaviso_otorgado' => false,
    ]);

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->preaviso_days)->toBe(45)
        ->and((float) $result->preaviso_amount)->toBeGreaterThan(0);
});

it('no calcula preaviso si preaviso_otorgado = true', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee, [
        'termination_type' => 'unjustified_dismissal',
        'preaviso_otorgado' => true,
    ]);

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->preaviso_days)->toBe(0)
        ->and((float) $result->preaviso_amount)->toBe(0.0);
});

it('no calcula preaviso para renuncia voluntaria', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee, ['termination_type' => 'resignation']);

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->preaviso_days)->toBe(0)
        ->and((float) $result->preaviso_amount)->toBe(0.0);
});

it('no calcula preaviso si el empleado está en período de prueba', function () {
    seedLiqSettings();
    // Contratado hace 15 días → dentro del período de prueba de 30 días
    $hireDate = Carbon::create(2026, 2, 28);
    $employee = makeLiqEmployee(startDate: $hireDate);
    $liquidacion = makeLiquidacion($employee, [
        'hire_date' => $hireDate->toDateString(),
        'termination_date' => '2026-03-15',
        'termination_type' => 'unjustified_dismissal',
    ]);

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->preaviso_days)->toBe(0);
});

it('aplica tier correcto de preaviso según antigüedad', function () {
    seedLiqSettings();
    // 0 años → 30 días; 1 año → 45 días; 5 años → 60 días; 10 años → 90 días
    $cases = [
        ['start' => '2026-03-01', 'end' => '2026-03-15', 'expected' => 0], // trial period
    ];

    // 3 años → 45 días
    $employee3y = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liq3y = makeLiquidacion($employee3y);
    $result3y = makeLiqService()->calculate($liq3y);
    expect($result3y->preaviso_days)->toBe(45);

    // 7 años → 60 días
    $employee7y = makeLiqEmployee(startDate: Carbon::create(2019, 3, 15));
    $liq7y = makeLiquidacion($employee7y);
    $result7y = makeLiqService()->calculate($liq7y);
    expect($result7y->preaviso_days)->toBe(60);

    // 12 años → 90 días
    $employee12y = makeLiqEmployee(startDate: Carbon::create(2014, 3, 15));
    $liq12y = makeLiquidacion($employee12y);
    $result12y = makeLiqService()->calculate($liq12y);
    expect($result12y->preaviso_days)->toBe(90);
});

// ─── Indemnización ────────────────────────────────────────────────────────────

it('calcula indemnización para despido injustificado', function () {
    seedLiqSettings();
    // 3 años, sin fracción > 6 meses → units = 3; 3 × 15 × dailyAvg
    $employee = makeLiqEmployee('mensual', 2_550_000, Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee, ['termination_type' => 'unjustified_dismissal']);

    $result = makeLiqService()->calculate($liquidacion);

    $dailyAvg = 2_550_000 / 30;
    $expected = round(3 * 15 * $dailyAvg, 0);

    expect((float) $result->indemnizacion_amount)->toBe((float) $expected);
});

it('no calcula indemnización para renuncia voluntaria', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee, ['termination_type' => 'resignation']);

    $result = makeLiqService()->calculate($liquidacion);

    expect((float) $result->indemnizacion_amount)->toBe(0.0);
});

it('duplica la indemnización por estabilidad laboral propia para 10+ años', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2014, 3, 15)); // 12 años
    $liquidacion = makeLiquidacion($employee, ['termination_type' => 'unjustified_dismissal']);

    $result = makeLiqService()->calculate($liquidacion);

    expect((float) $result->indemnizacion_estabilidad_amount)->toBe((float) $result->indemnizacion_amount)
        ->and((float) $result->indemnizacion_amount)->toBeGreaterThan(0);
});

it('no agrega indemnización estabilidad para menos de 10 años', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15)); // 3 años
    $liquidacion = makeLiquidacion($employee, ['termination_type' => 'unjustified_dismissal']);

    $result = makeLiqService()->calculate($liquidacion);

    expect((float) $result->indemnizacion_estabilidad_amount)->toBe(0.0);
});

// ─── Salario pendiente ────────────────────────────────────────────────────────

it('calcula salario pendiente si no hay nómina del mes de terminación', function () {
    seedLiqSettings();
    // Termina el día 15 de marzo, sin nómina de marzo → 15 días pendientes
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-15']);

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->salario_pendiente_days)->toBe(15)
        ->and((float) $result->salario_pendiente_amount)->toBeGreaterThan(0);
});

it('no calcula salario pendiente si ya existe nómina del mes de terminación', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-15']);

    // Nómina de marzo ya pagada
    makeLiqPayroll($employee, makeLiqPayPeriod(2026, 3), 2_550_000);

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->salario_pendiente_days)->toBe(0)
        ->and((float) $result->salario_pendiente_amount)->toBe(0.0);
});

it('calcula salario pendiente para empleado quincenal con solo la primera mitad del mes pagada', function () {
    seedLiqSettings();
    // Termina el día 20 de marzo, con nómina de la primera quincena (1-15) ya generada:
    // solo quedan pendientes los días 16 al 20. Los días 1 al 15 ya se pagaron en esa nómina
    // y no deben pagarse de nuevo (antes se pagaban 20 días, duplicando la primera quincena).
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-20']);

    makeLiqPayroll($employee, makeLiqBiweeklyPayPeriod(2026, 3, 1, 15), 1_275_000);

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->salario_pendiente_days)->toBe(5)
        ->and((float) $result->salario_pendiente_amount)->toBe(round(5 * (2_550_000 / 30), 0));
});

it('no calcula salario pendiente si la nómina existente ya cubre hasta la fecha de terminación', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-10']);

    // Nómina de la primera quincena (1-15) cubre de sobra el día 10 de terminación.
    makeLiqPayroll($employee, makeLiqBiweeklyPayPeriod(2026, 3, 1, 15), 1_275_000);

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->salario_pendiente_days)->toBe(0)
        ->and((float) $result->salario_pendiente_amount)->toBe(0.0);
});

// ─── Aguinaldo proporcional ───────────────────────────────────────────────────

it('calcula aguinaldo proporcional en base a nóminas del año', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-15']);

    // 2 nóminas del año 2026 (enero y febrero)
    makeLiqPayroll($employee, makeLiqPayPeriod(2026, 1), 2_550_000);
    makeLiqPayroll($employee, makeLiqPayPeriod(2026, 2), 2_550_000);

    $result = makeLiqService()->calculate($liquidacion);

    $totalEarned = 2 * 2_550_000;
    $expected = round($totalEarned / 12, 0);

    expect((float) $result->aguinaldo_proporcional_amount)->toBe((float) $expected);
});

it('calcula aguinaldo proporcional sobre salario pendiente cuando no hay nóminas previas', function () {
    seedLiqSettings();
    // Contratado en este mismo mes → no hay nóminas, salario pendiente es la base
    $hireDate = Carbon::create(2026, 3, 1);
    $employee = makeLiqEmployee(startDate: $hireDate);
    $liquidacion = makeLiquidacion($employee, [
        'hire_date' => $hireDate->toDateString(),
        'termination_date' => '2026-03-15',
    ]);

    $result = makeLiqService()->calculate($liquidacion);

    // Salario pendiente = 15 días; aguinaldo = salario_pendiente / 12
    $expectedAguinaldo = round((float) $result->salario_pendiente_amount / 12, 0);

    expect((float) $result->aguinaldo_proporcional_amount)->toBe((float) $expectedAguinaldo);
});

// ─── Totales ──────────────────────────────────────────────────────────────────

it('net_amount = total_haberes - total_deductions', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee);

    $result = makeLiqService()->calculate($liquidacion);

    $expectedNet = (float) $result->total_haberes - (float) $result->total_deductions;

    expect((float) $result->net_amount)->toBe($expectedNet);
});

it('crea LiquidacionItems con type haber y deduction', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee, ['termination_type' => 'unjustified_dismissal']);

    makeLiqService()->calculate($liquidacion);

    $items = $liquidacion->fresh()->items;

    expect($items->where('type', 'haber')->count())->toBeGreaterThan(0);
});

// ─── close ────────────────────────────────────────────────────────────────────

it('close marca al empleado como inactivo y termina el contrato', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee, ['status' => 'calculated']);

    makeLiqService()->close($liquidacion);

    expect($employee->fresh()->status)->toBe('inactive');

    $contract = $employee->fresh()->contracts()->first();
    expect($contract->status)->toBe('terminated')
        ->and($contract->end_date->toDateString())->toBe('2026-03-15');
});

it('close cambia el status de la liquidación a closed', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee, ['status' => 'calculated']);

    makeLiqService()->close($liquidacion);

    expect($liquidacion->fresh()->status)->toBe('closed')
        ->and($liquidacion->fresh()->closed_at)->not->toBeNull();
});

it('close cancela los préstamos activos del empleado', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 3, 15));
    $liquidacion = makeLiquidacion($employee, ['status' => 'calculated']);

    $loan = Loan::create([
        'employee_id' => $employee->id,
        'type' => 'loan',
        'amount' => 1_000_000,
        'remaining_debt' => 1_000_000,
        'installment_amount' => 100_000,
        'status' => 'approved',
    ]);

    makeLiqService()->close($liquidacion);

    expect($loan->fresh()->status)->toBe('cancelled');
});

// ─── Vacaciones proporcionales — base de cálculo ─────────────────────────────

it('las vacaciones proporcionales usan el promedio de 6 meses, no el salario contractual', function () {
    seedLiqSettings();

    // Empleado contratado 2024-01-01; termina 2026-03-15 → 2 años + 2 meses en el período actual
    $hireDate = Carbon::create(2024, 1, 1);
    $terminationDate = Carbon::create(2026, 3, 15);
    $employee = makeLiqEmployee(salary: 2_000_000, startDate: $hireDate);

    // 3 nóminas con gross_salary mayor al contractual (horas extra, etc.)
    // Promedio = (3.000.000 × 3) / 3 = 3.000.000 → daily_avg = 100.000
    foreach ([10, 11, 12] as $month) {
        $period = makeLiqPayPeriod(2025, $month);
        makeLiqPayroll($employee, $period, baseSalary: 2_000_000, perceptions: 1_000_000);
    }

    $liquidacion = makeLiquidacion($employee, [
        'hire_date' => $hireDate->toDateString(),
        'termination_date' => $terminationDate->toDateString(),
        'base_salary' => 2_000_000,
        'daily_salary' => round(2_000_000 / 30, 2),
    ]);

    $result = makeLiqService()->calculate($liquidacion);
    $vacItem = $result->items()->where('category', 'vacaciones')->firstOrFail();

    $days = (int) $vacItem->metadata['days'];
    $dailyAvg = round(3_000_000 / 30, 2);
    $dailyContractual = round(2_000_000 / 30, 2);
    $expectedWithAvg = round($days * $dailyAvg, 0);

    expect($days)->toBeGreaterThan(0)
        ->and((float) $vacItem->amount)->toBe((float) $expectedWithAvg)
        // El monto con promedio debe ser mayor que con la tarifa contractual
        ->and((float) $vacItem->amount)->toBeGreaterThan(round($days * $dailyContractual, 0))
        ->and($vacItem->metadata)->toHaveKey('average_salary_6m')
        ->and((float) $vacItem->metadata['average_salary_6m'])->toBe(3_000_000.0);
});

it('sin nóminas previas las vacaciones proporcionales hacen fallback al salario base de la liquidación', function () {
    seedLiqSettings();

    // Contratado 2024-01-01; termina 2026-03-15 → tiene días proporcionales
    $hireDate = Carbon::create(2024, 1, 1);
    $terminationDate = Carbon::create(2026, 3, 15);
    $employee = makeLiqEmployee(salary: 2_550_000, startDate: $hireDate);

    $liquidacion = makeLiquidacion($employee, [
        'hire_date' => $hireDate->toDateString(),
        'termination_date' => $terminationDate->toDateString(),
        'base_salary' => 2_550_000,
        'daily_salary' => round(2_550_000 / 30, 2),
    ]);

    $result = makeLiqService()->calculate($liquidacion);
    $vacItem = $result->items()->where('category', 'vacaciones')->firstOrFail();

    // Sin nóminas previas, averageSalary6m = baseSalary → daily_avg = baseSalary / 30
    $expectedDailyAvg = round(2_550_000 / 30, 2);
    $days = (int) $vacItem->metadata['days'];

    expect($days)->toBeGreaterThan(0)
        ->and((float) $vacItem->metadata['average_salary_6m'])->toBe(2_550_000.0)
        ->and((float) $vacItem->metadata['daily_avg'])->toBe($expectedDailyAvg)
        ->and((float) $vacItem->amount)->toBe((float) round($days * $expectedDailyAvg, 0));
});

afterEach(function () {
    Mockery::close();
});

// ─── Ausencias, suspensión y jornaleros en el tramo pendiente ───────────────

/** Crea una ausencia en la fecha dada con el estado indicado (pending/justified/unjustified). */
function makeLiqAbsence(Employee $employee, string $date, string $status): Absence
{
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => $date, 'status' => 'absent', 'is_calculated' => false]);
    $absence = Absence::where('attendance_day_id', $day->id)->firstOrFail();
    $absence->update(['status' => $status]);

    return $absence;
}

/** Registra un día de suspensión disciplinaria ya aplicado (SUS-DIS no pasa por nómina en la liquidación). */
function makeLiqSuspensionDay(Employee $employee, string $date): WarningSuspensionDay
{
    static $warning = null;
    $warning = Warning::where('employee_id', $employee->id)->first() ?? Warning::create([
        'employee_id' => $employee->id,
        'type' => 'severe',
        'reason' => 'conducta',
        'description' => 'Hecho de prueba',
        'issued_at' => '2026-03-01',
        'issued_by_id' => User::factory()->create()->id,
    ]);

    return WarningSuspensionDay::create(['warning_id' => $warning->id, 'employee_id' => $employee->id, 'date' => $date]);
}

it('descuenta solo las ausencias injustificadas: las justificadas y las pendientes no restan', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-15']);

    makeLiqAbsence($employee, '2026-03-03', 'unjustified');
    makeLiqAbsence($employee, '2026-03-04', 'unjustified');
    makeLiqAbsence($employee, '2026-03-05', 'justified');
    makeLiqAbsence($employee, '2026-03-06', 'pending');

    $result = makeLiqService()->calculate($liquidacion);

    $item = $result->items()->where('category', 'ausencias')->first();
    expect($item)->not->toBeNull()
        ->and($item->metadata['days'])->toBe(2)
        ->and((float) $item->amount)->toBe(round(2 * (2_550_000 / 30), 0));
});

it('no descuenta ausencias de días que una nómina ya liquidó (se descontaron allí como AUS-INJ)', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-20']);
    makeLiqPayroll($employee, makeLiqBiweeklyPayPeriod(2026, 3, 1, 15), 1_275_000);

    makeLiqAbsence($employee, '2026-03-10', 'unjustified'); // cubierta por la nómina 1-15
    makeLiqAbsence($employee, '2026-03-18', 'unjustified'); // tramo pendiente

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->items()->where('category', 'ausencias')->first()->metadata['days'])->toBe(1);
});

it('sin ausencias injustificadas no genera el ítem de ausencias (aunque haya días en absent)', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-15']);
    makeLiqAbsence($employee, '2026-03-03', 'justified');

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->items()->where('category', 'ausencias')->exists())->toBeFalse();
});

it('avisa de las ausencias pendientes de revisión sin descontarlas', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-15']);

    expect(makeLiqService()->getPendingAbsencesWarning($liquidacion))->toBeNull();

    makeLiqAbsence($employee, '2026-03-03', 'pending');
    makeLiqAbsence($employee, '2026-03-04', 'pending');

    $service = makeLiqService();
    expect($service->getPendingAbsencesWarning($liquidacion))->toContain('2 ausencia(s)');

    $result = $service->calculate($liquidacion);
    expect($result->items()->where('category', 'ausencias')->exists())->toBeFalse();
});

it('descuenta los días de suspensión disciplinaria del tramo pendiente en mensuales', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-15']);

    makeLiqSuspensionDay($employee, '2026-03-05');
    makeLiqSuspensionDay($employee, '2026-03-06');

    $result = makeLiqService()->calculate($liquidacion);

    $item = $result->items()->where('category', 'suspension')->first();
    expect($item->metadata['days'])->toBe(2)
        ->and((float) $item->amount)->toBe(round(2 * (2_550_000 / 30), 0));
});

it('no descuenta suspensión de días que una nómina ya liquidó', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-20']);
    makeLiqPayroll($employee, makeLiqBiweeklyPayPeriod(2026, 3, 1, 15), 1_275_000);

    makeLiqSuspensionDay($employee, '2026-03-10'); // ya en la nómina 1-15 (SUS-DIS)
    makeLiqSuspensionDay($employee, '2026-03-17'); // tramo pendiente

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->items()->where('category', 'suspension')->first()->metadata['days'])->toBe(1);
});

it('salario pendiente de un empleado ingresado a mitad de mes cuenta desde su ingreso', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee(startDate: Carbon::create(2026, 3, 10));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-20', 'hire_date' => '2026-03-10']);

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->salario_pendiente_days)->toBe(11);
});

it('jornalero: paga los días presentes del tramo más el descanso semanal, no días calendario', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee('jornal', 100_000, Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-15']);

    foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06'] as $date) {
        AttendanceDay::create(['employee_id' => $employee->id, 'date' => $date, 'status' => 'present']);
    }
    AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-03-09', 'status' => 'on_leave']);

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->salario_pendiente_days)->toBe(5)
        ->and((float) $result->salario_pendiente_amount)->toBe(500_000.0);

    $rest = $result->items()->where('category', 'descanso_semanal')->first();
    expect((float) $rest->amount)->toBe(round(5 * 100_000 / 6, 0));
});

it('jornalero: no genera descuento por ausencias ni por suspensión', function () {
    seedLiqSettings();
    $employee = makeLiqEmployee('jornal', 100_000, Carbon::create(2023, 1, 1));
    $liquidacion = makeLiquidacion($employee, ['termination_date' => '2026-03-15']);
    AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-03-02', 'status' => 'present']);
    makeLiqAbsence($employee, '2026-03-03', 'unjustified');
    makeLiqSuspensionDay($employee, '2026-03-04');

    $result = makeLiqService()->calculate($liquidacion);

    expect($result->items()->whereIn('category', ['ausencias', 'suspension'])->exists())->toBeFalse();
});
