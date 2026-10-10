<?php

use App\Filament\Resources\AttendanceMarkFailureResource\Pages\ListAttendanceMarkFailures;
use App\Models\AttendanceMarkFailure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function failureEmployee(): Employee
{
    static $n = 6500000;
    $n++;

    $company = Company::create(['name' => "EmpFL {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucFL {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Falla', 'last_name' => 'Lista', 'ci' => (string) $n, 'email' => "fl{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active',
    ]);
}

/** Fallo resoluble (sync_conflict de un empleado con evento intentado). */
function resolvableFailure(string $eventType = 'check_in'): AttendanceMarkFailure
{
    $employee = failureEmployee();

    return AttendanceMarkFailure::record([
        'mode' => 'terminal', 'failure_type' => 'sync_conflict', 'employee_id' => $employee->id,
        'branch_id' => $employee->branch_id, 'attempted_event_type' => $eventType,
        'failure_message' => 'Conflicto de prueba.', 'metadata' => ['recorded_at' => now()->subHour()->format('Y-m-d H:i:s')],
    ]);
}

function failureAsSuperAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

it('abre en la pestaña de pendientes y muestra solo esos fallos', function () {
    failureAsSuperAdmin();
    $pending = resolvableFailure();
    $resolved = resolvableFailure();
    $resolved->update(['resolution_status' => 'dismissed']);

    Livewire::test(ListAttendanceMarkFailures::class)
        ->assertSet('activeTab', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$resolved])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$pending, $resolved]);
});

it('calcula los contadores de las pestañas con una sola consulta agregada', function () {
    failureAsSuperAdmin();
    resolvableFailure();
    resolvableFailure()->update(['resolution_status' => 'approved']);
    AttendanceMarkFailure::record(['mode' => 'mobile', 'failure_type' => 'face_no_match', 'failure_message' => 'x']);

    $tabs = Livewire::test(ListAttendanceMarkFailures::class)->instance()->getTabs();

    expect($tabs['pending']->getBadge())->toBe(2)
        ->and($tabs['all']->getBadge())->toBe(3)
        ->and($tabs['terminal']->getBadge())->toBe(2)
        ->and($tabs['mobile']->getBadge())->toBe(1);
});

it('indica qué hacer según el estado del fallo', function () {
    $resolvable = resolvableFailure();
    $noData = AttendanceMarkFailure::record(['mode' => 'mobile', 'failure_type' => 'face_no_match', 'failure_message' => 'x']);

    expect($resolvable->getNextStepHint())->toBe('Aprobar si el empleado sí estuvo, o descartar')
        ->and($noData->getNextStepHint())->toBe('Sin datos para aprobar: ver diagnóstico');

    $resolvable->update(['resolution_status' => 'approved']);
    $noData->update(['resolution_status' => 'dismissed']);

    expect($resolvable->getNextStepHint())->toBe('Marcación registrada')
        ->and($noData->getNextStepHint())->toBe('Descartado sin registrar');
});

it('aprueba en bloque los resolubles y omite el resto', function () {
    failureAsSuperAdmin();
    $good = resolvableFailure();
    $noData = AttendanceMarkFailure::record(['mode' => 'mobile', 'failure_type' => 'face_no_match', 'failure_message' => 'x']);

    Livewire::test(ListAttendanceMarkFailures::class)
        ->callTableBulkAction('approve_bulk', [$good, $noData])
        ->assertHasNoTableBulkActionErrors();

    expect($good->fresh()->isApproved())->toBeTrue()
        ->and($noData->fresh()->isPending())->toBeTrue();
});

it('descarta en bloque solo los pendientes', function () {
    failureAsSuperAdmin();
    $pending = resolvableFailure();
    $already = resolvableFailure();
    $already->update(['resolution_status' => 'approved']);

    Livewire::test(ListAttendanceMarkFailures::class)
        ->set('activeTab', 'all')
        ->callTableBulkAction('dismiss_bulk', [$pending, $already], data: ['notes' => 'Ya no aplica'])
        ->assertHasNoTableBulkActionErrors();

    expect($pending->fresh()->resolution_status)->toBe('dismissed')
        ->and($pending->fresh()->resolution_notes)->toBe('Ya no aplica')
        ->and($already->fresh()->resolution_status)->toBe('approved');
});

it('las acciones masivas exigen permiso de edición', function () {
    foreach (['view_any_attendance_mark_failure', 'update_attendance_mark_failure'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    $failure = resolvableFailure();

    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo('view_any_attendance_mark_failure')));
    Livewire::test(ListAttendanceMarkFailures::class)
        ->assertTableBulkActionHidden('approve_bulk')
        ->assertTableBulkActionHidden('dismiss_bulk');

    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->givePermissionTo(['view_any_attendance_mark_failure', 'update_attendance_mark_failure'])));
    Livewire::test(ListAttendanceMarkFailures::class)
        ->assertTableBulkActionVisible('approve_bulk')
        ->assertTableBulkActionVisible('dismiss_bulk');
});
