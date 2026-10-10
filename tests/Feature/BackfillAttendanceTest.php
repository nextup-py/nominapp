<?php

use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ShiftOverride;
use App\Models\ShiftTemplate;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function backfillEmployee(): Employee
{
    static $n = 9300000;
    $n++;

    $company = Company::create(['name' => "EmpBF {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucBF {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Backfill', 'last_name' => "Prueba{$n}", 'ci' => (string) $n, 'email' => "bf{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
}

function plannedShiftOn(Employee $employee, Carbon $date): void
{
    $shift = ShiftTemplate::firstOrCreate(['company_id' => $employee->branch->company_id, 'name' => 'Mañana BF'], [
        'shift_type' => 'diurno',
        'is_day_off' => false, 'start_time' => '07:00', 'end_time' => '15:00', 'break_minutes' => 0, 'is_active' => true,
    ]);

    ShiftOverride::create(['employee_id' => $employee->id, 'override_date' => $date->toDateString(), 'shift_id' => $shift->id, 'reason_type' => 'cambio_turno']);
}

beforeEach(function () {
    app(GeneralSettings::class)->absence_threshold_minutes = 15;
    app(GeneralSettings::class)->save();
});

it('con --dry-run informa las ausencias y jornadas pendientes sin guardar nada', function () {
    $employee = backfillEmployee();
    $date = Carbon::today()->subDays(3);
    plannedShiftOn($employee, $date);

    $this->artisan('attendance:backfill', ['--from' => $date->toDateString(), '--dry-run' => true])
        ->expectsOutputToContain('Ausencias que se crearían')
        ->assertSuccessful();

    expect(AttendanceDay::where('employee_id', $employee->id)->count())->toBe(0)
        ->and(Absence::count())->toBe(0);
});

it('crea la ausencia pendiente de un día planificado sin marcación', function () {
    $employee = backfillEmployee();
    $date = Carbon::today()->subDays(3);
    plannedShiftOn($employee, $date);

    $this->artisan('attendance:backfill', ['--from' => $date->toDateString()])->assertSuccessful();

    $day = AttendanceDay::where('employee_id', $employee->id)->where('date', $date->toDateString())->first();
    expect($day)->not->toBeNull()
        ->and($day->status)->toBe('absent')
        ->and(Absence::where('employee_id', $employee->id)->where('status', 'pending')->count())->toBe(1);
});

it('no duplica ausencias al correrlo dos veces ni crea ausencias de hoy', function () {
    $employee = backfillEmployee();
    $past = Carbon::today()->subDays(2);
    plannedShiftOn($employee, $past);
    plannedShiftOn($employee, Carbon::today());

    $this->artisan('attendance:backfill', ['--from' => $past->toDateString(), '--to' => Carbon::today()->toDateString()])->assertSuccessful();
    $this->artisan('attendance:backfill', ['--from' => $past->toDateString()])->assertSuccessful();

    expect(AttendanceDay::where('employee_id', $employee->id)->count())->toBe(1)
        ->and(Absence::where('employee_id', $employee->id)->count())->toBe(1);
});

it('calcula las jornadas sin calcular del rango y no toca las ya calculadas', function () {
    $employee = backfillEmployee();
    $date = Carbon::today()->subDays(4);
    plannedShiftOn($employee, $date);

    $uncalculated = AttendanceDay::create(['employee_id' => $employee->id, 'date' => $date->toDateString(), 'status' => 'present', 'is_calculated' => false, 'is_weekend' => false, 'is_holiday' => false, 'on_vacation' => false, 'justified_absence' => false]);
    AttendanceEvent::create(['attendance_day_id' => $uncalculated->id, 'employee_id' => $employee->id, 'event_type' => 'check_in', 'recorded_at' => $date->copy()->setTime(7, 5)]);
    AttendanceEvent::create(['attendance_day_id' => $uncalculated->id, 'employee_id' => $employee->id, 'event_type' => 'check_out', 'recorded_at' => $date->copy()->setTime(15, 0)]);

    $other = backfillEmployee();
    $calculatedDate = Carbon::today()->subDays(5);
    $done = AttendanceDay::create(['employee_id' => $other->id, 'date' => $calculatedDate->toDateString(), 'status' => 'present', 'is_calculated' => true, 'extra_hours' => 1.5, 'is_weekend' => false, 'is_holiday' => false, 'on_vacation' => false, 'justified_absence' => false]);

    $this->artisan('attendance:backfill', ['--from' => $calculatedDate->toDateString()])->assertSuccessful();

    expect($uncalculated->fresh()->is_calculated)->toBeTrue()
        ->and($uncalculated->fresh()->expected_check_in)->not->toBeNull()
        ->and((float) $done->fresh()->extra_hours)->toBe(1.5);
});

it('rechaza un rango inválido o sin --from', function () {
    $this->artisan('attendance:backfill')->assertFailed();
    $this->artisan('attendance:backfill', ['--from' => Carbon::today()->addDays(2)->toDateString()])->assertFailed();
});
