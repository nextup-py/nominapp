<?php

use App\Filament\Resources\DepartmentResource\Pages\CreateDepartment;
use App\Filament\Resources\DepartmentResource\Pages\EditDepartment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

/** Departamento con un cargo y, opcionalmente, un contrato. */
function departmentInUse(Company $company, bool $withContract = false): Department
{
    $department = Department::create(['company_id' => $company->id, 'name' => 'Ventas']);
    $position = Position::create(['name' => 'Vendedor', 'department_id' => $department->id]);

    if ($withContract) {
        $branch = Branch::create(['name' => 'Central', 'company_id' => $company->id]);
        $employee = Employee::factory()->create(['branch_id' => $branch->id]);

        Contract::create([
            'position_id' => $position->id,
            'department_id' => $department->id,
            'employee_id' => $employee->id,
            'type' => 'indefinido',
            'start_date' => now()->subYear(),
            'salary_type' => 'mensual',
            'salary' => 3000000,
            'payroll_type' => 'monthly',
            'status' => 'active',
        ]);
    }

    return $department;
}

it('con una sola empresa activa oculta el campo Empresa y la asigna al crear', function () {
    $company = Company::factory()->create();

    Livewire::test(CreateDepartment::class)
        ->assertFormFieldIsHidden('company_id')
        ->fillForm(['name' => 'Finanzas'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Department::where('name', 'Finanzas')->value('company_id'))->toBe($company->id);

    Company::factory()->create();

    Livewire::test(CreateDepartment::class)->assertFormFieldIsVisible('company_id');
});

it('el nombre y el centro de costo repetidos muestran un mensaje propio', function () {
    $company = Company::factory()->create();
    Department::create(['company_id' => $company->id, 'name' => 'Finanzas', 'cost_center' => 'FIN-1']);

    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Finanzas', 'cost_center' => 'FIN-1'])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'Ya existe un departamento con este nombre en la empresa.',
            'cost_center' => 'Ya existe un departamento con este centro de costo en la empresa.',
        ]);
});

it('el título de la edición lleva el nombre del departamento', function () {
    $department = departmentInUse(Company::factory()->create());

    Livewire::test(EditDepartment::class, ['record' => $department->getKey()])
        ->assertSee('Editar: Ventas');
});

it('calcula qué impide eliminar el departamento', function () {
    $company = Company::factory()->create();

    expect(Department::create(['company_id' => $company->id, 'name' => 'Vacío'])->deletionBlockers())->toBe([])
        ->and(departmentInUse($company)->deletionBlockersSummary())->toBe('1 cargo');

    $other = Company::factory()->create();

    expect(departmentInUse($other, withContract: true)->deletionBlockersSummary())->toBe('1 cargo y 1 contrato');
});

it('no se puede eliminar un departamento con cargos o contratos, ni desde la acción ni desde el modelo', function () {
    $department = departmentInUse(Company::factory()->create(), withContract: true);

    Livewire::test(EditDepartment::class, ['record' => $department->getKey()])
        ->assertActionHidden(DeleteAction::class);

    expect(Department::find($department->id))->not->toBeNull();

    expect(fn () => $department->delete())->toThrow(DomainException::class);
});

it('elimina un departamento vacío', function () {
    $department = Department::create(['company_id' => Company::factory()->create()->id, 'name' => 'Vacío']);

    Livewire::test(EditDepartment::class, ['record' => $department->getKey()])
        ->callAction(DeleteAction::class);

    expect(Department::find($department->id))->toBeNull();
});

it('la empresa no se puede cambiar si el departamento ya tiene cargos o contratos', function () {
    Company::factory()->create();
    $used = departmentInUse(Company::factory()->create());
    $empty = Department::create(['company_id' => Company::factory()->create()->id, 'name' => 'Vacío']);

    Livewire::test(EditDepartment::class, ['record' => $used->getKey()])
        ->assertFormFieldIsDisabled('company_id');

    Livewire::test(EditDepartment::class, ['record' => $empty->getKey()])
        ->assertFormFieldIsEnabled('company_id');
});
