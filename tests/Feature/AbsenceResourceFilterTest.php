<?php

use App\Filament\Resources\AbsenceResource\Pages\ListAbsences;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regresión: el filtro de empleado mostraba nombre completo+CI pero solo
 * buscaba por 'first_name' (relationshipTitleAttribute), por ->searchable()
 * sin array explícito.
 */
it('busca el empleado por nombre, apellido o CI en el filtro de tabla de ausencias', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $filter = Livewire::test(ListAbsences::class)
        ->instance()
        ->getTable()
        ->getFilter('employee_id');

    expect($filter->getFormField()->getSearchColumns())->toBe(['first_name', 'last_name', 'ci']);
});
