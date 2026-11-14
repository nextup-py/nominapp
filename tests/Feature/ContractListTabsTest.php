<?php

use App\Filament\Resources\ContractResource\Pages\ListContracts;
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
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Regresión: las pestañas de estado recibían `Builder $q`. Filament inyecta la consulta del
 * listado por el nombre del parámetro (`$query`); con otro nombre el contenedor construía un
 * Builder vacío, sin modelo, y toda pestaña distinta de «Todos» devolvía un error 500.
 */

/** Contrato indefinido en el estado indicado, con su propio empleado. */
function contractForTabs(string $status): Contract
{
    static $n = 5100000;
    $n++;

    $company = Company::create(['name' => "EmpTab {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucTab {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepTab {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosTab {$n}", 'department_id' => $department->id]);
    $employee = Employee::create(['first_name' => 'Test', 'last_name' => "Tab{$n}", 'ci' => (string) $n, 'email' => "tab{$n}@test.com", 'branch_id' => $branch->id, 'status' => 'active']);

    return Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::today()->subDays(60)->toDateString(),
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

beforeEach(function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
});

it('abre cada pestaña de estado del listado de contratos sin error', function (string $tab) {
    Livewire::test(ListContracts::class)
        ->set('activeTab', $tab)
        ->assertSuccessful();
})->with(['all', 'expiring', 'draft', 'active', 'suspended', 'expired', 'terminated', 'renewed']);

it('la pestaña Vencidos lista solo contratos vencidos', function () {
    $expired = contractForTabs('expired');
    $active = contractForTabs('active');

    Livewire::test(ListContracts::class)
        ->set('activeTab', 'expired')
        ->assertCanSeeTableRecords([$expired])
        ->assertCanNotSeeTableRecords([$active]);
});
