<?php

use App\Filament\Resources\ContractResource\Pages\EditContract;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\ContractService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Empleado con contrato a plazo en el estado indicado; paga en efectivo para no exigir cuenta bancaria. */
function expiredFlowContract(string $status = 'expired', string $type = 'plazo_fijo'): Contract
{
    static $n = 4600000;
    $n++;

    $company = Company::create(['name' => "EmpEX {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucEX {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepEX {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosEX {$n}", 'department_id' => $department->id]);
    $employee = Employee::create(['first_name' => 'Test', 'last_name' => 'Vencido', 'ci' => (string) $n, 'email' => "ex{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active']);

    foreach (['min_salary_monthly' => 2_550_328, 'min_salary_daily_jornal' => 87_950] as $name => $value) {
        DB::table('settings')->updateOrInsert(['group' => 'payroll', 'name' => $name], ['payload' => json_encode($value)]);
    }

    return Contract::create([
        'employee_id' => $employee->id,
        'type' => $type,
        'start_date' => Carbon::today()->subDays(60)->toDateString(),
        'end_date' => Carbon::today()->subDays(10)->toDateString(),
        'salary_type' => 'mensual',
        'salary' => 3_044_000,
        'payroll_type' => 'monthly',
        'payment_method' => 'cash',
        'work_modality' => 'presencial',
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => $status,
    ]);
}

/** Entra al panel como Super Admin. */
function actAsExpiredFlowAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

it('canBeRenewed admite vigentes y vencidos a plazo, pero no indefinidos ni terminados', function (string $status, string $type, bool $expected) {
    expect(expiredFlowContract($status, $type)->canBeRenewed())->toBe($expected);
})->with([
    'vigente a plazo' => ['active', 'plazo_fijo', true],
    'vencido a plazo' => ['expired', 'plazo_fijo', true],
    'vigente indefinido' => ['active', 'indefinido', false],
    'terminado' => ['terminated', 'plazo_fijo', false],
    'renovado' => ['renewed', 'plazo_fijo', false],
]);

it('un contrato vencido se puede renovar: queda renovado y nace uno vigente', function () {
    $contract = expiredFlowContract('expired');

    $new = ContractService::renew($contract, ['start_date' => Carbon::today()->subDays(10)->toDateString(), 'end_date' => Carbon::today()->addDays(200)->toDateString()]);

    expect($contract->fresh()->status)->toBe('renewed')
        ->and($new->status)->toBe('active');
});

it('shouldReactivateOnEdit solo aplica a vencidos cuya nueva fecha de fin no pasó', function () {
    $vencido = expiredFlowContract('expired');
    $vigente = expiredFlowContract('active');

    expect($vencido->shouldReactivateOnEdit(Carbon::today()->addDay()->toDateString()))->toBeTrue()
        ->and($vencido->shouldReactivateOnEdit(Carbon::today()->toDateString()))->toBeTrue()
        ->and($vencido->shouldReactivateOnEdit(Carbon::yesterday()->toDateString()))->toBeFalse()
        ->and($vencido->shouldReactivateOnEdit(null))->toBeFalse()
        ->and($vigente->shouldReactivateOnEdit(Carbon::today()->addDays(30)->toDateString()))->toBeFalse();
});

it('al extender la fecha de un contrato vencido desde la edición vuelve a Vigente y reactiva al empleado', function () {
    $contract = expiredFlowContract('expired');
    $contract->employee->update(['status' => 'inactive']);
    actAsExpiredFlowAdmin();

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->fillForm(['end_date' => Carbon::today()->addDays(30)->toDateString()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contract->fresh()->status)->toBe('active')
        ->and($contract->employee->fresh()->status)->toBe('active');
});

it('si la nueva fecha de fin ya pasó el contrato vencido sigue Vencido', function () {
    $contract = expiredFlowContract('expired');
    actAsExpiredFlowAdmin();

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->fillForm(['end_date' => Carbon::yesterday()->toDateString()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contract->fresh()->status)->toBe('expired');
});

it('editar un contrato vigente no cambia su estado', function () {
    $contract = expiredFlowContract('active');
    actAsExpiredFlowAdmin();

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->fillForm(['end_date' => Carbon::today()->addDays(30)->toDateString()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contract->fresh()->status)->toBe('active');
});
