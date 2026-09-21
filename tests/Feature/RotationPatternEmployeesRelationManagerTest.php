<?php

use App\Filament\Resources\RotationPatternResource\Pages\EditRotationPattern;
use App\Filament\Resources\RotationPatternResource\RelationManagers\EmployeesRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\RotationAssignment;
use App\Models\RotationPattern;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\RotationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regresión: RotationService::assign()/closeActive() no tenían ningún
 * caller en la UI — no existía forma de asignar un patrón de rotación a un
 * empleado desde el panel. Este test cubre el nuevo RelationManager que
 * expone esa acción en RotationPatternResource.
 */
function makeRotationRmCompany(): Company
{
    static $n = 8700000;
    $n++;

    return Company::create(['name' => "Empresa RotationRM {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
}

function makeRotationRmEmployee(Company $company): Employee
{
    static $ci = 8700000;
    $n = $ci++;

    $branch = Branch::create(['name' => "Sucursal RotationRM {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto RotationRM {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo RotationRM {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Rotation',
        'last_name' => "RM {$n}",
        'ci' => (string) $n,
        'birth_date' => '1990-01-01',
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

function makeRotationRmPattern(Company $company): RotationPattern
{
    $shift = ShiftTemplate::create([
        'company_id' => $company->id,
        'name' => 'Turno Mañana',
        'shift_type' => 'diurno',
        'is_day_off' => false,
        'start_time' => '07:00',
        'end_time' => '15:00',
        'break_minutes' => 30,
        'is_active' => true,
    ]);

    return RotationPattern::create([
        'company_id' => $company->id,
        'name' => 'Patrón RM Test',
        'sequence' => [$shift->id],
        'is_active' => true,
    ]);
}

it('asigna el patrón de rotación a los empleados seleccionados desde el RelationManager', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = makeRotationRmCompany();
    $employee = makeRotationRmEmployee($company);
    $pattern = makeRotationRmPattern($company);

    Livewire::test(EmployeesRelationManager::class, [
        'ownerRecord' => $pattern,
        'pageClass' => EditRotationPattern::class,
    ])
        ->callTableAction('assign', data: ['employee_ids' => [$employee->id], 'start_index' => 0])
        ->assertHasNoTableActionErrors();

    $assignment = RotationAssignment::where('employee_id', $employee->id)
        ->where('pattern_id', $pattern->id)
        ->whereNull('valid_until')
        ->first();

    expect($assignment)->not->toBeNull();
    expect($assignment->start_index)->toBe(0);
    expect($assignment->valid_from->toDateString())->toBe(Carbon::today()->toDateString());
});

it('remueve la asignación activa de un empleado desde el RelationManager', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = makeRotationRmCompany();
    $employee = makeRotationRmEmployee($company);
    $pattern = makeRotationRmPattern($company);

    RotationService::assign($employee, $pattern, Carbon::today()->subMonth());

    Livewire::test(EmployeesRelationManager::class, [
        'ownerRecord' => $pattern,
        'pageClass' => EditRotationPattern::class,
    ])
        ->assertCanSeeTableRecords([$employee])
        ->callTableAction('remove', $employee)
        ->assertHasNoTableActionErrors();

    $assignment = RotationAssignment::where('employee_id', $employee->id)
        ->where('pattern_id', $pattern->id)
        ->first();

    expect($assignment->valid_until?->toDateString())->toBe(Carbon::today()->toDateString());
});

it('el RelationManager solo lista empleados con asignación activa vigente hoy', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = makeRotationRmCompany();
    $activeEmployee = makeRotationRmEmployee($company);
    $closedEmployee = makeRotationRmEmployee($company);
    $pattern = makeRotationRmPattern($company);

    RotationService::assign($activeEmployee, $pattern, Carbon::today()->subMonth());
    RotationService::assign($closedEmployee, $pattern, Carbon::today()->subMonths(3), validUntil: Carbon::today()->subMonth());

    Livewire::test(EmployeesRelationManager::class, [
        'ownerRecord' => $pattern,
        'pageClass' => EditRotationPattern::class,
    ])
        ->assertCanSeeTableRecords([$activeEmployee])
        ->assertCanNotSeeTableRecords([$closedEmployee])
        ->assertCountTableRecords(1);
});
