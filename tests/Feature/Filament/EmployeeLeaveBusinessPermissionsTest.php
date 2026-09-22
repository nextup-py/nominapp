<?php

use App\Filament\Resources\EmployeeLeaveResource\Pages\ViewEmployeeLeaves;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();

    Permission::firstOrCreate(['name' => 'approve_employee_leave', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'reject_employee_leave', 'guard_name' => 'web']);
});

function makeLeaveTestEmployee(): Employee
{
    static $ci = 9200000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpLeave {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucLeave {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Leave',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'email' => "leave{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
}

function makePendingLeave(Employee $employee): EmployeeLeave
{
    return EmployeeLeave::create([
        'employee_id' => $employee->id,
        'type' => 'vacation',
        'start_date' => now()->addDays(5),
        'end_date' => now()->addDays(7),
        'status' => 'pending',
    ]);
}

it('oculta la acción approve sin el permiso approve_employee_leave y la muestra con él', function () {
    $leave = makePendingLeave(makeLeaveTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Aprobar Licencias', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_employee_leave', 'view_any_employee_leave']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewEmployeeLeaves::class, ['record' => $leave->getRouteKey()])
        ->assertActionHidden('approve');

    $roleWith = Role::create(['name' => 'Con Aprobar Licencias', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_employee_leave', 'view_any_employee_leave', 'approve_employee_leave']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewEmployeeLeaves::class, ['record' => $leave->getRouteKey()])
        ->assertActionVisible('approve');
});

it('oculta la acción reject sin el permiso reject_employee_leave y la muestra con él', function () {
    $leave = makePendingLeave(makeLeaveTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Rechazar Licencias', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_employee_leave', 'view_any_employee_leave']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewEmployeeLeaves::class, ['record' => $leave->getRouteKey()])
        ->assertActionHidden('reject');

    $roleWith = Role::create(['name' => 'Con Rechazar Licencias', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_employee_leave', 'view_any_employee_leave', 'reject_employee_leave']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewEmployeeLeaves::class, ['record' => $leave->getRouteKey()])
        ->assertActionVisible('reject');
});
