<?php

use App\Filament\Pages\EmployeeMonthSheet;
use App\Filament\Resources\AttendanceDayResource\Pages\ListAttendanceDays;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeMonthSheetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function sheetEmployee(): Employee
{
    static $n = 4900000;
    $n++;

    $company = Company::create(['name' => "EmpFM {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucFM {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Ficha', 'last_name' => 'Mensual', 'ci' => (string) $n, 'email' => "fm{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
}

/** Jornada con los campos calculados fijados a mano (los observers los recalculan al crear eventos). */
function sheetDay(Employee $employee, Carbon $date, array $attributes = [], array $events = []): AttendanceDay
{
    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => $date->toDateString(), 'status' => 'present',
        'expected_check_in' => '07:00:00', 'expected_check_out' => '15:00:00', 'expected_hours' => 8.0,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false,
        'on_vacation' => false, 'justified_absence' => false,
    ]);

    foreach ($events as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => $type,
            'recorded_at' => Carbon::parse($date->toDateString().' '.$time),
        ]);
    }

    $day->forceFill($attributes)->saveQuietly();

    return $day->fresh();
}

function sheetAsSuperAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

function sheetDays(array $sheet): array
{
    return collect($sheet['days'])->keyBy(fn ($day) => $day['date']->day)->all();
}

it('arma una celda por día del mes con el estado de cada jornada', function () {
    $employee = sheetEmployee();
    $month = Carbon::today()->subMonths(2)->startOfMonth();

    sheetDay($employee, $month->copy()->day(2), ['check_in_time' => '07:02:00', 'check_out_time' => '15:10:00', 'total_hours' => 8.1], [['check_in', '07:02:00'], ['check_out', '15:10:00']]);
    sheetDay($employee, $month->copy()->day(3), ['status' => 'absent']);
    sheetDay($employee, $month->copy()->day(4), ['status' => 'absent', 'justified_absence' => true]);
    sheetDay($employee, $month->copy()->day(5), ['status' => 'on_leave']);
    sheetDay($employee, $month->copy()->day(6), ['status' => 'on_leave', 'on_vacation' => true]);
    sheetDay($employee, $month->copy()->day(7), ['status' => 'holiday']);
    sheetDay($employee, $month->copy()->day(8), ['status' => 'weekend']);

    $sheet = EmployeeMonthSheetService::build($employee, $month);
    $days = sheetDays($sheet);

    expect($sheet['days'])->toHaveCount($month->daysInMonth)
        ->and($sheet['leading_blanks'])->toBe($month->dayOfWeekIso - 1)
        ->and($days[2])->toMatchArray(['state' => 'present', 'in' => '07:02', 'out' => '15:10', 'hours' => 8.1])
        ->and($days[3]['state'])->toBe('absent')
        ->and($days[4]['state'])->toBe('absent_justified')
        ->and($days[5]['state'])->toBe('leave')
        ->and($days[6]['state'])->toBe('vacation')
        ->and($days[7]['state'])->toBe('holiday')
        ->and($days[8]['state'])->toBe('day_off')
        ->and($days[9]['state'])->toBe('no_record');
});

it('los días futuros quedan vacíos y no cuentan como sin registro', function () {
    $employee = sheetEmployee();
    $sheet = EmployeeMonthSheetService::build($employee, Carbon::today()->addMonth());

    expect(collect($sheet['days'])->pluck('state')->unique()->all())->toBe(['upcoming'])
        ->and($sheet['totals']['no_record_days'])->toBe(0);
});

it('suma los totales del mes', function () {
    $employee = sheetEmployee();
    $month = Carbon::today()->subMonths(2)->startOfMonth();

    sheetDay($employee, $month->copy()->day(2), ['total_hours' => 8.0, 'late_minutes' => 10, 'tardiness_deduction_approved' => true, 'extra_hours' => 1.5, 'overtime_approved' => true]);
    sheetDay($employee, $month->copy()->day(3), ['total_hours' => 9.0, 'late_minutes' => 5, 'extra_hours' => 2.0]);
    sheetDay($employee, $month->copy()->day(4), ['status' => 'absent']);
    sheetDay($employee, $month->copy()->day(5), ['status' => 'absent', 'justified_absence' => true]);

    $totals = EmployeeMonthSheetService::build($employee, $month)['totals'];

    expect($totals)->toMatchArray([
        'present_days' => 2, 'absent_days' => 1, 'absent_justified_days' => 1,
        'hours_worked' => 17.0, 'hours_expected' => 32.0,
        'extra_hours' => 3.5, 'extra_hours_approved' => 1.5,
        'late_days' => 2, 'late_minutes' => 15, 'late_minutes_approved' => 10,
        'pending_tardiness' => 1, 'pending_overtime' => 1,
    ]);
});

it('marca las jornadas sin salida y las alertas pendientes', function () {
    $employee = sheetEmployee();
    $month = Carbon::today()->subMonths(2)->startOfMonth();

    sheetDay($employee, $month->copy()->day(2), [], [['check_in', '07:00:00']]);
    sheetDay($employee, $month->copy()->day(3), ['late_minutes' => 8, 'extra_hours' => 1], [['check_in', '07:08:00'], ['check_out', '16:00:00']]);

    $sheet = EmployeeMonthSheetService::build($employee, $month);
    $days = sheetDays($sheet);

    expect($days[2]['alerts'])->toBe(['Sin salida'])
        ->and($days[3]['alerts'])->toBe(['Tardanza por aprobar', 'Extras por aprobar'])
        ->and($sheet['totals'])->toMatchArray(['pending_missing_check_out' => 1, 'pending_tardiness' => 1, 'pending_overtime' => 1]);
});

it('no mezcla jornadas de otros empleados ni de otros meses', function () {
    $employee = sheetEmployee();
    $other = sheetEmployee();
    $month = Carbon::today()->subMonths(2)->startOfMonth();

    sheetDay($other, $month->copy()->day(2));
    sheetDay($employee, $month->copy()->subMonth()->day(2));

    $sheet = EmployeeMonthSheetService::build($employee, $month);

    expect($sheet['totals']['present_days'])->toBe(0);
});

it('arma el mes sin una consulta por día', function () {
    $employee = sheetEmployee();
    $month = Carbon::today()->subMonths(2)->startOfMonth();
    foreach (range(2, 12) as $day) {
        sheetDay($employee, $month->copy()->day($day), ['late_minutes' => 3], [['check_in', '07:03:00']]);
    }

    DB::enableQueryLog();
    EmployeeMonthSheetService::build($employee, $month);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2);
});

it('la página muestra el empleado y navega entre meses', function () {
    sheetAsSuperAdmin();
    $employee = sheetEmployee();
    $month = Carbon::today()->subMonths(2)->startOfMonth();
    sheetDay($employee, $month->copy()->day(2));

    Livewire::withQueryParams(['employee' => $employee->id, 'month' => $month->format('Y-m')])
        ->test(EmployeeMonthSheet::class)
        ->assertSuccessful()
        ->assertSee('Ficha Mensual')
        ->assertSee('Presente')
        ->assertSee($month->copy()->subMonth()->format('Y-m'))
        ->assertSee($month->copy()->addMonth()->format('Y-m'));
});

it('un mes inválido cae en el mes en curso y un empleado inexistente da 404', function () {
    sheetAsSuperAdmin();
    $employee = sheetEmployee();

    Livewire::withQueryParams(['employee' => $employee->id, 'month' => 'abc'])
        ->test(EmployeeMonthSheet::class)
        ->assertSet('month', Carbon::today()->format('Y-m'));

    Livewire::withQueryParams(['employee' => 999999, 'month' => '2026-01'])
        ->test(EmployeeMonthSheet::class)
        ->assertNotFound();
});

it('sin permiso de ver asistencias no hay acceso a la ficha', function () {
    test()->actingAs(User::factory()->create());

    expect(EmployeeMonthSheet::canAccess())->toBeFalse();
});

it('cada fila del listado de Asistencias abre la ficha del mes de esa jornada', function () {
    Permission::firstOrCreate(['name' => 'view_any_attendance_day', 'guard_name' => 'web']);
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('view_any_attendance_day')));
    $employee = sheetEmployee();
    $day = sheetDay($employee, Carbon::today()->subDay());

    Livewire::test(ListAttendanceDays::class)
        ->set('activeTab', 'all')
        ->assertTableActionVisible('view_month', $day)
        ->assertTableActionHasUrl('view_month', EmployeeMonthSheet::getUrl(['employee' => $employee->id, 'month' => $day->date->format('Y-m')]), $day);
});
