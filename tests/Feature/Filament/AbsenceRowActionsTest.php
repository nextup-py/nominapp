<?php

use App\Filament\Resources\AbsenceResource;
use App\Filament\Resources\AbsenceResource\Pages\ListAbsences;
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

    foreach (['justify_absence', 'mark_unjustified_absence'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
});

function rowAbsence(int $daysAgo = 1, string $status = 'pending'): Absence
{
    static $n = 9400000;
    $n++;

    $company = Company::create(['name' => "EmpRow {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucRow {$n}", 'company_id' => $company->id]);
    $employee = Employee::create([
        'first_name' => 'Fila', 'last_name' => 'Ausente', 'ci' => (string) $n, 'email' => "row{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
    $day = AttendanceDay::create(['employee_id' => $employee->id, 'date' => now()->subDays($daysAgo)->toDateString(), 'status' => 'absent']);

    return Absence::create(['employee_id' => $employee->id, 'attendance_day_id' => $day->id, 'status' => $status, 'reported_at' => now()]);
}

function rowUserWith(array $permissions): User
{
    $role = Role::create(['name' => 'Rol '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions(array_merge(['view_any_absence', 'view_absence'], $permissions));

    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

it('muestra las acciones de resolución en la fila según el permiso', function () {
    $absence = rowAbsence();

    $this->actingAs(rowUserWith([]));
    Livewire::test(ListAbsences::class)
        ->assertTableActionHidden('justify', $absence)
        ->assertTableActionHidden('register_attendance', $absence)
        ->assertTableActionHidden('mark_unjustified', $absence);

    $this->actingAs(rowUserWith(['justify_absence', 'mark_unjustified_absence']));
    Livewire::test(ListAbsences::class)
        ->assertTableActionVisible('justify', $absence)
        ->assertTableActionVisible('register_attendance', $absence)
        ->assertTableActionVisible('mark_unjustified', $absence);
});

it('marca una ausencia como injustificada desde la fila', function () {
    $absence = rowAbsence();
    $this->actingAs(rowUserWith(['mark_unjustified_absence']));

    Livewire::test(ListAbsences::class)
        ->callTableAction('mark_unjustified', $absence, data: ['review_notes' => 'No avisó ni se presentó.'])
        ->assertHasNoTableActionErrors();

    expect($absence->fresh()->status)->toBe('unjustified');
});

it('exige las notas al marcar como injustificada desde la fila', function () {
    $absence = rowAbsence();
    $this->actingAs(rowUserWith(['mark_unjustified_absence']));

    Livewire::test(ListAbsences::class)
        ->callTableAction('mark_unjustified', $absence, data: ['review_notes' => ''])
        ->assertHasTableActionErrors(['review_notes' => 'required']);

    expect($absence->fresh()->status)->toBe('pending');
});

it('la insignia del menú cuenta todas las pendientes, no solo las creadas hoy', function () {
    $this->actingAs(rowUserWith([]));

    rowAbsence(daysAgo: 1);
    rowAbsence(daysAgo: 2, status: 'justified');
    Absence::query()->update(['created_at' => now()->subDays(6)]);

    $pending = Absence::query()->where('status', 'pending')->count();

    expect($pending)->toBeGreaterThan(0)
        ->and(AbsenceResource::getNavigationBadge())->toBe((string) $pending);
});
