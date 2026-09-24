<?php

use App\Models\AttendanceEvent;
use App\Models\AttendanceMarkFailure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Terminal;
use App\Services\AttendanceEventSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeSyncEmployee(): Employee
{
    static $ci = 8000000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa Sync {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Sync {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto Sync {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo Sync {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Sync',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'email' => "sync{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

function makeSyncTerminal(Employee $employee): Terminal
{
    return Terminal::create([
        'name' => 'Terminal Test',
        'branch_id' => $employee->branch_id,
    ]);
}

// ─── Tests ──────────────────────────────────────────────────────────────────

it('sincroniza un evento nuevo y lo marca con origen terminal', function () {
    $employee = makeSyncEmployee();
    $terminal = makeSyncTerminal($employee);
    $clientEventId = (string) Str::uuid();

    $results = app(AttendanceEventSyncService::class)->syncBatch($terminal, [[
        'client_event_id' => $clientEventId,
        'employee_id' => $employee->id,
        'event_type' => 'check_in',
        'recorded_at' => '2026-08-19 08:00:00',
    ]]);

    expect($results[0]['status'])->toBe('synced');

    $event = AttendanceEvent::where('client_event_id', $clientEventId)->first();
    expect($event)->not->toBeNull()
        ->and($event->source)->toBe('terminal')
        ->and($event->synced_at)->not->toBeNull()
        ->and($event->terminal_id)->toBe($terminal->id);
});

/**
 * Regresión: el terminal manda recorded_at en UTC (Date.toISOString(), ej.
 * "...T22:30:00.000Z"). Antes del fix, ese valor se persistía tal cual (sin
 * convertir a la timezone de la app), guardando la hora UTC "cruda" — un
 * evento marcado a las 19:30 en Paraguay (UTC-3) quedaba con recorded_at en
 * 22:30, 3 horas adelantado.
 */
it('convierte recorded_at de UTC a la timezone de la app antes de persistir', function () {
    // Se fija explícitamente (en vez de asumir el app.timezone ambiente, que en
    // CI es UTC) para que el test ejercite realmente la conversión y no solo
    // coincida "por casualidad" cuando origen y destino son el mismo UTC.
    config(['app.timezone' => 'America/Asuncion']);

    $employee = makeSyncEmployee();
    $terminal = makeSyncTerminal($employee);
    $clientEventId = (string) Str::uuid();

    app(AttendanceEventSyncService::class)->syncBatch($terminal, [[
        'client_event_id' => $clientEventId,
        'employee_id' => $employee->id,
        'event_type' => 'check_in',
        'recorded_at' => '2026-08-23T22:30:00.000Z',
    ]]);

    // El valor leído de vuelta se re-hidrata con el timezone default de PHP a
    // nivel de proceso (fijado una sola vez al boot vía date_default_timezone_set()),
    // no con el config() recién sobreescrito — por eso solo se verifica el
    // valor de reloj persistido, no el nombre de la timezone del objeto leído.
    $event = AttendanceEvent::where('client_event_id', $clientEventId)->first();
    expect($event->recorded_at->format('Y-m-d H:i:s'))->toBe('2026-08-23 19:30:00');
});

it('es idempotente — reenviar el mismo client_event_id no duplica el evento', function () {
    $employee = makeSyncEmployee();
    $terminal = makeSyncTerminal($employee);
    $clientEventId = (string) Str::uuid();
    $payload = [[
        'client_event_id' => $clientEventId,
        'employee_id' => $employee->id,
        'event_type' => 'check_in',
        'recorded_at' => '2026-08-19 08:00:00',
    ]];

    $service = app(AttendanceEventSyncService::class);
    $service->syncBatch($terminal, $payload);
    $results = $service->syncBatch($terminal, $payload);

    expect($results[0]['status'])->toBe('duplicate')
        ->and(AttendanceEvent::where('client_event_id', $clientEventId)->count())->toBe(1);
});

it('rechaza como conflict un evento cuya secuencia ya no es válida en el servidor y lo registra para revisión', function () {
    $employee = makeSyncEmployee();
    $terminal = makeSyncTerminal($employee);
    $service = app(AttendanceEventSyncService::class);

    // El check_out ya llegó al servidor (ej. desde otro origen mientras el terminal estaba offline).
    $service->syncBatch($terminal, [[
        'client_event_id' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'event_type' => 'check_out',
        'recorded_at' => '2026-08-19 17:00:00',
    ]]);

    // El terminal recién ahora sincroniza un break_start que había capturado offline, antes del check_out.
    $conflictingClientEventId = (string) Str::uuid();
    $results = $service->syncBatch($terminal, [[
        'client_event_id' => $conflictingClientEventId,
        'employee_id' => $employee->id,
        'event_type' => 'break_start',
        'recorded_at' => '2026-08-19 12:00:00',
    ]]);

    expect($results[0]['status'])->toBe('conflict')
        ->and($results[0]['conflict_reason'])->toBe('invalid_sequence')
        ->and(AttendanceEvent::where('client_event_id', $conflictingClientEventId)->exists())->toBeFalse();

    $failure = AttendanceMarkFailure::where('failure_type', 'sync_conflict')->first();
    expect($failure)->not->toBeNull()
        ->and($failure->mode)->toBe('terminal')
        ->and($failure->employee_id)->toBe($employee->id)
        ->and($failure->branch_id)->toBe($terminal->branch_id);
});

/**
 * Regresión: antes de este fix, la validación de forma de cada evento vivía
 * en TerminalEventSyncController como un solo $request->validate() sobre TODO
 * el array — un único evento malformado (ej. event_type inválido) hacía
 * fallar el batch entero con 422, sin llegar nunca a syncBatch(). Ahora
 * AttendanceEventSyncService valida cada evento individualmente, así que un
 * evento malformado se descarta sin afectar a los demás del mismo lote.
 */
it('descarta un evento malformado como invalid_payload sin afectar a los demás del lote', function () {
    $employee = makeSyncEmployee();
    $terminal = makeSyncTerminal($employee);
    $goodClientEventId = (string) Str::uuid();

    $results = app(AttendanceEventSyncService::class)->syncBatch($terminal, [
        [
            'client_event_id' => 'not-a-uuid',
            'employee_id' => $employee->id,
            'event_type' => 'an_invalid_type_from_a_newer_client',
            'recorded_at' => '2026-08-19 08:00:00',
        ],
        [
            'client_event_id' => $goodClientEventId,
            'employee_id' => $employee->id,
            'event_type' => 'check_in',
            'recorded_at' => '2026-08-19 08:01:00',
        ],
    ]);

    $malformed = collect($results)->firstWhere('client_event_id', 'not-a-uuid');
    $good = collect($results)->firstWhere('client_event_id', $goodClientEventId);

    expect($malformed['status'])->toBe('rejected')
        ->and($malformed['conflict_reason'])->toBe('invalid_payload')
        ->and($good['status'])->toBe('synced');

    $failure = AttendanceMarkFailure::where('failure_type', 'invalid_payload')->first();
    expect($failure)->not->toBeNull()
        // No debe ser "aprobable" — el dato en sí está corrupto, no hay nada válido que reconstruir.
        ->and($failure->canBeResolved())->toBeFalse();
});

it('rechaza un evento de un empleado inexistente o inactivo y lo registra para revisión', function () {
    $employee = makeSyncEmployee();
    $terminal = makeSyncTerminal($employee);
    $clientEventId = (string) Str::uuid();

    $results = app(AttendanceEventSyncService::class)->syncBatch($terminal, [[
        'client_event_id' => $clientEventId,
        'employee_id' => 999999,
        'event_type' => 'check_in',
        'recorded_at' => '2026-08-19 08:00:00',
    ]]);

    expect($results[0]['status'])->toBe('rejected')
        ->and($results[0]['conflict_reason'])->toBe('employee_not_found');

    $failure = AttendanceMarkFailure::where('failure_type', 'employee_not_found')
        ->where('metadata->client_event_id', $clientEventId)
        ->first();
    expect($failure)->not->toBeNull();
});
