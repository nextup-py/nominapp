<?php

use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\Position;
use App\Models\User;
use App\Notifications\OvertimeAccumulationNotification;
use App\Notifications\OvertimeRequestNotification;
use App\Notifications\PendingApprovalsNotification;
use App\Services\OvertimeAccumulationService;
use App\Services\OvertimeRequestService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();

    $general = app(GeneralSettings::class);
    $general->setup_completed = true;
    $general->attendance_overtime_request_min_notice_hours = 0;
    $general->save();
    enableMobileMarking();
});

/** Empleado con empresa, sucursal, cargo y contrato vigente. */
function d3Employee(): Employee
{
    static $n = 7600000;
    $n++;

    $company = Company::create(['name' => "Empresa {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo {$n}", 'department_id' => $department->id]);
    $employee = Employee::create([
        'first_name' => 'Ana', 'last_name' => "Test{$n}", 'ci' => (string) $n,
        'email' => "d3{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active',
    ]);
    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual', 'salary' => 2_550_000, 'position_id' => $position->id,
        'department_id' => $department->id, 'status' => 'active',
    ]);

    return $employee->fresh();
}

/** Usuario con permisos directos y ficha opcional. */
function d3User(array $permissions, ?Employee $employee = null): User
{
    $user = User::factory()->create(['employee_id' => $employee?->id]);
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $user->givePermissionTo($permissions);

    return $user;
}

/** Empleado con jefe (ficha + usuario con `view_team`) y un usuario de RR.HH. */
function d3Team(): array
{
    $boss = d3Employee();
    $employee = d3Employee();
    $employee->update(['reports_to_id' => $boss->id]);

    return [$employee->fresh(), d3User(['view_team'], $boss), d3User(['approve_overtime_request', 'create_overtime_request'])];
}

/** Datos válidos de un pedido para hoy. */
function d3Data(array $override = []): array
{
    return array_merge(['date_from' => today()->toDateString(), 'max_hours' => 2, 'reason' => 'cash_close'], $override);
}

it('el empleado pide desde el celular y el pedido espera a su jefe, que recibe el aviso', function () {
    Notification::fake();
    [$employee, $boss] = d3Team();
    $employee->forceFill(['mobile_marking_allowed' => true])->save();
    Sanctum::actingAs($employee->fresh(), [Employee::MOBILE_SYNC_ABILITY]);

    $this->postJson('/api/v1/mobile/overtime-requests', d3Data(['date_from' => today()->addDay()->toDateString()]))->assertCreated()->assertJsonPath('status', 'pending_boss');

    $request = OvertimeRequest::first();
    expect($request->requested_by_employee_id)->toBe($employee->id)->and($request->requesterName())->toContain('(empleado)');
    Notification::assertSentTo($boss, OvertimeRequestNotification::class, fn ($n) => $n->kind === 'boss');
});

it('sin jefe con usuario el pedido del empleado va directo a RR.HH.', function () {
    Notification::fake();
    $employee = d3Employee();
    $hr = d3User(['approve_overtime_request']);

    $request = app(OvertimeRequestService::class)->createForEmployee($employee, d3Data());

    expect($request->status)->toBe(OvertimeRequest::STATUS_PENDING_HR);
    Notification::assertSentTo($hr, OvertimeRequestNotification::class, fn ($n) => $n->kind === 'hr');
});

it('la regularización permite pedir las horas extra de hoy aunque falte anticipación, pero no otras fechas', function () {
    $general = app(GeneralSettings::class);
    $general->attendance_overtime_request_min_notice_hours = 48;
    $general->save();
    $employee = d3Employee();
    $service = app(OvertimeRequestService::class);

    expect(fn () => $service->createForEmployee($employee, d3Data()))->toThrow(DomainException::class, 'anticipación');
    expect(fn () => $service->createForEmployee($employee, d3Data(['date_from' => today()->subDay()->toDateString()]), true))->toThrow(DomainException::class, 'solo puede pedirse para las horas extra de hoy');

    $request = $service->createForEmployee($employee, d3Data(), true);
    expect($request->regularization)->toBeTrue();
});

it('el endpoint rechaza un pedido inválido con un mensaje', function () {
    $employee = d3Employee();
    $employee->forceFill(['mobile_marking_allowed' => true])->save();
    Sanctum::actingAs($employee->fresh(), [Employee::MOBILE_SYNC_ABILITY]);

    $this->postJson('/api/v1/mobile/overtime-requests', d3Data(['max_hours' => 9]))->assertStatus(422)->assertJsonPath('ok', false);
    $this->postJson('/api/v1/mobile/overtime-requests', d3Data(['reason' => 'inventado']))->assertStatus(422);
    $this->postJson('/api/v1/mobile/overtime-requests', [])->assertStatus(422);
});

it('el listado del celular trae los pedidos propios y si hoy ya está cubierto', function () {
    $employee = d3Employee();
    $employee->forceFill(['mobile_marking_allowed' => true])->save();
    Sanctum::actingAs($employee->fresh(), [Employee::MOBILE_SYNC_ABILITY]);

    $this->getJson('/api/v1/mobile/overtime-requests')->assertOk()->assertJsonPath('covered_today', false)->assertJsonCount(0, 'requests');

    $hr = d3User(['approve_overtime_request', 'create_overtime_request']);
    app(OvertimeRequestService::class)->create($hr, $employee, d3Data());

    $this->getJson('/api/v1/mobile/overtime-requests')->assertOk()->assertJsonPath('covered_today', true)->assertJsonCount(1, 'requests');
});

it('la aprobación del jefe queda definitiva hasta el tope automático y pasa a RR.HH. por encima', function () {
    Notification::fake();
    [$employee, $boss] = d3Team();
    $general = app(GeneralSettings::class);
    $general->attendance_overtime_request_auto_approve_hours = 2;
    $general->save();
    $service = app(OvertimeRequestService::class);

    $small = $service->createForEmployee($employee, d3Data(['date_from' => today()->addDay()->toDateString(), 'max_hours' => 2]));
    $service->bossApprove($boss, $small);
    expect($small->fresh()->status)->toBe(OvertimeRequest::STATUS_APPROVED)->and($small->fresh()->auto_approved)->toBeTrue();

    $big = $service->createForEmployee($employee, d3Data(['date_from' => today()->addDays(3)->toDateString(), 'max_hours' => 3]));
    $service->bossApprove($boss, $big);
    expect($big->fresh()->status)->toBe(OvertimeRequest::STATUS_PENDING_HR)->and($big->fresh()->auto_approved)->toBeFalse();
});

it('un pedido que hace el propio jefe dentro del tope automático queda aprobado', function () {
    [$employee, $boss] = d3Team();
    $general = app(GeneralSettings::class);
    $general->attendance_overtime_request_auto_approve_hours = 3;
    $general->save();

    $request = app(OvertimeRequestService::class)->create($boss, $employee, d3Data(['max_hours' => 3]));

    expect($request->status)->toBe(OvertimeRequest::STATUS_APPROVED)->and($request->auto_approved)->toBeTrue();
});

it('escala a RR.HH. el pedido que el jefe no resolvió a tiempo y no toca los recientes', function () {
    Notification::fake();
    [$employee] = d3Team();
    $hr = d3User(['approve_overtime_request']);
    $general = app(GeneralSettings::class);
    $general->attendance_overtime_request_boss_escalation_hours = 24;
    $general->save();
    $service = app(OvertimeRequestService::class);

    $old = $service->createForEmployee($employee, d3Data(['date_from' => today()->addDays(2)->toDateString()]));
    $old->forceFill(['created_at' => now()->subHours(30)])->save();
    $recent = $service->createForEmployee($employee, d3Data(['date_from' => today()->addDays(5)->toDateString()]));

    $this->artisan('overtime:escalate-requests')->assertSuccessful();

    expect($old->fresh()->status)->toBe(OvertimeRequest::STATUS_PENDING_HR)->and($old->fresh()->escalated_at)->not->toBeNull()
        ->and($recent->fresh()->status)->toBe(OvertimeRequest::STATUS_PENDING_BOSS);
    Notification::assertSentTo($hr, OvertimeRequestNotification::class, fn ($n) => $n->kind === 'hr');
});

it('el recordatorio diario incluye los pedidos que esperan al jefe', function () {
    Notification::fake();
    [$employee, $boss] = d3Team();
    app(OvertimeRequestService::class)->createForEmployee($employee, d3Data(['date_from' => today()->addDay()->toDateString()]));

    $this->artisan('approvals:notify-pending')->assertSuccessful();

    Notification::assertSentTo($boss, PendingApprovalsNotification::class, fn ($n) => ($n->counts['overtime_boss'] ?? 0) === 1);
});

// ─── Acumulación ─────────────────────────────────────────────────────────────

/** Jornada con horas extra para el empleado en la fecha. */
function d3ExtraDay(Employee $employee, string $date, float $hours): void
{
    AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => $date, 'status' => 'present', 'extra_hours' => $hours,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false, 'on_vacation' => false, 'justified_absence' => false,
    ]);
}

it('detecta a quien supera el tope semanal o mensual y reparte las filas por destinatario', function () {
    [$employee, $boss, $hr] = d3Team();
    [$other] = d3Team();
    $monday = Carbon::parse('2026-03-16');
    $general = app(GeneralSettings::class);
    $general->attendance_overtime_weekly_alert_hours = 5;
    $general->attendance_overtime_monthly_alert_hours = 0;
    $general->save();

    d3ExtraDay($employee, '2026-03-16', 3);
    d3ExtraDay($employee, '2026-03-17', 3);
    d3ExtraDay($other, '2026-03-16', 1);

    $service = app(OvertimeAccumulationService::class);
    $rows = $service->exceeding($monday->copy()->addDays(2));

    expect($rows)->toHaveCount(1)->and($rows[0]['employee_id'])->toBe($employee->id)->and($rows[0]['week'])->toBe(6.0)
        ->and($service->rowsFor($hr, $rows))->toHaveCount(1)
        ->and($service->rowsFor($boss, $rows))->toHaveCount(1)
        ->and($service->rowsFor(d3User(['view_team'], $other), $rows))->toHaveCount(0);
});

it('sin topes configurados no avisa y con ellos no repite mientras haya un aviso sin leer', function () {
    Notification::fake();
    [$employee, $boss, $hr] = d3Team();
    d3ExtraDay($employee, today()->toDateString(), 4);

    expect(app(OvertimeAccumulationService::class)->exceeding())->toBe([]);

    $general = app(GeneralSettings::class);
    $general->attendance_overtime_weekly_alert_hours = 2;
    $general->save();

    $this->artisan('overtime:notify-accumulation')->assertSuccessful();
    Notification::assertSentTo($hr, OvertimeAccumulationNotification::class);
    Notification::assertSentTo($boss, OvertimeAccumulationNotification::class);
});
