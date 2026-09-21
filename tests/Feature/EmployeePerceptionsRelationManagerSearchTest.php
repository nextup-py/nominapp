<?php

use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\EmployeeResource\RelationManagers\EmployeePerceptionsRelationManager;
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
 * Regresión: el select de percepción se creaba con ->relationship('perception', ...)
 * sin titleAttribute — Filament no tenía columna de referencia para buscar y el
 * buscador quedaba completamente inerte (no filtraba nada, ni siquiera fallaba).
 */
it('busca la percepción por nombre o código en el RelationManager de percepciones del empleado', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = Company::create(['name' => 'Empresa Perc', 'ruc' => '9100000-1', 'employer_number' => 9100000]);
    $branch = Branch::create(['name' => 'Sucursal Perc', 'company_id' => $company->id]);
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);

    $instance = Livewire::test(EmployeePerceptionsRelationManager::class, [
        'ownerRecord' => $employee,
        'pageClass' => EditEmployee::class,
    ])->instance();

    $field = $instance->form(Form::make($instance))
        ->getFlatFields(withHidden: true)['perception_id'];

    expect($field->getSearchColumns())->toBe(['name', 'code']);
});
