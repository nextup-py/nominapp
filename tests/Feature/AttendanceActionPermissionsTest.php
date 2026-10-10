<?php

use App\Filament\Resources\AttendanceMarkFailureResource\Pages\ListAttendanceMarkFailures;
use App\Filament\Resources\AttendanceMarkFailureResource\Pages\ViewAttendanceMarkFailure;
use App\Filament\Resources\EmployeeDeviceResource\Pages\ListEmployeeDevices;
use App\Filament\Resources\TerminalResource\Pages\ListTerminals;
use App\Models\AttendanceMarkFailure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/** Usuario con solo los permisos indicados (sin rol). */
function userWithPermissions(array $names): User
{
    foreach ($names as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    return tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo($names));
}

function permFailure(): AttendanceMarkFailure
{
    $company = Company::create(['name' => 'Empresa PM', 'ruc' => '4800000-1', 'employer_number' => 4800000]);
    $branch = Branch::create(['name' => 'Sucursal PM', 'company_id' => $company->id]);
    $employee = Employee::create([
        'first_name' => 'Perm', 'last_name' => 'Fallo', 'ci' => '4800001', 'email' => 'pm@test.com',
        'branch_id' => $branch->id, 'status' => 'active',
    ]);

    return AttendanceMarkFailure::record([
        'mode' => 'terminal', 'failure_type' => 'sync_conflict', 'employee_id' => $employee->id,
        'branch_id' => $branch->id, 'attempted_event_type' => 'check_in', 'failure_message' => 'Conflicto.',
        'metadata' => ['recorded_at' => now()->subHour()->format('Y-m-d H:i:s')],
    ]);
}

it('aprobar y descartar un fallo exigen update_attendance_mark_failure', function () {
    $failure = permFailure();

    $this->actingAs(userWithPermissions(['view_any_attendance_mark_failure', 'view_attendance_mark_failure']));
    Livewire::test(ListAttendanceMarkFailures::class)->assertTableActionHidden('approve', $failure);
    Livewire::test(ViewAttendanceMarkFailure::class, ['record' => $failure->getRouteKey()])
        ->assertActionHidden('approve')
        ->assertActionHidden('dismiss');

    $this->actingAs(userWithPermissions(['view_any_attendance_mark_failure', 'view_attendance_mark_failure', 'update_attendance_mark_failure']));
    Livewire::test(ListAttendanceMarkFailures::class)->assertTableActionVisible('approve', $failure);
    Livewire::test(ViewAttendanceMarkFailure::class, ['record' => $failure->getRouteKey()])
        ->assertActionVisible('approve')
        ->assertActionVisible('dismiss');
});

it('quien solo puede ver un fallo conserva el diagnóstico', function () {
    $failure = permFailure();

    $this->actingAs(userWithPermissions(['view_any_attendance_mark_failure', 'view_attendance_mark_failure']));

    Livewire::test(ListAttendanceMarkFailures::class)->assertTableActionVisible('diagnose', $failure);
});

it('revocar un dispositivo de empleado exige update_employee_device', function () {
    $company = Company::create(['name' => 'Empresa DV', 'ruc' => '4810000-1', 'employer_number' => 4810000]);
    $branch = Branch::create(['name' => 'Sucursal DV', 'company_id' => $company->id]);
    $employee = Employee::create([
        'first_name' => 'Dispo', 'last_name' => 'Sitivo', 'ci' => '4810001', 'email' => 'dv@test.com',
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
    $device = EmployeeDevice::create(['employee_id' => $employee->id, 'linked_at' => now()]);

    $this->actingAs(userWithPermissions(['view_any_employee_device']));
    Livewire::test(ListEmployeeDevices::class)->assertTableActionHidden('revoke', $device);

    $this->actingAs(userWithPermissions(['view_any_employee_device', 'update_employee_device']));
    Livewire::test(ListEmployeeDevices::class)->assertTableActionVisible('revoke', $device);
});

it('desvincular un terminal exige update_terminal', function () {
    $company = Company::create(['name' => 'Empresa TM', 'ruc' => '4820000-1', 'employer_number' => 4820000]);
    $branch = Branch::create(['name' => 'Sucursal TM', 'company_id' => $company->id]);
    $terminal = Terminal::create(['name' => 'Terminal PM', 'branch_id' => $branch->id, 'status' => 'active']);
    $terminal->createToken('terminal-sync', ['terminal:sync']);

    $this->actingAs(userWithPermissions(['view_any_terminal']));
    Livewire::test(ListTerminals::class)->assertTableActionHidden('revoke_token', $terminal);

    $this->actingAs(userWithPermissions(['view_any_terminal', 'update_terminal']));
    Livewire::test(ListTerminals::class)->assertTableActionVisible('revoke_token', $terminal);
});

it('el detalle de un fallo se renderiza y permite descartarlo', function () {
    $failure = permFailure();

    $this->actingAs(userWithPermissions(['view_any_attendance_mark_failure', 'view_attendance_mark_failure', 'update_attendance_mark_failure']));

    Livewire::test(ViewAttendanceMarkFailure::class, ['record' => $failure->getRouteKey()])
        ->assertSuccessful()
        ->callAction('dismiss', data: ['notes' => 'Ya no aplica'])
        ->assertHasNoActionErrors();

    expect($failure->fresh()->resolution_status)->toBe('dismissed');
});
