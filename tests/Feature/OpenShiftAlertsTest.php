<?php

use App\Filament\Resources\AttendanceDayResource\Pages\ListAttendanceDays;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AttendanceMarkFailure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Terminal;
use App\Models\User;
use App\Notifications\OpenShiftsPendingNotification;
use App\Services\AttendanceEventSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Jornadas que quedan sin salida: scope `missingCheckOut`, conflicto de una salida huérfana con
 * la jornada vieja abierta, filtro de Asistencias y aviso diario a RR.HH.
 */
function makeOpenShiftEmployee(): Employee
{
    static $ci = 8200000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa Abierta {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create([
        'name' => "Sucursal Abierta {$n}",
        'company_id' => $company->id,
        'coordinates' => ['lat' => -25.2867, 'lng' => -57.6478],
    ]);

    return Employee::create([
        'first_name' => 'Abierta',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'birth_date' => '1990-01-01',
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
}

/** Crea una jornada con la secuencia de eventos dada (tipo => hora H:i del mismo día). */
function makeDayWithEvents(Employee $employee, string $date, array $events): AttendanceDay
{
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => $date, 'status' => 'present']);

    foreach ($events as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id,
            'employee_id' => $employee->id,
            'event_type' => $type,
            'recorded_at' => Carbon::parse("{$date} {$time}"),
        ]);
    }

    return $day;
}

afterEach(function () {
    Carbon::setTestNow();
});

it('missingCheckOut incluye solo las jornadas anteriores a hoy cuyo último evento no es una salida', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00'));
    $employee = makeOpenShiftEmployee();

    $open = makeDayWithEvents($employee, '2026-10-09', [['check_in', '17:00']]);
    $openOnBreak = makeDayWithEvents($employee, '2026-10-08', [['check_in', '08:00'], ['break_start', '12:00']]);
    $closed = makeDayWithEvents($employee, '2026-10-07', [['check_in', '08:00'], ['check_out', '17:00']]);
    $today = makeDayWithEvents($employee, '2026-10-10', [['check_in', '08:00']]);
    $withoutEvents = AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-10-06', 'status' => 'absent']);

    $ids = AttendanceDay::missingCheckOut()->pluck('id')->all();

    expect($ids)->toContain($open->id, $openOnBreak->id)
        ->not->toContain($closed->id, $today->id, $withoutEvents->id);
});

it('una salida huérfana a 2+ días de la entrada queda como conflicto con la jornada abierta anotada', function () {
    $employee = makeOpenShiftEmployee();
    $terminal = Terminal::create(['name' => 'Terminal Abierta', 'branch_id' => $employee->branch_id]);
    $service = new AttendanceEventSyncService;

    $checkIn = makeDayWithEvents($employee, '2026-10-06', [['check_in', '17:56']]);

    $result = $service->syncBatch($terminal, [[
        'client_event_id' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'event_type' => 'check_out',
        'recorded_at' => '2026-10-09T01:08:00Z', // 22:08 del 08/10 en America/Asuncion
    ]]);

    expect($result[0]['status'])->toBe('conflict');

    $failure = AttendanceMarkFailure::where('employee_id', $employee->id)->where('failure_type', 'sync_conflict')->firstOrFail();

    expect($failure->metadata['open_day_id'])->toBe($checkIn->id)
        ->and($failure->metadata['open_day_date'])->toBe('2026-10-06')
        ->and($failure->failure_message)->toContain('06/10/2026');
});

it('el conflicto no anota jornada abierta si la entrada anterior ya tiene salida', function () {
    $employee = makeOpenShiftEmployee();
    $terminal = Terminal::create(['name' => 'Terminal Cerrada', 'branch_id' => $employee->branch_id]);

    makeDayWithEvents($employee, '2026-10-06', [['check_in', '08:00'], ['check_out', '17:00']]);

    (new AttendanceEventSyncService)->syncBatch($terminal, [[
        'client_event_id' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'event_type' => 'check_out',
        'recorded_at' => '2026-10-09T01:08:00Z',
    ]]);

    $failure = AttendanceMarkFailure::where('employee_id', $employee->id)->where('failure_type', 'sync_conflict')->firstOrFail();

    expect($failure->metadata)->not->toHaveKey('open_day_id');
});

it('el filtro "Sin salida registrada" de Asistencias muestra solo las jornadas abiertas', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00'));
    $employee = makeOpenShiftEmployee();

    $open = makeDayWithEvents($employee, '2026-10-09', [['check_in', '17:00']]);
    $closed = makeDayWithEvents($employee, '2026-10-08', [['check_in', '08:00'], ['check_out', '17:00']]);

    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    test()->actingAs($admin);

    Livewire::test(ListAttendanceDays::class)
        ->filterTable('missing_check_out')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$closed]);
});

it('attendance:notify-open-shifts avisa una sola vez a quien gestiona asistencias', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00'));
    $employee = makeOpenShiftEmployee();
    makeDayWithEvents($employee, '2026-10-09', [['check_in', '17:00']]);
    makeDayWithEvents($employee, '2026-10-05', [['check_in', '08:00']]);

    Permission::firstOrCreate(['name' => 'view_any_attendance_day', 'guard_name' => 'web']);
    $hr = User::factory()->create();
    $hr->givePermissionTo('view_any_attendance_day');
    $other = User::factory()->create();

    Artisan::call('attendance:notify-open-shifts');

    expect($hr->unreadNotifications()->where('type', OpenShiftsPendingNotification::class)->count())->toBe(1)
        ->and($other->unreadNotifications()->count())->toBe(0);

    $data = $hr->unreadNotifications()->first()->data;
    expect($data['open_shifts_count'])->toBe(2)
        ->and($data['body'])->toContain('05/10/2026');

    // Con el aviso sin leer no se repite.
    Artisan::call('attendance:notify-open-shifts');
    expect($hr->unreadNotifications()->count())->toBe(1);
});

it('attendance:notify-open-shifts no avisa si no hay jornadas abiertas', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00'));
    $employee = makeOpenShiftEmployee();
    makeDayWithEvents($employee, '2026-10-09', [['check_in', '08:00'], ['check_out', '17:00']]);

    Permission::firstOrCreate(['name' => 'view_any_attendance_day', 'guard_name' => 'web']);
    $hr = User::factory()->create();
    $hr->givePermissionTo('view_any_attendance_day');

    Artisan::call('attendance:notify-open-shifts');

    expect($hr->unreadNotifications()->count())->toBe(0);
});
