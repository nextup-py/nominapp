<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Schedule;
use App\Models\ScheduleDay;
use App\Services\ScheduleAssignmentService;
use App\Services\VacationService;
use App\Settings\PayrollSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Empleado de prueba; con `$workDays` (ISO) recibe un horario fijo esos días. */
function countEmployee(?array $workDays = null): Employee
{
    static $n = 5100000;
    $n++;

    $company = Company::create(['name' => "EmpVC {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucVC {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Cuenta{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);

    if ($workDays !== null) {
        $schedule = Schedule::create(['name' => "Horario VC {$n}", 'shift_type' => 'diurno']);
        foreach ($workDays as $dow) {
            ScheduleDay::create(['schedule_id' => $schedule->id, 'day_of_week' => $dow, 'is_active' => true, 'start_time' => '08:00', 'end_time' => '17:00']);
        }
        ScheduleAssignmentService::assign($employee, $schedule, Carbon::parse('2025-01-01'));
    }

    return $employee->fresh();
}

function countBySchedule(bool $on): void
{
    $settings = app(PayrollSettings::class);
    $settings->vacation_count_by_schedule = $on;
    $settings->vacation_business_days = [1, 2, 3, 4, 5, 6];
    $settings->save();
}

// Lunes 2026-06-01 a domingo 2026-06-07 (7 días)
it('con el ajuste global cuenta lunes a sábado aunque el horario sea de lunes a viernes', function () {
    countBySchedule(false);
    $employee = countEmployee([1, 2, 3, 4, 5]);

    expect(VacationService::calculateBusinessDays($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07')))->toBe(6);
});

it('según el horario, un empleado de lunes a viernes no gasta el sábado', function () {
    countBySchedule(true);
    $employee = countEmployee([1, 2, 3, 4, 5]);

    expect(VacationService::calculateBusinessDays($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07')))->toBe(5);

    $breakdown = collect(VacationService::breakdown($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07')));
    expect($breakdown->where('reason', 'day_off')->count())->toBe(2);
});

it('según el horario, sin horario ni rotación cae al ajuste global', function () {
    countBySchedule(true);
    $employee = countEmployee(null);

    expect(VacationService::calculateBusinessDays($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07')))->toBe(6);
});

it('los feriados nunca se descuentan, con cualquiera de los dos modos', function (bool $bySchedule) {
    countBySchedule($bySchedule);
    $employee = countEmployee([1, 2, 3, 4, 5]);
    Holiday::create(['date' => '2026-06-03', 'name' => 'Feriado de prueba']);

    expect(VacationService::calculateBusinessDays($employee, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-05')))->toBe(4);
})->with([false, true]);

it('el reintegro es el primer día con turno que no es feriado', function () {
    countBySchedule(true);
    $employee = countEmployee([1, 2, 3, 4, 5]);

    // Vacación hasta el viernes 5/6: sábado y domingo no son del horario, el lunes 8 es feriado → reintegro martes 9
    Holiday::create(['date' => '2026-06-08', 'name' => 'Feriado lunes']);

    expect(VacationService::calculateReturnDate($employee, Carbon::parse('2026-06-05'))->toDateString())->toBe('2026-06-09');

    countBySchedule(false);
    // Con el ajuste global el sábado 6 cuenta como hábil
    expect(VacationService::calculateReturnDate($employee, Carbon::parse('2026-06-05'))->toDateString())->toBe('2026-06-06');
});
