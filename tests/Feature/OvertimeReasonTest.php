<?php

use App\Exports\OvertimeReportDetailExport;
use App\Filament\Resources\AttendanceDayResource\Pages\ListAttendanceDays;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Terminal;
use App\Models\User;
use App\Services\AttendanceEventSyncService;
use App\Services\MobileEventSyncService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Empleado con empresa, sucursal, cargo y contrato, y un terminal de su sucursal. */
function overtimeReasonSetup(): array
{
    static $n = 6100000;
    $n++;

    $company = Company::create(['name' => "Empresa {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo {$n}", 'department_id' => $department->id]);
    $employee = Employee::create([
        'first_name' => 'Motivo', 'last_name' => 'Test', 'ci' => (string) $n,
        'email' => "motivo{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active',
    ]);
    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear(),
        'salary_type' => 'mensual', 'salary' => 2_550_000, 'position_id' => $position->id,
        'department_id' => $department->id, 'status' => 'active',
    ]);

    return [$employee->fresh(), Terminal::create(['name' => 'Terminal Motivo', 'branch_id' => $branch->id])];
}

/** Marca entrada y salida por el servicio de terminal; devuelve la jornada. */
function syncTerminalDay(Terminal $terminal, Employee $employee, array $exit): AttendanceDay
{
    $service = app(AttendanceEventSyncService::class);

    $service->syncBatch($terminal, [[
        'client_event_id' => (string) Str::uuid(), 'employee_id' => $employee->id,
        'event_type' => 'check_in', 'recorded_at' => '2026-08-19 08:00:00',
    ]]);
    $results = $service->syncBatch($terminal, [[
        'client_event_id' => (string) Str::uuid(), 'employee_id' => $employee->id,
        'event_type' => 'check_out', 'recorded_at' => '2026-08-19 19:00:00', ...$exit,
    ]]);

    expect($results[0]['status'])->toBe('synced');

    return AttendanceDay::where('employee_id', $employee->id)->firstOrFail();
}

it('guarda el motivo elegido en la salida del terminal, en el evento y en la jornada', function () {
    [$employee, $terminal] = overtimeReasonSetup();

    $day = syncTerminalDay($terminal, $employee, ['overtime_reason' => 'cash_close']);

    expect($day->overtime_reason)->toBe('cash_close')
        ->and($day->events()->where('event_type', 'check_out')->value('overtime_reason'))->toBe('cash_close')
        ->and($day->events()->where('event_type', 'check_in')->value('overtime_reason'))->toBeNull();
});

it('una salida sin motivo se registra igual y no borra uno ya guardado', function () {
    [$employee, $terminal] = overtimeReasonSetup();
    $day = syncTerminalDay($terminal, $employee, []);

    expect($day->overtime_reason)->toBeNull();

    $day->rememberOvertimeReason('boss_request');
    $day->rememberOvertimeReason(null);

    expect($day->fresh()->overtime_reason)->toBe('boss_request');
});

it('un motivo inválido no descarta la marcación: se guarda sin motivo', function () {
    [$employee, $terminal] = overtimeReasonSetup();

    $day = syncTerminalDay($terminal, $employee, ['overtime_reason' => 'inventado']);

    expect($day->events()->where('event_type', 'check_out')->exists())->toBeTrue()
        ->and($day->overtime_reason)->toBeNull();
});

it('el motivo solo se guarda en una salida, no en otros eventos', function () {
    [$employee, $terminal] = overtimeReasonSetup();

    app(AttendanceEventSyncService::class)->syncBatch($terminal, [[
        'client_event_id' => (string) Str::uuid(), 'employee_id' => $employee->id,
        'event_type' => 'check_in', 'recorded_at' => '2026-08-19 08:00:00', 'overtime_reason' => 'cash_close',
    ]]);

    expect(AttendanceDay::where('employee_id', $employee->id)->value('overtime_reason'))->toBeNull();
});

it('guarda el motivo también desde el celular', function () {
    [$employee] = overtimeReasonSetup();
    $service = app(MobileEventSyncService::class);

    $service->syncBatch($employee, [[
        'client_event_id' => (string) Str::uuid(), 'event_type' => 'check_in', 'recorded_at' => '2026-08-19 08:00:00',
    ]]);
    $results = $service->syncBatch($employee, [[
        'client_event_id' => (string) Str::uuid(), 'event_type' => 'check_out',
        'recorded_at' => '2026-08-19 19:00:00', 'overtime_reason' => 'pending_work',
    ]]);

    expect($results[0]['status'])->toBe('synced')
        ->and(AttendanceDay::where('employee_id', $employee->id)->value('overtime_reason'))->toBe('pending_work');
});

it('los latidos del terminal y del celular traen la lista de motivos y la tolerancia', function () {
    [$employee, $terminal] = overtimeReasonSetup();
    $general = app(GeneralSettings::class);
    $general->attendance_overtime_reason_required = true;
    $general->attendance_overtime_reason_timeout_seconds = 45;
    $general->save();

    Sanctum::actingAs($terminal, [Terminal::SYNC_ABILITY]);
    $terminalResponse = $this->postJson('/api/v1/terminal/heartbeat', [])->assertOk();

    expect($terminalResponse->json('config.overtime_reasons'))->toBe(AttendanceEvent::getOvertimeReasonOptions())
        ->and($terminalResponse->json('config.overtime_reason_required'))->toBeTrue()
        ->and($terminalResponse->json('config.overtime_reason_timeout_seconds'))->toBe(45)
        ->and($terminalResponse->json('config.overtime_tolerance_minutes'))->toBeInt();

    $employee->forceFill(['mobile_marking_allowed' => true])->save();
    Sanctum::actingAs($employee->fresh(), [Employee::MOBILE_SYNC_ABILITY]);
    $mobileResponse = $this->postJson('/api/v1/mobile/heartbeat')->assertOk();

    expect($mobileResponse->json('config.overtime_reasons'))->toBe(AttendanceEvent::getOvertimeReasonOptions())
        ->and($mobileResponse->json('config.overtime_reason_required'))->toBeTrue()
        ->and($mobileResponse->json('config.overtime_tolerance_minutes'))->toBeInt();
});

it('la etiqueta del motivo cae en «Sin motivo» cuando no hay ninguno', function () {
    expect(AttendanceEvent::overtimeReasonLabel(null))->toBe('Sin motivo')
        ->and(AttendanceEvent::overtimeReasonLabel(''))->toBe('Sin motivo')
        ->and(AttendanceEvent::overtimeReasonLabel('boss_request'))->toBe('Pedido del jefe');
});

it('Asistencias filtra las jornadas con horas extra por motivo, incluido «Sin motivo»', function () {
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    [$withReason] = overtimeReasonSetup();
    [$withoutReason] = overtimeReasonSetup();
    $cash = AttendanceDay::create(['employee_id' => $withReason->id, 'date' => '2026-03-16', 'status' => 'present', 'extra_hours' => 2, 'overtime_reason' => 'cash_close']);
    $none = AttendanceDay::create(['employee_id' => $withoutReason->id, 'date' => '2026-03-16', 'status' => 'present', 'extra_hours' => 1.5]);

    Livewire::test(ListAttendanceDays::class)
        ->set('activeTab', 'all')
        ->filterTable('overtime_reason', 'cash_close')
        ->assertCanSeeTableRecords([$cash])
        ->assertCanNotSeeTableRecords([$none])
        ->filterTable('overtime_reason', 'none')
        ->assertCanSeeTableRecords([$none])
        ->assertCanNotSeeTableRecords([$cash]);
});

it('el Excel de detalle de horas extra incluye el motivo y «Sin motivo» cuando falta', function () {
    [$employee] = overtimeReasonSetup();
    $withReason = AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-03-16', 'status' => 'present', 'extra_hours' => 2, 'overtime_reason' => 'boss_request']);
    $withoutReason = AttendanceDay::create(['employee_id' => $employee->id, 'date' => '2026-03-17', 'status' => 'present', 'extra_hours' => 1]);
    $export = new OvertimeReportDetailExport('2026-03-01', '2026-03-31', null, null, null, null, ['date', 'overtime_reason']);

    expect(OvertimeReportDetailExport::availableColumns())->toHaveKey('overtime_reason')
        ->and($export->map($withReason->load('employee')))->toBe(['16/03/2026', 'Pedido del jefe'])
        ->and($export->map($withoutReason->load('employee')))->toBe(['17/03/2026', 'Sin motivo']);
});
