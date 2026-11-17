<?php

use App\Filament\Resources\VacationResource\Pages\ListVacations;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vacation;
use App\Models\VacationBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Vacación de un empleado nuevo con el estado y método de pago dados. */
function bulkPayVacation(string $status = 'approved', string $method = 'immediate', string $payment = 'unpaid'): Vacation
{
    static $n = 6300000;
    $n++;

    $company = Company::create(['name' => "EmpVP {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucVP {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Vac{$n}", 'ci' => (string) $n, 'branch_id' => $branch->id, 'status' => 'active']);
    $balance = VacationBalance::create(['employee_id' => $employee->id, 'year' => 2026, 'years_of_service' => 2, 'entitled_days' => 12, 'used_days' => 6, 'pending_days' => 0]);

    return Vacation::create([
        'employee_id' => $employee->id, 'vacation_balance_id' => $balance->id, 'start_date' => '2026-05-04', 'end_date' => '2026-05-09',
        'payment_method' => $method, 'status' => $status, 'business_days' => 6, 'payment_amount' => 1_000_000, 'payment_status' => $payment,
    ]);
}

it('marca como pagadas las aprobadas sin pagar, con la fecha de inicio, y omite el resto', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $immediate = bulkPayVacation();
    $payroll = bulkPayVacation(method: 'with_payroll');
    $alreadyPaid = bulkPayVacation(payment: 'paid');
    $pending = bulkPayVacation(status: 'pending');

    Livewire::test(ListVacations::class)
        ->callTableBulkAction('markPaidBulk', [$immediate, $payroll, $alreadyPaid, $pending], ['use_start_date' => true])
        ->assertHasNoTableBulkActionErrors();

    expect($immediate->fresh()->payment_status)->toBe('paid')
        ->and($immediate->fresh()->paid_at->toDateString())->toBe('2026-05-04')
        ->and($payroll->fresh()->payment_status)->toBe('paid')
        ->and($pending->fresh()->payment_status)->toBe('unpaid');
});

it('usa la fecha elegida cuando no se usa la de inicio', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $vacation = bulkPayVacation();

    Livewire::test(ListVacations::class)
        ->callTableBulkAction('markPaidBulk', [$vacation], ['use_start_date' => false, 'paid_at' => '2026-05-01'])
        ->assertHasNoTableBulkActionErrors();

    expect($vacation->fresh()->paid_at->toDateString())->toBe('2026-05-01');
});

it('el pago individual también está disponible para las «con nómina»', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $payroll = bulkPayVacation(method: 'with_payroll');

    Livewire::test(ListVacations::class)->assertTableActionVisible('mark_paid', $payroll);
});
