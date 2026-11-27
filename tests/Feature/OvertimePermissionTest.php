<?php

use App\Filament\Resources\AttendanceDayResource;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\AttendanceCalculator;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Empleado con empresa, sucursal, cargo y contrato vigente propios. */
function makeOvertimeEmployee(): array
{
    static $n = 7100000;
    $n++;

    $company = Company::create(['name' => "Empresa {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo {$n}", 'department_id' => $department->id]);
    $employee = Employee::create([
        'first_name' => 'Hex', 'last_name' => 'Test', 'ci' => (string) $n,
        'email' => "hex{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active',
    ]);
    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual', 'salary' => 2_550_000, 'position_id' => $position->id,
        'department_id' => $department->id, 'status' => 'active',
    ]);

    return [$employee->fresh(), $position, $branch, $company];
}

/** Jornada con 2 horas extra sobre un horario de 9 horas. */
function makeOvertimeDay(Employee $employee, string $date = '2026-03-16'): AttendanceDay
{
    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => $date, 'status' => 'absent',
        'expected_check_in' => '08:00:00', 'expected_check_out' => '17:00:00', 'expected_hours' => 9.0,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false,
        'on_vacation' => false, 'justified_absence' => false,
    ]);
    foreach ([['check_in', '08:00:00'], ['check_out', '19:00:00']] as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id, 'employee_id' => $employee->id,
            'event_type' => $type, 'recorded_at' => Carbon::parse("{$date} {$time}"),
        ]);
    }

    return $day;
}

it('resuelve el permiso en orden empleado, cargo, sucursal, empresa y general', function () {
    [$employee, $position, $branch, $company] = makeOvertimeEmployee();
    $settings = app(GeneralSettings::class);
    $settings->attendance_overtime_allowed_default = true;
    $settings->save();

    expect($employee->overtimeAllowedSource())->toBe(['allowed' => true, 'source' => 'general']);

    $company->update(['overtime_allowed' => false]);
    expect($employee->fresh()->overtimeAllowedSource())->toBe(['allowed' => false, 'source' => 'company']);

    $branch->update(['overtime_allowed' => true]);
    expect($employee->fresh()->overtimeAllowedSource())->toBe(['allowed' => true, 'source' => 'branch']);

    $position->update(['overtime_allowed' => false]);
    expect($employee->fresh()->overtimeAllowedSource())->toBe(['allowed' => false, 'source' => 'position']);

    $employee->update(['overtime_allowed' => true]);
    $fresh = $employee->fresh();
    expect($fresh->overtimeAllowedSource())->toBe(['allowed' => true, 'source' => 'employee'])
        ->and($fresh->overtimeAllowedLabel())->toBe('Sí');

    $employee->update(['overtime_allowed' => null]);
    expect($employee->fresh()->overtimeAllowedLabel())->toBe('No (del cargo)');
});

it('registra las horas extra de quien no está autorizado pero las marca y las saca de pendientes', function () {
    [$employee, $position] = makeOvertimeEmployee();
    $position->update(['overtime_allowed' => false]);
    $day = makeOvertimeDay($employee);

    AttendanceCalculator::apply($day);
    $day = $day->fresh();

    expect((float) $day->extra_hours)->toBe(2.0)
        ->and($day->overtime_unauthorized)->toBeTrue()
        ->and(AttendanceDay::query()->pendingOvertime()->whereKey($day->id)->exists())->toBeFalse()
        ->and($day->attentionReasons())->not->toContain('Extras por aprobar');
});

it('deja las horas extra de quien sí está autorizado como pendientes de aprobación', function () {
    [$employee] = makeOvertimeEmployee();
    $day = makeOvertimeDay($employee);

    AttendanceCalculator::apply($day);
    $day = $day->fresh();

    expect($day->overtime_unauthorized)->toBeFalse()
        ->and(AttendanceDay::query()->pendingOvertime()->whereKey($day->id)->exists())->toBeTrue()
        ->and($day->attentionReasons())->toContain('Extras por aprobar');
});

it('no incluye las horas extra no autorizadas en la aprobación por rango', function () {
    [$blocked, $position] = makeOvertimeEmployee();
    $position->update(['overtime_allowed' => false]);
    [$allowed] = makeOvertimeEmployee();

    foreach ([$blocked, $allowed] as $employee) {
        AttendanceCalculator::apply(makeOvertimeDay($employee));
    }

    ['approvable' => $approvable, 'skipped' => $skipped] = AttendanceDayResource::overtimeRangeCandidates('2026-03-16', '2026-03-16');

    expect($approvable)->toHaveCount(1)
        ->and($approvable->first()->employee_id)->toBe($allowed->id)
        ->and($skipped['unauthorized'])->toBe(1);
});

it('cambiar el permiso no altera lo ya calculado hasta recalcular', function () {
    [$employee, $position] = makeOvertimeEmployee();
    $day = makeOvertimeDay($employee);
    AttendanceCalculator::apply($day);

    $position->update(['overtime_allowed' => false]);

    expect($day->fresh()->overtime_unauthorized)->toBeFalse();
});
