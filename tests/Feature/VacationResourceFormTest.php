<?php

use App\Filament\Resources\VacationResource\Pages\CreateVacation;
use App\Filament\Resources\VacationResource\Pages\ListVacations;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regresión: el selector de empleado en el form de Vacaciones usaba 'id' como
 * relationshipTitleAttribute sin un ->searchable([...]) explícito, por lo que
 * Filament buscaba contra la columna `id` en vez del nombre — un empleado
 * activo no aparecía al buscarlo por nombre, solo escribiendo su ID numérico.
 */
it('busca el empleado por nombre, apellido o CI en el select de vacaciones, no solo por id', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $field = Livewire::test(CreateVacation::class)
        ->instance()
        ->form
        ->getFlatFields(withHidden: true)['employee_id'];

    expect($field->getSearchColumns())->toBe(['first_name', 'last_name', 'ci']);
});

/**
 * Regresión: el mismo bug existía en el filtro de tabla employee_id
 * (->relationship('employee', 'first_name') + ->searchable() sin array),
 * pero el label mostrado (full_name + CI) no era buscable con el fallback.
 */
it('busca el empleado por nombre, apellido o CI en el filtro de tabla de vacaciones', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $filter = Livewire::test(ListVacations::class)
        ->instance()
        ->getTable()
        ->getFilter('employee_id');

    expect($filter->getFormField()->getSearchColumns())->toBe(['first_name', 'last_name', 'ci']);
});
