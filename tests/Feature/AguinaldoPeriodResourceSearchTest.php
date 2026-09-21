<?php

use App\Filament\Resources\AguinaldoPeriodResource\Pages\CreateAguinaldoPeriod;
use App\Filament\Resources\AguinaldoPeriodResource\Pages\ListAguinaldoPeriods;
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
it('busca la empresa por nombre o nombre comercial en el form de períodos de aguinaldo', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $field = Livewire::test(CreateAguinaldoPeriod::class)
        ->instance()
        ->form
        ->getFlatFields(withHidden: true)['company_id'];

    expect($field->getSearchColumns())->toBe(['name', 'trade_name']);
});

it('busca la empresa por nombre o nombre comercial en el filtro de tabla de períodos de aguinaldo', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $filter = Livewire::test(ListAguinaldoPeriods::class)
        ->instance()
        ->getTable()
        ->getFilter('company_id');

    expect($filter->getFormField()->getSearchColumns())->toBe(['name', 'trade_name']);
});
