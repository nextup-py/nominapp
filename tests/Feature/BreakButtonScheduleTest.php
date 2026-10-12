<?php

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\ScheduleBreak;
use App\Models\ScheduleDay;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regresión: "Inicio de descanso" aparecía siempre después de marcar Entrada,
 * sin importar si el horario del empleado contempla un descanso o no. Ahora
 * depende del turno/horario efectivo del empleado (break_minutes > 0) — ver
 * AttendanceCalculator::hasScheduledBreak() y
 * AttendanceDay::filterAllowedByBreakSchedule().
 */
function makeBreakTestEmployee(): Employee
{
    static $ci = 8200000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa Break {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create([
        'name' => "Sucursal Break {$n}",
        'company_id' => $company->id,
        'coordinates' => ['lat' => -25.2867, 'lng' => -57.6478],
    ]);
    $department = Department::create(['name' => "Depto Break {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo Break {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Break',
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

/** Asigna un horario fijo los 7 días de la semana, con o sin descanso configurado. */
function assignScheduleWithBreak(Employee $employee, bool $withBreak): void
{
    $schedule = Schedule::create(['name' => 'Horario Break Test', 'shift_type' => 'diurno', 'description' => null]);

    foreach (range(1, 7) as $dayOfWeek) {
        $day = ScheduleDay::create([
            'schedule_id' => $schedule->id,
            'day_of_week' => $dayOfWeek,
            'is_active' => true,
            'start_time' => '07:00',
            'end_time' => '22:00',
        ]);

        if ($withBreak) {
            ScheduleBreak::create([
                'schedule_day_id' => $day->id,
                'name' => 'Almuerzo',
                'start_time' => '12:00',
                'end_time' => '13:00',
            ]);
        }
    }

    ScheduleAssignmentService::assign($employee, $schedule, now()->subYear());
}

afterEach(function () {
    Carbon::setTestNow();
});

it('sin horario asignado, no ofrece ni acepta "Inicio de descanso"', function () {
    $employee = makeBreakTestEmployee();

    expect(markAttendanceEvent($employee, 'check_in')['ok'])->toBeTrue();

    Carbon::setTestNow(Carbon::now()->addMinutes(5));

    $state = AttendanceDay::currentStateFor($employee, Carbon::now());
    expect($state['allowed'])->not->toContain('break_start')
        ->and($state['allowed'])->toContain('check_out');

    expect(markAttendanceEvent($employee, 'break_start')['ok'])->toBeFalse();
});

it('con horario asignado pero sin descanso configurado (break_minutes = 0), tampoco lo ofrece', function () {
    $employee = makeBreakTestEmployee();
    assignScheduleWithBreak($employee, withBreak: false);

    expect(markAttendanceEvent($employee, 'check_in')['ok'])->toBeTrue();

    $state = AttendanceDay::currentStateFor($employee, Carbon::now());
    expect($state['allowed'])->not->toContain('break_start');
});

it('con horario que sí tiene descanso configurado, ofrece y acepta "Inicio de descanso"', function () {
    $employee = makeBreakTestEmployee();
    assignScheduleWithBreak($employee, withBreak: true);

    expect(markAttendanceEvent($employee, 'check_in')['ok'])->toBeTrue();

    $state = AttendanceDay::currentStateFor($employee, Carbon::now());
    expect($state['allowed'])->toContain('break_start');

    expect(markAttendanceEvent($employee, 'break_start')['ok'])->toBeTrue();
});

it('una vez iniciado el descanso, siempre se puede cerrar (break_end nunca se filtra)', function () {
    $employee = makeBreakTestEmployee();

    // Sin horario asignado — pero el empleado YA está en pausa (dato existente,
    // ej. cargado antes de este fix o por un horario que después cambió).
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => now()->toDateString(), 'status' => 'present']);
    AttendanceEvent::create([
        'attendance_day_id' => $day->id,
        'employee_id' => $employee->id,
        'event_type' => 'check_in',
        'recorded_at' => now()->subHours(2),
        'source' => 'manual',
    ]);
    AttendanceEvent::create([
        'attendance_day_id' => $day->id,
        'employee_id' => $employee->id,
        'event_type' => 'break_start',
        'recorded_at' => now()->subHour(),
        'source' => 'manual',
    ]);

    $state = AttendanceDay::currentStateFor($employee, Carbon::now());
    expect($state['allowed'])->toBe(['break_end']);

    expect(markAttendanceEvent($employee, 'break_end')['ok'])->toBeTrue();
});
