<?php

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Position;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ScheduleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Empleado de prueba; con `$contract` true tiene un contrato vigente. */
function statusEmployee(string $status, bool $contract = false): Employee
{
    static $n = 5500000;
    $n++;

    $company = Company::create(['name' => "EmpST {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucST {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Eva', 'last_name' => "Estado{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => $status]);

    if ($contract) {
        $department = Department::create(['name' => "DepST {$n}", 'company_id' => $company->id]);
        $position = Position::create(['name' => "PosST {$n}", 'department_id' => $department->id]);
        Contract::create([
            'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subMonths(6)->toDateString(),
            'salary_type' => 'mensual', 'salary' => 3_044_000, 'payroll_type' => 'monthly', 'payment_method' => 'cash',
            'work_modality' => 'presencial', 'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
        ]);
    }

    return $employee->fresh();
}

function statusUser(array $permissions): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Operador', 'guard_name' => 'web']));
    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    return $user;
}

it('activar en bloque activa solo a quien tiene contrato vigente y avisa de los omitidos', function () {
    $withContract = statusEmployee('inactive', contract: true);
    $without = statusEmployee('inactive');

    Livewire::actingAs(statusUser(['view_any_employee', 'update_employee']))->test(ListEmployees::class)
        ->set('activeTab', 'all')
        ->callTableBulkAction('activate', [$withContract, $without])
        ->assertNotified('Empleados activados');

    expect($withContract->fresh()->status)->toBe('active')
        ->and($without->fresh()->status)->toBe('inactive');
});

it('desactivar en bloque pasa por el observer: cierra el horario vigente', function () {
    $employee = statusEmployee('active');
    $schedule = Schedule::create(['name' => 'Horario ST', 'shift_type' => 'diurno', 'description' => null]);
    ScheduleAssignmentService::assign($employee, $schedule, today()->subDays(10));
    expect(EmployeeScheduleAssignment::where('employee_id', $employee->id)->whereNull('valid_until')->count())->toBe(1);

    Livewire::actingAs(statusUser(['view_any_employee', 'update_employee']))->test(ListEmployees::class)
        ->callTableBulkAction('deactivate', [$employee]);

    expect($employee->fresh()->status)->toBe('inactive')
        ->and(EmployeeScheduleAssignment::where('employee_id', $employee->id)->whereNull('valid_until')->count())->toBe(0);
});

it('activar a un inactivo sin contrato se bloquea en el modelo', function () {
    $employee = statusEmployee('inactive');

    expect($employee->activationBlocker())->toMatch('/contrato vigente/')
        ->and(statusEmployee('inactive', contract: true)->activationBlocker())->toBeNull();
});

it('las acciones de estado, enlace y revocación exigen permiso', function () {
    $employee = statusEmployee('active');
    $readOnly = statusUser(['view_any_employee', 'view_employee']);
    $editor = statusUser(['view_any_employee', 'update_employee']);

    Livewire::actingAs($readOnly)->test(ListEmployees::class)
        ->assertTableActionHidden('change_status', $employee)
        ->assertTableActionHidden('generate_enrollment', $employee)
        ->assertTableBulkActionHidden('activate')
        ->assertTableBulkActionHidden('deactivate')
        ->assertTableBulkActionHidden('set_advance_percent');

    Livewire::actingAs($editor)->test(ListEmployees::class)
        ->assertTableActionVisible('change_status', $employee)
        ->assertTableBulkActionVisible('activate');
});
