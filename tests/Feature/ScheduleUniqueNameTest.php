<?php

use App\Filament\Resources\ScheduleResource\Pages\CreateSchedule;
use App\Filament\Resources\ScheduleResource\Pages\EditSchedule;
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
