<?php

use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\EmployeeResource\RelationManagers\EmployeeDeductionsRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Filament\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regresión: el select de deducción se creaba con ->relationship('deduction', ...)
 * sin titleAttribute — Filament no tenía columna de referencia para buscar y el
 * buscador quedaba completamente inerte (no filtraba nada, ni siquiera fallaba).
 */
it('busca la deducción por nombre o código en el RelationManager de deducciones del empleado', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = Company::create(['name' => 'Empresa Ded', 'ruc' => '9200000-1', 'employer_number' => 9200000]);
    $branch = Branch::create(['name' => 'Sucursal Ded', 'company_id' => $company->id]);
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);

    $instance = Livewire::test(EmployeeDeductionsRelationManager::class, [
        'ownerRecord' => $employee,
        'pageClass' => EditEmployee::class,
    ])->instance();

    $field = $instance->form(Form::make($instance))
        ->getFlatFields(withHidden: true)['deduction_id'];

    expect($field->getSearchColumns())->toBe(['name', 'code']);
});
