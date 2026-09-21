<?php

use App\Filament\Resources\AguinaldoPeriodResource\Pages\ViewAguinaldoPeriod;
use App\Filament\Resources\AguinaldoPeriodResource\RelationManagers\AguinaldosRelationManager;
use App\Models\AguinaldoPeriod;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regresión: el filtro de empleado mostraba el nombre completo pero solo
 * buscaba por 'first_name' (relationshipTitleAttribute), por ->searchable()
 * sin array explícito.
 */
it('busca el empleado por nombre o apellido en el filtro del RelationManager de aguinaldos del período', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = Company::create([
        'name' => 'Empresa Test Agu',
        'ruc' => '9000000-1',
        'employer_number' => 9000000,
    ]);

    $period = AguinaldoPeriod::create([
        'company_id' => $company->id,
        'year' => 2025,
        'status' => 'draft',
    ]);

    $filter = Livewire::test(AguinaldosRelationManager::class, [
        'ownerRecord' => $period,
        'pageClass' => ViewAguinaldoPeriod::class,
    ])
        ->instance()
        ->getTable()
        ->getFilter('employee_id');

    expect($filter->getFormField()->getSearchColumns())->toBe(['first_name', 'last_name']);
});
