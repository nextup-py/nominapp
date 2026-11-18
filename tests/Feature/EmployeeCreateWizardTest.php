<?php

use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Deduction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use Filament\Forms\Components\Wizard\Step;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Inicia sesión como Super Admin y crea una empresa con una sucursal. */
function wizardSetup(): Branch
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = Company::create(['name' => 'Empresa Wizard', 'ruc' => '1-1', 'employer_number' => 1, 'is_active' => true]);

    return Branch::create(['name' => 'Casa Central', 'company_id' => $company->id]);
}

/**
 * Etiquetas de los pasos visibles del asistente.
 *
 * @return array<int, string>
 */
function wizardStepLabels(Testable $component): array
{
    return collect($component->instance()->form->getFlatComponents())
        ->filter(fn ($component): bool => $component instanceof Step && $component->isVisible())
        ->map(fn (Step $step): string => (string) $step->getLabel())
        ->values()
        ->all();
}

/** Datos mínimos del alta: CI, nombre, apellido, nacimiento y sucursal. */
function wizardData(Branch $branch, array $extra = []): array
{
    return array_merge([
        'ci' => '4567890',
        'first_name' => 'Juan',
        'last_name' => 'Pérez',
        'birth_date' => '1990-05-10',
        'gender' => 'masculino',
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
    ], $extra);
}

it('el alta es un asistente de tres pasos: datos básicos, ubicación y horario, contrato', function () {
    wizardSetup();

    expect(wizardStepLabels(Livewire::test(CreateEmployee::class)))->toBe(['Datos básicos', 'Ubicación y horario', 'Contrato']);
});

it('crea el empleado solo con CI, nombre, apellido, nacimiento, género y sucursal', function () {
    $branch = wizardSetup();

    Livewire::test(CreateEmployee::class)
        ->fillForm(wizardData($branch))
        ->call('create')
        ->assertHasNoFormErrors();

    $employee = Employee::where('ci', '4567890')->firstOrFail();

    expect($employee->branch_id)->toBe($branch->id)
        ->and($employee->gender)->toBe('masculino')
        ->and($employee->contracts()->count())->toBe(0);
});

it('exige CI, nombre, apellido, fecha de nacimiento, género y sucursal', function () {
    wizardSetup();

    Livewire::test(CreateEmployee::class)
        ->fillForm(['ci' => null, 'first_name' => null, 'last_name' => null, 'birth_date' => null, 'gender' => null, 'branch_id' => null])
        ->call('create')
        ->assertHasFormErrors(['ci' => 'required', 'first_name' => 'required', 'last_name' => 'required', 'birth_date' => 'required', 'gender' => 'required', 'branch_id' => 'required']);
});

it('el alta no pide foto, contacto, maternidad ni deducciones, pero la edición sí los tiene', function () {
    $branch = wizardSetup();

    $createFields = array_keys(Livewire::test(CreateEmployee::class)->instance()->form->getFlatFields(withHidden: true));

    expect($createFields)->not->toContain('photo', 'phone', 'email', 'reports_to_id', 'maternity_protection_until', 'mandatory_deduction_ids');

    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    $editFields = array_keys(Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])->instance()->form->getFlatFields(withHidden: true));

    expect($editFields)->toContain('photo', 'phone', 'email', 'reports_to_id', 'maternity_protection_until', 'status');
});

it('asigna al nuevo empleado todas las deducciones obligatorias sin pedirlas', function () {
    $branch = wizardSetup();

    $ips = Deduction::create(['name' => 'IPS', 'code' => 'IPS', 'type' => 'legal', 'calculation' => 'percentage', 'amount' => 9, 'is_mandatory' => true, 'is_active' => true]);
    $optional = Deduction::create(['name' => 'Seguro', 'code' => 'SEG', 'type' => 'voluntary', 'calculation' => 'fixed', 'amount' => 5000, 'is_mandatory' => false, 'is_active' => true]);

    Livewire::test(CreateEmployee::class)->fillForm(wizardData($branch))->call('create')->assertHasNoFormErrors();

    $assigned = Employee::where('ci', '4567890')->firstOrFail()->employeeDeductions()->pluck('deduction_id');

    expect($assigned)->toContain($ips->id)->not->toContain($optional->id);
});

it('crea el contrato y el horario iniciales si se completan', function () {
    $branch = wizardSetup();
    $department = Department::create(['name' => 'Ventas', 'company_id' => $branch->company_id]);
    $position = Position::create(['name' => 'Cajero', 'department_id' => $department->id]);
    $schedule = Schedule::create(['name' => 'Horario Wizard', 'shift_type' => 'diurno']);

    Livewire::test(CreateEmployee::class)
        ->fillForm(wizardData($branch, [
            'initial_schedule_id' => $schedule->id,
            'ic_department_id' => $department->id,
            'ic_position_id' => $position->id,
            'ic_salary' => 3_000_000,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $employee = Employee::where('ci', '4567890')->firstOrFail();
    $contract = $employee->contracts()->firstOrFail();

    expect($contract->position_id)->toBe($position->id)
        ->and((int) $contract->salary)->toBe(3_000_000)
        ->and($contract->status)->toBe('active')
        ->and($employee->scheduleAssignments()->count())->toBe(1);
});

it('el paso de contrato solo aparece con el permiso para crear contratos', function () {
    $branch = wizardSetup();

    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Solo Empleados', 'guard_name' => 'web']));
    $user->givePermissionTo(collect(['view_any_employee', 'create_employee'])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])));
    $this->actingAs($user);

    expect(wizardStepLabels(Livewire::test(CreateEmployee::class)))->toBe(['Datos básicos', 'Ubicación y horario']);
});

it('toma el departamento del contrato desde el cargo cuando no se elige uno', function () {
    $branch = wizardSetup();
    $department = Department::create(['name' => 'Ventas', 'company_id' => $branch->company_id]);
    $position = Position::create(['name' => 'Cajero', 'department_id' => $department->id]);

    Livewire::test(CreateEmployee::class)
        ->fillForm(wizardData($branch, ['ic_position_id' => $position->id, 'ic_salary' => 3_000_000]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Employee::where('ci', '4567890')->firstOrFail()->contracts()->firstOrFail()->department_id)->toBe($department->id);
});

it('la edición agrupa el enrolamiento en «Más acciones» y ya no ofrece Inactivar', function () {
    $branch = wizardSetup();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);

    Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->assertActionExists('capture_face')
        ->assertActionExists('generate_enrollment')
        ->assertActionDoesNotExist('toggle_status');
});

it('el lugar de trabajo propio está en una sección aparte de la edición', function () {
    $branch = wizardSetup();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);

    $fields = Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])->instance()->form->getFlatFields(withHidden: true);

    expect($fields)->toHaveKeys(['work_location.lat', 'work_location.lng', 'location_radius_m']);
});
