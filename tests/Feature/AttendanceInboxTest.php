<?php

use App\Filament\Pages\AttendanceInbox;
use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AttendanceMarkFailure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceInboxService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function inboxEmployee(): Employee
{
    $company = Company::create(['name' => 'Empresa IB', 'ruc' => '4600000-1', 'employer_number' => 4600000]);
    $branch = Branch::create(['name' => 'Sucursal IB', 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Bandeja', 'last_name' => 'Pendiente', 'ci' => '4600001', 'email' => 'ib@test.com',
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
}

/** Jornada con los campos calculados fijados a mano (los observers los recalculan al crear eventos). */
function inboxDay(Employee $employee, int $daysAgo, array $computed = [], bool $openShift = false): AttendanceDay
{
    $date = Carbon::today()->subDays($daysAgo);
    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => $date->toDateString(), 'status' => 'present',
        'expected_check_in' => '07:00:00', 'expected_check_out' => '15:00:00', 'expected_hours' => 8.0,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false,
        'on_vacation' => false, 'justified_absence' => false,
    ]);

    $events = $openShift ? [['check_in', '07:00:00']] : [['check_in', '07:00:00'], ['check_out', '15:00:00']];
    foreach ($events as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id, 'employee_id' => $employee->id, 'event_type' => $type,
            'recorded_at' => Carbon::parse($date->toDateString().' '.$time),
        ]);
    }

    $day->forceFill($computed + ['late_minutes' => 0, 'extra_hours' => 0, 'tardiness_deduction_approved' => false, 'overtime_approved' => false])->saveQuietly();

    return $day->fresh();
}

function inboxAsSuperAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

function inboxCounts(): array
{
    return collect(AttendanceInboxService::sections())->mapWithKeys(fn ($s) => [$s['key'] => $s['count']])->all();
}

it('cuenta cada tipo de pendiente por separado', function () {
    inboxAsSuperAdmin();
    $employee = inboxEmployee();

    inboxDay($employee, 2, openShift: true);
    inboxDay($employee, 3, ['late_minutes' => 10]);
    inboxDay($employee, 4, ['late_minutes' => 5]);
    inboxDay($employee, 5, ['extra_hours' => 1.5]);
    inboxDay($employee, 6, ['late_minutes' => 9, 'tardiness_deduction_approved' => true]);

    $day = inboxDay($employee, 7);
    Absence::create(['employee_id' => $employee->id, 'attendance_day_id' => $day->id, 'status' => 'pending']);
    Absence::create(['employee_id' => $employee->id, 'attendance_day_id' => $day->id, 'status' => 'justified']);

    AttendanceMarkFailure::create(['failure_type' => 'sync_conflict', 'failure_message' => 'Conflicto de prueba', 'mode' => 'terminal', 'occurred_at' => now(), 'resolution_status' => 'pending']);
    AttendanceMarkFailure::create(['failure_type' => 'sync_conflict', 'failure_message' => 'Conflicto de prueba', 'mode' => 'terminal', 'occurred_at' => now(), 'resolution_status' => 'resolved']);

    expect(inboxCounts())->toBe([
        'missing_check_out' => 1,
        'tardiness' => 2,
        'overtime' => 1,
        'absences' => 1,
        'mark_failures' => 1,
    ]);
});

it('ignora jornadas fuera de la ventana de atención', function () {
    inboxAsSuperAdmin();
    inboxDay(inboxEmployee(), AttendanceDay::ATTENTION_WINDOW_DAYS + 5, ['late_minutes' => 10, 'extra_hours' => 2]);

    expect(inboxCounts())->toMatchArray(['tardiness' => 0, 'overtime' => 0]);
});

it('muestra la fecha más antigua de cada sección', function () {
    inboxAsSuperAdmin();
    $employee = inboxEmployee();
    inboxDay($employee, 3, ['late_minutes' => 10]);
    inboxDay($employee, 9, ['late_minutes' => 10]);

    $tardiness = collect(AttendanceInboxService::sections())->firstWhere('key', 'tardiness');

    expect($tardiness['oldest']->toDateString())->toBe(Carbon::today()->subDays(9)->toDateString());
});

it('cada usuario solo ve las secciones de los módulos que puede consultar', function () {
    Permission::firstOrCreate(['name' => 'view_any_absence', 'guard_name' => 'web']);
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('view_any_absence')));

    expect(array_column(AttendanceInboxService::sections(), 'key'))->toBe(['absences']);
});

it('sin permisos no hay acceso a la página', function () {
    test()->actingAs(User::factory()->create());

    expect(AttendanceInbox::canAccess())->toBeFalse();
});

it('la página muestra los contadores y la insignia del menú suma el total', function () {
    inboxAsSuperAdmin();
    $employee = inboxEmployee();
    inboxDay($employee, 2, ['late_minutes' => 10]);
    inboxDay($employee, 3, ['extra_hours' => 1]);

    Livewire::test(AttendanceInbox::class)
        ->assertSuccessful()
        ->assertSee('Tardanzas por aprobar')
        ->assertSee('Horas extra por aprobar');

    expect(AttendanceInbox::getNavigationBadge())->toBe('2');
});

it('muestra "Todo al día" cuando no hay nada pendiente', function () {
    inboxAsSuperAdmin();

    Livewire::test(AttendanceInbox::class)->assertSee('Todo al día');

    expect(AttendanceInbox::getNavigationBadge())->toBeNull();
});
