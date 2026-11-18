<?php

use App\Filament\Resources\DepartmentResource\Pages\EditDepartment;
use App\Filament\Resources\DepartmentResource\RelationManagers\PositionsRelationManager;
use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Filament\Resources\PositionResource\Pages\EditPosition;
use App\Filament\Resources\PositionResource\Pages\ListPositions;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

/** Departamento nuevo de la empresa dada. */
function positionDepartment(Company $company, string $name = 'Ventas'): Department
{
    return Department::create(['company_id' => $company->id, 'name' => $name]);
}

/** Cargo con un contrato vigente. */
function positionWithContract(Department $department, string $name = 'Vendedor'): Position
{
    $position = Position::create(['name' => $name, 'department_id' => $department->id]);
    $branch = Branch::create(['name' => 'Central '.$position->id, 'company_id' => $department->company_id]);

    Contract::create([
        'position_id' => $position->id,
        'department_id' => $department->id,
        'employee_id' => Employee::factory()->create(['branch_id' => $branch->id])->id,
        'type' => 'indefinido',
        'start_date' => now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 3000000,
        'payroll_type' => 'monthly',
        'status' => 'active',
    ]);

    return $position;
}

it('el nombre repetido en el departamento muestra un mensaje propio', function () {
    $department = positionDepartment(Company::factory()->create());
    Position::create(['name' => 'Vendedor', 'department_id' => $department->id]);

    Livewire::test(CreatePosition::class)
        ->fillForm(['name' => 'Vendedor', 'department_id' => $department->id])
        ->call('create')
        ->assertHasFormErrors(['name' => 'Ya existe un cargo con este nombre en el departamento.']);
});

it('el título de la edición lleva el nombre del cargo', function () {
    $position = Position::create(['name' => 'Vendedor', 'department_id' => positionDepartment(Company::factory()->create())->id]);

    Livewire::test(EditPosition::class, ['record' => $position->getKey()])->assertSee('Editar: Vendedor');
});

it('«Reporta a» solo ofrece cargos de la misma empresa que no dependen del cargo', function () {
    $department = positionDepartment(Company::factory()->create());
    $other = positionDepartment(Company::factory()->create(), 'Ajeno');

    $boss = Position::create(['name' => 'Gerente', 'department_id' => $department->id]);
    $self = Position::create(['name' => 'Jefe', 'department_id' => $department->id, 'parent_id' => $boss->id]);
    $child = Position::create(['name' => 'Cajero', 'department_id' => $department->id, 'parent_id' => $self->id]);
    $peer = Position::create(['name' => 'Contador', 'department_id' => $department->id]);
    $foreign = Position::create(['name' => 'Extranjero', 'department_id' => $other->id]);

    $options = Livewire::test(EditPosition::class, ['record' => $self->getKey()])
        ->instance()->form->getFlatFields()['parent_id']->getOptions();

    expect(array_keys($options))->toEqualCanonicalizing([$boss->id, $peer->id])
        ->and($options)->not->toHaveKeys([$self->id, $child->id, $foreign->id]);

    expect(Livewire::test(CreatePosition::class)->instance()->form->getFlatFields()['parent_id']->getOptions())->toBe([]);
});

it('el departamento no se puede cambiar si el cargo tiene contratos', function () {
    $department = positionDepartment(Company::factory()->create());
    $used = positionWithContract($department);
    $empty = Position::create(['name' => 'Libre', 'department_id' => $department->id]);

    Livewire::test(EditPosition::class, ['record' => $used->getKey()])->assertFormFieldIsDisabled('department_id');
    Livewire::test(EditPosition::class, ['record' => $empty->getKey()])->assertFormFieldIsEnabled('department_id');
});

it('no se elimina un cargo con contratos, ni desde la edición ni desde el modelo', function () {
    $position = positionWithContract(positionDepartment(Company::factory()->create()));

    Livewire::test(EditPosition::class, ['record' => $position->getKey()])->callAction(DeleteAction::class);

    expect(Position::find($position->id))->not->toBeNull()
        ->and(fn () => $position->delete())->toThrow(DomainException::class)
        ->and($position->deletionBlockersSummary())->toBe('1 contrato');
});

it('la fila del listado y la pestaña del departamento tampoco eliminan cargos con contratos', function () {
    $department = positionDepartment(Company::factory()->create());
    $used = positionWithContract($department);

    Livewire::test(ListPositions::class)->callTableAction('delete', $used);

    expect(Position::find($used->id))->not->toBeNull();

    Livewire::test(PositionsRelationManager::class, [
        'ownerRecord' => $department, 'pageClass' => EditDepartment::class,
    ])->callTableAction('delete', $used);

    expect(Position::find($used->id))->not->toBeNull();
});

it('la eliminación masiva borra solo los cargos sin contratos', function () {
    $department = positionDepartment(Company::factory()->create());
    $used = positionWithContract($department);
    $empty = Position::create(['name' => 'Libre', 'department_id' => $department->id]);

    Livewire::test(ListPositions::class)
        ->callTableBulkAction(DeleteBulkAction::class, [$used, $empty]);

    expect(Position::find($used->id))->not->toBeNull()
        ->and(Position::find($empty->id))->toBeNull();
});
