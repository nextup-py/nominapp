<?php

use App\Filament\Pages\ShiftPlannerPage;
use App\Filament\Resources\ShiftTemplateResource\Pages\ViewShiftTemplate;
use App\Filament\Resources\ShiftTemplateResource\RelationManagers\OverridesRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\RotationPattern;
use App\Models\ShiftOverride;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\RotationService;
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
