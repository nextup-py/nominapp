<?php

use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Resources\LiquidacionResource\Pages\CreateLiquidacion;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\EmployeeTerminationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Empleado activo con un contrato vigente de indefinido que empezó hace `$monthsAgo` meses. */
function terminationEmployee(int $monthsAgo = 8): Employee
{
    static $n = 7100000;
    $n++;

    $company = Company::create(['name' => "EmpBJ {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucBJ {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Baja{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);
    $department = Department::create(['name' => "DepBJ {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosBJ {$n}", 'department_id' => $department->id]);

    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subMonths($monthsAgo)->toDateString(),
        'salary_type' => 'mensual', 'salary' => 3_044_000, 'payroll_type' => 'monthly', 'payment_method' => 'cash',
        'work_modality' => 'presencial', 'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
    ]);

    return $employee->fresh();
}

function terminationAdmin(): User
{
    return tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'])));
}

it('la baja termina el contrato con fecha, tipo y motivo y deja inactivo al empleado', function () {
    $employee = terminationEmployee();
    $date = today()->subDays(3);

    app(EmployeeTerminationService::class)->terminate($employee, $date, 'resignation', 'Se mudó');

    $contract = Contract::where('employee_id', $employee->id)->first();

    expect($employee->fresh()->status)->toBe('inactive')
        ->and($contract->status)->toBe('terminated')
        ->and($contract->termination_type)->toBe('resignation')
        ->and($contract->termination_reason)->toBe('Se mudó')
        ->and($contract->terminated_at->toDateString())->toBe($date->toDateString())
        ->and($contract->end_date->toDateString())->toBe($date->toDateString());
});

it('rechaza fechas futuras, anteriores al contrato y empleados ya inactivos', function () {
    $employee = terminationEmployee(monthsAgo: 1);
    $service = app(EmployeeTerminationService::class);

    expect(fn () => $service->terminate($employee, today()->addDay(), 'resignation'))->toThrow(DomainException::class, 'futura')
        ->and(fn () => $service->terminate($employee, today()->subMonths(3), 'resignation'))->toThrow(DomainException::class, 'anterior')
        ->and($employee->fresh()->status)->toBe('active');

    $service->terminate($employee, today(), 'resignation');

    expect(fn () => $service->terminate($employee->fresh(), today(), 'resignation'))->toThrow(DomainException::class, 'inactivo');
});

it('la acción Dar de baja del detalle da de baja al empleado', function () {
    $employee = terminationEmployee();

    Livewire::actingAs(terminationAdmin())->test(ViewEmployee::class, ['record' => $employee->id])
        ->callAction('terminate_employment', ['date' => today()->toDateString(), 'type' => 'mutual_agreement', 'reason' => 'Acuerdo', 'calculate_liquidation' => false])
        ->assertHasNoActionErrors();

    expect($employee->fresh()->status)->toBe('inactive');
});

it('con «calcular liquidación» no da de baja y lleva al formulario de liquidación', function () {
    $employee = terminationEmployee();

    Livewire::actingAs(terminationAdmin())->test(ViewEmployee::class, ['record' => $employee->id])
        ->callAction('terminate_employment', ['date' => today()->toDateString(), 'type' => 'resignation', 'reason' => null, 'calculate_liquidation' => true])
        ->assertRedirect();

    expect($employee->fresh()->status)->toBe('active')
        ->and(Contract::where('employee_id', $employee->id)->value('status'))->toBe('active');
});

it('el formulario de liquidación se carga con los datos de la baja', function () {
    $employee = terminationEmployee();

    $this->actingAs(terminationAdmin());
    request()->merge(['employee_id' => $employee->id, 'termination_type' => 'resignation', 'termination_date' => today()->toDateString()]);

    Livewire::withQueryParams(['employee_id' => $employee->id, 'termination_type' => 'resignation', 'termination_date' => today()->toDateString()])
        ->test(CreateLiquidacion::class)
        ->assertFormSet(['employee_id' => $employee->id, 'termination_type' => 'resignation']);
});

it('la acción no aparece para empleados inactivos', function () {
    $employee = terminationEmployee();
    app(EmployeeTerminationService::class)->terminate($employee, today(), 'resignation');

    Livewire::actingAs(terminationAdmin())->test(ViewEmployee::class, ['record' => $employee->id])
        ->assertActionHidden('terminate_employment');
});
