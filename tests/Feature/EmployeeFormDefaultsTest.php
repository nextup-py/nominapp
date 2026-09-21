<?php

use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regresión/mejora: con una sola empresa/sucursal activa, el alta de
 * empleado preselecciona ambas — evita el clic inútil de elegir la única
 * opción disponible en cada alta (mismo patrón ya usado para ocultar
 * columnas cuando Company::active()->count() <= 1).
 */
it('preselecciona empresa y sucursal cuando solo hay una activa', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = Company::create(['name' => 'Única S.A.', 'ruc' => '1-1', 'employer_number' => 1, 'is_active' => true]);
    $branch = Branch::create(['name' => 'Casa Central', 'company_id' => $company->id]);

    Livewire::test(CreateEmployee::class)
        ->assertFormSet([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ]);
});

it('no preselecciona empresa cuando hay más de una activa', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    Company::create(['name' => 'Una S.A.', 'ruc' => '1-1', 'employer_number' => 1, 'is_active' => true]);
    Company::create(['name' => 'Otra S.A.', 'ruc' => '2-2', 'employer_number' => 2, 'is_active' => true]);

    Livewire::test(CreateEmployee::class)
        ->assertFormSet(['company_id' => null]);
});

it('no preselecciona sucursal cuando la empresa tiene más de una', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = Company::create(['name' => 'Única S.A.', 'ruc' => '1-1', 'employer_number' => 1, 'is_active' => true]);
    Branch::create(['name' => 'Casa Central', 'company_id' => $company->id]);
    Branch::create(['name' => 'Sucursal 2', 'company_id' => $company->id]);

    Livewire::test(CreateEmployee::class)
        ->assertFormSet(['branch_id' => null]);
});

/**
 * Regresión/mejora: el aviso de CI duplicado aparece en vivo (al perder
 * foco el campo), no recién al hacer submit de todo el formulario.
 */
it('avisa en vivo si la CI ya está en uso por otro empleado', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = Company::create(['name' => 'Empresa Hint', 'ruc' => '9-9', 'employer_number' => 9]);
    $branch = Branch::create(['name' => 'Sucursal Hint', 'company_id' => $company->id]);
    Employee::create(['first_name' => 'Existente', 'last_name' => 'X', 'ci' => '5551234', 'branch_id' => $branch->id, 'status' => 'active']);

    $field = Livewire::test(CreateEmployee::class)
        ->set('data.ci', '5551234')
        ->instance()
        ->form
        ->getFlatFields(withHidden: true)['ci'];

    expect($field->getHint())->toBe('Ya existe un empleado con esta CI');
});

it('no avisa si la CI no está en uso', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $field = Livewire::test(CreateEmployee::class)
        ->set('data.ci', '9999999')
        ->instance()
        ->form
        ->getFlatFields(withHidden: true)['ci'];

    expect($field->getHint())->toBeNull();
});

/**
 * Regresión: el departamento/cargo del "Contrato Inicial" mostraba TODOS
 * los departamentos de TODAS las empresas, a diferencia de
 * ContractsRelationManager (edición) que sí filtra por la empresa del
 * empleado — en un cliente multiempresa se podía elegir el departamento
 * equivocado al crear.
 */
it('el departamento del contrato inicial se filtra por la empresa de la sucursal elegida', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $companyA = Company::create(['name' => 'Empresa A', 'ruc' => '1-1', 'employer_number' => 1]);
    $companyB = Company::create(['name' => 'Empresa B', 'ruc' => '2-2', 'employer_number' => 2]);
    $branchA = Branch::create(['name' => 'Sucursal A', 'company_id' => $companyA->id]);
    Branch::create(['name' => 'Sucursal B', 'company_id' => $companyB->id]);

    $deptA = Department::create(['name' => 'Depto A', 'company_id' => $companyA->id]);
    $deptB = Department::create(['name' => 'Depto B', 'company_id' => $companyB->id]);

    $field = Livewire::test(CreateEmployee::class)
        ->set('data.branch_id', $branchA->id)
        ->instance()
        ->form
        ->getFlatFields(withHidden: true)['ic_department_id'];

    expect($field->getOptions())->toHaveKey($deptA->id)
        ->not->toHaveKey($deptB->id);
});

it('el cargo del contrato inicial se filtra por la empresa aunque no haya departamento elegido', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $companyA = Company::create(['name' => 'Empresa A', 'ruc' => '1-1', 'employer_number' => 1]);
    $companyB = Company::create(['name' => 'Empresa B', 'ruc' => '2-2', 'employer_number' => 2]);
    $branchA = Branch::create(['name' => 'Sucursal A', 'company_id' => $companyA->id]);

    $deptA = Department::create(['name' => 'Depto A', 'company_id' => $companyA->id]);
    $deptB = Department::create(['name' => 'Depto B', 'company_id' => $companyB->id]);
    $positionA = Position::create(['name' => 'Cargo A', 'department_id' => $deptA->id]);
    $positionB = Position::create(['name' => 'Cargo B', 'department_id' => $deptB->id]);

    $field = Livewire::test(CreateEmployee::class)
        ->set('data.branch_id', $branchA->id)
        ->instance()
        ->form
        ->getFlatFields(withHidden: true)['ic_position_id'];

    expect($field->getOptions())->toHaveKey($positionA->id)
        ->not->toHaveKey($positionB->id);
});
