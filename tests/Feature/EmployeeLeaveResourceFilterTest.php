<?php

use App\Filament\Resources\EmployeeLeaveResource\Pages\ListEmployeeLeaves;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regresión: el filtro de empleado mostraba nombre completo pero solo
 * buscaba por 'first_name' (relationshipTitleAttribute), por ->searchable()
 * sin array explícito.
 */
it('busca el empleado por nombre o apellido en el filtro de tabla de permisos y licencias', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $filter = Livewire::test(ListEmployeeLeaves::class)
        ->instance()
        ->getTable()
        ->getFilter('employee_id');

    expect($filter->getFormField()->getSearchColumns())->toBe(['first_name', 'last_name']);
});

it('el formulario de licencias oculta el estado al crear', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $this->actingAs($user);

    Livewire::test(\App\Filament\Resources\EmployeeLeaveResource\Pages\CreateEmployeeLeaves::class)
        ->assertFormFieldIsHidden('status')
        ->assertFormFieldExists('employee_id');
});
