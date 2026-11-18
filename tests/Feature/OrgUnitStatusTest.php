<?php

use App\Filament\Actions\PositionMergeAction;
use App\Filament\Resources\ContractResource\Pages\CreateContract;
use App\Filament\Resources\ContractResource\Pages\EditContract;
use App\Filament\Resources\DepartmentResource\Pages\EditDepartment;
use App\Filament\Resources\DepartmentResource\Pages\ListDepartments;
use App\Filament\Resources\DepartmentResource\Pages\ViewDepartment;
use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $this->company = Company::factory()->create();
    $this->branch = Branch::create(['name' => 'Central', 'company_id' => $this->company->id]);
    $this->department = Department::create(['company_id' => $this->company->id, 'name' => 'Ventas']);
    $this->position = Position::create(['name' => 'Vendedor', 'department_id' => $this->department->id]);
});

/** Contrato del cargo dado, con el estado indicado. */
function unitContract(Position $position, Branch $branch, string $status = 'active'): Contract
{
    return Contract::create([
        'position_id' => $position->id,
        'department_id' => $position->department_id,
        'employee_id' => Employee::factory()->create(['branch_id' => $branch->id, 'status' => 'active'])->id,
        'type' => 'indefinido',
        'start_date' => now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 3000000,
        'payroll_type' => 'monthly',
        'status' => $status,
    ]);
}

it('departamentos y cargos nacen activos', function () {
    expect($this->department->fresh()->is_active)->toBeTrue()
        ->and($this->position->fresh()->is_active)->toBeTrue()
        ->and(Department::active()->count())->toBe(1);
});

it('no se desactiva un departamento ni un cargo con contratos vigentes, ni desde el modelo', function () {
    unitContract($this->position, $this->branch);

    expect($this->department->deactivationBlockers())->toBe(['contratos vigentes' => 1])
        ->and(fn () => $this->department->update(['is_active' => false]))->toThrow(DomainException::class)
        ->and(fn () => $this->position->update(['is_active' => false]))->toThrow(DomainException::class)
        ->and($this->department->fresh()->is_active)->toBeTrue();
});

it('los contratos terminados no impiden desactivar', function () {
    unitContract($this->position, $this->branch, 'terminated');

    $this->position->update(['is_active' => false]);
    $this->department->update(['is_active' => false]);

    expect($this->position->fresh()->is_active)->toBeFalse()
        ->and($this->department->fresh()->is_active)->toBeFalse();
});

it('la acción Desactivar y Activar del departamento funciona desde el listado y el detalle', function () {
    Livewire::test(ListDepartments::class)->callTableAction('toggle_status', $this->department);

    expect($this->department->fresh()->is_active)->toBeFalse();

    Livewire::test(ViewDepartment::class, ['record' => $this->department->getKey()])->callAction('toggle_status');

    expect($this->department->fresh()->is_active)->toBeTrue();
});

it('la acción no desactiva un cargo con contratos vigentes y avisa', function () {
    unitContract($this->position, $this->branch);

    Livewire::test(ListPositions::class)->callTableAction('toggle_status', $this->position);

    expect($this->position->fresh()->is_active)->toBeTrue();

    $employeeless = Position::create(['name' => 'Libre', 'department_id' => $this->department->id]);

    Livewire::test(ListPositions::class)->callTableAction('toggle_status', $employeeless);

    expect($employeeless->fresh()->is_active)->toBeFalse();
});

it('el interruptor Activo del formulario se deshabilita con contratos vigentes y solo existe al editar', function () {
    Livewire::test(CreatePosition::class)->assertFormFieldDoesNotExist('is_active');

    Livewire::test(EditPosition::class, ['record' => $this->position->getKey()])->assertFormFieldIsEnabled('is_active');
    Livewire::test(EditDepartment::class, ['record' => $this->department->getKey()])->assertFormFieldIsEnabled('is_active');

    unitContract($this->position, $this->branch);

    Livewire::test(EditPosition::class, ['record' => $this->position->getKey()])->assertFormFieldIsDisabled('is_active');
    Livewire::test(EditDepartment::class, ['record' => $this->department->getKey()])->assertFormFieldIsDisabled('is_active');
});

it('el formulario de contrato no ofrece departamentos ni cargos inactivos, salvo el ya asignado', function () {
    $inactiveDepartment = Department::create(['company_id' => $this->company->id, 'name' => 'Cerrado', 'is_active' => false]);
    $inactivePosition = Position::create(['name' => 'Viejo', 'department_id' => $this->department->id, 'is_active' => false]);
    $contract = unitContract($this->position, $this->branch);

    $fields = fn () => Livewire::test(CreateContract::class)
        ->set('data.employee_id', $contract->employee_id)
        ->set('data.department_id', $this->department->id)
        ->instance()->form->getFlatFields();

    expect($fields()['department_id']->getOptions())->toHaveKey($this->department->id)
        ->and($fields()['department_id']->getOptions())->not->toHaveKey($inactiveDepartment->id)
        ->and($fields()['position_id']->getOptions())->toHaveKey($this->position->id)
        ->and($fields()['position_id']->getOptions())->not->toHaveKey($inactivePosition->id);

    // Al editar, el cargo ya asignado se conserva aunque esté inactivo.
    DB::table('positions')->where('id', $this->position->id)->update(['is_active' => false]);

    $edit = Livewire::test(EditContract::class, ['record' => $contract->getKey()])->instance()->form->getFlatFields();

    expect($edit['position_id']->getOptions())->toHaveKey($this->position->id);
});

it('el alta de empleado no ofrece cargos ni departamentos inactivos', function () {
    $inactiveDepartment = Department::create(['company_id' => $this->company->id, 'name' => 'Cerrado', 'is_active' => false]);
    $inactivePosition = Position::create(['name' => 'Viejo', 'department_id' => $this->department->id, 'is_active' => false]);

    $fields = Livewire::test(CreateEmployee::class)
        ->set('data.branch_id', $this->branch->id)
        ->set('data.ic_department_id', $this->department->id)
        ->instance()->form->getFlatFields();

    expect($fields['ic_department_id']->getOptions())->toHaveKey($this->department->id)
        ->and($fields['ic_department_id']->getOptions())->not->toHaveKey($inactiveDepartment->id)
        ->and($fields['ic_position_id']->getOptions())->toHaveKey($this->position->id)
        ->and($fields['ic_position_id']->getOptions())->not->toHaveKey($inactivePosition->id);
});

it('«Reporta a» y el destino de Fusionar no ofrecen cargos inactivos', function () {
    $inactive = Position::create(['name' => 'Viejo', 'department_id' => $this->department->id, 'is_active' => false]);
    $active = Position::create(['name' => 'Gerente', 'department_id' => $this->department->id]);

    $parents = Livewire::test(EditPosition::class, ['record' => $this->position->getKey()])
        ->instance()->form->getFlatFields()['parent_id']->getOptions();

    expect($parents)->toHaveKey($active->id)->and($parents)->not->toHaveKey($inactive->id);

    $targets = new ReflectionMethod(PositionMergeAction::class, 'targets');
    $options = $targets->invoke(null, $this->position);

    expect($options)->toHaveKey($active->id)->and($options)->not->toHaveKey($inactive->id);
});

it('el filtro Estado separa activos de inactivos en los listados', function () {
    $inactive = Position::create(['name' => 'Viejo', 'department_id' => $this->department->id, 'is_active' => false]);

    Livewire::test(ListPositions::class)
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$this->position]);
});
