<?php

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * El cargo es un nivel más de la cadena del permiso de marcación desde el celular:
 * empleado → cargo → sucursal → empresa → general.
 */
function positionMobileEmployee(?bool $positionValue = null, ?bool $branchValue = null, ?bool $employeeValue = null): Employee
{
    static $n = 8800000;
    $n++;

    $company = Company::create(['name' => "EmpPM {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucPM {$n}", 'company_id' => $company->id, 'mobile_marking_allowed' => $branchValue]);
    $employee = Employee::create([
        'first_name' => 'Sara', 'last_name' => "Cargo{$n}", 'ci' => (string) $n, 'email' => "pm{$n}@test.com", 'phone' => '0981123456',
        'branch_id' => $branch->id, 'status' => 'active', 'mobile_marking_allowed' => $employeeValue,
    ]);

    $department = Department::create(['name' => "DepPM {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Supervisor {$n}", 'department_id' => $department->id, 'mobile_marking_allowed' => $positionValue]);

    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear()->toDateString(),
        'salary_type' => 'mensual', 'salary' => 3_044_000, 'payroll_type' => 'monthly', 'payment_method' => 'cash',
        'work_modality' => 'presencial', 'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
    ]);

    return $employee->fresh();
}

it('el cargo habilita al empleado que no tiene valor propio', function () {
    $employee = positionMobileEmployee(positionValue: true);

    expect($employee->canMarkFromMobile())->toBeTrue()
        ->and($employee->mobileMarkingSource()['source'])->toBe('position')
        ->and($employee->mobileMarkingLabel())->toBe('Sí (del cargo)');
});

it('el valor propio del empleado gana sobre el cargo y el cargo gana sobre la sucursal', function () {
    expect(positionMobileEmployee(positionValue: true, employeeValue: false)->canMarkFromMobile())->toBeFalse()
        ->and(positionMobileEmployee(positionValue: false, branchValue: true)->canMarkFromMobile())->toBeFalse()
        ->and(positionMobileEmployee(positionValue: null, branchValue: true)->mobileMarkingSource()['source'])->toBe('branch');
});

it('el filtro SQL coincide con el cálculo en PHP para todas las combinaciones', function () {
    $combos = [];
    foreach ([null, true, false] as $employeeValue) {
        foreach ([null, true, false] as $positionValue) {
            foreach ([null, true, false] as $branchValue) {
                $combos[] = positionMobileEmployee($positionValue, $branchValue, $employeeValue);
            }
        }
    }

    $allowedIds = Employee::query()->mobileMarking(true)->pluck('id')->all();

    foreach ($combos as $employee) {
        expect(in_array($employee->id, $allowedIds, true))->toBe($employee->canMarkFromMobile());
    }
});

it('cambiar el permiso del cargo revoca el celular de quienes dejan de estar habilitados', function () {
    $employee = positionMobileEmployee(positionValue: true);
    $employee->forceFill(['mobile_linked_at' => now()])->save();
    $employee->claimMobileToken('Mozilla/5.0 (Linux; Android 14) Test');

    expect($employee->fresh()->hasMobileLinked())->toBeTrue();

    $employee->activeContract->position->update(['mobile_marking_allowed' => false]);

    expect($employee->fresh()->hasMobileLinked())->toBeFalse();
});

it('la acción de fila alterna el permiso propio del empleado', function () {
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = positionMobileEmployee();

    Livewire::test(ListEmployees::class)
        ->callTableAction('toggle_mobile_marking', $employee);

    expect($employee->fresh()->mobile_marking_allowed)->toBeTrue();

    Livewire::test(ListEmployees::class)
        ->callTableAction('toggle_mobile_marking', $employee->fresh());

    expect($employee->fresh()->mobile_marking_allowed)->toBeFalse();
});

it('ofrece avisar por WhatsApp solo a quien puede marcar y no vinculó su celular', function () {
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $habilitado = positionMobileEmployee(positionValue: true);
    $sinPermiso = positionMobileEmployee();

    Livewire::test(ListEmployees::class)
        ->assertTableActionVisible('whatsapp_mobile_link', $habilitado)
        ->assertTableActionHidden('whatsapp_mobile_link', $sinPermiso);
});
