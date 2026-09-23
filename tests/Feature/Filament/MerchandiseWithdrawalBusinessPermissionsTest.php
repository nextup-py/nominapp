<?php

use App\Filament\Resources\MerchandiseWithdrawalResource\Pages\ViewMerchandiseWithdrawal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\MerchandiseWithdrawal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crea un empleado activo para tests de permisos de negocio de MerchandiseWithdrawal.
 */
function makeMerchPermEmployee(): Employee
{
    static $ci = 9400000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpMerchPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucMerchPerm {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Test',
        'last_name' => 'MerchPerm',
        'ci' => (string) $n,
        'email' => "merchperm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
}

/**
 * Crea un retiro de mercadería en el estado dado para el empleado.
 */
function makeMerchPermWithdrawal(Employee $employee, string $status = 'pending'): MerchandiseWithdrawal
{
    return MerchandiseWithdrawal::create([
        'employee_id' => $employee->id,
        'total_amount' => 500_000,
        'installments_count' => 2,
        'installment_amount' => 250_000,
        'outstanding_balance' => 500_000,
        'status' => $status,
    ]);
}

/**
 * Autentica un usuario con un rol de prueba que tiene los permisos CRUD de
 * MerchandiseWithdrawal (necesarios para acceder al recurso) más los permisos
 * de negocio indicados.
 *
 * @param  array<int, string>  $businessPermissions
 */
function actingAsMerchPermUser(array $businessPermissions): User
{
    foreach (array_merge(['view_any_merchandise_withdrawal', 'view_merchandise_withdrawal'], $businessPermissions) as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions(array_merge(['view_any_merchandise_withdrawal', 'view_merchandise_withdrawal'], $businessPermissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

// ─── approve_merchandise_withdrawal ────────────────────────────────────────

it('oculta la acción de aprobar retiro sin el permiso approve_merchandise_withdrawal', function () {
    actingAsMerchPermUser([]);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'pending');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionHidden('approve');
});

it('muestra la acción de aprobar retiro con el permiso approve_merchandise_withdrawal', function () {
    actingAsMerchPermUser(['approve_merchandise_withdrawal']);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'pending');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionVisible('approve');
});

// ─── reject_merchandise_withdrawal ─────────────────────────────────────────

it('oculta la acción de rechazar retiro sin el permiso reject_merchandise_withdrawal', function () {
    actingAsMerchPermUser([]);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'pending');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionHidden('reject');
});

it('muestra la acción de rechazar retiro con el permiso reject_merchandise_withdrawal', function () {
    actingAsMerchPermUser(['reject_merchandise_withdrawal']);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'pending');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionVisible('reject');
});

// ─── cancel_merchandise_withdrawal ──────────────────────────────────────────

it('oculta la acción de cancelar retiro sin el permiso cancel_merchandise_withdrawal', function () {
    actingAsMerchPermUser([]);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'approved');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionHidden('cancel');
});

it('muestra la acción de cancelar retiro con el permiso cancel_merchandise_withdrawal', function () {
    actingAsMerchPermUser(['cancel_merchandise_withdrawal']);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'approved');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionVisible('cancel');
});
