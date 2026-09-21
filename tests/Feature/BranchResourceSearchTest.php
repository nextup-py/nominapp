<?php

use App\Filament\Resources\BranchResource\Pages\CreateBranch;
use App\Filament\Resources\BranchResource\Pages\ListBranches;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regresión: el select de empresa muestra nombre + nombre comercial
 * (trade_name), pero solo buscaba por 'name' (relationshipTitleAttribute),
 * por ->searchable() sin array explícito.
 */
it('busca la empresa por nombre o nombre comercial en el form de sucursales', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    // El form de sucursales incluye un campo Map (filament-google-maps) que
    // requiere una API key configurada para renderizar — sin relación con
    // este fix, solo necesario para que el form monte en el entorno de test.
    config(['filament-google-maps.keys.web_key' => 'test-key']);

    $field = Livewire::test(CreateBranch::class)
        ->instance()
        ->form
        ->getFlatFields(withHidden: true)['company_id'];

    expect($field->getSearchColumns())->toBe(['name', 'trade_name']);
});

it('busca la empresa por nombre o nombre comercial en el filtro de tabla de sucursales', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $filter = Livewire::test(ListBranches::class)
        ->instance()
        ->getTable()
        ->getFilter('company_id');

    expect($filter->getFormField()->getSearchColumns())->toBe(['name', 'trade_name']);
});
