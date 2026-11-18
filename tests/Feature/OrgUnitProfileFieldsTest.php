<?php

use App\Filament\Resources\DepartmentResource\Pages\CreateDepartment;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Filament\Resources\PositionResource\Pages\EditPosition;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $this->company = Company::factory()->create();
    $this->branch = Branch::create(['name' => 'Central', 'company_id' => $this->company->id]);
    $this->department = Department::create(['company_id' => $this->company->id, 'name' => 'Ventas']);
});

/** Ocupa el cargo con un contrato vigente de un empleado nuevo. */
function occupy(Position $position, Branch $branch, string $status = 'active'): Employee
{
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'status' => 'active']);

    Contract::create([
        'position_id' => $position->id,
        'department_id' => $position->department_id,
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 3000000,
        'payroll_type' => 'monthly',
        'status' => $status,
    ]);

    return $employee;
}

it('avisa cuando el salario queda fuera del rango del cargo y no cuando está dentro o sin rango', function () {
    $position = Position::create(['name' => 'Cajero', 'department_id' => $this->department->id, 'salary_min' => 3000000, 'salary_max' => 4000000]);
    $open = Position::create(['name' => 'Libre', 'department_id' => $this->department->id]);

    expect($position->salaryRangeLabel())->toBe('Gs. 3.000.000 – 4.000.000')
        ->and($position->salaryRangeWarning(2500000))->toContain('Por debajo del rango')
        ->and($position->salaryRangeWarning(4500000))->toContain('Por encima del rango')
        ->and($position->salaryRangeWarning(3500000))->toBeNull()
        ->and($open->salaryRangeWarning(1))->toBeNull()
        ->and(Position::salaryHint($position->id, 2500000, 'mensual'))->not->toBeNull()
        ->and(Position::salaryHint($position->id, 2500000, 'jornal'))->toBeNull();
});

it('calcula plazas ocupadas, vacantes y el aviso de plazas completas', function () {
    $position = Position::create(['name' => 'Cajero', 'department_id' => $this->department->id, 'headcount' => 2]);
    $unlimited = Position::create(['name' => 'Libre', 'department_id' => $this->department->id]);

    expect($position->vacanciesCount())->toBe(2)->and($unlimited->vacanciesCount())->toBeNull();

    $first = occupy($position, $this->branch);

    expect($position->vacanciesCount())->toBe(1)->and($position->headcountWarning())->toBeNull();

    occupy($position, $this->branch);
    occupy($position, $this->branch, 'terminated');

    expect($position->vacanciesCount())->toBe(0)
        ->and($position->headcountWarning())->toContain('2 de 2 plazas')
        ->and($position->headcountWarning($first->contracts()->first()->id))->toBeNull();
});

it('las vacantes del departamento suman solo los cargos activos con plazas definidas', function () {
    $a = Position::create(['name' => 'A', 'department_id' => $this->department->id, 'headcount' => 3]);
    Position::create(['name' => 'B', 'department_id' => $this->department->id, 'headcount' => 2, 'is_active' => false]);
    Position::create(['name' => 'C', 'department_id' => $this->department->id]);
    occupy($a, $this->branch);

    expect($this->department->vacanciesCount())->toBe(2);
});

it('resuelve el permiso de horas extra del cargo, heredando cuando no se define', function () {
    $inherit = Position::create(['name' => 'A', 'department_id' => $this->department->id]);
    $yes = Position::create(['name' => 'B', 'department_id' => $this->department->id, 'overtime_allowed' => true]);
    $no = Position::create(['name' => 'C', 'department_id' => $this->department->id, 'overtime_allowed' => false]);

    expect($inherit->overtimeAllowed())->toBe(Position::OVERTIME_ALLOWED_DEFAULT)
        ->and($yes->overtimeAllowed())->toBeTrue()
        ->and($no->overtimeAllowed())->toBeFalse();
});

it('el formulario del cargo guarda los datos nuevos y valida el rango', function () {
    $schedule = Schedule::create(['name' => 'Oficina', 'shift_type' => 'diurno']);

    Livewire::test(CreatePosition::class)
        ->fillForm([
            'name' => 'Cajero',
            'department_id' => $this->department->id,
            'description' => 'Atiende la caja.',
            'is_managerial' => true,
            'salary_min' => 4000000,
            'salary_max' => 3000000,
        ])
        ->call('create')
        ->assertHasFormErrors(['salary_max' => 'El máximo no puede ser menor que el mínimo.']);

    Livewire::test(CreatePosition::class)
        ->fillForm([
            'name' => 'Cajero',
            'department_id' => $this->department->id,
            'description' => 'Atiende la caja.',
            'is_managerial' => true,
            'salary_min' => 3000000,
            'salary_max' => 4000000,
            'headcount' => 3,
            'overtime_allowed' => 'no',
            'default_schedule_id' => $schedule->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $position = Position::where('name', 'Cajero')->first();

    expect($position->description)->toBe('Atiende la caja.')
        ->and($position->is_managerial)->toBeTrue()
        ->and($position->salary_min)->toBe(3000000)
        ->and($position->headcount)->toBe(3)
        ->and($position->overtime_allowed)->toBeFalse()
        ->and($position->default_schedule_id)->toBe($schedule->id);

    Livewire::test(EditPosition::class, ['record' => $position->getKey()])
        ->assertFormSet(['overtime_allowed' => 'no'])
        ->fillForm(['overtime_allowed' => 'inherit'])
        ->call('save');

    expect($position->fresh()->overtime_allowed)->toBeNull();
});

it('el responsable del departamento solo puede ser un empleado activo de la misma empresa', function () {
    $other = Company::factory()->create();
    $otherBranch = Branch::create(['name' => 'Ajena', 'company_id' => $other->id]);

    $own = Employee::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active', 'first_name' => 'Ana', 'last_name' => 'Gómez']);
    $foreign = Employee::factory()->create(['branch_id' => $otherBranch->id, 'status' => 'active', 'first_name' => 'Ana', 'last_name' => 'Ajena']);
    $inactive = Employee::factory()->create(['branch_id' => $this->branch->id, 'status' => 'inactive', 'first_name' => 'Ana', 'last_name' => 'Baja']);

    $field = Livewire::test(CreateDepartment::class)
        ->set('data.company_id', $this->company->id)
        ->instance()->form->getFlatFields(withHidden: true)['manager_id'];

    expect(array_keys($field->getSearchResults('Ana')))->toBe([$own->id]);

    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Finanzas', 'company_id' => $this->company->id, 'manager_id' => $foreign->id])
        ->call('create')
        ->assertHasFormErrors(['manager_id']);

    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Finanzas', 'company_id' => $this->company->id, 'manager_id' => $inactive->id])
        ->call('create')
        ->assertHasFormErrors(['manager_id']);

    Livewire::test(CreateDepartment::class)
        ->fillForm(['name' => 'Finanzas', 'company_id' => $this->company->id, 'manager_id' => $own->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Department::where('name', 'Finanzas')->first()->manager->is($own))->toBeTrue();
});

it('«Asignar horario» preselecciona el horario del cargo cuando no hay sugerencia por marcaciones', function () {
    $schedule = Schedule::create(['name' => 'Oficina', 'shift_type' => 'diurno']);
    $position = Position::create(['name' => 'Cajero', 'department_id' => $this->department->id, 'default_schedule_id' => $schedule->id]);
    $employee = occupy($position, $this->branch);

    Livewire::test(ListEmployees::class)
        ->mountTableAction('assign_schedule', $employee)
        ->assertTableActionDataSet(['schedule_id' => $schedule->id]);
});
