<?php

use App\Filament\Resources\AttendanceDayResource\Pages\ListAttendanceDays;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Listado de Asistencias: pestaña "Requieren atención" (sin salida, tardanza por aprobar, extras
 * por aprobar), contadores de pestañas, columna "Pendiente" y acciones agrupadas.
 */
function makeAttentionEmployee(): Employee
{
    static $ci = 8300000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa Atención {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create([
        'name' => "Sucursal Atención {$n}",
        'company_id' => $company->id,
        'coordinates' => ['lat' => -25.2867, 'lng' => -57.6478],
    ]);

    return Employee::create([
        'first_name' => 'Atencion',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'birth_date' => '1990-01-01',
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
}

/**
 * Jornada presente con la secuencia de eventos dada. Los campos calculados (`$attrs`) se fijan
 * al final y sin eventos del modelo: registrar marcaciones dispara el recálculo y los pisaría.
 */
function makeAttentionDay(Employee $employee, string $date, array $events, array $attrs = []): AttendanceDay
{
    $day = AttendanceDay::create([
        'employee_id' => $employee->id,
        'date' => $date,
        'status' => 'present',
        'is_calculated' => true,
    ]);

    foreach ($events as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id,
            'employee_id' => $employee->id,
            'event_type' => $type,
            'recorded_at' => Carbon::parse("{$date} {$time}"),
        ]);
    }

    $day = $day->fresh();
    $day->forceFill(array_merge(['late_minutes' => null, 'extra_hours' => null], $attrs))->saveQuietly();

    return $day->fresh();
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00'));
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('needsAttention incluye sin salida, tardanza y extras por aprobar, y excluye lo resuelto', function () {
    $employee = makeAttentionEmployee();

    $noExit = makeAttentionDay($employee, '2026-10-09', [['check_in', '08:00']]);
    $late = makeAttentionDay($employee, '2026-10-08', [['check_in', '08:20'], ['check_out', '17:00']], ['late_minutes' => 20]);
    $extra = makeAttentionDay($employee, '2026-10-07', [['check_in', '08:00'], ['check_out', '19:00']], ['extra_hours' => 2]);
    $ok = makeAttentionDay($employee, '2026-10-06', [['check_in', '08:00'], ['check_out', '17:00']]);
    $lateApproved = makeAttentionDay($employee, '2026-10-05', [['check_in', '08:20'], ['check_out', '17:00']], ['late_minutes' => 20, 'tardiness_deduction_approved' => true]);
    $extraApproved = makeAttentionDay($employee, '2026-10-04', [['check_in', '08:00'], ['check_out', '19:00']], ['extra_hours' => 2, 'overtime_approved' => true]);
    $regular = makeAttentionDay($employee, '2026-10-03', [['check_in', '07:00'], ['check_out', '12:00'], ['check_in', '15:00'], ['check_out', '23:00']], ['extra_hours' => 2, 'second_shift_regular' => true]);
    $todayOpen = makeAttentionDay($employee, '2026-10-10', [['check_in', '08:00']]);

    $ids = AttendanceDay::needsAttention()->pluck('id')->all();

    expect($ids)->toContain($noExit->id, $late->id, $extra->id)
        ->not->toContain($ok->id, $lateApproved->id, $extraApproved->id, $regular->id, $todayOpen->id);
});

it('attentionReasons lista los motivos de cada jornada', function () {
    $employee = makeAttentionEmployee();

    $day = makeAttentionDay($employee, '2026-10-09', [['check_in', '08:20']], ['late_minutes' => 20, 'extra_hours' => 1]);
    $ok = makeAttentionDay($employee, '2026-10-08', [['check_in', '08:00'], ['check_out', '17:00']]);

    expect($day->attentionReasons())->toBe(['Sin salida', 'Tardanza por aprobar', 'Extras por aprobar'])
        ->and($ok->attentionReasons())->toBe([]);
});

it('el listado abre en "Requieren atención" y "Todos" muestra el resto', function () {
    $employee = makeAttentionEmployee();
    $pending = makeAttentionDay($employee, '2026-10-09', [['check_in', '08:00']]);
    $ok = makeAttentionDay($employee, '2026-10-08', [['check_in', '08:00'], ['check_out', '17:00']]);

    $component = Livewire::test(ListAttendanceDays::class)
        ->assertSet('activeTab', 'attention')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$ok]);

    $component->set('activeTab', 'all')->assertCanSeeTableRecords([$pending, $ok]);
});

it('las pestañas Ayer y Hoy filtran por fecha y los contadores coinciden', function () {
    $employee = makeAttentionEmployee();
    $yesterday = makeAttentionDay($employee, '2026-10-09', [['check_in', '08:00'], ['check_out', '17:00']]);
    $today = makeAttentionDay($employee, '2026-10-10', [['check_in', '08:00']]);
    $older = makeAttentionDay($employee, '2026-10-01', [['check_in', '08:00']]);

    $component = Livewire::test(ListAttendanceDays::class);

    $component->set('activeTab', 'yesterday')
        ->assertCanSeeTableRecords([$yesterday])
        ->assertCanNotSeeTableRecords([$today, $older]);

    $component->set('activeTab', 'today')
        ->assertCanSeeTableRecords([$today])
        ->assertCanNotSeeTableRecords([$yesterday, $older]);

    $tabs = $component->instance()->getTabs();
    expect((int) $tabs['attention']->getBadge())->toBe(1)     // solo $older (sin salida, anterior a hoy)
        ->and((int) $tabs['yesterday']->getBadge())->toBe(1)
        ->and((int) $tabs['today']->getBadge())->toBe(1)
        ->and((int) $tabs['all']->getBadge())->toBe(3);
});

it('aprobar la tardanza desde las acciones agrupadas saca la jornada de "Requieren atención"', function () {
    $employee = makeAttentionEmployee();
    $day = makeAttentionDay($employee, '2026-10-08', [['check_in', '08:20'], ['check_out', '17:00']], ['late_minutes' => 20]);

    Livewire::test(ListAttendanceDays::class)
        ->assertCanSeeTableRecords([$day])
        ->callTableAction('approve_tardiness', $day)
        ->assertHasNoTableActionErrors();

    expect($day->fresh()->tardiness_deduction_approved)->toBeTrue()
        ->and(AttendanceDay::needsAttention()->whereKey($day->id)->exists())->toBeFalse();
});

it('"Requieren atención" solo mira los últimos 30 días; lo más viejo sigue en "Todos"', function () {
    $employee = makeAttentionEmployee();
    $recent = makeAttentionDay($employee, '2026-09-20', [['check_in', '08:00']]);          // 20 días atrás
    $old = makeAttentionDay($employee, '2026-08-20', [['check_in', '08:00']]);             // 51 días atrás
    $oldLate = makeAttentionDay($employee, '2026-08-19', [['check_in', '08:20'], ['check_out', '17:00']], ['late_minutes' => 20]);

    $ids = AttendanceDay::needsAttention()->pluck('id')->all();

    expect($ids)->toContain($recent->id)->not->toContain($old->id, $oldLate->id);

    $component = Livewire::test(ListAttendanceDays::class)
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$old, $oldLate]);

    expect((int) $component->instance()->getTabs()['attention']->getBadge())->toBe(1);

    $component->set('activeTab', 'all')->assertCanSeeTableRecords([$recent, $old, $oldLate]);
});
