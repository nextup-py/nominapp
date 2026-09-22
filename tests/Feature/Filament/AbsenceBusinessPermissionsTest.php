<?php

use App\Filament\Resources\AbsenceResource\Pages\ListAbsences;
use App\Filament\Resources\AbsenceResource\Pages\ViewAbsence;
use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();

    Permission::firstOrCreate(['name' => 'justify_absence', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'mark_unjustified_absence', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'export_absence', 'guard_name' => 'web']);
});

function makeAbsenceTestEmployee(): Employee
{
    static $ci = 9300000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpAbs {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucAbs {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Abs',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'email' => "abstest{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
}

function makePendingAbsence(Employee $employee): Absence
{
    $day = AttendanceDay::create([
        'employee_id' => $employee->id,
        'date' => now()->subDay()->toDateString(),
        'status' => 'absent',
    ]);

    return Absence::create([
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'status' => 'pending',
        'reported_at' => now(),
    ]);
}

it('oculta justify y register_attendance sin justify_absence y las muestra con él', function () {
    $absence = makePendingAbsence(makeAbsenceTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Justificar Ausencias', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_absence', 'view_any_absence']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewAbsence::class, ['record' => $absence->getRouteKey()])
        ->assertActionHidden('justify')
        ->assertActionHidden('register_attendance');

    $roleWith = Role::create(['name' => 'Con Justificar Ausencias', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_absence', 'view_any_absence', 'justify_absence']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewAbsence::class, ['record' => $absence->getRouteKey()])
        ->assertActionVisible('justify')
        ->assertActionVisible('register_attendance');
});

it('oculta bulk_justify sin justify_absence y la muestra con él', function () {
    makePendingAbsence(makeAbsenceTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Bulk Justificar', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_absence']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListAbsences::class)
        ->assertTableBulkActionHidden('bulk_justify');

    $roleWith = Role::create(['name' => 'Con Bulk Justificar', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_absence', 'justify_absence']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListAbsences::class)
        ->assertTableBulkActionVisible('bulk_justify');
});

it('oculta mark_unjustified sin mark_unjustified_absence y la muestra con él', function () {
    $absence = makePendingAbsence(makeAbsenceTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Injustificar', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_absence', 'view_any_absence']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewAbsence::class, ['record' => $absence->getRouteKey()])
        ->assertActionHidden('mark_unjustified');

    $roleWith = Role::create(['name' => 'Con Injustificar', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_absence', 'view_any_absence', 'mark_unjustified_absence']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewAbsence::class, ['record' => $absence->getRouteKey()])
        ->assertActionVisible('mark_unjustified');
});

it('oculta bulk_unjustify sin mark_unjustified_absence y la muestra con él', function () {
    makePendingAbsence(makeAbsenceTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Bulk Injustificar', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_absence']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListAbsences::class)
        ->assertTableBulkActionHidden('bulk_unjustify');

    $roleWith = Role::create(['name' => 'Con Bulk Injustificar', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_absence', 'mark_unjustified_absence']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListAbsences::class)
        ->assertTableBulkActionVisible('bulk_unjustify');
});

it('oculta export_excel sin export_absence y la muestra con él', function () {
    $roleWithout = Role::create(['name' => 'Sin Exportar Ausencias', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_absence']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListAbsences::class)
        ->assertActionHidden('export_excel');

    $roleWith = Role::create(['name' => 'Con Exportar Ausencias', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_absence', 'export_absence']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListAbsences::class)
        ->assertActionVisible('export_excel');
});
