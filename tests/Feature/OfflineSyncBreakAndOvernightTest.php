<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\ScheduleBreak;
use App\Models\ScheduleDay;
use App\Models\Terminal;
use App\Services\EmployeeDescriptorSyncService;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Regresión: el turno nocturno y el filtro de "Inicio de descanso" solo se
 * habían arreglado en el flujo de marcación online (AttendanceFaceMarkController)
 * y en el sync de eventos offline (AttendanceEventSyncService/
 * MobileEventSyncService) — pero TerminalEmployeeSyncController::status()
 * (consultado por el terminal cuando SÍ hay red, antes de caer al cálculo
 * local) y los payloads de heartbeat/sync que alimentan la caché offline
 * (EmployeeDescriptorSyncService, MobileHeartbeatController) seguían con la
 * lógica vieja.
 */
function makeOfflineTestEmployee(): Employee
{
    static $ci = 8300000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa Offline {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create([
        'name' => "Sucursal Offline {$n}",
        'company_id' => $company->id,
        'coordinates' => ['lat' => -25.2867, 'lng' => -57.6478],
    ]);
    $department = Department::create(['name' => "Depto Offline {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo Offline {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Offline',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'birth_date' => '1990-01-01',
        'branch_id' => $branch->id,
        'status' => 'active',
        'face_descriptor' => array_fill(0, 128, 0.1),
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

function assignBreakScheduleToEmployee(Employee $employee, bool $withBreak = true): void
{
    $schedule = Schedule::create(['name' => 'Horario Offline Test', 'shift_type' => 'diurno', 'description' => null]);

    foreach (range(1, 7) as $dayOfWeek) {
        $day = ScheduleDay::create([
            'schedule_id' => $schedule->id,
            'day_of_week' => $dayOfWeek,
            'is_active' => true,
            'start_time' => '07:00',
            'end_time' => '22:00',
        ]);

        if ($withBreak) {
            ScheduleBreak::create([
                'schedule_day_id' => $day->id,
                'name' => 'Almuerzo',
                'start_time' => '12:00',
                'end_time' => '13:00',
            ]);
        }
    }

    ScheduleAssignmentService::assign($employee, $schedule, now()->subYear());
}

afterEach(function () {
    Carbon::setTestNow();
});

// ─── TerminalEmployeeSyncController::status() ──────────────────────────────

it('status() permite marcar salida de un turno nocturno abierto el día anterior', function () {
    $employee = makeOfflineTestEmployee();
    $terminal = Terminal::create(['name' => 'Terminal Status Test', 'branch_id' => $employee->branch_id]);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    Carbon::setTestNow(Carbon::parse('2026-08-27 17:00:00'));
    expect(markAttendanceEvent($employee, 'check_in')['ok'])->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-08-28 01:00:00'));
    $response = $this->getJson("/api/v1/terminal/employees/{$employee->id}/status");

    $response->assertOk();
    expect($response->json('allowed_events'))->toContain('check_out')
        ->and($response->json('last_event'))->toBe('check_in');
});

it('status() sí ofrece "Inicio de descanso" sin horario asignado (sin turno no hay regla que lo impida)', function () {
    $employee = makeOfflineTestEmployee();
    $terminal = Terminal::create(['name' => 'Terminal Status Test 2', 'branch_id' => $employee->branch_id]);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    expect(markAttendanceEvent($employee, 'check_in')['ok'])->toBeTrue();

    $response = $this->getJson("/api/v1/terminal/employees/{$employee->id}/status");

    $response->assertOk();
    expect($response->json('allowed_events'))->toContain('break_start');
});

// ─── EmployeeDescriptorSyncService::deltaSince() — break_flags ─────────────

it('deltaSince incluye break_flags reflejando si cada empleado tiene descanso configurado', function () {
    $employeeWithBreak = makeOfflineTestEmployee();
    assignBreakScheduleToEmployee($employeeWithBreak);

    $employeeWithoutBreak = makeOfflineTestEmployee();
    $employeeWithoutBreak->update(['branch_id' => $employeeWithBreak->branch_id]);
    assignBreakScheduleToEmployee($employeeWithoutBreak, withBreak: false);

    $employeeWithoutSchedule = makeOfflineTestEmployee();
    $employeeWithoutSchedule->update(['branch_id' => $employeeWithBreak->branch_id]);

    $terminal = Terminal::create(['name' => 'Terminal Delta Test', 'branch_id' => $employeeWithBreak->branch_id]);

    $delta = app(EmployeeDescriptorSyncService::class)->deltaSince($terminal, null);

    // Un turno sin descanso no lo ofrece; sin horario no hay turno esperado y sí se puede marcar.
    expect($delta['break_flags'][$employeeWithBreak->id])->toBeTrue()
        ->and($delta['break_flags'][$employeeWithoutBreak->id])->toBeFalse()
        ->and($delta['break_flags'][$employeeWithoutSchedule->id])->toBeTrue();
});

it('deltaSince recalcula break_flags para TODOS los empleados activos, no solo los que cambiaron desde $since', function () {
    $employee = makeOfflineTestEmployee();
    $terminal = Terminal::create(['name' => 'Terminal Delta Test 2', 'branch_id' => $employee->branch_id]);
    $service = app(EmployeeDescriptorSyncService::class);

    // Primera sync completa — el empleado tiene un horario sin descanso.
    assignBreakScheduleToEmployee($employee, withBreak: false);
    $first = $service->deltaSince($terminal, null);
    expect($first['break_flags'][$employee->id])->toBeFalse();

    // Se asigna un horario con descanso DESPUÉS de la primera sync — no toca
    // employees.updated_at, así que un delta filtrado por $since lo pasaría por alto.
    $since = Carbon::parse($first['sync_version']);
    assignBreakScheduleToEmployee($employee);

    $second = $service->deltaSince($terminal, $since);
    expect($second['employees'])->toBeEmpty() // no cambió el registro del empleado
        ->and($second['break_flags'][$employee->id])->toBeTrue(); // pero el flag sí se actualiza
});

// ─── MobileHeartbeatController — has_scheduled_break ───────────────────────

it('el heartbeat móvil incluye has_scheduled_break en el payload del empleado', function () {
    $employee = makeOfflineTestEmployee();
    assignBreakScheduleToEmployee($employee);
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/mobile/heartbeat');

    $response->assertOk();
    expect($response->json('employee.has_scheduled_break'))->toBeTrue();
});

it('el heartbeat móvil marca has_scheduled_break en true sin horario asignado (sin turno puede marcar descanso)', function () {
    $employee = makeOfflineTestEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    $response = $this->postJson('/api/v1/mobile/heartbeat');

    $response->assertOk();
    expect($response->json('employee.has_scheduled_break'))->toBeTrue();
});

it('status() informa la hora de la última marcación y la salida esperada del turno para el recordatorio', function () {
    $employee = makeOfflineTestEmployee();
    assignBreakScheduleToEmployee($employee);
    $terminal = Terminal::create(['name' => 'Terminal Status Test 3', 'branch_id' => $employee->branch_id]);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    Carbon::setTestNow(Carbon::parse('2026-08-27 07:05:00'));
    expect(markAttendanceEvent($employee, 'check_in')['ok'])->toBeTrue();

    $response = $this->getJson("/api/v1/terminal/employees/{$employee->id}/status");

    $response->assertOk();
    expect($response->json('expected_check_out'))->toBe('22:00')
        ->and(Carbon::parse($response->json('last_event_at'))->format('H:i'))->toBe('07:05');
});

it('status() devuelve expected_check_out nulo si el empleado no tiene horario ese día', function () {
    $employee = makeOfflineTestEmployee();
    $terminal = Terminal::create(['name' => 'Terminal Status Test 4', 'branch_id' => $employee->branch_id]);
    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);

    $response = $this->getJson("/api/v1/terminal/employees/{$employee->id}/status");

    $response->assertOk();
    expect($response->json('expected_check_out'))->toBeNull()
        ->and($response->json('last_event_at'))->toBeNull();
});
