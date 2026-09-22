<?php

use App\Filament\Resources\LoanResource\Pages\ListLoans;
use App\Filament\Resources\LoanResource\Pages\ViewLoan;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crea un empleado con contrato activo para tests de permisos de negocio de Loan.
 */
function makeLoanPermEmployee(): Employee
{
    static $ci = 9100000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpLoanPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucLoanPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepLoanPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosLoanPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'LoanPerm',
        'ci' => (string) $n,
        'email' => "loanperm{$n}@test.com",
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
 * Crea un préstamo en el estado dado para el empleado.
 */
function makeLoanPermLoan(Employee $employee, string $status = 'pending'): Loan
{
    return Loan::create([
        'employee_id' => $employee->id,
        'amount' => 1_000_000,
        'interest_rate' => 0,
        'installments_count' => 4,
        'installment_amount' => 250_000,
        'status' => $status,
        'reason' => 'personal',
    ]);
}

/**
 * Autentica un usuario con un rol de prueba que tiene los permisos CRUD de Loan
 * (necesarios para acceder al recurso) más los permisos de negocio indicados.
 *
 * @param  array<int, string>  $businessPermissions
 */
function actingAsLoanPermUser(array $businessPermissions): User
{
    foreach (array_merge(['view_any_loan', 'view_loan'], $businessPermissions) as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions(array_merge(['view_any_loan', 'view_loan'], $businessPermissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

// ─── approve_loan ──────────────────────────────────────────────────────────

it('oculta la acción de aprobar préstamo sin el permiso approve_loan', function () {
    actingAsLoanPermUser([]);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'pending');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionHidden('activate');
});

it('muestra la acción de aprobar préstamo con el permiso approve_loan', function () {
    actingAsLoanPermUser(['approve_loan']);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'pending');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionVisible('activate');
});

// ─── reject_loan ───────────────────────────────────────────────────────────

it('oculta la acción de rechazar préstamo sin el permiso reject_loan', function () {
    actingAsLoanPermUser([]);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'pending');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionHidden('reject');
});

it('muestra la acción de rechazar préstamo con el permiso reject_loan', function () {
    actingAsLoanPermUser(['reject_loan']);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'pending');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionVisible('reject');
});

// ─── disburse_loan ─────────────────────────────────────────────────────────

it('oculta la acción de desembolsar préstamo sin el permiso disburse_loan', function () {
    actingAsLoanPermUser([]);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'approved');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionHidden('mark_disbursed');
});

it('muestra la acción de desembolsar préstamo con el permiso disburse_loan', function () {
    actingAsLoanPermUser(['disburse_loan']);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'approved');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionVisible('mark_disbursed');
});

// ─── cancel_loan ───────────────────────────────────────────────────────────

it('oculta la acción de cancelar préstamo sin el permiso cancel_loan', function () {
    actingAsLoanPermUser([]);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'approved');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionHidden('cancel');
});

it('muestra la acción de cancelar préstamo con el permiso cancel_loan', function () {
    actingAsLoanPermUser(['cancel_loan']);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'approved');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionVisible('cancel');
});

// ─── export_loan ───────────────────────────────────────────────────────────

it('oculta la acción de exportar préstamos sin el permiso export_loan', function () {
    actingAsLoanPermUser([]);

    Livewire::test(ListLoans::class)
        ->assertActionHidden('export');
});

it('muestra la acción de exportar préstamos con el permiso export_loan', function () {
    actingAsLoanPermUser(['export_loan']);

    Livewire::test(ListLoans::class)
        ->assertActionVisible('export');
});
