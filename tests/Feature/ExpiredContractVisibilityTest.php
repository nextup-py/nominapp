<?php

use App\Console\Commands\ExpireContracts;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\AttendanceInboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * El vencimiento automático de contratos no desactiva al empleado (hacerlo cerraría su horario y daría de baja sus
 * percepciones y deducciones, y una renovación posterior no lo restauraría): en su lugar RR.HH. ve a quienes quedaron
 * con el contrato vencido y sin otro vigente, en un filtro de Empleados y en una tarjeta de «Por resolver».
 */
function expiredVisibilityEmployee(array $contractStatuses): Employee
{
    static $n = 7700000;
    $n++;

    $company = Company::create(['name' => "EmpCV {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucCV {$n}", 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Ana', 'last_name' => "Venc{$n}", 'ci' => (string) $n, 'email' => "cv{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active']);

    $department = Department::create(['name' => "DepCV {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosCV {$n}", 'department_id' => $department->id]);

    foreach ($contractStatuses as $i => $status) {
        Contract::create([
            'employee_id' => $employee->id,
            'type' => 'plazo_fijo',
            'start_date' => now()->subDays(120 - $i)->toDateString(),
            'end_date' => $status === 'active' ? now()->addDays(30)->toDateString() : now()->subDays(10 + $i)->toDateString(),
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

    return $employee;
}

function expiredVisibilityAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

it('el scope incluye a quien tiene el contrato vencido y ninguno vigente', function () {
    $vencido = expiredVisibilityEmployee(['expired']);

    expect(Employee::query()->withExpiredContract()->pluck('id')->all())->toBe([$vencido->id]);
});

it('el scope excluye a quien tiene otro contrato activo o suspendido', function (string $vigente) {
    expiredVisibilityEmployee(['expired', $vigente]);

    expect(Employee::query()->withExpiredContract()->count())->toBe(0);
})->with(['activo' => 'active', 'suspendido' => 'suspended']);

it('el scope excluye a quien nunca tuvo un contrato vencido', function (array $statuses) {
    expiredVisibilityEmployee($statuses);

    expect(Employee::query()->withExpiredContract()->count())->toBe(0);
})->with([
    'sin contratos' => [[]],
    'solo vigente' => [['active']],
    'terminado' => [['terminated']],
    'renovado' => [['renewed', 'active']],
]);

it('contracts:expire vence el contrato pero deja activo al empleado y a sus asignaciones', function () {
    $employee = expiredVisibilityEmployee(['active']);
    Contract::query()->where('employee_id', $employee->id)->update(['end_date' => now()->subDays(2)->toDateString()]);

    $this->artisan(ExpireContracts::class)->assertSuccessful();

    expect(Contract::query()->where('employee_id', $employee->id)->value('status'))->toBe('expired')
        ->and($employee->fresh()->status)->toBe('active')
        ->and(Employee::query()->active()->withExpiredContract()->pluck('id')->all())->toBe([$employee->id]);
});

it('el listado de empleados filtra por contrato vencido sin renovar', function () {
    expiredVisibilityAdmin();
    $vencido = expiredVisibilityEmployee(['expired']);
    $vigente = expiredVisibilityEmployee(['active']);

    Livewire::test(ListEmployees::class)
        ->set('activeTab', 'active')
        ->filterTable('expired_contract', true)
        ->assertCanSeeTableRecords([$vencido])
        ->assertCanNotSeeTableRecords([$vigente]);
});

it('la bandeja Por resolver cuenta a los empleados activos con contrato vencido', function () {
    expiredVisibilityAdmin();
    expiredVisibilityEmployee(['expired']);
    expiredVisibilityEmployee(['expired']);
    expiredVisibilityEmployee(['expired', 'active']);
    $inactivo = expiredVisibilityEmployee(['expired']);
    $inactivo->update(['status' => 'inactive']);

    $section = collect(AttendanceInboxService::sections())->firstWhere('key', 'expired_contract');

    expect($section['count'])->toBe(2)
        ->and($section['url'])->toContain('expired_contract');
});

// ─── Reparación de contratos vencidos con fecha de fin futura ────────────────

it('contracts:reactivate-misexpired vuelve a vigente el vencido con fin futuro y respeta al que ya tiene otro vigente', function () {
    $company = Company::create(['name' => 'Empresa Repara', 'ruc' => '5200000-1', 'employer_number' => 5200000]);
    $branch = Branch::create(['name' => 'Central', 'company_id' => $company->id]);
    $department = Department::create(['name' => 'General', 'company_id' => $company->id]);
    $position = Position::create(['name' => 'Cajero', 'department_id' => $department->id]);

    $make = function (string $first, string $status, string $end) use ($branch, $position, $department) {
        static $ci = 5200100;
        $employee = Employee::create(['first_name' => $first, 'last_name' => 'Repara', 'ci' => (string) $ci++, 'email' => strtolower($first).$ci.'@test.com', 'branch_id' => $branch->id, 'status' => 'active']);

        return [$employee, Contract::create([
            'employee_id' => $employee->id, 'type' => 'plazo_fijo', 'start_date' => now()->subMonths(6), 'end_date' => $end,
            'salary_type' => 'mensual', 'salary' => 2_550_000, 'position_id' => $position->id, 'department_id' => $department->id, 'status' => $status,
        ])];
    };

    [, $wrong] = $make('Mal', 'expired', now()->addMonths(3)->toDateString());
    [, $truly] = $make('Vencido', 'expired', now()->subDays(5)->toDateString());
    [$both, $oldFuture] = $make('Doble', 'expired', now()->addMonths(2)->toDateString());
    Contract::create([
        'employee_id' => $both->id, 'type' => 'indefinido', 'start_date' => now()->subDays(10), 'salary_type' => 'mensual',
        'salary' => 2_550_000, 'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
    ]);

    $this->artisan('contracts:reactivate-misexpired', ['--dry-run' => true])->expectsOutputToContain('[dry-run] Se reactivarían 1 contrato(s); 1 omitido(s).')->assertSuccessful();
    expect($wrong->fresh()->status)->toBe('expired');

    $this->artisan('contracts:reactivate-misexpired')->expectsOutputToContain('Reactivados 1 contrato(s); 1 omitido(s).')->assertSuccessful();

    expect($wrong->fresh()->status)->toBe('active')
        ->and($truly->fresh()->status)->toBe('expired')
        ->and($oldFuture->fresh()->status)->toBe('expired');
});
