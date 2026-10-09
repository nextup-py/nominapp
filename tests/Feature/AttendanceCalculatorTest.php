<?php

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Holiday;
use App\Models\Position;
use App\Models\Vacation;
use App\Models\VacationBalance;
use App\Services\AttendanceCalculator;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function seedAttSettings(): void
{
    $settings = [
        'overtime_max_daily_hours' => 3,
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

function makeAttEmployee(): Employee
{
    static $ci = 3000000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'Att',
        'ci' => (string) $n,
        'email' => "att{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/**
 * Crea un AttendanceDay con expected_check_in/out pre-cargados (simula recalculación).
 * Usar is_calculated=true para evitar que apply() sobreescriba los valores esperados
 * con los del horario del empleado (que puede ser null en tests).
 */
function makeAttDay(Employee $employee, string $date = '2026-03-16', array $attrs = []): AttendanceDay
{
    return AttendanceDay::create(array_merge([
        'employee_id' => $employee->id,
        'date' => $date,
        'status' => 'absent',
        'expected_check_in' => '08:00:00',
        'expected_check_out' => '17:00:00',
        'expected_hours' => 9.0,
        'is_calculated' => true, // evita sobreescribir expected_* con horario del empleado
        'is_weekend' => false,
        'is_holiday' => false,
        'on_vacation' => false,
        'justified_absence' => false,
    ], $attrs));
}

function addEvent(AttendanceDay $day, string $type, string $time): AttendanceEvent
{
    return AttendanceEvent::create([
        'attendance_day_id' => $day->id,
        'employee_id' => $day->employee_id,
        'event_type' => $type,
        'recorded_at' => Carbon::parse($day->date->toDateString().' '.$time),
    ]);
}

// ─── Status sin eventos ───────────────────────────────────────────────────────

it('marca como absent si no hay eventos y es día laboral normal', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee);

    AttendanceCalculator::apply($day);

    expect($day->status)->toBe('absent')
        ->and($day->is_calculated)->toBeTrue();
});

it('marca como holiday si el día es feriado y no hay eventos', function () {
    $employee = makeAttEmployee();

    Holiday::create(['date' => '2026-03-16', 'name' => 'Feriado Test']);

    $day = makeAttDay($employee, '2026-03-16');
    AttendanceCalculator::apply($day);

    expect($day->status)->toBe('holiday')
        ->and($day->is_holiday)->toBeTrue();
});

it('marca como weekend si es domingo y no hay eventos', function () {
    $employee = makeAttEmployee();
    // 2026-03-22 es domingo
    $day = makeAttDay($employee, '2026-03-22');
    AttendanceCalculator::apply($day);

    expect($day->status)->toBe('weekend')
        ->and($day->is_weekend)->toBeTrue();
});

// ─── Vacaciones y permisos ────────────────────────────────────────────────────

it('marca como on_leave si el empleado tiene vacación aprobada ese día', function () {
    $employee = makeAttEmployee();
    $balance = VacationBalance::create([
        'employee_id' => $employee->id,
        'year' => 2026,
        'entitled_days' => 12,
        'used_days' => 0,
        'pending_days' => 0,
    ]);

    Vacation::create([
        'employee_id' => $employee->id,
        'vacation_balance_id' => $balance->id,
        'start_date' => '2026-03-16',
        'end_date' => '2026-03-20',
        'type' => 'paid',
        'status' => 'approved',
        'business_days' => 5,
    ]);

    $day = makeAttDay($employee, '2026-03-18');
    AttendanceCalculator::apply($day);

    expect($day->status)->toBe('on_leave')
        ->and($day->on_vacation)->toBeTrue()
        ->and($day->check_in_time)->toBeNull();
});

it('marca como on_leave si el empleado tiene permiso aprobado ese día', function () {
    $employee = makeAttEmployee();

    EmployeeLeave::create([
        'employee_id' => $employee->id,
        'type' => 'day_off',
        'start_date' => '2026-03-16',
        'end_date' => '2026-03-16',
        'status' => 'approved',
    ]);

    $day = makeAttDay($employee, '2026-03-16');
    AttendanceCalculator::apply($day);

    expect($day->status)->toBe('on_leave')
        ->and($day->justified_absence)->toBeTrue();
});

it('no aplica on_leave si la vacación no está aprobada', function () {
    $employee = makeAttEmployee();
    $balance = VacationBalance::create([
        'employee_id' => $employee->id,
        'year' => 2026,
        'entitled_days' => 12,
        'used_days' => 0,
        'pending_days' => 0,
    ]);

    Vacation::create([
        'employee_id' => $employee->id,
        'vacation_balance_id' => $balance->id,
        'start_date' => '2026-03-16',
        'end_date' => '2026-03-20',
        'type' => 'paid',
        'status' => 'pending', // no aprobada
        'business_days' => 5,
    ]);

    $day = makeAttDay($employee, '2026-03-18');
    AttendanceCalculator::apply($day);

    expect($day->status)->not->toBe('on_leave');
});

// ─── Presencia con eventos ────────────────────────────────────────────────────

it('marca como present con check_in y check_out normales', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee);

    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'check_out', '17:00:00');

    AttendanceCalculator::apply($day);

    expect($day->status)->toBe('present')
        ->and($day->check_in_time)->toBe('08:00:00')
        ->and($day->check_out_time)->toBe('17:00:00');
});

it('calcula total_hours y net_hours correctamente', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee);

    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'check_out', '17:00:00');

    AttendanceCalculator::apply($day);

    // 9h totales, 0 descanso → net = total
    expect((float) $day->total_hours)->toBe(9.0)
        ->and((float) $day->net_hours)->toBe(9.0);
});

it('descuenta break_minutes del net_hours', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee);

    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'break_start', '12:00:00');
    addEvent($day, 'break_end', '12:30:00');
    addEvent($day, 'check_out', '17:00:00');

    AttendanceCalculator::apply($day);

    expect($day->break_minutes)->toBe(30)
        ->and((float) $day->total_hours)->toBe(9.0)
        ->and((float) $day->net_hours)->toBe(8.5);
});

it('calcula múltiples descansos correctamente', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee);

    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'break_start', '10:00:00');
    addEvent($day, 'break_end', '10:15:00'); // 15 min
    addEvent($day, 'break_start', '12:00:00');
    addEvent($day, 'break_end', '12:30:00'); // 30 min
    addEvent($day, 'check_out', '17:00:00');

    AttendanceCalculator::apply($day);

    expect($day->break_minutes)->toBe(45)
        ->and((float) $day->net_hours)->toBe(8.25);
});

// ─── Horas extra ─────────────────────────────────────────────────────────────

it('calcula extra_hours cuando el empleado trabaja más de lo esperado', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    // expected_hours = 9 → trabajó 11h → 2h extra
    $day = makeAttDay($employee, '2026-03-16', ['expected_hours' => 9.0, 'expected_check_out' => '17:00:00']);

    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'check_out', '19:00:00'); // 11h

    AttendanceCalculator::apply($day);

    expect((float) $day->extra_hours)->toBe(2.0);
});

it('extra_hours es 0 cuando el empleado no supera las horas esperadas', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', ['expected_hours' => 9.0]);

    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'check_out', '17:00:00'); // justo 9h

    AttendanceCalculator::apply($day);

    expect((float) $day->extra_hours)->toBe(0.0);
});

it('extra_hours_diurnas cuando el overtime es antes de las 20:00', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    // Jornada 08:00-16:00 (8h esperadas), trabaja hasta 18:00 → 2h extra diurnas
    $day = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '08:00:00',
        'expected_check_out' => '16:00:00',
        'expected_hours' => 8.0,
    ]);

    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'check_out', '18:00:00');

    AttendanceCalculator::apply($day);

    expect((float) $day->extra_hours)->toBe(2.0)
        ->and((float) $day->extra_hours_diurnas)->toBe(2.0)
        ->and((float) $day->extra_hours_nocturnas)->toBe(0.0);
});

it('extra_hours_nocturnas cuando el overtime es después de las 20:00', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    // Jornada 12:00-20:00 (8h), trabaja hasta 22:00 → 2h extra nocturnas
    $day = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '12:00:00',
        'expected_check_out' => '20:00:00',
        'expected_hours' => 8.0,
    ]);

    addEvent($day, 'check_in', '12:00:00');
    addEvent($day, 'check_out', '22:00:00');

    AttendanceCalculator::apply($day);

    expect((float) $day->extra_hours)->toBe(2.0)
        ->and((float) $day->extra_hours_nocturnas)->toBe(2.0)
        ->and((float) $day->extra_hours_diurnas)->toBe(0.0);
});

it('marca overtime_limit_exceeded cuando las horas extra superan el límite legal', function () {
    seedAttSettings(); // límite = 3h
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '08:00:00',
        'expected_check_out' => '17:00:00',
        'expected_hours' => 9.0,
    ]);

    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'check_out', '20:00:00'); // 12h total − 9h esperadas = 3h extra (igual al límite, NO excede)

    AttendanceCalculator::apply($day);

    expect($day->overtime_limit_exceeded)->toBeFalse();
});

it('overtime_limit_exceeded es true cuando supera el límite legal', function () {
    seedAttSettings(); // límite = 3h
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '08:00:00',
        'expected_check_out' => '17:00:00',
        'expected_hours' => 9.0,
    ]);

    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'check_out', '21:30:00'); // 13.5h → 4.5h extra > 3h

    AttendanceCalculator::apply($day);

    expect($day->overtime_limit_exceeded)->toBeTrue();
});

// ─── Tardanza y salida anticipada ─────────────────────────────────────────────

it('calcula late_minutes cuando el empleado llega tarde', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '08:00:00',
        'expected_check_out' => '17:00:00',
        'expected_hours' => 9.0,
    ]);

    addEvent($day, 'check_in', '08:20:00'); // 20 min tarde
    addEvent($day, 'check_out', '17:00:00');

    AttendanceCalculator::apply($day);

    expect($day->late_minutes)->toBe(20);
});

it('late_minutes es 0 cuando el empleado llega puntual o antes', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '08:00:00',
        'expected_check_out' => '17:00:00',
        'expected_hours' => 9.0,
    ]);

    addEvent($day, 'check_in', '07:55:00'); // antes de tiempo
    addEvent($day, 'check_out', '17:00:00');

    AttendanceCalculator::apply($day);

    expect($day->late_minutes)->toBe(0);
});

it('calcula early_leave_minutes cuando el empleado sale antes', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '08:00:00',
        'expected_check_out' => '17:00:00',
        'expected_hours' => 9.0,
    ]);

    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'check_out', '16:45:00'); // 15 min antes

    AttendanceCalculator::apply($day);

    expect($day->early_leave_minutes)->toBe(15);
});

// ─── Trabajo extraordinario (feriado/domingo con eventos) ────────────────────

it('marca is_extraordinary_work cuando hay eventos en feriado', function () {
    seedAttSettings();
    $employee = makeAttEmployee();

    Holiday::create(['date' => '2026-03-16', 'name' => 'Feriado Test']);

    $day = makeAttDay($employee, '2026-03-16');
    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'check_out', '17:00:00');

    AttendanceCalculator::apply($day);

    expect($day->is_extraordinary_work)->toBeTrue()
        ->and($day->status)->toBe('present');
});

it('marca is_extraordinary_work cuando hay eventos en domingo', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    // 2026-03-22 es domingo
    $day = makeAttDay($employee, '2026-03-22');
    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'check_out', '13:00:00');

    AttendanceCalculator::apply($day);

    expect($day->is_extraordinary_work)->toBeTrue()
        ->and($day->status)->toBe('present');
});

// ─── is_calculated ────────────────────────────────────────────────────────────

it('siempre marca is_calculated = true y guarda calculated_at', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', ['is_calculated' => false]);

    AttendanceCalculator::apply($day);

    expect($day->is_calculated)->toBeTrue()
        ->and($day->calculated_at)->not->toBeNull();
});


// ─── Turnos que cruzan medianoche ─────────────────────────────────────────────

/** Agrega un evento en una fecha/hora absoluta (para marcaciones de madrugada del día siguiente). */
function addEventAt(AttendanceDay $day, string $type, string $dateTime): AttendanceEvent
{
    return AttendanceEvent::create([
        'attendance_day_id' => $day->id,
        'employee_id' => $day->employee_id,
        'event_type' => $type,
        'recorded_at' => Carbon::parse($dateTime),
    ]);
}

it('calcula horas esperadas positivas en un horario que cruza medianoche', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    $schedule = \App\Models\Schedule::create(['name' => 'Nocturno 23-07', 'shift_type' => 'nocturno']);
    $schedule->days()->create(['day_of_week' => 1, 'is_active' => true, 'start_time' => '23:00:00', 'end_time' => '07:00:00']);
    \App\Services\ScheduleAssignmentService::assign($employee, $schedule, Carbon::parse('2026-01-01'));

    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-03-16', 'status' => 'absent']);
    addEventAt($day, 'check_in', '2026-03-16 23:00:00');
    addEventAt($day, 'check_out', '2026-03-17 07:00:00');

    AttendanceCalculator::apply($day);

    expect((float) $day->expected_hours)->toBe(8.0)
        ->and((float) $day->total_hours)->toBe(8.0)
        ->and((float) $day->extra_hours)->toBe(0.0)
        ->and((int) $day->late_minutes)->toBe(0)
        ->and((int) $day->early_leave_minutes)->toBe(0);
});

it('detecta salida anticipada antes de medianoche en un turno que termina de madrugada', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '16:00:00',
        'expected_check_out' => '01:00:00',
        'expected_hours' => 9.0,
    ]);

    addEventAt($day, 'check_in', '2026-03-16 16:00:00');
    addEventAt($day, 'check_out', '2026-03-16 23:30:00');

    AttendanceCalculator::apply($day);

    expect((int) $day->early_leave_minutes)->toBe(90);
});

it('no marca salida anticipada ni extra cuando sale pasada la medianoche a la hora prevista', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '16:00:00',
        'expected_check_out' => '01:00:00',
        'expected_hours' => 9.0,
    ]);

    addEventAt($day, 'check_in', '2026-03-16 16:10:00');
    addEventAt($day, 'check_out', '2026-03-17 01:00:00');

    AttendanceCalculator::apply($day);

    expect((int) $day->early_leave_minutes)->toBe(0)
        ->and((int) $day->late_minutes)->toBe(10);
});

it('clasifica como nocturnas las horas extra de madrugada de un turno que cruza medianoche', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    // Turno 18:00-04:00 (10h); sale a las 05:00 → 1h extra, toda dentro de 00:00-06:00
    $day = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '18:00:00',
        'expected_check_out' => '04:00:00',
        'expected_hours' => 10.0,
    ]);

    addEventAt($day, 'check_in', '2026-03-16 18:00:00');
    addEventAt($day, 'check_out', '2026-03-17 05:00:00');

    AttendanceCalculator::apply($day);

    expect((float) $day->extra_hours)->toBe(1.0)
        ->and((float) $day->extra_hours_nocturnas)->toBe(1.0)
        ->and((float) $day->extra_hours_diurnas)->toBe(0.0);
});

it('reparte las extra que cruzan las 06:00 entre nocturnas y diurnas', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    // Turno 22:00-05:00 (7h); sale a las 07:00 → 2h extra: 1h nocturna (05-06) + 1h diurna (06-07)
    $day = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '22:00:00',
        'expected_check_out' => '05:00:00',
        'expected_hours' => 7.0,
    ]);

    addEventAt($day, 'check_in', '2026-03-16 22:00:00');
    addEventAt($day, 'check_out', '2026-03-17 07:00:00');

    AttendanceCalculator::apply($day);

    expect((float) $day->extra_hours)->toBe(2.0)
        ->and((float) $day->extra_hours_nocturnas)->toBe(1.0)
        ->and((float) $day->extra_hours_diurnas)->toBe(1.0);
});

it('attendance:recalculate-overnight corrige jornadas nocturnas con horas esperadas negativas y respeta las aprobadas', function () {
    seedAttSettings();
    $employee = makeAttEmployee();

    $wrong = makeAttDay($employee, '2026-03-16', [
        'expected_check_in' => '23:00:00', 'expected_check_out' => '07:00:00', 'expected_hours' => -16.0,
    ]);
    addEventAt($wrong, 'check_in', '2026-03-16 23:00:00');
    addEventAt($wrong, 'check_out', '2026-03-17 07:00:00');

    $approved = makeAttDay($employee, '2026-03-17', [
        'expected_check_in' => '23:00:00', 'expected_check_out' => '07:00:00', 'expected_hours' => -16.0,
        'overtime_approved' => true,
    ]);

    \Illuminate\Support\Facades\Artisan::call('attendance:recalculate-overnight', ['--dry-run' => true]);
    expect((float) $wrong->fresh()->expected_hours)->toBe(-16.0);

    \Illuminate\Support\Facades\Artisan::call('attendance:recalculate-overnight');

    expect((float) $wrong->fresh()->expected_hours)->toBe(8.0)
        ->and((float) $wrong->fresh()->extra_hours)->toBe(0.0)
        ->and((float) $approved->fresh()->expected_hours)->toBe(-16.0);
});

// ─── Segundo turno el mismo día (varias entradas/salidas) ─────────────────────

it('tras una salida permite una nueva entrada (segundo turno del día)', function () {
    expect(AttendanceEvent::allowedNextEventTypes('check_out'))->toBe(['check_in']);
});

it('suma cada tramo y no cuenta la pausa entre turnos como trabajada', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', ['expected_hours' => 8.0]);

    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'check_out', '11:00:00');
    addEvent($day, 'check_in', '15:00:00');
    addEvent($day, 'check_out', '19:00:00');

    AttendanceCalculator::apply($day);

    expect($day->check_in_time)->toBe('07:00:00')
        ->and($day->check_out_time)->toBe('19:00:00')
        ->and((float) $day->total_hours)->toBe(8.0)
        ->and((float) $day->net_hours)->toBe(8.0)
        ->and((float) $day->extra_hours)->toBe(0.0)
        ->and($day->status)->toBe('present');
});

it('con el segundo turno abierto el día queda incompleto hasta marcar la salida', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee);

    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'check_out', '11:00:00');
    addEvent($day, 'check_in', '15:00:00');

    AttendanceCalculator::apply($day);

    expect($day->check_out_time)->toBeNull()
        ->and($day->total_hours)->toBeNull()
        ->and($day->status)->toBe('present');
});

it('un día con una sola entrada y una sola salida se calcula igual que antes', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee);

    addEvent($day, 'check_in', '08:00:00');
    addEvent($day, 'check_out', '17:00:00');

    AttendanceCalculator::apply($day);

    expect((float) $day->total_hours)->toBe(9.0)
        ->and($day->check_out_time)->toBe('17:00:00');
});

it('descuenta la pausa de cada tramo al sumar las horas netas', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee);

    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'break_start', '09:00:00');
    addEvent($day, 'break_end', '09:30:00');
    addEvent($day, 'check_out', '11:00:00');
    addEvent($day, 'check_in', '15:00:00');
    addEvent($day, 'check_out', '19:00:00');

    AttendanceCalculator::apply($day);

    expect((float) $day->total_hours)->toBe(8.0)
        ->and((float) $day->net_hours)->toBe(7.5);
});

it('el observer recalcula al registrar la nueva entrada y el día vuelve a quedar abierto', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee);

    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'check_out', '11:00:00');
    expect($day->fresh()->check_out_time)->toBe('11:00:00');

    addEvent($day, 'check_in', '15:00:00');

    $day = $day->fresh();
    expect($day->check_out_time)->toBeNull()
        ->and($day->total_hours)->toBeNull();
});

it('resolveForEvent acepta la nueva entrada sobre la jornada de hoy después de una salida', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16');

    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'check_out', '11:00:00');

    $resolved = AttendanceDay::resolveForEvent($employee, Carbon::parse('2026-03-16 15:00:00'), 'check_in');

    expect($resolved['day']?->id)->toBe($day->id)
        ->and($resolved['last']?->event_type)->toBe('check_out')
        ->and($resolved['allowed'])->toBe(['check_in']);
});

// ─── Mínimo entre salida y nueva entrada ──────────────────────────────────────

it('no permite una nueva entrada antes del mínimo configurado desde la salida', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16');
    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'check_out', '11:00:00');

    $tooSoon = AttendanceDay::resolveForEvent($employee, Carbon::parse('2026-03-16 11:03:00'), 'check_in');
    $enough = AttendanceDay::resolveForEvent($employee, Carbon::parse('2026-03-16 11:05:00'), 'check_in');

    expect($tooSoon['allowed'])->toBe([])
        ->and($enough['allowed'])->toBe(['check_in']);
});

it('sin mínimo configurado la nueva entrada se permite enseguida', function () {
    $settings = app(GeneralSettings::class);
    $settings->attendance_min_reentry_minutes = 0;
    $settings->save();

    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16');
    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'check_out', '11:00:00');

    $resolved = AttendanceDay::resolveForEvent($employee, Carbon::parse('2026-03-16 11:00:30'), 'check_in');

    expect($resolved['allowed'])->toBe(['check_in']);
});

it('la aprobación manual de un conflicto puede saltarse el mínimo', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16');
    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'check_out', '11:00:00');

    $resolved = AttendanceDay::resolveForEvent(
        $employee,
        Carbon::parse('2026-03-16 11:01:00'),
        'check_in',
        enforceReentryGap: false,
    );

    expect($resolved['allowed'])->toBe(['check_in']);
});

it('el estado que ve el terminal no ofrece la entrada hasta pasar el mínimo', function () {
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16');
    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'check_out', '11:00:00');

    $soon = AttendanceDay::currentStateFor($employee, Carbon::parse('2026-03-16 11:01:00'));
    $later = AttendanceDay::currentStateFor($employee, Carbon::parse('2026-03-16 11:30:00'));

    expect($soon['allowed'])->toBe([])
        ->and($later['allowed'])->toBe(['check_in']);
});

// ─── Doble turno aceptado como jornada normal ─────────────────────────────────

it('las horas del segundo turno sobre el horario cuentan como extra', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', ['expected_hours' => 8.0]);

    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'check_out', '15:00:00');
    addEvent($day, 'check_in', '15:30:00');
    addEvent($day, 'check_out', '19:30:00');

    AttendanceCalculator::apply($day);

    expect((float) $day->total_hours)->toBe(12.0)
        ->and((float) $day->extra_hours)->toBe(4.0);
});

it('un doble turno aceptado como jornada normal no genera horas extra y se puede deshacer', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', ['expected_hours' => 8.0]);

    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'check_out', '15:00:00');
    addEvent($day, 'check_in', '15:30:00');
    addEvent($day, 'check_out', '19:30:00');

    $day->second_shift_regular = true;
    AttendanceCalculator::apply($day);

    expect((float) $day->total_hours)->toBe(12.0)
        ->and((float) $day->extra_hours)->toBe(0.0)
        ->and($day->overtime_limit_exceeded)->toBeFalse();

    $day->second_shift_regular = false;
    AttendanceCalculator::apply($day);

    expect((float) $day->extra_hours)->toBe(4.0);
});

it('la marca de doble turno no afecta a un día de un solo turno', function () {
    seedAttSettings();
    $employee = makeAttEmployee();
    $day = makeAttDay($employee, '2026-03-16', ['expected_hours' => 8.0, 'second_shift_regular' => true]);

    addEvent($day, 'check_in', '07:00:00');
    addEvent($day, 'check_out', '17:00:00');

    AttendanceCalculator::apply($day);

    expect((float) $day->extra_hours)->toBe(2.0);
});
