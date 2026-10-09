<?php

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function seedObserverSettings(): void
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

function makeObsEmployee(): Employee
{
    static $ci = 9000000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Obs',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'email' => "obs{$n}@test.com",
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

function makeObsDay(Employee $employee, string $status = 'present', array $attrs = []): AttendanceDay
{
    return AttendanceDay::create(array_merge([
        'employee_id' => $employee->id,
        'date' => '2026-03-16',
        'status' => $status,
        'expected_check_in' => '08:00:00',
        'expected_check_out' => '17:00:00',
        'expected_hours' => 9.0,
        'is_calculated' => true,
        'is_weekend' => false,
        'is_holiday' => false,
        'on_vacation' => false,
        'justified_absence' => false,
    ], $attrs));
}

function createObsEvent(AttendanceDay $day, string $type, string $time): AttendanceEvent
{
    return AttendanceEvent::create([
        'attendance_day_id' => $day->id,
        'employee_id' => $day->employee_id,
        'event_type' => $type,
        'recorded_at' => Carbon::parse('2026-03-16 '.$time),
    ]);
}

// ─── Tests ──────────────────────────────────────────────────────────────────

beforeEach(fn () => seedObserverSettings());

it('recalcula el día al registrar check_out', function () {
    $employee = makeObsEmployee();
    $day = makeObsDay($employee);

    createObsEvent($day, 'check_in', '08:00:00');
    createObsEvent($day, 'check_out', '19:00:00'); // 11h total → 2h extras

    $day->refresh();

    expect($day->check_out_time)->toBe('19:00:00')
        ->and((float) $day->extra_hours)->toBe(2.0)
        ->and($day->status)->toBe('present');
});

it('recalcula el día al registrar break_end', function () {
    $employee = makeObsEmployee();
    $day = makeObsDay($employee);

    createObsEvent($day, 'check_in', '08:00:00');
    createObsEvent($day, 'break_start', '12:00:00');
    createObsEvent($day, 'break_end', '13:00:00'); // 60 min pausa → recalcula

    $day->refresh();

    expect($day->break_minutes)->toBe(60);
});

it('recalcula al registrar check_in cuando el día estaba ausente', function () {
    $employee = makeObsEmployee();
    $day = makeObsDay($employee, 'absent');

    createObsEvent($day, 'check_in', '10:00:00'); // Llegada tardía

    $day->refresh();

    expect($day->status)->toBe('present')
        ->and($day->check_in_time)->toBe('10:00:00');
});

it('NO recalcula al registrar check_in si el día ya estaba presente y sigue abierto', function () {
    $employee = makeObsEmployee();
    $day = makeObsDay($employee, 'present', [
        'check_in_time' => '08:00:00',
        'check_out_time' => null,
    ]);

    // Un check_in sobre un día abierto (sin salida previa) no dispara recálculo
    createObsEvent($day, 'check_in', '08:05:00');

    $day->refresh();

    expect($day->check_in_time)->toBe('08:00:00');
});

it('no recalcula al registrar break_start', function () {
    $employee = makeObsEmployee();
    $day = makeObsDay($employee, 'present', [
        'check_in_time' => '08:00:00',
        'break_minutes' => 0,
    ]);

    createObsEvent($day, 'check_in', '08:00:00');
    createObsEvent($day, 'break_start', '12:00:00');

    $day->refresh();

    // break_start solo no actualiza break_minutes (aún no hay break_end)
    expect($day->break_minutes)->toBe(0);
});
