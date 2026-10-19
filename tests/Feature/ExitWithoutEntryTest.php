<?php

use App\Filament\Resources\AttendanceMarkFailureResource\Pages\ListAttendanceMarkFailures;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AttendanceMarkFailure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\ScheduleDay;
use App\Models\Terminal;
use App\Models\User;
use App\Notifications\SyncConflictPendingNotification;
use App\Services\AttendanceEventSyncService;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeExitEmployee(): Employee
{
    static $n = 9800000;
    $n++;

    $company = Company::create(['name' => "Empresa Salida {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Salida {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Salida',
        'last_name' => 'Sin Entrada',
        'ci' => (string) $n,
        'email' => "salida{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ])->fresh();
}

/** Asigna un horario que rige todos los días con el inicio y el fin dados. */
function assignExitSchedule(Employee $employee, string $start, string $end): void
{
    $schedule = Schedule::create(['name' => "Horario {$start}-{$end} ".Str::random(4), 'shift_type' => 'diurno', 'description' => null]);

    foreach (range(1, 7) as $dayOfWeek) {
        ScheduleDay::create(['schedule_id' => $schedule->id, 'day_of_week' => $dayOfWeek, 'is_active' => true, 'start_time' => $start, 'end_time' => $end]);
    }

    ScheduleAssignmentService::assign($employee, $schedule, Carbon::parse('2026-01-01'));
}

/** Sincroniza una salida (sin entrada previa) y devuelve el fallo que deja para RR.HH. */
function syncExitWithoutEntry(Employee $employee, string $recordedAt): AttendanceMarkFailure
{
    $terminal = Terminal::create(['name' => 'Terminal Salida '.Str::random(4), 'branch_id' => $employee->branch_id]);

    $results = app(AttendanceEventSyncService::class)->syncBatch($terminal, [[
        'client_event_id' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'event_type' => 'check_out',
        'recorded_at' => $recordedAt,
    ]]);

    expect($results[0]['status'])->toBe('conflict');

    return AttendanceMarkFailure::where('employee_id', $employee->id)->latest('id')->firstOrFail();
}

function exitAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

// ─── Servidor: estado del empleado ──────────────────────────────────────────

it('status() informa también el inicio del turno de hoy', function () {
    $employee = makeExitEmployee();
    assignExitSchedule($employee, '08:00', '17:00');
    $terminal = Terminal::create(['name' => 'Terminal Status Entrada', 'branch_id' => $employee->branch_id]);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $response = $this->getJson("/api/v1/terminal/employees/{$employee->id}/status");

    $response->assertOk();
    expect($response->json('expected_check_in'))->toBe('08:00')
        ->and($response->json('expected_check_out'))->toBe('17:00');
});

it('status() devuelve null en ambas horas sin horario ese día', function () {
    $employee = makeExitEmployee();
    $terminal = Terminal::create(['name' => 'Terminal Status Sin Horario', 'branch_id' => $employee->branch_id]);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $response = $this->getJson("/api/v1/terminal/employees/{$employee->id}/status");

    expect($response->json('expected_check_in'))->toBeNull()
        ->and($response->json('expected_check_out'))->toBeNull();
});

// ─── Servidor: el fallo que deja la salida sin entrada ──────────────────────

it('una salida sin entrada queda como fallo para RR.HH. con la entrada del horario', function () {
    $employee = makeExitEmployee();
    assignExitSchedule($employee, '08:00', '17:00');

    $failure = syncExitWithoutEntry($employee, '2026-08-19 16:59:00');

    expect($failure->failure_type)->toBe('sync_conflict')
        ->and($failure->isMissingEntry())->toBeTrue()
        ->and($failure->metadata['expected_check_in'])->toBe('08:00')
        ->and($failure->failure_message)->toContain('Salida sin entrada')
        ->and($failure->getNextStepHint())->toBe('Aprobar agregando la entrada del horario, o descartar')
        ->and(AttendanceEvent::count())->toBe(0);
});

it('una salida rechazada con entrada previa no es una salida sin entrada', function () {
    $employee = makeExitEmployee();
    $terminal = Terminal::create(['name' => 'Terminal Con Entrada', 'branch_id' => $employee->branch_id]);
    $service = app(AttendanceEventSyncService::class);

    foreach ([['check_in', '2026-08-19 08:00:00'], ['check_out', '2026-08-19 17:00:00'], ['check_out', '2026-08-19 18:00:00']] as [$type, $at]) {
        $service->syncBatch($terminal, [['client_event_id' => (string) Str::uuid(), 'employee_id' => $employee->id, 'event_type' => $type, 'recorded_at' => $at]]);
    }

    $failure = AttendanceMarkFailure::where('employee_id', $employee->id)->latest('id')->first();

    expect($failure)->not->toBeNull()
        ->and($failure->isMissingEntry())->toBeFalse();
});

it('sin horario la salida sin entrada queda igual para revisión, sin hora sugerida', function () {
    $employee = makeExitEmployee();

    $failure = syncExitWithoutEntry($employee, '2026-08-19 16:59:00');

    expect($failure->isMissingEntry())->toBeTrue()
        ->and($failure->metadata['expected_check_in'])->toBeNull()
        ->and($failure->suggestedEntryAt())->toBeNull();
});

it('la entrada sugerida de un turno nocturno cae en el día anterior a la salida', function () {
    $employee = makeExitEmployee();
    assignExitSchedule($employee, '20:00', '04:00');

    $failure = syncExitWithoutEntry($employee, '2026-08-20 04:10:00');

    expect($failure->suggestedEntryAt()->format('Y-m-d H:i'))->toBe('2026-08-19 20:00');
});

it('la entrada sugerida de un turno diurno cae en el mismo día', function () {
    $employee = makeExitEmployee();
    assignExitSchedule($employee, '08:00', '17:00');

    $failure = syncExitWithoutEntry($employee, '2026-08-19 16:59:00');

    expect($failure->suggestedEntryAt()->format('Y-m-d H:i'))->toBe('2026-08-19 08:00');
});

// ─── Aprobación ─────────────────────────────────────────────────────────────

it('aprobar agregando la entrada crea entrada (manual) y salida y deja la jornada completa', function () {
    $employee = makeExitEmployee();
    assignExitSchedule($employee, '08:00', '17:00');
    $failure = syncExitWithoutEntry($employee, '2026-08-19 16:59:00');
    $admin = exitAdmin();

    $result = $failure->approve($admin->id, entryAt: $failure->suggestedEntryAt());

    expect($result['success'])->toBeTrue();

    $day = AttendanceDay::where('employee_id', $employee->id)->where('date', '2026-08-19')->first();
    $events = $day->events()->orderBy('recorded_at')->get();

    expect($events->pluck('event_type')->all())->toBe(['check_in', 'check_out'])
        ->and($events[0]->source)->toBe('manual')
        ->and($events[0]->recorded_at->format('H:i'))->toBe('08:00')
        ->and($events[1]->recorded_at->format('H:i'))->toBe('16:59')
        ->and($failure->fresh()->resolution_status)->toBe('approved');
});

it('aprobar una salida sin entrada y sin agregar la entrada sigue fallando por la secuencia', function () {
    $employee = makeExitEmployee();
    $failure = syncExitWithoutEntry($employee, '2026-08-19 16:59:00');

    $result = $failure->approve(exitAdmin()->id);

    expect($result['success'])->toBeFalse()
        ->and($failure->fresh()->resolution_status)->toBe('pending');
});

it('la entrada a agregar debe ser anterior a la salida y no deja nada creado', function () {
    $employee = makeExitEmployee();
    $failure = syncExitWithoutEntry($employee, '2026-08-19 16:59:00');

    $result = $failure->approve(exitAdmin()->id, entryAt: Carbon::parse('2026-08-19 17:30:00'));

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('anterior a la salida')
        ->and(AttendanceEvent::count())->toBe(0);
});

it('el modal de Aprobar pide la entrada, la propone del horario y registra entrada y salida', function () {
    $employee = makeExitEmployee();
    assignExitSchedule($employee, '08:00', '17:00');
    $failure = syncExitWithoutEntry($employee, '2026-08-19 16:59:00');

    Livewire::actingAs(exitAdmin())
        ->test(ListAttendanceMarkFailures::class)
        ->set('activeTab', 'all')
        ->mountTableAction('approve', $failure)
        ->assertTableActionDataSet(['entry_at' => '2026-08-19 08:00:00'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect(AttendanceEvent::pluck('event_type')->sort()->values()->all())->toBe(['check_in', 'check_out']);
});

it('la aprobación en bloque omite las salidas sin entrada: se aprueban una por una', function () {
    $employee = makeExitEmployee();
    $failure = syncExitWithoutEntry($employee, '2026-08-19 16:59:00');

    Livewire::actingAs(exitAdmin())
        ->test(ListAttendanceMarkFailures::class)
        ->set('activeTab', 'all')
        ->callTableBulkAction('approve_bulk', [$failure]);

    expect($failure->fresh()->resolution_status)->toBe('pending')
        ->and(AttendanceEvent::count())->toBe(0);
});

it('el aviso a los administradores distingue una salida sin entrada de un conflicto cualquiera', function () {
    $employee = makeExitEmployee();
    $missing = syncExitWithoutEntry($employee, '2026-08-19 16:59:00');
    $admin = exitAdmin();

    $data = (new SyncConflictPendingNotification($missing))->toDatabase($admin);

    expect($data['title'])->toBe('Salida sin entrada para revisar')
        ->and($data['body'])->toContain('no tiene una entrada registrada');

    $other = AttendanceMarkFailure::record(['mode' => 'terminal', 'failure_type' => 'sync_conflict', 'employee_id' => $employee->id, 'attempted_event_type' => 'check_in', 'failure_message' => 'x']);

    expect((new SyncConflictPendingNotification($other))->toDatabase($admin)['title'])->toBe('Conflicto al sincronizar una marcación offline');
});
