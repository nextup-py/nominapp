<?php

use App\Filament\Resources\AttendanceDayResource\Pages\ListAttendanceDays;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\ScheduleResource\Pages\ListSchedules;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AttendanceInboxService;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function scheduleTestEmployee(string $status = 'active'): Employee
{
    static $n = 7700000;
    $n++;

    $company = Company::create(['name' => "EmpSA {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucSA {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Horario', 'last_name' => "Prueba{$n}", 'ci' => (string) $n, 'email' => "sa{$n}@test.com",
        'branch_id' => $branch->id, 'status' => $status,
    ]);
}

function scheduleTestSchedule(string $name = 'Diurno prueba'): Schedule
{
    return Schedule::create(['name' => $name, 'shift_type' => 'diurno']);
}

function scheduleAsSuperAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

it('detecta empleados sin horario ni rotación vigente', function () {
    $without = scheduleTestEmployee();
    $assigned = scheduleTestEmployee();
    $expired = scheduleTestEmployee();
    $legacy = scheduleTestEmployee();
    $schedule = scheduleTestSchedule();

    $assigned->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => Carbon::today()->subMonth(), 'valid_until' => null]);
    $expired->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => Carbon::today()->subMonths(2), 'valid_until' => Carbon::today()->subMonth()]);
    $legacy->update(['schedule_id' => $schedule->id]);

    $ids = Employee::query()->withoutSchedule()->pluck('id')->all();

    expect($ids)->toContain($without->id, $expired->id)
        ->and($ids)->not->toContain($assigned->id, $legacy->id)
        ->and(Employee::query()->withoutSchedule(Carbon::today()->subMonths(3))->pluck('id')->all())->toContain($assigned->id);
});

it('asigna un horario desde el listado de empleados con la vigencia elegida', function () {
    scheduleAsSuperAdmin();
    $employee = scheduleTestEmployee();
    $schedule = scheduleTestSchedule();
    $from = Carbon::today()->addDays(3)->toDateString();

    Livewire::test(ListEmployees::class)
        ->callTableAction('assign_schedule', $employee, data: ['schedule_id' => $schedule->id, 'valid_from' => $from, 'notes' => 'Cambio de turno'])
        ->assertHasNoTableActionErrors();

    $assignment = $employee->scheduleAssignments()->first();
    expect($assignment->schedule_id)->toBe($schedule->id)
        ->and($assignment->valid_from->toDateString())->toBe($from)
        ->and($assignment->valid_until)->toBeNull()
        ->and($assignment->notes)->toBe('Cambio de turno');
});

it('programa un cambio futuro sin tocar el horario actual hasta ese día', function () {
    scheduleAsSuperAdmin();
    $employee = scheduleTestEmployee();
    $current = scheduleTestSchedule('Actual');
    $next = scheduleTestSchedule('Nuevo');
    $employee->scheduleAssignments()->create(['schedule_id' => $current->id, 'valid_from' => Carbon::today()->subMonth(), 'valid_until' => null]);
    $from = Carbon::today()->addDays(7);

    Livewire::test(ListEmployees::class)
        ->callTableAction('assign_schedule', $employee, data: ['schedule_id' => $next->id, 'valid_from' => $from->toDateString()]);

    expect($employee->getScheduleForDate(Carbon::today())->id)->toBe($current->id)
        ->and($employee->getScheduleForDate($from)->id)->toBe($next->id)
        ->and($employee->scheduleAssignments()->where('schedule_id', $current->id)->value('valid_until')->toDateString())->toBe($from->copy()->subDay()->toDateString());
});

it('asigna en bloque a los seleccionados y omite inactivos o con ese mismo horario', function () {
    scheduleAsSuperAdmin();
    $a = scheduleTestEmployee();
    $b = scheduleTestEmployee();
    $inactive = scheduleTestEmployee('inactive');
    $already = scheduleTestEmployee();
    $schedule = scheduleTestSchedule();
    $already->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => Carbon::today()->subMonth(), 'valid_until' => null]);

    Livewire::test(ListEmployees::class)
        ->set('activeTab', 'all')
        ->callTableBulkAction('assign_schedule_bulk', [$a, $b, $inactive, $already], data: ['schedule_id' => $schedule->id, 'valid_from' => Carbon::today()->toDateString()])
        ->assertHasNoTableBulkActionErrors();

    expect($a->scheduleAssignments()->count())->toBe(1)
        ->and($b->scheduleAssignments()->count())->toBe(1)
        ->and($inactive->scheduleAssignments()->count())->toBe(0)
        ->and($already->scheduleAssignments()->count())->toBe(1);
});

it('no ofrece asignar horarios sin el permiso de horarios', function () {
    $employee = scheduleTestEmployee();
    $role = Role::firstOrCreate(['name' => 'Solo ver empleados', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any_employee', 'guard_name' => 'web']));
    $this->actingAs(User::factory()->create()->assignRole($role));

    Livewire::test(ListEmployees::class)->assertTableActionHidden('assign_schedule', $employee);
});

it('filtra los empleados sin horario en el listado', function () {
    scheduleAsSuperAdmin();
    $without = scheduleTestEmployee();
    $with = scheduleTestEmployee();
    $with->scheduleAssignments()->create(['schedule_id' => scheduleTestSchedule()->id, 'valid_from' => Carbon::today()->subMonth(), 'valid_until' => null]);

    Livewire::test(ListEmployees::class)
        ->filterTable('without_schedule', true)
        ->assertCanSeeTableRecords([$without])
        ->assertCanNotSeeTableRecords([$with]);
});

it('la bandeja Por resolver cuenta los empleados activos sin horario', function () {
    scheduleAsSuperAdmin();
    scheduleTestEmployee();
    scheduleTestEmployee('inactive');

    $section = collect(AttendanceInboxService::sections())->firstWhere('key', 'no_schedule');

    expect($section['count'])->toBe(1);
});

it('avisa en Asistencias cuando hay empleados sin horario', function () {
    scheduleAsSuperAdmin();

    Livewire::test(ListAttendanceDays::class)->assertDontSee('no tienen horario asignado')->assertDontSee('no tiene horario asignado');

    scheduleTestEmployee();

    Livewire::test(ListAttendanceDays::class)->assertSee('no tiene horario asignado');
});

it('asignar desde el horario respeta la fecha de inicio elegida', function () {
    scheduleAsSuperAdmin();
    $employee = scheduleTestEmployee();
    $schedule = scheduleTestSchedule();
    $from = Carbon::today()->addDays(2)->toDateString();

    Livewire::test(ListSchedules::class)
        ->callTableAction('assignToEmployees', $schedule, data: ['employee_ids' => [$employee->id], 'valid_from' => $from]);

    expect($employee->scheduleAssignments()->first()->valid_from->toDateString())->toBe($from);
});

/** Jornada registrada hace `$daysAgo` días con entrada 09:30 sin horario (sin expected_check_in). */
function pastDayFor(Employee $employee, int $daysAgo, array $attributes = []): AttendanceDay
{
    $date = Carbon::today()->subDays($daysAgo);
    $day = AttendanceDay::create(array_merge([
        'employee_id' => $employee->id, 'date' => $date->toDateString(), 'status' => 'present',
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false, 'on_vacation' => false, 'justified_absence' => false,
    ], $attributes));

    AttendanceEvent::create(['attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => 'check_in', 'recorded_at' => $date->copy()->setTime(9, 30)]);
    AttendanceEvent::create(['attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => 'check_out', 'recorded_at' => $date->copy()->setTime(17, 0)]);

    return $day->fresh();
}

/** Horario de lunes a domingo 09:00-17:00 para que cualquier día de prueba sea laboral. */
function everyDaySchedule(): Schedule
{
    $schedule = scheduleTestSchedule('Todos los dias');
    foreach (range(1, 7) as $dow) {
        $schedule->days()->create(['day_of_week' => $dow, 'is_active' => true, 'start_time' => '09:00', 'end_time' => '17:00']);
    }

    return $schedule;
}

it('recalcula las jornadas pasadas al asignar con la opción activada y protege las decididas por RR.HH.', function () {
    scheduleAsSuperAdmin();
    $employee = scheduleTestEmployee();
    $schedule = everyDaySchedule();
    $plain = pastDayFor($employee, 3);
    $approved = pastDayFor($employee, 4, ['overtime_approved' => true]);
    $manual = pastDayFor($employee, 5, ['manual_adjustment' => true]);

    Livewire::test(ListEmployees::class)
        ->callTableAction('assign_schedule', $employee, data: [
            'schedule_id' => $schedule->id, 'valid_from' => Carbon::today()->subDays(10)->toDateString(), 'recalculate' => true,
        ])
        ->assertHasNoTableActionErrors();

    expect($plain->fresh()->expected_check_in)->not->toBeNull()
        ->and((float) $plain->fresh()->expected_hours)->toBe(8.0)
        ->and($approved->fresh()->expected_check_in)->toBeNull()
        ->and($manual->fresh()->expected_check_in)->toBeNull();
});

it('no recalcula si la opción está desactivada', function () {
    scheduleAsSuperAdmin();
    $employee = scheduleTestEmployee();
    $schedule = everyDaySchedule();
    $day = pastDayFor($employee, 3);

    Livewire::test(ListEmployees::class)
        ->callTableAction('assign_schedule', $employee, data: [
            'schedule_id' => $schedule->id, 'valid_from' => Carbon::today()->subDays(10)->toDateString(), 'recalculate' => false,
        ]);

    expect($day->fresh()->expected_check_in)->toBeNull();
});

it('no recalcula jornadas de períodos con nómina generada', function () {
    $employee = scheduleTestEmployee();
    $schedule = everyDaySchedule();
    $locked = pastDayFor($employee, 3);
    $free = pastDayFor($employee, 20);
    $employee->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => Carbon::today()->subDays(30), 'valid_until' => null]);

    $period = PayrollPeriod::query()->create([
        'company_id' => $employee->branch->company_id, 'start_date' => Carbon::today()->subDays(5)->toDateString(),
        'end_date' => Carbon::today()->toDateString(), 'status' => 'draft', 'period_type' => 'mensual', 'name' => 'Periodo prueba',
    ]);
    DB::table('payrolls')->insert(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'base_salary' => 0, 'gross_salary' => 0, 'net_salary' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $result = ScheduleAssignmentService::recalculateDays($employee, Carbon::today()->subDays(25));

    expect($result['payroll_locked'])->toBe(1)
        ->and($result['recalculated'])->toBe(1)
        ->and($locked->fresh()->expected_check_in)->toBeNull()
        ->and($free->fresh()->expected_check_in)->not->toBeNull();
});
