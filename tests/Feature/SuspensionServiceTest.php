<?php

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Deduction;
use App\Models\EmployeeDeduction;
use App\Models\Holiday;
use App\Models\PayrollPeriod;
use App\Models\User;
use App\Models\Warning;
use App\Models\WarningSuspensionDay;
use App\Services\DeductionCalculator;
use App\Services\SuspensionService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/SuspensionHelpers.php';

/**
 * Suspensión disciplinaria: cada día laborable suspendido genera una deducción SUS-DIS
 * (sin pasar por Absence ni AUS-INJ) y deja el día en `on_leave` en asistencia.
 *
 * Calendario de referencia: 2026-10-05 es lunes (jornada lun-vie).
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
    ];

    foreach ($settings as $name => $value) {
        DB::table('settings')->updateOrInsert(
            ['group' => 'payroll', 'name' => $name],
            ['payload' => json_encode($value)]
        );
    }

    app(GeneralSettings::class)->absence_threshold_minutes = 15;
    app(GeneralSettings::class)->save();
});

afterEach(function () {
    Carbon::setTestNow();
});

// ─── Resolución de días ─────────────────────────────────────────────────────

it('cuenta solo días laborables: salta fin de semana y feriados', function () {
    $employee = makeSuspEmployee();
    Holiday::create(['date' => '2026-10-05', 'name' => 'Feriado de prueba']);

    // Viernes 2/10 inicia: vie 2, (sáb/dom), lun 5 feriado, mar 6, mié 7
    $dates = app(SuspensionService::class)->resolveDates($employee, Carbon::parse('2026-10-02'), 3);

    expect($dates->map->toDateString()->all())->toBe(['2026-10-02', '2026-10-06', '2026-10-07']);
});

// ─── Aplicación ─────────────────────────────────────────────────────────────

it('genera una deducción SUS-DIS por día laborable y registra los días suspendidos', function () {
    $employee = makeSuspEmployee();

    $warning = makeSuspWarning($employee, 3);

    $deduction = Deduction::where('code', 'SUS-DIS')->first();
    expect($deduction)->not->toBeNull()
        ->and($deduction->is_mandatory)->toBeFalse();

    $rows = $warning->suspensionDays;
    expect($rows)->toHaveCount(3)
        ->and($rows->map(fn ($r) => $r->date->toDateString())->all())->toBe(['2026-10-05', '2026-10-06', '2026-10-07']);

    $deductions = EmployeeDeduction::where('employee_id', $employee->id)->where('deduction_id', $deduction->id)->get();
    expect($deductions)->toHaveCount(3)
        ->and((float) $deductions->first()->custom_amount)->toBe(85000.0)
        ->and($warning->suspension_end_date->toDateString())->toBe('2026-10-07');
});

it('descuenta el jornal completo por día en empleados jornaleros', function () {
    $employee = makeSuspEmployee('jornal', 100_000);

    makeSuspWarning($employee, 2);

    $amounts = EmployeeDeduction::where('employee_id', $employee->id)->pluck('custom_amount')->map(fn ($a) => (float) $a)->all();
    expect($amounts)->toBe([(float) $employee->daily_rate, (float) $employee->daily_rate]);
});

it('es idempotente: reaplicar o cambiar los días reemplaza sin duplicar', function () {
    $employee = makeSuspEmployee();
    $warning = makeSuspWarning($employee, 2);

    app(SuspensionService::class)->apply($warning);
    expect(EmployeeDeduction::where('employee_id', $employee->id)->count())->toBe(2);

    $warning->update(['suspension_days' => 3]);

    expect(EmployeeDeduction::where('employee_id', $employee->id)->count())->toBe(3)
        ->and(WarningSuspensionDay::where('warning_id', $warning->id)->count())->toBe(3);

    $warning->update(['suspension_days' => 0, 'suspension_start_date' => null]);

    expect(EmployeeDeduction::where('employee_id', $employee->id)->count())->toBe(0)
        ->and(WarningSuspensionDay::where('warning_id', $warning->id)->count())->toBe(0);
});

it('no genera nada cuando la amonestación no lleva suspensión', function () {
    $employee = makeSuspEmployee();

    makeSuspWarning($employee, 0);

    expect(EmployeeDeduction::count())->toBe(0)
        ->and(WarningSuspensionDay::count())->toBe(0);
});

it('editar campos no relacionados con la suspensión no la recalcula ni la bloquea', function () {
    $employee = makeSuspEmployee();
    $warning = makeSuspWarning($employee, 2);
    makeSuspPayroll($employee);

    $warning->update(['notes' => 'Nota agregada después de generar la nómina']);

    expect(WarningSuspensionDay::where('warning_id', $warning->id)->count())->toBe(2);
});

// ─── Validaciones ───────────────────────────────────────────────────────────

it('bloquea más de 8 días', function () {
    $employee = makeSuspEmployee();

    expect(fn () => makeSuspWarning($employee, 9, summary: true))->toThrow(ValidationException::class);
    expect(Warning::count())->toBe(0);
});

it('exige sumario instruido entre 4 y 8 días, pero no por debajo de 4', function () {
    $employee = makeSuspEmployee();

    expect(fn () => makeSuspWarning($employee, 4))->toThrow(ValidationException::class);

    makeSuspWarning($employee, 3);
    expect(WarningSuspensionDay::count())->toBe(3);

    // 4 días con sumario, ya sin choque con los 3 anteriores (otra semana)
    makeSuspWarning($employee, 4, '2026-10-12', true);
    expect(WarningSuspensionDay::count())->toBe(7);
});

it('exige fecha de inicio cuando hay días de suspensión', function () {
    $employee = makeSuspEmployee();

    expect(fn () => Warning::create([
        'employee_id' => $employee->id,
        'type' => 'written',
        'reason' => 'conducta',
        'description' => 'x',
        'issued_at' => '2026-10-01',
        'issued_by_id' => User::factory()->create()->id,
        'suspension_days' => 2,
    ]))->toThrow(ValidationException::class);
});

it('bloquea la suspensión de un empleado inactivo', function () {
    $employee = makeSuspEmployee(status: 'inactive');

    expect(fn () => makeSuspWarning($employee, 1))->toThrow(ValidationException::class);
});

it('bloquea cuando el empleado ya tiene nómina del período', function () {
    $employee = makeSuspEmployee();
    makeSuspPayroll($employee);

    expect(fn () => makeSuspWarning($employee, 2))->toThrow(ValidationException::class);
    expect(EmployeeDeduction::count())->toBe(0);
});

it('bloquea fechas con marcaciones reales de asistencia', function () {
    $employee = makeSuspEmployee();
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-10-05', 'status' => 'present', 'is_calculated' => false]);
    AttendanceEvent::create([
        'attendance_day_id' => $day->id,
        'employee_id' => $employee->id,
        'event_type' => 'check_in',
        'recorded_at' => '2026-10-05 08:01:00',
    ]);

    expect(fn () => makeSuspWarning($employee, 2))->toThrow(ValidationException::class);
});

it('bloquea fechas con una ausencia ya descontada (evita doble descuento con AUS-INJ)', function () {
    $employee = makeSuspEmployee();
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-10-05', 'status' => 'absent', 'is_calculated' => false]);
    $absence = Absence::create(['employee_id' => $employee->id, 'attendance_day_id' => $day->id, 'status' => 'pending']);
    $absence->markAsUnjustified(User::factory()->create()->id, 'Falta sin aviso');

    expect(fn () => makeSuspWarning($employee, 2))->toThrow(ValidationException::class);
});

it('bloquea fechas ya cubiertas por otra suspensión del empleado', function () {
    $employee = makeSuspEmployee();
    makeSuspWarning($employee, 2);

    expect(fn () => makeSuspWarning($employee, 2, '2026-10-06'))->toThrow(ValidationException::class);
});

// ─── Reversión ──────────────────────────────────────────────────────────────

it('al eliminar la amonestación revierte deducciones y días suspendidos', function () {
    $employee = makeSuspEmployee();
    $warning = makeSuspWarning($employee, 2);

    $warning->delete();

    expect(EmployeeDeduction::count())->toBe(0)
        ->and(WarningSuspensionDay::count())->toBe(0);
});

it('bloquea eliminar o reducir la suspensión si ya hay nómina de esas fechas', function () {
    $employee = makeSuspEmployee();
    $warning = makeSuspWarning($employee, 2);
    makeSuspPayroll($employee);

    expect(fn () => $warning->delete())->toThrow(ValidationException::class);
    expect(fn () => $warning->update(['suspension_days' => 1]))->toThrow(ValidationException::class);

    expect(WarningSuspensionDay::where('warning_id', $warning->id)->count())->toBe(2)
        ->and(EmployeeDeduction::count())->toBe(2);
});

// ─── Integración con nómina y asistencia ────────────────────────────────────

it('DeductionCalculator incluye las deducciones SUS-DIS del período', function () {
    $employee = makeSuspEmployee();
    makeSuspWarning($employee, 2);

    $period = PayrollPeriod::create([
        'name' => 'Octubre 2026',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'frequency' => 'monthly',
        'status' => 'draft',
    ]);

    $result = app(DeductionCalculator::class)->calculate($employee, $period);

    $items = collect($result['items'])->where('description', 'Suspensión Disciplinaria');
    expect($items)->toHaveCount(2)
        ->and((float) $result['total'])->toBe(170000.0);
});

it('deja on_leave un día ya registrado como ausente y lo restituye al revertir', function () {
    $employee = makeSuspEmployee();
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-10-05', 'status' => 'absent', 'is_calculated' => false]);

    $warning = makeSuspWarning($employee, 1);

    expect($day->fresh()->status)->toBe('on_leave');

    $warning->delete();

    expect($day->fresh()->status)->not->toBe('on_leave');
});

it('attendance:check-missing no genera ausencia en un día suspendido', function () {
    $employee = makeSuspEmployee();
    makeSuspWarning($employee, 1);

    Carbon::setTestNow(Carbon::parse('2026-10-05 08:30'));
    Artisan::call('attendance:check-missing', ['--date' => '2026-10-05']);

    $day = AttendanceDay::where('employee_id', $employee->id)->where('date', '2026-10-05')->first();
    expect($day?->status)->toBe('on_leave');
    expect(Absence::where('employee_id', $employee->id)->count())->toBe(0);
});

// ─── Regresiones del code review ────────────────────────────────────────────

it('suspender un día con ausencia pendiente la reemplaza: la ausencia se elimina y no genera AUS-INJ', function () {
    $employee = makeSuspEmployee();
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-10-05', 'status' => 'absent', 'is_calculated' => false]);
    expect(Absence::where('attendance_day_id', $day->id)->count())->toBe(1);

    makeSuspWarning($employee, 1);

    expect(Absence::where('attendance_day_id', $day->id)->count())->toBe(0)
        ->and($day->fresh()->status)->toBe('on_leave')
        ->and(EmployeeDeduction::whereHas('deduction', fn ($q) => $q->where('code', 'AUS-INJ'))->count())->toBe(0)
        ->and(EmployeeDeduction::whereHas('deduction', fn ($q) => $q->where('code', 'SUS-DIS'))->count())->toBe(1);
});

it('no permite marcar como injustificada una ausencia de un día ya suspendido (evita AUS-INJ + SUS-DIS)', function () {
    $employee = makeSuspEmployee();
    makeSuspWarning($employee, 1);

    $day = AttendanceDay::where('employee_id', $employee->id)->where('date', '2026-10-05')->first()
        ?? AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-10-05', 'status' => 'on_leave', 'is_calculated' => false]);
    $absence = Absence::create(['employee_id' => $employee->id, 'attendance_day_id' => $day->id, 'status' => 'pending']);

    $result = $absence->markAsUnjustified(User::factory()->create()->id, 'Falta');

    expect($result['success'])->toBeFalse()
        ->and($absence->fresh()->status)->toBe('pending')
        ->and(EmployeeDeduction::whereHas('deduction', fn ($q) => $q->where('code', 'AUS-INJ'))->count())->toBe(0);
});

it('si apply() falla al crear, no queda la amonestación guardada sin sus deducciones', function () {
    $employee = makeSuspEmployee();

    $this->partialMock(SuspensionService::class, fn ($mock) => $mock->shouldReceive('apply')->andThrow(new RuntimeException('fallo simulado')));

    expect(fn () => makeSuspWarning($employee, 2))->toThrow(RuntimeException::class);
    expect(Warning::count())->toBe(0)
        ->and(EmployeeDeduction::count())->toBe(0);
});

it('si apply() falla al editar, la amonestación vuelve a su suspensión anterior', function () {
    $employee = makeSuspEmployee();
    $warning = makeSuspWarning($employee, 2);

    $this->partialMock(SuspensionService::class, fn ($mock) => $mock->shouldReceive('apply')->andThrow(new RuntimeException('fallo simulado')));

    expect(fn () => $warning->update(['suspension_days' => 3]))->toThrow(RuntimeException::class);

    expect($warning->fresh()->suspension_days)->toBe(2)
        ->and(WarningSuspensionDay::where('warning_id', $warning->id)->count())->toBe(2)
        ->and(EmployeeDeduction::count())->toBe(2);
});
