<?php

use App\Exports\ShiftPlanExport;
use App\Filament\Pages\ShiftPlannerPage;
use App\Filament\Resources\ShiftTemplateResource\Pages\ViewShiftTemplate;
use App\Filament\Resources\ShiftTemplateResource\RelationManagers\OverridesRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\RotationPattern;
use App\Models\ShiftOverride;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Models\Vacation;
use App\Services\RotationService;
use App\Services\ShiftPlanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])));
    $this->actingAs($this->admin);
});

/** Empresa, empleado y turno de prueba con datos únicos por llamada. */
function plannerSetup(): array
{
    static $n = 7860000;
    $n++;
    $company = Company::create(['name' => "EmpPlan {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucPlan {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Pablo', 'last_name' => "Plan {$n}", 'ci' => (string) $n, 'email' => "plan{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active']);
    $shift = ShiftTemplate::create(['company_id' => $company->id, 'name' => 'Tarde', 'shift_type' => 'diurno', 'start_time' => '15:00', 'end_time' => '23:00', 'break_minutes' => 30]);

    return [$company, $employee, $shift];
}

it('guarda un turno puntual válido y devuelve los minutos netos', function () {
    [, $employee, $shift] = plannerSetup();

    $this->postJson(route('shift-planner.override.store'), [
        'employee_id' => $employee->id, 'date' => today()->toDateString(), 'shift_id' => $shift->id, 'reason_type' => 'otro',
    ])->assertOk()->assertJsonPath('net_minutes', 450)->assertJsonPath('is_active', true);

    expect(ShiftOverride::count())->toBe(1);
});

it('rechaza un turno de otra empresa, un turno inactivo y un empleado inactivo', function () {
    [, $employee, $shift] = plannerSetup();
    [, , $foreignShift] = plannerSetup();
    $payload = fn (int $shiftId) => ['employee_id' => $employee->id, 'date' => today()->toDateString(), 'shift_id' => $shiftId, 'reason_type' => 'otro'];

    $this->postJson(route('shift-planner.override.store'), $payload($foreignShift->id))->assertStatus(422)->assertJsonPath('message', 'El turno pertenece a otra empresa que el empleado.');

    $shift->update(['is_active' => false]);
    $this->postJson(route('shift-planner.override.store'), $payload($shift->id))->assertStatus(422);

    $shift->update(['is_active' => true]);
    $employee->update(['status' => 'inactive']);
    $this->postJson(route('shift-planner.override.store'), $payload($shift->id))->assertStatus(422);

    expect(ShiftOverride::count())->toBe(0);
});

it('un usuario de solo lectura ve el planificador pero no puede guardar ni restaurar', function () {
    [, $employee, $shift] = plannerSetup();
    Permission::findOrCreate('view_any_schedule', 'web');
    $reader = User::factory()->create();
    $reader->assignRole(Role::firstOrCreate(['name' => 'Lector planificador', 'guard_name' => 'web']));
    $reader->givePermissionTo('view_any_schedule');
    $this->actingAs($reader);

    expect(ShiftPlannerPage::canAccess())->toBeTrue();

    $this->postJson(route('shift-planner.override.store'), [
        'employee_id' => $employee->id, 'date' => today()->toDateString(), 'shift_id' => $shift->id, 'reason_type' => 'otro',
    ])->assertForbidden();

    $this->deleteJson(route('shift-planner.override.destroy'), ['employee_id' => $employee->id, 'date' => today()->toDateString()])->assertForbidden();
});

it('el endpoint de datos admite filtrar por empleado y marca los turnos inactivos', function () {
    [$company, $employee, $shift] = plannerSetup();
    $other = Employee::create(['first_name' => 'Otro', 'last_name' => 'Empleado', 'ci' => '7869999', 'email' => 'otro@test.com', 'branch_id' => $employee->branch_id, 'status' => 'active']);
    $pattern = RotationPattern::create(['company_id' => $company->id, 'name' => 'P', 'sequence' => [$shift->id], 'is_active' => true]);
    RotationService::assign($employee, $pattern, Carbon::today()->subDay());
    RotationService::assign($other, $pattern, Carbon::today()->subDay());
    // Dato heredado: turnos desactivados antes del bloqueo siguen dentro de patrones.
    ShiftTemplate::whereKey($shift->id)->update(['is_active' => false]);

    $response = $this->getJson(route('shift-planner.data', ['start' => today()->toDateString(), 'end' => today()->toDateString(), 'employee_id' => $employee->id]))->assertOk();

    expect($response->json('employees'))->toHaveCount(1)
        ->and($response->json('shifts.'.$employee->id.'.'.today()->toDateString().'.is_active'))->toBeFalse()
        ->and($response->json('shifts.'.$employee->id.'.'.today()->toDateString().'.net_minutes'))->toBe(450);
});

it('registra quién asignó y quién restauró el turno puntual', function () {
    config(['audit.console' => true]);
    [, $employee, $shift] = plannerSetup();

    $this->postJson(route('shift-planner.override.store'), ['employee_id' => $employee->id, 'date' => today()->toDateString(), 'shift_id' => $shift->id, 'reason_type' => 'guardia_extra', 'notes' => 'Cubre licencia'])->assertOk();
    $override = ShiftOverride::first();

    expect($override->audits()->count())->toBe(1)
        ->and(ShiftOverride::describeAuditValues($override->audits()->first()->new_values))->toBe('Tarde · Guardia extra · «Cubre licencia»');

    Livewire::test(OverridesRelationManager::class, ['ownerRecord' => $shift, 'pageClass' => ViewShiftTemplate::class])
        ->assertCanSeeTableRecords([$override])
        ->mountTableAction('history', $override)
        ->assertSee('Asignado');

    $this->deleteJson(route('shift-planner.override.destroy'), ['employee_id' => $employee->id, 'date' => today()->toDateString()])->assertOk()->assertJsonPath('removed', true);

    expect(Audit::where('auditable_type', ShiftOverride::class)->where('event', 'deleted')->count())->toBe(1);
});

/** Plan base: empleado con patrón de un solo turno de mañana y su turno. */
function plannerWithPattern(): array
{
    [$company, $employee, $shift] = plannerSetup();
    $pattern = RotationPattern::create(['company_id' => $company->id, 'name' => 'Mañanas', 'sequence' => [$shift->id], 'is_active' => true]);
    RotationService::assign($employee, $pattern, Carbon::today()->subDays(10));

    return [$company, $employee, $shift, $pattern];
}

it('marca vacaciones y licencias aprobadas y cuenta la cobertura por día', function () {
    [, $employee] = plannerWithPattern();
    $day = today()->toDateString();

    Vacation::create(['employee_id' => $employee->id, 'start_date' => today(), 'end_date' => today()->addDay(), 'status' => 'approved', 'payment_method' => 'immediate', 'business_days' => 2]);
    EmployeeLeave::create(['employee_id' => $employee->id, 'type' => 'medical_leave', 'start_date' => today()->addDays(3), 'end_date' => today()->addDays(3), 'status' => 'approved']);
    EmployeeLeave::create(['employee_id' => $employee->id, 'type' => 'other', 'start_date' => today()->addDays(5), 'end_date' => today()->addDays(5), 'status' => 'pending']);

    $json = $this->getJson(route('shift-planner.data', ['start' => $day, 'end' => today()->addDays(5)->toDateString()]))->assertOk()->json();

    expect($json['conflicts'][$employee->id][$day]['label'])->toBe('Vacaciones')
        ->and($json['conflicts'][$employee->id][today()->addDays(3)->toDateString()]['label'])->toBe('Reposo Médico')
        ->and($json['conflicts'][$employee->id])->not->toHaveKey(today()->addDays(5)->toDateString())
        ->and($json['coverage'][$day])->toBe(['working' => 0, 'off' => 0, 'away' => 1])
        ->and($json['coverage'][today()->addDays(2)->toDateString()])->toBe(['working' => 1, 'off' => 0, 'away' => 0]);
});

it('filtra por patrón, cargo y alcance, y muestra el patrón de cada empleado', function () {
    [$company, $withPattern, , $pattern] = plannerWithPattern();
    $without = Employee::create(['first_name' => 'Sin', 'last_name' => 'Patron', 'ci' => '7861111', 'email' => 'sinpatron@test.com', 'branch_id' => $withPattern->branch_id, 'status' => 'active']);
    $range = ['start' => today()->toDateString(), 'end' => today()->toDateString()];

    $ids = fn (array $filters) => collect($this->getJson(route('shift-planner.data', $range + $filters))->assertOk()->json('employees'))->pluck('id')->all();

    expect($ids([]))->toEqualCanonicalizing([$withPattern->id, $without->id])
        ->and($ids(['pattern_id' => $pattern->id]))->toBe([$withPattern->id])
        ->and($ids(['scope' => 'without_pattern']))->toBe([$without->id])
        ->and($ids(['scope' => 'with_pattern']))->toBe([$withPattern->id])
        ->and(collect($this->getJson(route('shift-planner.data', $range))->json('employees'))->firstWhere('id', $withPattern->id)['pattern'])->toBe('Mañanas');
});

it('copia los turnos puntuales de una semana a otra con vista previa y sin tocar pasado ni turnos inactivos', function () {
    [$company, $employee, $shift] = plannerWithPattern();
    $monday = today()->addWeek()->startOfWeek();
    $nextMonday = $monday->copy()->addWeek();
    ShiftOverride::create(['employee_id' => $employee->id, 'override_date' => $monday->copy()->addDay(), 'shift_id' => $shift->id, 'reason_type' => 'guardia_extra']);
    ShiftOverride::create(['employee_id' => $employee->id, 'override_date' => $monday->copy()->addDays(2), 'shift_id' => $shift->id, 'reason_type' => 'otro']);
    ShiftOverride::create(['employee_id' => $employee->id, 'override_date' => $nextMonday->copy()->addDays(2), 'shift_id' => $shift->id, 'reason_type' => 'permiso']);
    $payload = ['from_start' => $monday->toDateString(), 'to_start' => $nextMonday->toDateString(), 'employee_id' => $employee->id];

    $this->postJson(route('shift-planner.copy-week'), $payload + ['preview' => true])->assertOk()->assertJsonPath('create', 1)->assertJsonPath('overwrite', 1);
    expect(ShiftOverride::count())->toBe(3);

    $this->postJson(route('shift-planner.copy-week'), $payload)->assertOk();
    expect(ShiftOverride::count())->toBe(4)
        ->and(ShiftOverride::where('override_date', $nextMonday->copy()->addDay()->toDateString())->first()->notes)->toContain('Copiado de la semana');

    $this->postJson(route('shift-planner.copy-week'), ['from_start' => $nextMonday->toDateString(), 'to_start' => $monday->copy()->subWeeks(4)->toDateString(), 'employee_id' => $employee->id, 'preview' => true])
        ->assertOk()->assertJsonPath('skipped.past', 2);

    $this->postJson(route('shift-planner.copy-week'), ['from_start' => $monday->toDateString(), 'to_start' => $monday->toDateString()])->assertStatus(422);

    ShiftTemplate::whereKey($shift->id)->update(['is_active' => false]);
    $this->postJson(route('shift-planner.copy-week'), $payload + ['preview' => true])->assertOk()->assertJsonPath('skipped.inactive_shift', 2);
});

it('exporta la grilla con las horas netas y la cobertura', function () {
    [, $employee] = plannerWithPattern();
    $start = today();
    $plan = app(ShiftPlanService::class)->plan([], $start, $start->copy()->addDay());
    $export = new ShiftPlanExport($plan, $start, $start->copy()->addDay());
    $rows = $export->array();

    expect($export->headings())->toHaveCount(7)
        ->and($rows[0][0])->toContain('Pablo')
        ->and($rows[0][3])->toBe('Mañanas')
        ->and($rows[0][4])->toBe('Tarde')
        ->and($rows[0][6])->toBe('15 h');

    $this->get(route('shift-planner.export', ['start' => $start->toDateString(), 'end' => $start->copy()->addDay()->toDateString()]))->assertOk();
});

it('solo quien puede editar copia semanas', function () {
    Permission::findOrCreate('view_any_schedule', 'web');
    $reader = User::factory()->create();
    $reader->assignRole(Role::firstOrCreate(['name' => 'Lector copia', 'guard_name' => 'web']));
    $reader->givePermissionTo('view_any_schedule');
    $this->actingAs($reader);

    $this->postJson(route('shift-planner.copy-week'), ['from_start' => today()->toDateString(), 'to_start' => today()->addWeek()->toDateString()])->assertForbidden();
    $this->get(route('shift-planner.export', ['start' => today()->toDateString(), 'end' => today()->toDateString()]))->assertOk();
});
