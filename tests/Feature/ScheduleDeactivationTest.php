<?php

use App\Filament\Resources\ScheduleResource\Pages\EditSchedule;
use App\Filament\Resources\ScheduleResource\Pages\ListSchedules;
use App\Filament\Resources\ScheduleResource\Pages\ViewSchedule;
use App\Filament\Resources\ScheduleResource\RelationManagers\AssignmentsRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ScheduleAssignmentService;
use Carbon\Carbon;
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

it('el listado oculta los inactivos por defecto y la acción Desactivar funciona', function () {
    $active = Schedule::create(['name' => 'Activo uno', 'shift_type' => 'diurno']);
    $inactive = Schedule::create(['name' => 'Inactivo uno', 'shift_type' => 'diurno', 'is_active' => false]);

    Livewire::test(ListSchedules::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive])
        ->callTableAction('toggle_status', $active);

    expect($active->fresh()->is_active)->toBeFalse();

    Livewire::test(ListSchedules::class)
        ->removeTableFilter('is_active')
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
