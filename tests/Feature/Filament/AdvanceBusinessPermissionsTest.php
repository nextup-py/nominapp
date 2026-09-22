<?php

use App\Filament\Pages\AdvanceReport;
use App\Filament\Resources\AdvanceResource\Pages\ViewAdvance;
use App\Models\Advance;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crea un empleado con contrato activo para tests de permisos de negocio de Advance.
 */
function makeAdvancePermEmployee(): Employee
{
    static $ci = 9200000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpAdvPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucAdvPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepAdvPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosAdvPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'AdvPerm',
        'ci' => (string) $n,
        'email' => "advperm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'payroll_type' => 'monthly',
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/**
 * Crea un adelanto en el estado dado para el empleado.
 */
function makeAdvancePermAdvance(Employee $employee, string $status = 'pending'): Advance
{
    return Advance::create([
        'employee_id' => $employee->id,
        'amount' => 500_000,
        'status' => $status,
        'payment_method' => 'transfer',
    ]);
}

/**
 * Autentica un usuario con un rol de prueba que tiene los permisos CRUD de Advance
 * (necesarios para acceder al recurso) más los permisos de negocio indicados.
 *
 * @param  array<int, string>  $businessPermissions
 */
function actingAsAdvancePermUser(array $businessPermissions, bool $withCrud = true): User
{
    $crud = $withCrud ? ['view_any_advance', 'view_advance'] : [];

    foreach (array_merge($crud, $businessPermissions) as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions(array_merge($crud, $businessPermissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

// ─── approve_advance ───────────────────────────────────────────────────────

it('oculta la acción de aprobar adelanto sin el permiso approve_advance', function () {
    actingAsAdvancePermUser([]);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionHidden('approve');
});

it('muestra la acción de aprobar adelanto con el permiso approve_advance', function () {
    actingAsAdvancePermUser(['approve_advance']);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionVisible('approve');
});

// ─── reject_advance ────────────────────────────────────────────────────────

it('oculta la acción de rechazar adelanto sin el permiso reject_advance', function () {
    actingAsAdvancePermUser([]);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionHidden('reject');
});

it('muestra la acción de rechazar adelanto con el permiso reject_advance', function () {
    actingAsAdvancePermUser(['reject_advance']);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionVisible('reject');
});

// ─── disburse_advance ──────────────────────────────────────────────────────

it('oculta la acción de desembolsar adelanto sin el permiso disburse_advance', function () {
    actingAsAdvancePermUser([]);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'approved');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionHidden('mark_disbursed');
});

it('muestra la acción de desembolsar adelanto con el permiso disburse_advance', function () {
    actingAsAdvancePermUser(['disburse_advance']);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'approved');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionVisible('mark_disbursed');
});

// ─── revert_advance ────────────────────────────────────────────────────────

it('oculta la acción de revertir adelanto sin el permiso revert_advance', function () {
    actingAsAdvancePermUser([]);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'approved');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionHidden('revert_to_pending');
});

it('muestra la acción de revertir adelanto con el permiso revert_advance', function () {
    actingAsAdvancePermUser(['revert_advance']);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'approved');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionVisible('revert_to_pending');
});

// ─── cancel_advance ────────────────────────────────────────────────────────

it('oculta la acción de cancelar adelanto sin el permiso cancel_advance', function () {
    actingAsAdvancePermUser([]);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionHidden('cancel');
});

it('muestra la acción de cancelar adelanto con el permiso cancel_advance', function () {
    actingAsAdvancePermUser(['cancel_advance']);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionVisible('cancel');
});

// ─── export_advance ────────────────────────────────────────────────────────

it('oculta la acción de exportar adelantos sin el permiso export_advance', function () {
    actingAsAdvancePermUser([], withCrud: false);

    Livewire::test(AdvanceReport::class)
        ->assertActionHidden('export');
});

it('muestra la acción de exportar adelantos con el permiso export_advance', function () {
    actingAsAdvancePermUser(['export_advance'], withCrud: false);

    Livewire::test(AdvanceReport::class)
        ->assertActionVisible('export');
});
