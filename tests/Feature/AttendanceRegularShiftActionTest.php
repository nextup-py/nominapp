<?php

use App\Filament\Resources\AttendanceDayResource;
use App\Filament\Resources\AttendanceDayResource\Pages\EditAttendanceDay;
use App\Filament\Resources\AttendanceDayResource\Pages\ListAttendanceDays;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\Schedule;
use App\Models\ScheduleDay;
use App\Models\User;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function seedRegularShiftSettings(): void
{
    foreach ([
        'overtime_max_daily_hours' => 3,
        'overtime_multiplier_nocturno_holiday' => 2.6,
        'min_salary_monthly' => 2_550_328,
        'min_salary_daily_jornal' => 87_950,
        'family_bonus_percentage' => 5.0,
    ] as $name => $value) {
        DB::table('settings')->updateOrInsert(['group' => 'payroll', 'name' => $name], ['payload' => json_encode($value)]);
    }
}

/**
 * Día con doble turno (07-15 y 15:30-19:30) y 8 h esperadas: 4 h sobre el horario.
 */
function makeDoubleShiftDay(): AttendanceDay
{
    seedRegularShiftSettings();

    $company = Company::create(['name' => 'Empresa DT', 'ruc' => '4400000-1', 'employer_number' => 4400000]);
    $branch = Branch::create(['name' => 'Sucursal DT', 'company_id' => $company->id]);
    $employee = Employee::create([
        'first_name' => 'Doble', 'last_name' => 'Turno', 'ci' => '4400001', 'email' => 'dt@test.com',
        'branch_id' => $branch->id, 'status' => 'active',
    ]);

    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => '2026-03-16', 'status' => 'absent',
        'expected_check_in' => '07:00:00', 'expected_check_out' => '15:00:00', 'expected_hours' => 8.0,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false,
        'on_vacation' => false, 'justified_absence' => false,
    ]);

    foreach ([['check_in', '07:00:00'], ['check_out', '15:00:00'], ['check_in', '15:30:00'], ['check_out', '19:30:00']] as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => $type,
            'recorded_at' => Carbon::parse('2026-03-16 '.$time),
        ]);
    }

    return $day->fresh();
}

function actingAsSuperAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

it('detecta que el día tiene más de un turno', function () {
    $day = makeDoubleShiftDay();

    expect($day->hasMultipleShifts())->toBeTrue()
        ->and((float) $day->extra_hours)->toBe(4.0);
});

it('RR.HH. acepta el doble turno como jornada normal desde la tabla y se puede volver a revisar', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();

    Livewire::test(ListAttendanceDays::class)
        ->set('activeTab', 'all')
        ->callTableAction('regular_shift', $day)
        ->assertHasNoTableActionErrors();

    $day->refresh();
    expect($day->second_shift_regular)->toBeTrue()
        ->and((float) $day->extra_hours)->toBe(0.0)
        ->and($day->overtime_approved)->toBeFalse();

    // Resuelto, el día sale de "Requieren atención": para deshacerlo se busca en "Todos".
    Livewire::test(ListAttendanceDays::class)
        ->set('activeTab', 'all')
        ->callTableAction('regular_shift', $day);

    $day->refresh();
    expect($day->second_shift_regular)->toBeFalse()
        ->and((float) $day->extra_hours)->toBe(4.0);
});

it('aceptar el doble turno revoca una aprobación de horas extra previa', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();
    $day->update(['overtime_approved' => true]);

    // Con las extras ya aprobadas el día no está en "Requieren atención": se abre "Todos".
    Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all')->callTableAction('regular_shift', $day);

    expect($day->fresh()->overtime_approved)->toBeFalse();
});

it('no ofrece la decisión en un día de un solo turno con horas extra', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();
    $day->events()->whereIn('recorded_at', ['2026-03-16 15:30:00', '2026-03-16 19:30:00'])->delete();
    $day->update(['extra_hours' => 1.5]);

    Livewire::test(ListAttendanceDays::class)
        ->assertTableActionHidden('regular_shift', $day->fresh());

    expect(AttendanceDayResource::getRegularShiftTableAction()->getName())->toBe('regular_shift');
});

it('excluye una jornada del cálculo conservando las marcaciones y la saca de los pendientes', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();
    expect((float) $day->extra_hours)->toBe(4.0);

    Livewire::test(ListAttendanceDays::class)
        ->set('activeTab', 'all')
        ->callTableAction('exclude_from_calculation', $day, data: ['reason' => 'Prueba de instalación'])
        ->assertHasNoTableActionErrors();

    $day->refresh();
    expect($day->excluded_from_calculation)->toBeTrue()
        ->and($day->exclusion_reason)->toBe('Prueba de instalación')
        ->and($day->status)->toBe('on_leave')
        ->and((int) $day->late_minutes)->toBe(0)
        ->and((float) $day->extra_hours)->toBe(0.0)
        ->and($day->events()->count())->toBe(4)
        ->and(AttendanceDay::query()->needsAttention()->whereKey($day->id)->exists())->toBeFalse();
});

it('vuelve a incluir la jornada en el cálculo', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();

    Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all')
        ->callTableAction('exclude_from_calculation', $day, data: ['reason' => 'Prueba']);
    expect($day->fresh()->excluded_from_calculation)->toBeTrue();

    Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$day])
        ->callTableAction('exclude_from_calculation', $day->fresh())
        ->assertHasNoTableActionErrors();

    $day->refresh();
    expect($day->excluded_from_calculation)->toBeFalse()
        ->and($day->exclusion_reason)->toBeNull()
        ->and($day->status)->toBe('present')
        ->and($day->check_in_time)->not->toBeNull()
        ->and((float) $day->total_hours)->toBeGreaterThan(0.0);
});

it('exige el motivo al excluir', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();

    Livewire::test(ListAttendanceDays::class)
        ->set('activeTab', 'all')
        ->callTableAction('exclude_from_calculation', $day, data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);

    expect($day->fresh()->excluded_from_calculation)->toBeFalse();
});

// ─── Permisos y nómina generada ─────────────────────────────────────────────

function viewOnlyAttendanceUser(): User
{
    foreach (['view_any_attendance_day', 'view_attendance_day'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    return tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo(['view_any_attendance_day', 'view_attendance_day']));
}

function lockPayrollFor(AttendanceDay $day): void
{
    $period = PayrollPeriod::create([
        'name' => 'Marzo 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'frequency' => 'monthly', 'status' => 'processing',
    ]);
    Payroll::create([
        'employee_id' => $day->employee_id, 'payroll_period_id' => $period->id, 'status' => 'draft',
        'base_salary' => 2_550_000, 'gross_salary' => 2_550_000, 'net_salary' => 2_550_000,
        'total_deductions' => 0, 'total_perceptions' => 0, 'payment_method' => 'transfer',
    ]);
}

it('un usuario de solo lectura no puede aprobar, ajustar ni recalcular asistencia', function () {
    $day = makeDoubleShiftDay();
    test()->actingAs(viewOnlyAttendanceUser());

    expect(AttendanceDayResource::canManageDays())->toBeFalse();

    $table = Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all');

    foreach (['approve_overtime', 'regular_shift', 'adjust_extra_hours', 'calculate'] as $action) {
        expect(fn () => $table->callTableAction($action, $day))->toThrow(AssertionFailedError::class);
    }

    expect(fn () => $table->callTableBulkAction('approve_overtime', [$day]))->toThrow(AssertionFailedError::class);

    $day->refresh();
    expect($day->overtime_approved)->toBeFalse()
        ->and($day->manual_adjustment)->toBeFalse()
        ->and($day->second_shift_regular)->toBeFalse();
});

it('con permiso de edición puede aprobar horas extra', function () {
    $day = makeDoubleShiftDay();
    Permission::firstOrCreate(['name' => 'update_attendance_day', 'guard_name' => 'web']);
    $user = viewOnlyAttendanceUser();
    $user->givePermissionTo('update_attendance_day');
    test()->actingAs($user);

    Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all')->callTableAction('approve_overtime', $day);

    expect($day->fresh()->overtime_approved)->toBeTrue();
});

it('no modifica la asistencia de una fecha con nómina generada', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();
    lockPayrollFor($day);

    $table = Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all');
    $table->callTableAction('approve_overtime', $day);
    $table->callTableAction('regular_shift', $day);
    $table->callTableAction('adjust_extra_hours', $day, data: ['extra_hours_diurnas' => 1, 'extra_hours_nocturnas' => 0]);
    $table->callTableBulkAction('approve_overtime', [$day]);

    $day->refresh();
    expect($day->overtime_approved)->toBeFalse()
        ->and($day->second_shift_regular)->toBeFalse()
        ->and($day->manual_adjustment)->toBeFalse()
        ->and((float) $day->extra_hours)->toBe(4.0);
});

it('la edición y la eliminación de una jornada con nómina generada quedan bloqueadas', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();
    lockPayrollFor($day);

    Livewire::test(EditAttendanceDay::class, ['record' => $day->id])
        ->fillForm(['notes' => 'cambio'])
        ->call('save');

    Livewire::test(EditAttendanceDay::class, ['record' => $day->id])
        ->callAction(DeleteAction::class);

    $day = AttendanceDay::find($day->id);
    expect($day)->not->toBeNull()
        ->and($day->notes)->not->toBe('cambio');
});

it('Aprobar HE del período aprueba lo seguro, deja auditoría y omite tope legal, doble turno y nómina', function () {
    config(['audit.console' => true]);
    actingAsSuperAdmin();
    $doubleShift = makeDoubleShiftDay();
    $make = fn (string $date, array $extra = []) => AttendanceDay::create([
        'employee_id' => $doubleShift->employee_id, 'date' => $date, 'status' => 'present',
        'expected_hours' => 8, 'extra_hours' => 2, 'is_calculated' => true,
        'on_vacation' => false, 'justified_absence' => false, 'overtime_approved' => false,
        ...$extra,
    ]);
    $safe = $make('2026-03-17');
    $overLimit = $make('2026-03-18', ['overtime_limit_exceeded' => true]);
    $twoShifts = $make('2026-03-26');
    foreach ([['check_in', '07:00'], ['check_out', '12:00'], ['check_in', '13:00'], ['check_out', '18:00']] as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $twoShifts->id, 'employee_id' => $twoShifts->employee_id, 'event_type' => $type,
            'recorded_at' => Carbon::parse("2026-03-26 {$time}"),
        ]);
    }

    Livewire::test(ListAttendanceDays::class)
        ->callAction('approve_overtime_range', data: ['start_date' => '2026-03-16', 'end_date' => '2026-03-26']);

    expect($safe->fresh()->overtime_approved)->toBeTrue()
        ->and($safe->audits()->count())->toBeGreaterThan(0)
        ->and($overLimit->fresh()->overtime_approved)->toBeFalse()
        ->and($doubleShift->fresh()->overtime_approved)->toBeFalse()
        ->and($twoShifts->fresh()->overtime_approved)->toBeFalse();

    ['approvable' => $left, 'skipped' => $skipped] = AttendanceDayResource::overtimeRangeCandidates('2026-03-16', '2026-03-26');
    expect($left)->toHaveCount(0)
        ->and($skipped)->toBe(['limit' => 2, 'double_shift' => 1, 'payroll' => 0]);

    $lockedDay = $make('2026-03-31');
    lockPayrollFor($lockedDay);

    expect(AttendanceDayResource::overtimeRangeCandidates('2026-03-31', '2026-03-31'))
        ->approvable->toHaveCount(0)
        ->skipped->toBe(['limit' => 0, 'double_shift' => 0, 'payroll' => 1]);
});

// ─── Búsqueda, filtros, recálculo con horario y auditoría de estado ──────────

it('el listado busca por nombre completo, por apellido y por cédula', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();

    foreach (['Doble Turno', 'Turno', '4400001'] as $term) {
        Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all')
            ->searchTable($term)->assertCanSeeTableRecords([$day]);
    }

    Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all')
        ->searchTable('Inexistente Persona')->assertCanNotSeeTableRecords([$day]);
});

it('el filtro de fechas incluye los extremos del rango', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();

    Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all')
        ->filterTable('date', ['from' => '2026-03-16', 'to' => '2026-03-16'])->assertCanSeeTableRecords([$day]);

    Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all')
        ->filterTable('date', ['from' => '2026-03-17', 'to' => null])->assertCanNotSeeTableRecords([$day]);
});

/** Asigna al empleado un horario 09:00-17:00 los lunes, vigente desde enero (el día de prueba fue calculado con 07:00-15:00). */
function assignMondayNineToFive(AttendanceDay $day): void
{
    $schedule = Schedule::create(['name' => 'Lunes 9 a 17', 'shift_type' => 'diurno', 'description' => null]);
    ScheduleDay::create([
        'schedule_id' => $schedule->id, 'day_of_week' => 1, 'is_active' => true, 'start_time' => '09:00', 'end_time' => '17:00',
    ]);
    ScheduleAssignmentService::assign($day->employee, $schedule, Carbon::parse('2026-01-01'));
}

it('recalcular conserva las horas esperadas salvo que se pida leer el horario vigente', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();
    assignMondayNineToFive($day);

    $table = Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all');
    $table->callTableAction('calculate', $day, data: ['refresh_schedule' => false]);
    expect($day->fresh()->expected_check_in)->toBe('07:00:00');

    $table->callTableAction('calculate', $day->fresh(), data: ['refresh_schedule' => true]);
    expect($day->fresh()->expected_check_in)->toBe('09:00:00');
});

it('leer el horario vigente respeta las jornadas con aprobaciones o ajustes', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();
    assignMondayNineToFive($day);
    $day->update(['overtime_approved' => true]);

    $table = Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all');
    $table->callTableAction('calculate', $day->fresh(), data: ['refresh_schedule' => true]);
    $table->callTableBulkAction('calculate', [$day->fresh()], data: ['refresh_schedule' => true]);

    expect($day->fresh()->expected_check_in)->toBe('07:00:00')
        ->and($day->fresh()->hasProtectedDecisions())->toBeTrue();
});

it('la acción masiva de calcular puede leer el horario vigente', function () {
    actingAsSuperAdmin();
    $day = makeDoubleShiftDay();
    assignMondayNineToFive($day);

    Livewire::test(ListAttendanceDays::class)->set('activeTab', 'all')
        ->callTableBulkAction('calculate', [$day], data: ['refresh_schedule' => true]);

    expect($day->fresh()->expected_check_in)->toBe('09:00:00');
});

it('un cambio manual de estado queda en el historial de la jornada', function () {
    config(['audit.console' => true]);
    $day = makeDoubleShiftDay();

    $day->update(['status' => 'absent']);

    $audit = $day->audits()->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->new_values)->toHaveKey('status', 'absent');
});
