<?php

use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\RotationPattern;
use App\Models\Schedule;
use App\Models\ScheduleDay;
use App\Models\ShiftTemplate;
use App\Services\RotationService;
use App\Services\ScheduleAssignmentService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * Regresión: attendance:check-missing solo consultaba empleados con horario
 * fijo (scheduleAssignments / schedule.days) — un empleado con rotación
 * asignada (RotationAssignment / ShiftOverride) nunca generaba una ausencia
 * automática por más que faltara a marcar, sin importar el turno.
 *
 * Regresión 2: el comando solo evaluaba "hoy" contra "ahora" — un turno que
 * arranca de noche (ej. 22:00) nunca generaba ausencia automática, porque el
 * cron deja de correr a las 20:00 (nunca llega a pasar el umbral ese mismo
 * día) y la corrida del día siguiente solo miraba la fecha nueva, sin
 * revisar si el día anterior quedó un turno sin marcar. El fix procesa
 * siempre la fecha pedida y el día anterior en cada corrida.
 */
function makeCheckMissingCompany(): Company
{
    static $n = 8600000;
    $n++;

    return Company::create(['name' => "Empresa CheckMissing {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
}

function makeCheckMissingEmployee(Company $company): Employee
{
    static $ci = 8600000;
    $n = $ci++;

    $branch = Branch::create(['name' => "Sucursal CheckMissing {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto CheckMissing {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo CheckMissing {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'CheckMissing',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'birth_date' => '1990-01-01',
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

beforeEach(function () {
    app(GeneralSettings::class)->absence_threshold_minutes = 15;
    app(GeneralSettings::class)->save();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('crea ausencia para un empleado con horario fijo que no marcó pasado el umbral (regresión)', function () {
    $company = makeCheckMissingCompany();
    $employee = makeCheckMissingEmployee($company);

    $schedule = Schedule::create(['name' => 'Horario Fijo CheckMissing', 'shift_type' => 'diurno', 'description' => null]);
    $today = Carbon::parse('2026-08-24'); // lunes
    ScheduleDay::create([
        'schedule_id' => $schedule->id,
        'day_of_week' => $today->dayOfWeekIso,
        'is_active' => true,
        'start_time' => '08:00',
        'end_time' => '17:00',
    ]);
    ScheduleAssignmentService::assign($employee, $schedule, $today->copy()->subYear());

    Carbon::setTestNow($today->copy()->setTime(8, 30));

    Artisan::call('attendance:check-missing', ['--date' => $today->toDateString()]);

    $day = AttendanceDay::where('employee_id', $employee->id)->where('date', $today->toDateString())->first();
    expect($day)->not->toBeNull()
        ->and($day->status)->toBe('absent')
        ->and($day->expected_check_in)->toBe('08:00:00');
});

it('crea ausencia para un empleado con rotación asignada que no marcó pasado el umbral', function () {
    $company = makeCheckMissingCompany();
    $employee = makeCheckMissingEmployee($company);

    $shift = ShiftTemplate::create([
        'company_id' => $company->id,
        'name' => 'Turno Mañana',
        'shift_type' => 'diurno',
        'is_day_off' => false,
        'start_time' => '07:00',
        'end_time' => '15:00',
        'break_minutes' => 30,
        'is_active' => true,
    ]);
    $pattern = RotationPattern::create([
        'company_id' => $company->id,
        'name' => 'Patrón Fijo Mañana',
        'sequence' => [$shift->id],
        'is_active' => true,
    ]);

    $today = Carbon::parse('2026-08-24');
    RotationService::assign($employee, $pattern, $today->copy()->subMonth());

    Carbon::setTestNow($today->copy()->setTime(7, 30));

    Artisan::call('attendance:check-missing', ['--date' => $today->toDateString()]);

    $day = AttendanceDay::where('employee_id', $employee->id)->where('date', $today->toDateString())->first();
    expect($day)->not->toBeNull()
        ->and($day->status)->toBe('absent')
        ->and($day->expected_check_in)->toBe('07:00:00')
        ->and($day->expected_check_out)->toBe('15:00:00');
});

it('no crea ausencia para un empleado con rotación en día de franco', function () {
    $company = makeCheckMissingCompany();
    $employee = makeCheckMissingEmployee($company);

    $workShift = ShiftTemplate::create([
        'company_id' => $company->id,
        'name' => 'Turno Mañana',
        'shift_type' => 'diurno',
        'is_day_off' => false,
        'start_time' => '07:00',
        'end_time' => '15:00',
        'break_minutes' => 30,
        'is_active' => true,
    ]);
    $dayOffShift = ShiftTemplate::create([
        'company_id' => $company->id,
        'name' => 'Franco',
        'shift_type' => 'diurno',
        'is_day_off' => true,
        'is_active' => true,
    ]);
    $pattern = RotationPattern::create([
        'company_id' => $company->id,
        'name' => 'Patrón 1x1',
        'sequence' => [$workShift->id, $dayOffShift->id],
        'is_active' => true,
    ]);

    $today = Carbon::parse('2026-08-24');
    // valid_from 10 días antes (diff par) + start_index=1 → offset (10+1)%2=1 → franco.
    RotationService::assign($employee, $pattern, $today->copy()->subDays(10), startIndex: 1);

    Carbon::setTestNow($today->copy()->setTime(23, 0));

    Artisan::call('attendance:check-missing', ['--date' => $today->toDateString()]);

    expect(AttendanceDay::where('employee_id', $employee->id)->where('date', $today->toDateString())->exists())->toBeFalse();
});

it('no crea ausencia para un empleado con rotación si todavía no pasó el umbral de tolerancia', function () {
    $company = makeCheckMissingCompany();
    $employee = makeCheckMissingEmployee($company);

    $shift = ShiftTemplate::create([
        'company_id' => $company->id,
        'name' => 'Turno Tarde',
        'shift_type' => 'diurno',
        'is_day_off' => false,
        'start_time' => '14:00',
        'end_time' => '22:00',
        'break_minutes' => 30,
        'is_active' => true,
    ]);
    $pattern = RotationPattern::create([
        'company_id' => $company->id,
        'name' => 'Patrón Tarde',
        'sequence' => [$shift->id],
        'is_active' => true,
    ]);

    $today = Carbon::parse('2026-08-24');
    RotationService::assign($employee, $pattern, $today->copy()->subMonth());

    // Todavía no llegó ni la hora de entrada (14:00).
    Carbon::setTestNow($today->copy()->setTime(10, 0));

    Artisan::call('attendance:check-missing', ['--date' => $today->toDateString()]);

    expect(AttendanceDay::where('employee_id', $employee->id)->where('date', $today->toDateString())->exists())->toBeFalse();
});

it('crea ausencia para un turno nocturno (cruza medianoche) recién en la corrida del día siguiente', function () {
    $company = makeCheckMissingCompany();
    $employee = makeCheckMissingEmployee($company);

    $shift = ShiftTemplate::create([
        'company_id' => $company->id,
        'name' => 'Turno Noche',
        'shift_type' => 'nocturno',
        'is_day_off' => false,
        'start_time' => '22:00',
        'end_time' => '06:00',
        'break_minutes' => 30,
        'is_active' => true,
    ]);
    $pattern = RotationPattern::create([
        'company_id' => $company->id,
        'name' => 'Patrón Fijo Noche',
        'sequence' => [$shift->id],
        'is_active' => true,
    ]);

    $shiftStart = Carbon::parse('2026-08-24'); // lunes — el turno arranca esta noche a las 22:00
    RotationService::assign($employee, $pattern, $shiftStart->copy()->subMonth());

    // El cron ya no corre después de las 20:00, así que el umbral del turno
    // (22:15) nunca se evalúa el lunes. La próxima corrida es el martes a las
    // 06:00 — sin el fix, el comando solo miraba "hoy" (martes) y la ausencia
    // del lunes nunca se generaba.
    Carbon::setTestNow($shiftStart->copy()->addDay()->setTime(6, 0));

    Artisan::call('attendance:check-missing');

    $day = AttendanceDay::where('employee_id', $employee->id)->where('date', $shiftStart->toDateString())->first();
    expect($day)->not->toBeNull()
        ->and($day->status)->toBe('absent')
        ->and($day->expected_check_in)->toBe('22:00:00')
        ->and($day->expected_check_out)->toBe('06:00:00');
});

it('--date también revisa el día anterior para cubrir turnos nocturnos al re-procesar manualmente', function () {
    $company = makeCheckMissingCompany();
    $employee = makeCheckMissingEmployee($company);

    $shift = ShiftTemplate::create([
        'company_id' => $company->id,
        'name' => 'Turno Noche',
        'shift_type' => 'nocturno',
        'is_day_off' => false,
        'start_time' => '22:00',
        'end_time' => '06:00',
        'break_minutes' => 30,
        'is_active' => true,
    ]);
    $pattern = RotationPattern::create([
        'company_id' => $company->id,
        'name' => 'Patrón Fijo Noche',
        'sequence' => [$shift->id],
        'is_active' => true,
    ]);

    $shiftStart = Carbon::parse('2026-08-24');
    RotationService::assign($employee, $pattern, $shiftStart->copy()->subMonth());

    Carbon::setTestNow($shiftStart->copy()->addDay()->setTime(10, 0));

    Artisan::call('attendance:check-missing', ['--date' => $shiftStart->copy()->addDay()->toDateString()]);

    $day = AttendanceDay::where('employee_id', $employee->id)->where('date', $shiftStart->toDateString())->first();
    expect($day)->not->toBeNull()->and($day->status)->toBe('absent');
});

it('no duplica la ausencia si ya existe un AttendanceDay para la fecha', function () {
    $company = makeCheckMissingCompany();
    $employee = makeCheckMissingEmployee($company);

    $shift = ShiftTemplate::create([
        'company_id' => $company->id,
        'name' => 'Turno Mañana',
        'shift_type' => 'diurno',
        'is_day_off' => false,
        'start_time' => '07:00',
        'end_time' => '15:00',
        'break_minutes' => 30,
        'is_active' => true,
    ]);
    $pattern = RotationPattern::create([
        'company_id' => $company->id,
        'name' => 'Patrón Fijo',
        'sequence' => [$shift->id],
        'is_active' => true,
    ]);

    $today = Carbon::parse('2026-08-24');
    RotationService::assign($employee, $pattern, $today->copy()->subMonth());

    AttendanceDay::create(['employee_id' => $employee->id, 'date' => $today->toDateString(), 'status' => 'present']);

    Carbon::setTestNow($today->copy()->setTime(23, 0));

    Artisan::call('attendance:check-missing', ['--date' => $today->toDateString()]);

    expect(AttendanceDay::where('employee_id', $employee->id)->where('date', $today->toDateString())->count())->toBe(1);
});
