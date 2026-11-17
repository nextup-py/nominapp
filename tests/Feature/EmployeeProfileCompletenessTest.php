<?php

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\User;
use App\Services\EmployeeProfileCompleteness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Empleado activo con los datos de `$attrs` (por defecto, una ficha casi vacía).
 */
function profileEmployee(array $attrs = [], ?array $requirements = null): Employee
{
    static $n = 9900000;
    $n++;

    $company = Company::create(['name' => "EmpFC {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n, 'profile_requirements' => $requirements]);
    $branch = Branch::create(['name' => "SucFC {$n}", 'company_id' => $company->id]);

    return Employee::create($attrs + ['first_name' => 'Ana', 'last_name' => "Ficha{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active'])->load('branch.company');
}

it('de fábrica exige teléfono, nacimiento, domicilio, foto, rostro y cuenta', function () {
    expect(EmployeeProfileCompleteness::enabledFor(null))->toBe(['phone', 'birth_date', 'address', 'photo', 'face', 'bank_account']);
});

it('lista lo que falta y calcula el porcentaje', function () {
    $employee = profileEmployee(['phone' => '0981123456', 'birth_date' => '1990-01-01']);

    expect(EmployeeProfileCompleteness::missing($employee))->toBe(['address', 'photo', 'face'])
        ->and(EmployeeProfileCompleteness::percent($employee))->toBe(50);

    EmployeeAddress::create(['employee_id' => $employee->id, 'type' => 'home', 'street' => 'Calle 1']);

    expect(EmployeeProfileCompleteness::missing($employee->fresh('branch.company')))->toBe(['photo', 'face']);
});

it('respeta lo que exige cada empresa', function () {
    $employee = profileEmployee([], ['phone']);

    expect(EmployeeProfileCompleteness::missing($employee))->toBe(['phone'])
        ->and(EmployeeProfileCompleteness::percent(profileEmployee(['phone' => '0981123456'], ['phone'])))->toBe(100)
        ->and(EmployeeProfileCompleteness::percent(profileEmployee([], [])))->toBe(100);
});

it('el scope SQL coincide con la evaluación en PHP', function (string $key) {
    $faltante = profileEmployee([], [$key]);
    $completo = profileEmployee(['phone' => '0981', 'birth_date' => '1990-01-01', 'photo' => 'a.jpg', 'face_descriptor' => [0.1], 'email' => 'a@b.com'], [$key]);
    EmployeeAddress::create(['employee_id' => $completo->id, 'type' => 'home', 'street' => 'Calle 1']);

    $ids = Employee::query()->missingProfileItem($key)->pluck('id');

    expect($ids->contains($faltante->id))->toBe(in_array($key, EmployeeProfileCompleteness::missing($faltante)))
        ->and($ids->contains($completo->id))->toBeFalse();
})->with(['phone', 'birth_date', 'address', 'photo', 'face', 'email']);

it('el listado muestra la columna y filtra por lo que falta', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $sinTelefono = profileEmployee();
    $conTelefono = profileEmployee(['phone' => '0981123456']);

    Livewire::test(ListEmployees::class)
        ->assertTableColumnExists('profile_completeness')
        ->filterTable('profile_missing', 'phone')
        ->assertCanSeeTableRecords([$sinTelefono])
        ->assertCanNotSeeTableRecords([$conTelefono]);
});
