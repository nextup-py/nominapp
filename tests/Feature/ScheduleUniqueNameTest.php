<?php

use App\Filament\Resources\ScheduleResource\Pages\CreateSchedule;
use App\Filament\Resources\ScheduleResource\Pages\EditSchedule;
use App\Filament\Resources\ScheduleResource\Pages\ListSchedules;
use App\Filament\Resources\ScheduleResource\Pages\ViewSchedule;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

it('rechaza crear un horario con un nombre que ya existe', function () {
    Schedule::create(['name' => 'Turno madrugada central', 'shift_type' => 'nocturno']);

    Livewire::test(CreateSchedule::class)
        ->fillForm(['name' => 'Turno madrugada central', 'shift_type' => 'nocturno'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);

    expect(Schedule::where('name', 'Turno madrugada central')->count())->toBe(1);
});

it('permite guardar un horario existente sin cambiar su nombre', function () {
    $schedule = Schedule::create(['name' => 'Turno mañana central', 'shift_type' => 'diurno']);

    Livewire::test(EditSchedule::class, ['record' => $schedule->getKey()])
        ->fillForm(['name' => 'Turno mañana central'])
        ->call('save')
        ->assertHasNoFormErrors(['name']);
});

it('rechaza renombrar un horario con el nombre de otro', function () {
    Schedule::create(['name' => 'Turno tarde central', 'shift_type' => 'mixto']);
    $other = Schedule::create(['name' => 'Turno noche central', 'shift_type' => 'nocturno']);

    Livewire::test(EditSchedule::class, ['record' => $other->getKey()])
        ->fillForm(['name' => 'Turno tarde central'])
        ->call('save')
        ->assertHasFormErrors(['name' => 'unique']);
});

it('sugiere el tipo de jornada según las horas de los días activos', function (array $days, ?string $expected) {
    expect(Schedule::suggestShiftType($days))->toBe($expected);
})->with([
    'oficina 09-16' => [[['is_active' => true, 'start_time' => '09:00:00', 'end_time' => '16:00:00']], 'diurno'],
    'tarde-noche con 2 h nocturnas' => [[['is_active' => true, 'start_time' => '16:00', 'end_time' => '22:00']], 'mixto'],
    'madrugada 22-07' => [[['is_active' => true, 'start_time' => '22:00', 'end_time' => '07:00']], 'nocturno'],
    'noche 20-04' => [[['is_active' => true, 'start_time' => '20:00', 'end_time' => '04:00']], 'nocturno'],
    'un día nocturno entre diurnos' => [[
        ['is_active' => true, 'start_time' => '09:00', 'end_time' => '17:00'],
        ['is_active' => true, 'start_time' => '21:00', 'end_time' => '06:00'],
    ], 'nocturno'],
    'ignora días inactivos' => [[['is_active' => false, 'start_time' => '22:00', 'end_time' => '06:00']], null],
    'sin horas completas' => [[['is_active' => true, 'start_time' => null, 'end_time' => null]], null],
]);

it('duplica un horario con sus días y descansos, sin asignaciones', function () {
    $schedule = Schedule::create(['name' => 'Oficina', 'description' => 'Base', 'shift_type' => 'diurno']);
    $day = $schedule->days()->create(['day_of_week' => 1, 'is_active' => true, 'start_time' => '09:00', 'end_time' => '16:00']);
    $day->breaks()->create(['name' => 'Almuerzo', 'start_time' => '12:00', 'end_time' => '12:30']);
    $schedule->days()->create(['day_of_week' => 7, 'is_active' => false]);

    $copy = $schedule->duplicate('Oficina 2');

    expect($copy->id)->not->toBe($schedule->id)
        ->and($copy->name)->toBe('Oficina 2')
        ->and($copy->shift_type)->toBe('diurno')
        ->and($copy->days()->count())->toBe(2)
        ->and($copy->breaks()->count())->toBe(1)
        ->and($copy->assignments()->count())->toBe(0)
        ->and($schedule->days()->count())->toBe(2);
});

it('no deja eliminar un horario con asignaciones', function () {
    $schedule = Schedule::create(['name' => 'Con gente', 'shift_type' => 'diurno']);
    $empty = Schedule::create(['name' => 'Vacio', 'shift_type' => 'diurno']);

    $company = Company::create(['name' => 'EmpDel', 'ruc' => '7800001-1', 'employer_number' => 7800001]);
    $branch = Branch::create(['name' => 'SucDel', 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Del', 'last_name' => 'Prueba', 'ci' => '7800001', 'email' => 'del@test.com', 'branch_id' => $branch->id, 'status' => 'active']);
    $employee->scheduleAssignments()->create(['schedule_id' => $schedule->id, 'valid_from' => now()->subYear(), 'valid_until' => now()->subMonth()]);

    expect($schedule->deletionBlockers())->not->toBe([]);
    expect(fn () => $schedule->delete())->toThrow(DomainException::class);
    expect(Schedule::find($schedule->id))->not->toBeNull();

    $empty->delete();
    expect(Schedule::find($empty->id))->toBeNull();
});

it('duplica desde el listado y avisa si el nombre ya existe', function () {
    $schedule = Schedule::create(['name' => 'Original', 'shift_type' => 'diurno']);
    $schedule->days()->create(['day_of_week' => 1, 'is_active' => true, 'start_time' => '08:00', 'end_time' => '16:00']);

    Livewire::test(ListSchedules::class)
        ->callTableAction('duplicate', $schedule, data: ['name' => 'Original'])
        ->assertHasTableActionErrors(['name' => 'unique']);

    Livewire::test(ListSchedules::class)
        ->callTableAction('duplicate', $schedule, data: ['name' => 'Copia nueva'])
        ->assertHasNoTableActionErrors();

    expect(Schedule::where('name', 'Copia nueva')->first()?->days()->count())->toBe(1);
});

it('duplica desde el detalle del horario', function () {
    $schedule = Schedule::create(['name' => 'Detalle', 'shift_type' => 'diurno']);
    $schedule->days()->create(['day_of_week' => 2, 'is_active' => true, 'start_time' => '08:00', 'end_time' => '16:00']);

    Livewire::test(ViewSchedule::class, ['record' => $schedule->getKey()])
        ->callAction('duplicate', data: ['name' => 'Detalle copia'])
        ->assertHasNoActionErrors();

    expect(Schedule::where('name', 'Detalle copia')->first()?->days()->count())->toBe(1);
});
