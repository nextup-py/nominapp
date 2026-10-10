<?php

use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use App\Notifications\EmployeesWithoutScheduleNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function noShiftEmployee(): Employee
{
    static $n = 8800000;
    $n++;

    $company = Company::create(['name' => "EmpNS {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucNS {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Sin', 'last_name' => "Horario{$n}", 'ci' => (string) $n, 'email' => "ns{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
}

function noShiftDay(Employee $employee, array $attributes = []): AttendanceDay
{
    return AttendanceDay::create(array_merge([
        'employee_id' => $employee->id,
        'date' => Carbon::today()->subDays(2)->toDateString(),
        'status' => 'present',
        'is_calculated' => true,
        'check_in_time' => '08:50:00',
        'check_out_time' => '15:00:00',
        'expected_check_in' => null,
        'expected_check_out' => null,
    ], $attributes));
}

it('marca como sin horario esperado la jornada calculada con marcaciones y sin turno', function () {
    $employee = noShiftEmployee();
    $without = noShiftDay($employee);
    $withShift = noShiftDay($employee, ['date' => Carbon::today()->subDays(3)->toDateString(), 'expected_check_in' => '09:00:00', 'expected_check_out' => '16:00:00']);
    $notCalculated = noShiftDay($employee, ['date' => Carbon::today()->subDays(4)->toDateString(), 'is_calculated' => false]);
    $noMarks = noShiftDay($employee, ['date' => Carbon::today()->subDays(5)->toDateString(), 'check_in_time' => null, 'check_out_time' => null]);

    $ids = AttendanceDay::query()->noExpectedShift()->pluck('id')->all();

    expect($ids)->toBe([$without->id])
        ->and($withShift->attentionReasons())->not->toContain('Sin horario ese día')
        ->and($without->attentionReasons())->toContain('Sin horario ese día')
        ->and($notCalculated->attentionReasons())->not->toContain('Sin horario ese día')
        ->and($noMarks->attentionReasons())->not->toContain('Sin horario ese día');
});

it('incluye las jornadas sin horario esperado en "Requieren atención" dentro de la ventana de 30 días', function () {
    $employee = noShiftEmployee();
    $recent = noShiftDay($employee);
    $old = noShiftDay($employee, ['date' => Carbon::today()->subDays(AttendanceDay::ATTENTION_WINDOW_DAYS + 5)->toDateString()]);

    $ids = AttendanceDay::query()->needsAttention()->pluck('id')->all();

    expect($ids)->toContain($recent->id)->not->toContain($old->id);
});

it('avisa una sola vez por día a quien puede asignar horarios cuando hay empleados sin horario', function () {
    $permission = Permission::firstOrCreate(['name' => 'update_schedule', 'guard_name' => 'web']);
    $hr = User::factory()->create();
    $hr->givePermissionTo($permission);
    $other = User::factory()->create();

    noShiftEmployee();

    Artisan::call('attendance:notify-no-schedule');
    Artisan::call('attendance:notify-no-schedule');

    expect($hr->unreadNotifications()->where('type', EmployeesWithoutScheduleNotification::class)->count())->toBe(1)
        ->and($other->unreadNotifications()->count())->toBe(0);
});

it('no avisa si todos los empleados activos tienen horario', function () {
    $permission = Permission::firstOrCreate(['name' => 'update_schedule', 'guard_name' => 'web']);
    $hr = User::factory()->create();
    $hr->givePermissionTo($permission);

    $employee = noShiftEmployee();
    $schedule = Schedule::create(['name' => 'Con horario', 'shift_type' => 'diurno']);
    $employee->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => Carbon::today()->subMonth(), 'valid_until' => null]);

    Artisan::call('attendance:notify-no-schedule');

    expect($hr->unreadNotifications()->count())->toBe(0);
});
