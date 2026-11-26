<?php

use App\Exports\ScheduleAssignmentsExport;
use App\Filament\Resources\ScheduleResource\Pages\EditSchedule;
use App\Filament\Resources\ScheduleResource\Pages\ViewSchedule;
use App\Filament\Resources\ScheduleResource\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\ScheduleResource\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\ScheduleResource\RelationManagers\EmployeesRelationManager;
use App\Filament\Resources\ScheduleResource\RelationManagers\PositionsRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['audit.console' => true]);
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

/** Horario de prueba con lunes y martes laborables (08:00–17:00, con un almuerzo el lunes). */
function rmSchedule(string $name = 'Horario RM'): Schedule
{
    $schedule = Schedule::create(['name' => $name, 'shift_type' => 'diurno']);

    foreach (range(1, 7) as $dow) {
        $day = $schedule->days()->create(['day_of_week' => $dow, 'is_active' => $dow <= 2, 'start_time' => $dow <= 2 ? '08:00' : null, 'end_time' => $dow <= 2 ? '17:00' : null]);

        if ($dow === 1) {
            $day->breaks()->create(['name' => 'Almuerzo', 'start_time' => '12:00', 'end_time' => '13:00']);
        }
    }

    return $schedule->fresh();
}

/** Empleado con contrato vigente en un cargo y departamento nuevos de una empresa nueva. */
function rmEmployee(?Position $position = null): Employee
{
    static $n = 7820000;
    $n++;

    $company = Company::create(['name' => "EmpRM {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucRM {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Rm {$n}", 'ci' => (string) $n, 'email' => "rm{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active']);

    if ($position === null) {
        $department = Department::create(['name' => "DepRM {$n}", 'company_id' => $company->id]);
        $position = Position::create(['name' => "CargoRM {$n}", 'department_id' => $department->id]);
    }

    Contract::create(['employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear(), 'salary_type' => 'mensual', 'salary' => 3000000, 'payroll_type' => 'monthly', 'position_id' => $position->id, 'department_id' => $position->department_id, 'status' => 'active']);

    return $employee;
}

/** Parámetros comunes para montar una pestaña sobre el detalle del horario. */
function rmParams(Schedule $schedule): array
{
    return ['ownerRecord' => $schedule, 'pageClass' => ViewSchedule::class];
}

// ─── Historial de cambios ─────────────────────────────────────────────────────

it('el resumen de días y la comparación registran solo los días que cambiaron', function () {
    $schedule = rmSchedule();
    $before = $schedule->daysSummary();

    expect($before['Lunes'])->toBe('08:00–17:00 · Almuerzo 12:00–13:00')
        ->and($before['Martes'])->toBe('08:00–17:00')
        ->and($before['Domingo'])->toBe('Libre');

    $schedule->days()->where('day_of_week', 2)->update(['end_time' => '18:00']);
    $schedule->recordDaysAudit($before, $schedule->fresh()->daysSummary());

    $audit = Audit::where('auditable_type', Schedule::class)->where('auditable_id', $schedule->id)->latest('id')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->old_values)->toBe(['days' => ['Martes' => '08:00–17:00']])
        ->and($audit->new_values)->toBe(['days' => ['Martes' => '08:00–18:00']]);
});

it('sin cambios en los días no se registra ningún evento', function () {
    $schedule = rmSchedule();
    $count = Audit::count();

    $schedule->recordDaysAudit($schedule->daysSummary(), $schedule->daysSummary());

    expect(Audit::count())->toBe($count);
});

it('guardar el formulario registra los campos propios y los cambios de horas', function () {
    $schedule = rmSchedule();
    $count = Audit::where('auditable_type', Schedule::class)->count();

    $component = Livewire::test(EditSchedule::class, ['record' => $schedule->getKey()]);
    $days = $component->get('data.days');
    $tuesday = collect($days)->search(fn (array $day) => (int) $day['day_of_week'] === 2);

    $component
        ->set('data.name', 'Horario RM cambiado')
        ->set("data.days.{$tuesday}.end_time", '18:00:00')
        ->call('save')
        ->assertHasNoErrors();

    $audits = Audit::where('auditable_type', Schedule::class)->where('auditable_id', $schedule->id)->latest('id')->get();

    expect($audits->count())->toBeGreaterThan($count)
        ->and($audits->pluck('new_values')->map(fn ($values) => array_key_exists('name', $values))->contains(true))->toBeTrue()
        ->and($audits->pluck('new_values')->contains(fn ($values) => ($values['days']['Martes'] ?? null) === '08:00–18:00'))->toBeTrue();
});

it('la pestaña Historial de cambios lista los eventos con etiquetas en español', function () {
    $schedule = rmSchedule();
    $schedule->update(['is_active' => false]);

    $audit = Audit::where('auditable_type', Schedule::class)->where('auditable_id', $schedule->id)->where('event', 'updated')->first();
    $html = (string) $schedule->formatAuditFieldsForPresentation('new_values', $audit);

    expect($html)->toContain('Estado')->toContain('Inactivo');

    Livewire::test(AuditsRelationManager::class, rmParams($schedule))->assertCanSeeTableRecords([$audit]);
});

// ─── Cargos con este horario ──────────────────────────────────────────────────

it('la pestaña Cargos lista solo los cargos que lo tienen como horario sugerido', function () {
    $schedule = rmSchedule();
    $other = rmSchedule('Otro horario');
    $company = Company::create(['name' => 'EmpCargos', 'ruc' => '7829999-1', 'employer_number' => 7829999]);
    $department = Department::create(['name' => 'DepCargos', 'company_id' => $company->id]);
    $suggested = Position::create(['name' => 'Cargo sugerido', 'department_id' => $department->id, 'default_schedule_id' => $schedule->id]);
    $notSuggested = Position::create(['name' => 'Cargo con otro', 'department_id' => $department->id, 'default_schedule_id' => $other->id]);
    $without = Position::create(['name' => 'Cargo sin horario', 'department_id' => $department->id]);

    Livewire::test(PositionsRelationManager::class, rmParams($schedule))
        ->assertCanSeeTableRecords([$suggested])
        ->assertCanNotSeeTableRecords([$notSuggested, $without]);
});

// ─── Empleados asignados ──────────────────────────────────────────────────────

it('Empleados Asignados muestra el fin programado y filtra por cargo y departamento', function () {
    $schedule = rmSchedule();
    $a = rmEmployee();
    $b = rmEmployee();
    $a->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subMonth(), 'valid_until' => now()->addDays(10)]);
    $b->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subMonth()]);

    $positionA = $a->activeContract->position_id;
    $departmentA = $a->activeContract->department_id;

    Livewire::test(EmployeesRelationManager::class, rmParams($schedule))
        ->assertCanSeeTableRecords([$a, $b])
        ->assertSee(now()->addDays(10)->format('d/m/Y'))
        ->assertSee('Sin fecha de fin')
        ->filterTable('position_id', $positionA)
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b])
        ->resetTableFilters()
        ->filterTable('department_id', $departmentA)
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b]);
});

it('la columna Empresa solo aparece con más de una empresa activa', function () {
    $schedule = rmSchedule();
    $employee = rmEmployee();
    $employee->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subMonth()]);

    Company::query()->update(['is_active' => false]);
    Company::query()->whereKey($employee->branch->company_id)->update(['is_active' => true]);

    Livewire::test(EmployeesRelationManager::class, rmParams($schedule))->assertTableColumnHidden('branch.company.name');

    rmEmployee();
    Company::query()->update(['is_active' => true]);

    Livewire::test(EmployeesRelationManager::class, rmParams($schedule))->assertTableColumnVisible('branch.company.name');
});

it('un empleado sin cargo muestra «Sin cargo» en lugar de un guion', function () {
    $schedule = rmSchedule();
    $company = Company::create(['name' => 'EmpSinCargo', 'ruc' => '7828888-1', 'employer_number' => 7828888]);
    $branch = Branch::create(['name' => 'SucSinCargo', 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Luis', 'last_name' => 'Sincargo', 'ci' => '7828888', 'email' => 'sc@test.com', 'branch_id' => $branch->id, 'status' => 'active']);
    $employee->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subMonth()]);

    Livewire::test(EmployeesRelationManager::class, rmParams($schedule))->assertSee('Sin cargo');
});

// ─── Historial de asignaciones ────────────────────────────────────────────────

it('el historial filtra por empleado y por período, y muestra las notas', function () {
    $schedule = rmSchedule();
    $a = rmEmployee();
    $b = rmEmployee();
    $old = $a->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => '2026-01-01', 'valid_until' => '2026-03-31', 'notes' => 'Cambio de turno por pedido']);
    $recent = $b->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => '2026-09-01']);

    Livewire::test(AssignmentsRelationManager::class, rmParams($schedule))
        ->assertTableColumnVisible('notes')
        ->assertSee('Cambio de turno por pedido')
        ->filterTable('employee_id', $a->id)
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$recent])
        ->resetTableFilters()
        ->filterTable('period', ['from' => '2026-02-01', 'until' => '2026-02-28'])
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$recent])
        ->resetTableFilters()
        ->filterTable('period', ['from' => '2026-10-01', 'until' => null])
        ->assertCanSeeTableRecords([$recent])
        ->assertCanNotSeeTableRecords([$old]);
});

it('la exportación del historial respeta los ids y mapea cada asignación', function () {
    $schedule = rmSchedule();
    $a = rmEmployee();
    $b = rmEmployee();
    $first = $a->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => '2026-01-01', 'valid_until' => '2026-03-31', 'notes' => 'Nota']);
    $b->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => '2026-09-01']);

    $export = new ScheduleAssignmentsExport($schedule->id, [$first->id]);
    $rows = $export->query()->get();

    expect($rows)->toHaveCount(1)
        ->and($export->headings())->toBe(['Apellido', 'Nombre', 'CI', 'Estado', 'Desde', 'Hasta', 'Asignado por', 'Notas'])
        ->and($export->map($rows->first()))->toBe([$a->last_name, 'Ana', $a->ci, 'Finalizada', '01/01/2026', '31/03/2026', 'Sin dato', 'Nota'])
        ->and((new ScheduleAssignmentsExport($schedule->id))->query()->count())->toBe(2)
        ->and(EmployeeScheduleAssignment::getStatusLabels())->toHaveKey('ended');
});
