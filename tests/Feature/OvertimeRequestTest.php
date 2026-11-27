<?php

use App\Filament\Pages\MyTeam;
use App\Filament\Resources\OvertimeRequestResource\Pages\ListOvertimeRequests;
use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\Position;
use App\Models\User;
use App\Notifications\OvertimeRequestNotification;
use App\Services\AttendanceCalculator;
use App\Services\OvertimeRequestService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/** Empleado con empresa, sucursal, cargo y contrato vigente propios. */
function requestTestEmployee(): array
{
    static $n = 7300000;
    $n++;

    $company = Company::create(['name' => "Empresa {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo {$n}", 'department_id' => $department->id]);
    $employee = Employee::create([
        'first_name' => 'Hex', 'last_name' => 'Test', 'ci' => (string) $n,
        'email' => "hex{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active',
    ]);
    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual', 'salary' => 2_550_000, 'position_id' => $position->id,
        'department_id' => $department->id, 'status' => 'active',
    ]);

    return [$employee->fresh(), $position, $branch, $company];
}

/** Jornada con 2 horas extra sobre un horario de 9 horas. */
function requestTestDay(Employee $employee, string $date = '2026-03-16'): AttendanceDay
{
    $day = AttendanceDay::create([
        'employee_id' => $employee->id, 'date' => $date, 'status' => 'absent',
        'expected_check_in' => '08:00:00', 'expected_check_out' => '17:00:00', 'expected_hours' => 9.0,
        'is_calculated' => true, 'is_weekend' => false, 'is_holiday' => false,
        'on_vacation' => false, 'justified_absence' => false,
    ]);
    foreach ([['check_in', '08:00:00'], ['check_out', '19:00:00']] as [$type, $time]) {
        AttendanceEvent::create([
            'attendance_day_id' => $day->id, 'employee_id' => $employee->id,
            'event_type' => $type, 'recorded_at' => Carbon::parse("{$date} {$time}"),
        ]);
    }

    return $day;
}

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();

    $general = app(GeneralSettings::class);
    $general->setup_completed = true;
    $general->attendance_overtime_request_min_notice_hours = 0;
    $general->save();
});

/** Usuario con permisos directos (sin rol), vinculado opcionalmente a una ficha. */
function overtimeRequestUser(array $permissions, ?Employee $employee = null): User
{
    $user = User::factory()->create(['employee_id' => $employee?->id]);
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $user->givePermissionTo($permissions);

    return $user;
}

/** Empleado con jefe directo (ficha del jefe) y el jefe como usuario con `view_team`. */
function overtimeTeam(): array
{
    [$bossEmployee] = requestTestEmployee();
    [$employee] = requestTestEmployee();
    $employee->update(['reports_to_id' => $bossEmployee->id]);

    return [
        $employee->fresh(),
        overtimeRequestUser(['view_team'], $bossEmployee),
        overtimeRequestUser(['approve_overtime_request', 'reject_overtime_request', 'cancel_overtime_request', 'create_overtime_request', 'view_any_overtime_request', 'view_overtime_request']),
    ];
}

/** Datos válidos de un pedido para hoy. */
function overtimeRequestData(array $override = []): array
{
    return array_merge(['date_from' => today()->toDateString(), 'max_hours' => 2, 'reason' => 'cash_close'], $override);
}

it('un jefe pide horas extra para su equipo y queda pendiente de RR.HH., avisando a RR.HH.', function () {
    Notification::fake();
    [$employee, $boss, $hr] = overtimeTeam();

    $request = app(OvertimeRequestService::class)->create($boss, $employee, overtimeRequestData());

    expect($request->status)->toBe(OvertimeRequest::STATUS_PENDING_HR)
        ->and($request->boss_approved_by_id)->toBe($boss->id);
    Notification::assertSentTo($hr, OvertimeRequestNotification::class, fn ($n) => $n->kind === 'hr');
});

it('RR.HH. deja el pedido aprobado de una vez y puede regularizar fechas pasadas', function () {
    [$employee, , $hr] = overtimeTeam();

    $request = app(OvertimeRequestService::class)->create($hr, $employee, overtimeRequestData(['date_from' => today()->subDays(3)->toDateString()]));

    expect($request->status)->toBe(OvertimeRequest::STATUS_APPROVED)->and($request->hr_approved_by_id)->toBe($hr->id);
});

it('rechaza pedidos inválidos', function (array $override, string $message) {
    [$employee, $boss] = overtimeTeam();

    expect(fn () => app(OvertimeRequestService::class)->create($boss, $employee, overtimeRequestData($override)))
        ->toThrow(DomainException::class, $message);
})->with([
    'fecha pasada para un jefe' => [['date_from' => '2020-01-01'], 'Solo RR.HH.'],
    'más horas que el límite diario' => [['max_hours' => 8], 'entre 0,25'],
    'ventana incompleta' => [['time_from' => '18:00'], 'hora desde y la hora hasta'],
    'fechas invertidas' => [['date_from' => '2099-01-02', 'date_to' => '2099-01-01'], 'anterior'],
]);

it('un usuario ajeno al equipo no puede pedir', function () {
    [$employee] = overtimeTeam();
    [$otherEmployee] = requestTestEmployee();
    $stranger = overtimeRequestUser(['view_team'], $otherEmployee);

    expect(fn () => app(OvertimeRequestService::class)->create($stranger, $employee, overtimeRequestData()))
        ->toThrow(DomainException::class, 'No puede pedir');
});

it('respeta la anticipación mínima y no permite superponer pedidos', function () {
    [$employee, $boss] = overtimeTeam();
    $general = app(GeneralSettings::class);
    $general->attendance_overtime_request_min_notice_hours = 48;
    $general->save();

    expect(fn () => app(OvertimeRequestService::class)->create($boss, $employee, overtimeRequestData()))
        ->toThrow(DomainException::class, 'anticipación');

    $far = today()->addDays(10)->toDateString();
    app(OvertimeRequestService::class)->create($boss, $employee, overtimeRequestData(['date_from' => $far]));

    expect(fn () => app(OvertimeRequestService::class)->create($boss, $employee, overtimeRequestData(['date_from' => $far])))
        ->toThrow(DomainException::class, 'superpone');
});

it('el jefe aprueba un pedido que lo espera y pasa a RR.HH.; RR.HH. lo deja aprobado y avisa a quien pidió', function () {
    Notification::fake();
    [$employee, $boss, $hr] = overtimeTeam();
    $request = OvertimeRequest::create(overtimeRequestData() + ['date_to' => today()->toDateString(), 'employee_id' => $employee->id, 'requested_by_id' => $hr->id, 'status' => OvertimeRequest::STATUS_PENDING_BOSS]);
    $service = app(OvertimeRequestService::class);

    $service->bossApprove($boss, $request);
    expect($request->fresh()->status)->toBe(OvertimeRequest::STATUS_PENDING_HR);

    $approver = overtimeRequestUser(['approve_overtime_request']);
    $service->hrApprove($approver, $request->fresh());

    expect($request->fresh()->status)->toBe(OvertimeRequest::STATUS_APPROVED);
    Notification::assertSentTo($hr, OvertimeRequestNotification::class, fn ($n) => $n->kind === 'result');
});

it('rechazar guarda el motivo; cancelar se bloquea si el pedido ya se aplicó a una jornada', function () {
    [$employee, , $hr] = overtimeTeam();
    $service = app(OvertimeRequestService::class);

    $rejected = OvertimeRequest::create(overtimeRequestData() + ['date_to' => today()->toDateString(), 'employee_id' => $employee->id, 'requested_by_id' => $hr->id, 'status' => OvertimeRequest::STATUS_PENDING_HR]);
    $service->reject($hr, $rejected, 'No hay presupuesto');
    expect($rejected->fresh()->status)->toBe(OvertimeRequest::STATUS_REJECTED)->and($rejected->fresh()->rejection_reason)->toBe('No hay presupuesto');

    $approved = $service->create($hr, $employee, overtimeRequestData(['date_from' => today()->addDay()->toDateString()]));
    $day = requestTestDay($employee, today()->addDay()->toDateString());
    $day->update(['overtime_request_id' => $approved->id]);

    expect(fn () => $service->cancel($hr, $approved->fresh()))->toThrow(DomainException::class, 'ya se aplicó');
});

it('vence los pedidos pendientes de fechas pasadas', function () {
    [$employee, , $hr] = overtimeTeam();
    $old = OvertimeRequest::create(['employee_id' => $employee->id, 'requested_by_id' => $hr->id, 'date_from' => today()->subDays(2), 'date_to' => today()->subDay(), 'max_hours' => 1, 'status' => OvertimeRequest::STATUS_PENDING_HR]);

    $this->artisan('overtime:expire-requests')->assertSuccessful();

    expect($old->fresh()->status)->toBe(OvertimeRequest::STATUS_EXPIRED);
});

// ─── Efecto sobre la jornada ─────────────────────────────────────────────────

it('un pedido aprobado autoriza y aprueba solas las horas extra hasta el tope', function () {
    [$employee, , $hr] = overtimeTeam();
    $employee->update(['overtime_request_required' => true]);
    $request = app(OvertimeRequestService::class)->create($hr, $employee, overtimeRequestData(['date_from' => '2026-03-16', 'max_hours' => 2.5]));
    $day = requestTestDay($employee);

    AttendanceCalculator::apply($day);
    $day = $day->fresh();

    expect($day->overtime_request_id)->toBe($request->id)
        ->and($day->overtime_unauthorized)->toBeFalse()
        ->and($day->overtime_approved)->toBeTrue();
});

it('el exceso sobre lo pedido queda por revisar y sin pedido se marca no autorizada si se exige', function () {
    [$employee, , $hr] = overtimeTeam();
    $employee->update(['overtime_request_required' => true]);

    $noRequest = requestTestDay($employee, '2026-03-17');
    AttendanceCalculator::apply($noRequest);
    expect($noRequest->fresh()->overtime_unauthorized)->toBeTrue()->and($noRequest->fresh()->overtime_approved)->toBeFalse();

    app(OvertimeRequestService::class)->create($hr, $employee, overtimeRequestData(['date_from' => '2026-03-16', 'max_hours' => 1]));
    $day = requestTestDay($employee);
    AttendanceCalculator::apply($day);
    $day = $day->fresh();

    expect($day->overtime_unauthorized)->toBeFalse()
        ->and($day->overtime_approved)->toBeFalse()
        ->and(AttendanceDay::query()->pendingOvertime()->whereKey($day->id)->exists())->toBeTrue();
});

it('la ventana horaria impide aprobar solas las extras que pasan de la hora hasta', function () {
    [$employee, , $hr] = overtimeTeam();
    app(OvertimeRequestService::class)->create($hr, $employee, overtimeRequestData(['date_from' => '2026-03-16', 'max_hours' => 3, 'time_from' => '17:00', 'time_to' => '18:00']));
    $day = requestTestDay($employee); // sale 19:00

    AttendanceCalculator::apply($day);

    expect($day->fresh()->overtime_approved)->toBeFalse()->and($day->fresh()->overtime_request_id)->not->toBeNull();
});

it('resuelve la exigencia de pedido previo en orden empleado, cargo, sucursal, empresa y general', function () {
    [$employee, $position, $branch, $company] = requestTestEmployee();

    expect($employee->overtimeRequestRequiredSource())->toBe(['required' => false, 'source' => 'general']);

    $company->update(['overtime_request_required' => true]);
    expect($employee->fresh()->overtimeRequestRequiredSource())->toBe(['required' => true, 'source' => 'company']);

    $branch->update(['overtime_request_required' => false]);
    $position->update(['overtime_request_required' => true]);
    expect($employee->fresh()->overtimeRequestRequiredSource())->toBe(['required' => true, 'source' => 'position']);

    $employee->update(['overtime_request_required' => false]);
    expect($employee->fresh()->overtimeRequestRequired())->toBeFalse();
});

// ─── Pantallas ───────────────────────────────────────────────────────────────

it('Mi equipo permite al jefe pedir horas extra y RR.HH. las ve en el listado', function () {
    [$employee, $boss, $hr] = overtimeTeam();

    $this->actingAs($boss);
    Livewire::test(MyTeam::class)
        ->callAction('request_overtime', overtimeRequestData(['employee_id' => $employee->id]))
        ->assertHasNoActionErrors();

    expect(OvertimeRequest::where('employee_id', $employee->id)->value('status'))->toBe(OvertimeRequest::STATUS_PENDING_HR);

    $this->actingAs($hr);
    Livewire::test(ListOvertimeRequests::class)
        ->assertCanSeeTableRecords(OvertimeRequest::all());
});

it('RR.HH. aprueba desde el listado y un usuario sin permiso no ve las acciones', function () {
    [$employee, $boss, $hr] = overtimeTeam();
    $request = app(OvertimeRequestService::class)->create($boss, $employee, overtimeRequestData());

    $this->actingAs($hr);
    Livewire::test(ListOvertimeRequests::class)->callTableAction('approve_overtime_request', $request);
    expect($request->fresh()->status)->toBe(OvertimeRequest::STATUS_APPROVED);

    [$teammate] = requestTestEmployee();
    $teammate->update(['reports_to_id' => $boss->employee_id]);
    $pending = app(OvertimeRequestService::class)->create($boss, $teammate->fresh(), overtimeRequestData(['date_from' => today()->addDays(5)->toDateString()]));
    $this->actingAs($boss);
    Livewire::test(MyTeam::class)->assertTableActionHidden('approve_overtime_request', $pending);
});
