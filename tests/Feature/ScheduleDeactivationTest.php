<?php

use App\Exports\SchedulesExport;
use App\Filament\Resources\ScheduleResource;
use App\Filament\Resources\ScheduleResource\Pages\EditSchedule;
use App\Filament\Resources\ScheduleResource\Pages\ListSchedules;
use App\Filament\Resources\ScheduleResource\Pages\ViewSchedule;
use App\Filament\Resources\ScheduleResource\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\ScheduleResource\RelationManagers\EmployeesRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
use Filament\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

/** Empleado de prueba con CI y correo únicos por llamada. */
function deactivationScheduleEmployee(): Employee
{
    static $n = 7810000;
    $n++;

    $company = Company::create(['name' => "EmpDes {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucDes {$n}", 'company_id' => $company->id]);

    return Employee::create(['first_name' => 'Ana', 'last_name' => "Horario {$n}", 'ci' => (string) $n, 'email' => "des{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active']);
}

it('un horario nuevo nace activo', function () {
    $schedule = Schedule::create(['name' => 'Nuevo', 'shift_type' => 'diurno']);

    expect($schedule->fresh()->is_active)->toBeTrue()
        ->and(Schedule::query()->active()->whereKey($schedule->id)->exists())->toBeTrue();
});

it('con solo asignaciones finalizadas se puede desactivar pero no eliminar, y el aviso lo dice', function () {
    $schedule = Schedule::create(['name' => 'Noche vieja', 'shift_type' => 'nocturno']);
    deactivationScheduleEmployee()->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subYear(), 'valid_until' => now()->subDay()]);

    expect($schedule->currentAssignmentsCount())->toBe(0)
        ->and($schedule->endedAssignmentsCount())->toBe(1)
        ->and($schedule->deactivationBlockers())->toBe([])
        ->and($schedule->deletionBlockers())->not->toBe([])
        ->and($schedule->deletionBlockersSummary())->toContain('desactivar el horario');

    $schedule->update(['is_active' => false]);

    expect($schedule->fresh()->is_active)->toBeFalse()
        ->and(Schedule::find($schedule->id))->not->toBeNull();
});

it('no se puede desactivar con asignaciones vigentes o futuras', function (?string $from, ?string $until) {
    $schedule = Schedule::create(['name' => 'En uso '.uniqid(), 'shift_type' => 'diurno']);
    deactivationScheduleEmployee()->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => $from ?? now()->subMonth(), 'valid_until' => $until]);

    expect($schedule->deactivationBlockers())->not->toBe([])
        ->and(fn () => $schedule->update(['is_active' => false]))->toThrow(DomainException::class)
        ->and($schedule->fresh()->is_active)->toBeTrue();
})->with([
    'vigente sin fin' => [null, null],
    'futura' => ['tomorrow', null],
]);

it('no se puede desactivar con empleados que lo tienen como horario fijo', function () {
    $schedule = Schedule::create(['name' => 'Fijo', 'shift_type' => 'diurno']);
    $employee = deactivationScheduleEmployee();
    $employee->forceFill(['schedule_id' => $schedule->id])->save();

    expect(fn () => $schedule->update(['is_active' => false]))->toThrow(DomainException::class);
});

it('el estado de una asignación sale de sus fechas', function () {
    $schedule = Schedule::create(['name' => 'Estados', 'shift_type' => 'diurno']);
    $employee = deactivationScheduleEmployee();
    $make = fn ($from, $until) => $employee->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => $from, 'valid_until' => $until]);

    expect($make(now()->subYear(), now()->subDay())->status())->toBe(EmployeeScheduleAssignment::STATUS_ENDED)
        ->and($make(now()->subMonth(), null)->status())->toBe(EmployeeScheduleAssignment::STATUS_ACTIVE)
        ->and($make(now()->addDay(), null)->status())->toBe(EmployeeScheduleAssignment::STATUS_UPCOMING);
});

it('el listado abre en Activos, la pestaña Inactivos los muestra y la acción Desactivar funciona', function () {
    $active = Schedule::create(['name' => 'Activo uno', 'shift_type' => 'diurno']);
    $inactive = Schedule::create(['name' => 'Inactivo uno', 'shift_type' => 'diurno', 'is_active' => false]);

    Livewire::test(ListSchedules::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive])
        ->callTableAction('toggle_status', $active);

    expect($active->fresh()->is_active)->toBeFalse();

    Livewire::test(ListSchedules::class)
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$active, $inactive])
        ->callTableAction('toggle_status', $inactive);

    expect($inactive->fresh()->is_active)->toBeTrue();
});

it('Desactivar avisa y no cambia nada si el horario tiene asignaciones vigentes', function () {
    $schedule = Schedule::create(['name' => 'Ocupado', 'shift_type' => 'diurno']);
    deactivationScheduleEmployee()->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subMonth()]);

    Livewire::test(ViewSchedule::class, ['record' => $schedule->id])->callAction('toggle_status');

    expect($schedule->fresh()->is_active)->toBeTrue();
});

it('el formulario de edición deshabilita el interruptor con asignaciones vigentes', function () {
    $schedule = Schedule::create(['name' => 'Ocupado 2', 'shift_type' => 'diurno']);
    deactivationScheduleEmployee()->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subMonth()]);

    Livewire::test(EditSchedule::class, ['record' => $schedule->id])
        ->assertFormFieldIsDisabled('is_active');

    $free = Schedule::create(['name' => 'Libre', 'shift_type' => 'diurno']);

    Livewire::test(EditSchedule::class, ['record' => $free->id])
        ->assertFormFieldIsEnabled('is_active');
});

it('la pestaña de historial muestra las asignaciones finalizadas y filtra por estado', function () {
    $schedule = Schedule::create(['name' => 'Con historial', 'shift_type' => 'diurno']);
    $ended = deactivationScheduleEmployee()->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subYear(), 'valid_until' => now()->subMonth()]);
    $current = deactivationScheduleEmployee()->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subWeek()]);

    Livewire::test(AssignmentsRelationManager::class, ['ownerRecord' => $schedule, 'pageClass' => ViewSchedule::class])
        ->assertCanSeeTableRecords([$ended, $current])
        ->filterTable('status', EmployeeScheduleAssignment::STATUS_ENDED)
        ->assertCanSeeTableRecords([$ended])
        ->assertCanNotSeeTableRecords([$current]);
});

it('los horarios inactivos no se ofrecen para asignar, salvo el ya asignado', function () {
    $inactive = Schedule::create(['name' => 'Inactivo sugerido', 'shift_type' => 'diurno', 'is_active' => false]);
    $active = Schedule::create(['name' => 'Activo sugerido', 'shift_type' => 'diurno']);

    expect(Schedule::query()->assignable()->pluck('id')->all())->toContain($active->id)->not->toContain($inactive->id)
        ->and(Schedule::query()->assignable($inactive->id)->pluck('id')->all())->toContain($inactive->id);
});

it('oculta Eliminar en un horario con historial y lo muestra en uno sin uso', function () {
    $used = Schedule::create(['name' => 'Con historial', 'shift_type' => 'diurno']);
    $employee = deactivationScheduleEmployee();
    EmployeeScheduleAssignment::create([
        'employee_id' => $employee->id,
        'schedule_id' => $used->id,
        'valid_from' => now()->subDays(10)->toDateString(),
        'valid_until' => now()->subDays(5)->toDateString(),
    ]);
    $unused = Schedule::create(['name' => 'Sin uso', 'shift_type' => 'diurno']);

    Livewire::test(EditSchedule::class, ['record' => $used->getKey()])->assertActionHidden('delete');
    Livewire::test(EditSchedule::class, ['record' => $unused->getKey()])->assertActionVisible('delete');
});

it('el aviso de bloqueo de un horario concuerda en singular', function () {
    $schedule = Schedule::create(['name' => 'Una asignación', 'shift_type' => 'diurno']);
    EmployeeScheduleAssignment::create([
        'employee_id' => deactivationScheduleEmployee()->id,
        'schedule_id' => $schedule->id,
        'valid_from' => now()->subDays(10)->toDateString(),
        'valid_until' => now()->subDays(5)->toDateString(),
    ]);

    expect($schedule->deletionBlockersSummary())->toContain('1 asignación de empleado (vigente o finalizada)');
});

it('una asignación que termina hoy, como la que deja Remover, no impide desactivar', function () {
    $schedule = Schedule::create(['name' => 'Madrugada', 'shift_type' => 'nocturno']);
    $employee = deactivationScheduleEmployee();
    $employee->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subMonths(2), 'valid_until' => null]);

    expect($schedule->deactivationBlockers())->not->toBe([]);

    ScheduleAssignmentService::closeActive($employee, Carbon::today());

    expect($schedule->fresh()->deactivationBlockers())->toBe([])
        ->and($schedule->fresh()->update(['is_active' => false]))->toBeTrue()
        ->and($schedule->fresh()->is_active)->toBeFalse();
});

it('Remover con hoy saca a la persona de Empleados Asignados y la deja en el historial', function () {
    $schedule = Schedule::create(['name' => 'Madrugada', 'shift_type' => 'nocturno']);
    $employee = deactivationScheduleEmployee();
    $assignment = $employee->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subMonths(2)]);
    $rm = ['ownerRecord' => $schedule, 'pageClass' => ViewSchedule::class];

    Livewire::test(EmployeesRelationManager::class, $rm)
        ->assertCanSeeTableRecords([$employee])
        ->callTableAction('remove', $employee, ['valid_until' => today()->toDateString()])
        ->assertHasNoTableActionErrors()
        ->assertCanNotSeeTableRecords([$employee]);

    expect($assignment->fresh()->valid_until->toDateString())->toBe(today()->toDateString())
        ->and($schedule->fresh()->deactivationBlockers())->toBe([]);

    Livewire::test(AssignmentsRelationManager::class, $rm)->assertCanSeeTableRecords([$assignment]);
});

it('Remover con una fecha futura programa el cambio y la persona sigue en la lista', function () {
    $schedule = Schedule::create(['name' => 'Programado', 'shift_type' => 'diurno']);
    $employee = deactivationScheduleEmployee();
    $assignment = $employee->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subMonth()]);
    $until = today()->addDays(5)->toDateString();

    Livewire::test(EmployeesRelationManager::class, ['ownerRecord' => $schedule, 'pageClass' => ViewSchedule::class])
        ->callTableAction('remove', $employee, ['valid_until' => $until])
        ->assertCanSeeTableRecords([$employee]);

    expect($assignment->fresh()->valid_until->toDateString())->toBe($until);
});

it('closeActive adelanta una asignación con fin posterior pero no alarga una ya cerrada antes', function () {
    $schedule = Schedule::create(['name' => 'Cierre', 'shift_type' => 'diurno']);
    $employee = deactivationScheduleEmployee();
    $later = $employee->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subMonth(), 'valid_until' => now()->addMonth()]);

    expect(ScheduleAssignmentService::closeActive($employee, Carbon::today()))->toBe(1)
        ->and($later->fresh()->valid_until->toDateString())->toBe(today()->toDateString())
        ->and(ScheduleAssignmentService::closeActive($employee, Carbon::today()->addDays(3)))->toBe(0)
        ->and($later->fresh()->valid_until->toDateString())->toBe(today()->toDateString());
});

it('Remover seleccionados cierra varias asignaciones con la fecha elegida', function () {
    $schedule = Schedule::create(['name' => 'Masivo', 'shift_type' => 'diurno']);
    $a = deactivationScheduleEmployee();
    $b = deactivationScheduleEmployee();
    foreach ([$a, $b] as $e) {
        $e->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subMonth()]);
    }

    Livewire::test(EmployeesRelationManager::class, ['ownerRecord' => $schedule, 'pageClass' => ViewSchedule::class])
        ->callTableBulkAction('remove', [$a, $b], ['valid_until' => today()->toDateString()])
        ->assertCanNotSeeTableRecords([$a, $b]);

    expect($schedule->fresh()->currentAssignmentsCount())->toBe(0);
});

/** Horario de prueba con días activos (lunes a sábado 23:00–07:00 con un descanso) y el domingo libre. */
function nightScheduleForView(): Schedule
{
    $schedule = Schedule::create(['name' => 'Madrugada vista', 'shift_type' => 'nocturno']);

    foreach (range(1, 7) as $dow) {
        $day = $schedule->days()->create(['day_of_week' => $dow, 'is_active' => $dow <= 6, 'start_time' => '23:00', 'end_time' => '07:00']);

        if ($dow === 1) {
            $day->breaks()->create(['name' => 'Cena', 'start_time' => '02:00', 'end_time' => '03:00']);
        }
    }

    return $schedule->fresh();
}

it('el resumen semanal cuenta días con turno y horas netas', function () {
    $schedule = nightScheduleForView()->load('days.breaks');

    expect($schedule->weeklySummary())->toBe(['days' => 6, 'hours' => 47.0])
        ->and($schedule->weeklySummaryLabel())->toBe('6 días · 47 h por semana')
        ->and($schedule->crossesMidnight())->toBeTrue();

    $diurno = Schedule::create(['name' => 'Diurno vista', 'shift_type' => 'diurno']);
    $diurno->days()->create(['day_of_week' => 1, 'is_active' => true, 'start_time' => '08:00', 'end_time' => '12:00']);

    expect($diurno->fresh()->load('days.breaks')->crossesMidnight())->toBeFalse()
        ->and($diurno->fresh()->load('days.breaks')->weeklySummaryLabel())->toBe('1 día · 4 h por semana')
        ->and(Schedule::create(['name' => 'Vacío', 'shift_type' => 'diurno'])->load('days.breaks')->weeklySummaryLabel())->toBe('Sin días con turno');
});

it('la vista del horario muestra el resumen, la tabla de días y el cruce de medianoche', function () {
    $schedule = nightScheduleForView();
    deactivationScheduleEmployee()->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subWeek()]);

    Livewire::test(ViewSchedule::class, ['record' => $schedule->getKey()])
        ->assertSee('6 días · 47 h por semana')
        ->assertSee('1 empleado')
        ->assertSee('Cruza medianoche')
        ->assertSee('Cena 02:00–03:00')
        ->assertSee('Libre')
        ->assertSee('(día siguiente)');
});

it('un horario sin empleados lo dice en el resumen', function () {
    $schedule = Schedule::create(['name' => 'Sin gente', 'shift_type' => 'diurno']);

    Livewire::test(ViewSchedule::class, ['record' => $schedule->getKey()])
        ->assertSee('Sin empleados asignados');
});

it('Duplicar y Desactivar van en «Más acciones» y Editar queda a la vista', function () {
    $schedule = nightScheduleForView();

    $component = Livewire::test(ViewSchedule::class, ['record' => $schedule->getKey()]);
    $headerActions = $component->instance()->getCachedHeaderActions();

    $group = collect($headerActions)->first(fn ($action) => $action instanceof ActionGroup);
    expect($group)->not->toBeNull()
        ->and($group->getLabel())->toBe('Más acciones')
        ->and(collect($group->getFlatActions())->keys()->all())->toBe(['duplicate', 'toggle_status'])
        ->and(collect($headerActions)->reject(fn ($action) => $action instanceof ActionGroup)->map->getName()->values()->all())->toBe(['edit']);
});

it('el listado muestra el resumen semanal y la marca de cruce de medianoche', function () {
    $schedule = Schedule::create(['name' => 'Cena', 'shift_type' => 'nocturno']);
    $schedule->days()->create(['day_of_week' => 1, 'is_active' => true, 'start_time' => '23:00', 'end_time' => '07:00']);

    Livewire::test(ListSchedules::class)
        ->assertCanSeeTableRecords([$schedule])
        ->assertSee('1 día · 8 h por semana')
        ->assertSee('Cruza la medianoche');
});

it('las pestañas del listado cuentan activos e inactivos', function () {
    Schedule::create(['name' => 'Act A', 'shift_type' => 'diurno']);
    Schedule::create(['name' => 'Inact A', 'shift_type' => 'diurno', 'is_active' => false]);
    $inactive = Schedule::where('name', 'Inact A')->first();

    Livewire::test(ListSchedules::class)
        ->assertSet('activeTab', 'active')
        ->set('activeTab', 'inactive')
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords(Schedule::where('is_active', true)->get());
});

it('Exportar incluye solo los horarios del listado filtrado', function () {
    $active = Schedule::create(['name' => 'Exp activo', 'shift_type' => 'diurno']);
    $inactive = Schedule::create(['name' => 'Exp inactivo', 'shift_type' => 'diurno', 'is_active' => false]);

    $rows = (new SchedulesExport([$active->id]))->query()->pluck('name')->all();

    expect($rows)->toBe(['Exp activo'])
        ->and((new SchedulesExport)->query()->pluck('name')->all())->toContain('Exp inactivo');
});

it('Asignar a Empleados ofrece empleados sin emoji y con el horario actual indicado', function () {
    $schedule = Schedule::create(['name' => 'Nuevo', 'shift_type' => 'diurno']);
    $other = Schedule::create(['name' => 'Actual', 'shift_type' => 'diurno']);
    $employee = deactivationScheduleEmployee();
    $employee->scheduleAssignments()->create(['schedule_id' => $other->id, 'valid_from' => now()->subMonth()]);

    $options = ScheduleResource::assignableEmployees($schedule->id);

    expect($options)->toHaveKey($employee->id)
        ->and($options[$employee->id])->toContain('hoy: Actual')->not->toContain('⚠')
        ->and(ScheduleResource::assignableEmployees($other->id))->not->toHaveKey($employee->id);
});
