<?php

use App\Models\AttendanceDay;
use App\Models\AttendanceMarkFailure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\ScheduleBreak;
use App\Models\ScheduleDay;
use App\Models\User;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(fn () => enableMobileMarking());

/**
 * Regresión: MobileStatusController::show() (endpoint online-preferido de
 * /marcar para el celular) y AttendanceMarkFailure::approve() (aprobación
 * manual de un fallo desde Filament) se habían quedado con la lógica vieja
 * al arreglar el turno nocturno y el filtro de "Inicio de descanso" — ambos
 * son puntos de entrada tan válidos como los ya corregidos.
 */
function makeStatusTestEmployee(): Employee
{
    static $ci = 8400000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa Status {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Status {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto Status {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo Status {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Status',
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

function assignStatusTestBreakSchedule(Employee $employee): void
{
    $schedule = Schedule::create(['name' => 'Horario Status Test', 'shift_type' => 'diurno', 'description' => null]);

    foreach (range(1, 7) as $dayOfWeek) {
        $day = ScheduleDay::create([
            'schedule_id' => $schedule->id,
            'day_of_week' => $dayOfWeek,
            'is_active' => true,
            'start_time' => '07:00',
            'end_time' => '22:00',
        ]);

        ScheduleBreak::create([
            'schedule_day_id' => $day->id,
            'name' => 'Almuerzo',
            'start_time' => '12:00',
            'end_time' => '13:00',
        ]);
    }

    ScheduleAssignmentService::assign($employee, $schedule, now()->subYear());
}

afterEach(function () {
    Carbon::setTestNow();
});

// ─── MobileStatusController::show() ────────────────────────────────────────

it('status móvil permite marcar salida de un turno nocturno abierto el día anterior', function () {
    $employee = makeStatusTestEmployee();

    Carbon::setTestNow(Carbon::parse('2026-08-27 17:00:00'));
    expect(markAttendanceEvent($employee, 'check_in')['ok'])->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-08-28 01:00:00'));
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);

    $response = $this->getJson('/api/v1/mobile/status');

    $response->assertOk();
    expect($response->json('last_event'))->toBe('check_in')
        ->and($response->json('allowed_events'))->toContain('check_out')
        ->and($response->json('today_events'))->toHaveCount(1)
        ->and($response->json('today_events.0.event_type'))->toBe('check_in');
});

it('status móvil sí ofrece "Inicio de descanso" sin horario asignado (sin turno no hay regla que lo impida)', function () {
    $employee = makeStatusTestEmployee();

    expect(markAttendanceEvent($employee, 'check_in')['ok'])->toBeTrue();

    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);
    $response = $this->getJson('/api/v1/mobile/status');

    $response->assertOk();
    expect($response->json('allowed_events'))->toContain('break_start');
});

it('status móvil ofrece "Inicio de descanso" con horario que lo contempla', function () {
    $employee = makeStatusTestEmployee();
    assignStatusTestBreakSchedule($employee);

    expect(markAttendanceEvent($employee, 'check_in')['ok'])->toBeTrue();

    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);
    $response = $this->getJson('/api/v1/mobile/status');

    $response->assertOk();
    expect($response->json('allowed_events'))->toContain('break_start');
});

// ─── AttendanceMarkFailure::approve() ──────────────────────────────────────

it('approve() reconstruye la salida de un turno nocturno rechazado por cruzar medianoche', function () {
    $employee = makeStatusTestEmployee();

    Carbon::setTestNow(Carbon::parse('2026-08-27 17:00:00'));
    expect(markAttendanceEvent($employee, 'check_in')['ok'])->toBeTrue();

    // Salida rechazada (simula el estado pre-fix: quedó como fallo registrado).
    Carbon::setTestNow(Carbon::parse('2026-08-28 01:00:00'));
    $failure = AttendanceMarkFailure::record([
        'mode' => 'unknown',
        'failure_type' => 'invalid_event_sequence',
        'employee_id' => $employee->id,
        'branch_id' => $employee->branch_id,
        'attempted_event_type' => 'check_out',
        'failure_message' => 'Secuencia de evento no permitida.',
    ]);

    $admin = User::create(['name' => 'Admin', 'email' => 'admin-status@test.com', 'password' => bcrypt('secret')]);
    $result = $failure->approve($admin->id, 'check_out', Carbon::now());

    expect($result['success'])->toBeTrue();

    $day = AttendanceDay::where('employee_id', $employee->id)->where('date', '2026-08-27')->first();
    expect($day)->not->toBeNull()
        ->and($day->events()->pluck('event_type')->toArray())->toBe(['check_in', 'check_out']);
});
