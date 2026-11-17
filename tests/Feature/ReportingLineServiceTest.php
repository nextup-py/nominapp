<?php

use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\ReportingLineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function reportingBranch(string $name, ?Company $company = null): Branch
{
    static $ruc = 4900000;
    $ruc++;
    $company ??= Company::create(['name' => "Empresa Linea {$ruc}", 'ruc' => "{$ruc}-1", 'employer_number' => $ruc]);

    return Branch::create(['name' => $name, 'company_id' => $company->id]);
}

function reportingPosition(Company $company, string $name): Position
{
    $department = Department::firstOrCreate(['name' => 'General', 'company_id' => $company->id]);

    return Position::firstOrCreate(['name' => $name, 'department_id' => $department->id]);
}

function reportingEmployee(string $first, Branch $branch, ?Position $position = null, string $status = 'active'): Employee
{
    static $ci = 4900100;
    $employee = Employee::create([
        'first_name' => $first, 'last_name' => 'Linea', 'ci' => (string) $ci++, 'email' => strtolower($first).$ci.'@test.com',
        'branch_id' => $branch->id, 'status' => $status,
    ]);

    if ($position) {
        Contract::create([
            'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear(), 'salary_type' => 'mensual',
            'salary' => 2_550_000, 'position_id' => $position->id, 'department_id' => $position->department_id, 'status' => 'active',
        ]);
    }

    return $employee->fresh();
}

it('valida que un superior sea posible: no uno mismo, activo, de la misma empresa y sin ciclos', function () {
    $service = app(ReportingLineService::class);
    $branch = reportingBranch('Central');
    $boss = reportingEmployee('Jefe', $branch);
    $worker = reportingEmployee('Obrero', $branch);
    $inactive = reportingEmployee('Baja', $branch, status: 'inactive');
    $stranger = reportingEmployee('Ajeno', reportingBranch('Otra empresa'));

    expect($service->validate($worker, $boss))->toBeNull()
        ->and($service->validate($worker, $worker))->toBe(ReportingLineService::ERROR_SELF)
        ->and($service->validate($worker, $inactive))->toBe(ReportingLineService::ERROR_INACTIVE)
        ->and($service->validate($worker, $stranger))->toBe(ReportingLineService::ERROR_OTHER_COMPANY);

    expect($service->assign($worker, $boss))->toBeNull();
    expect($worker->fresh()->reports_to_id)->toBe($boss->id)
        ->and($service->validate($boss->fresh(), $worker->fresh()))->toBe(ReportingLineService::ERROR_CYCLE);

    $deep = reportingEmployee('Practicante', $branch);
    $service->assign($deep, $worker->fresh());
    expect($service->validate($boss->fresh(), $deep->fresh()))->toBe(ReportingLineService::ERROR_CYCLE);
});

it('asigna y quita el superior, y en bloque cuenta lo omitido', function () {
    $service = app(ReportingLineService::class);
    $branch = reportingBranch('Central');
    $boss = reportingEmployee('Jefe', $branch);
    $a = reportingEmployee('Uno', $branch);
    $b = reportingEmployee('Dos', $branch);

    $result = $service->assignMany([$a, $b, $boss], $boss);

    expect($result)->toBe(['assigned' => 2, 'skipped' => [ReportingLineService::ERROR_SELF => 1]])
        ->and($a->fresh()->reports_to_id)->toBe($boss->id);

    expect($service->assign($a->fresh(), null))->toBeNull();
    expect($a->fresh()->reports_to_id)->toBeNull();
});

it('la regla por cargo asigna a quien tiene un único superior posible en su sucursal y explica el resto', function () {
    $service = app(ReportingLineService::class);
    $company = Company::create(['name' => 'Empresa Regla', 'ruc' => '4950000-1', 'employer_number' => 4950000]);
    $encargada = reportingPosition($company, 'Encargada');
    $cajero = reportingPosition($company, 'Cajero');

    $luque = reportingBranch('Luque', $company);
    $capiata = reportingBranch('Capiata', $company);
    $itaugua = reportingBranch('Itaugua', $company);

    $luqueBoss = reportingEmployee('LuqueEncargada', $luque, $encargada);
    $luqueCajero = reportingEmployee('LuqueCajero', $luque, $cajero);
    $capiataBossA = reportingEmployee('CapiataUna', $capiata, $encargada);
    reportingEmployee('CapiataOtra', $capiata, $encargada);
    $capiataCajero = reportingEmployee('CapiataCajero', $capiata, $cajero);
    $itauguaCajero = reportingEmployee('ItauguaCajero', $itaugua, $cajero);
    $alreadyHasBoss = reportingEmployee('YaTiene', $luque, $cajero);
    $alreadyHasBoss->update(['reports_to_id' => $capiataBossA->id]);

    $rule = ['position_ids' => [$cajero->id], 'manager_position_id' => $encargada->id];
    $rows = $service->preview($rule)->keyBy(fn ($row) => $row['employee']->first_name);

    expect($rows['LuqueCajero']['manager']?->id)->toBe($luqueBoss->id)
        ->and($rows['LuqueCajero']['skip'])->toBeNull()
        ->and($rows['CapiataCajero']['skip'])->toBe(ReportingLineService::SKIP_MANY_BOSSES)
        ->and($rows['ItauguaCajero']['skip'])->toBe(ReportingLineService::SKIP_NO_BOSS)
        ->and($rows['YaTiene']['skip'])->toBe(ReportingLineService::SKIP_ALREADY)
        ->and($rows)->not->toHaveKey('LuqueEncargada');

    expect($service->apply($rule))->toBe(['assigned' => 1, 'skipped' => [
        ReportingLineService::SKIP_MANY_BOSSES => 1,
        ReportingLineService::SKIP_NO_BOSS => 1,
        ReportingLineService::SKIP_ALREADY => 1,
    ]]);

    expect($luqueCajero->fresh()->reports_to_id)->toBe($luqueBoss->id)
        ->and($capiataCajero->fresh()->reports_to_id)->toBeNull()
        ->and($itauguaCajero->fresh()->reports_to_id)->toBeNull();
});

it('la regla con un superior fijo respeta la sucursal, y puede pisar al que ya tiene superior', function () {
    $service = app(ReportingLineService::class);
    $company = Company::create(['name' => 'Empresa Fija', 'ruc' => '4960000-1', 'employer_number' => 4960000]);
    $central = reportingBranch('Central', $company);
    $luque = reportingBranch('Luque', $company);
    $supervisor = reportingEmployee('Supervisor', $central);
    $inLuque = reportingEmployee('EnLuque', $luque);
    $inCentral = reportingEmployee('EnCentral', $central);
    $inCentral->update(['reports_to_id' => $inLuque->id]);

    $rule = ['branch_id' => $luque->id, 'manager_id' => $supervisor->id];
    expect($service->apply($rule)['assigned'])->toBe(1)
        ->and($inLuque->fresh()->reports_to_id)->toBe($supervisor->id)
        ->and($inCentral->fresh()->reports_to_id)->toBe($inLuque->id);

    $override = ['branch_id' => $central->id, 'manager_id' => $supervisor->id, 'only_without_manager' => false];
    $result = $service->apply($override);

    expect($result['assigned'])->toBe(1)
        ->and($result['skipped'])->toBe([ReportingLineService::ERROR_SELF => 1])
        ->and($inCentral->fresh()->reports_to_id)->toBe($supervisor->id);
});

// ─── Pantalla de Empleados ────────────────────────────────────────────────────

it('la acción masiva asigna el superior a los seleccionados y avisa lo omitido', function () {
    $this->actingAs(tap(User::factory()->create(), fn ($user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $branch = reportingBranch('Central');
    $boss = reportingEmployee('Jefe', $branch);
    $a = reportingEmployee('Uno', $branch);
    $b = reportingEmployee('Dos', $branch);

    Livewire::test(ListEmployees::class)
        ->callTableBulkAction('assign_manager', [$a, $b, $boss], ['manager_id' => $boss->id])
        ->assertNotified();

    expect($a->fresh()->reports_to_id)->toBe($boss->id)
        ->and($b->fresh()->reports_to_id)->toBe($boss->id)
        ->and($boss->fresh()->reports_to_id)->toBeNull();
});

it('la regla del encabezado aplica y respeta lo que la vista previa omite', function () {
    $this->actingAs(tap(User::factory()->create(), fn ($user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = Company::create(['name' => 'Empresa UI', 'ruc' => '4970000-1', 'employer_number' => 4970000]);
    $encargada = reportingPosition($company, 'Encargada');
    $cajero = reportingPosition($company, 'Cajero');
    $branch = reportingBranch('Luque', $company);
    $boss = reportingEmployee('Encargada', $branch, $encargada);
    $cashier = reportingEmployee('Cajero', $branch, $cajero);

    Livewire::test(ListEmployees::class)
        ->callAction('assign_managers_by_rule', [
            'mode' => 'position',
            'position_ids' => [$cajero->id],
            'manager_position_id' => $encargada->id,
            'only_without_manager' => true,
        ])
        ->assertNotified();

    expect($cashier->fresh()->reports_to_id)->toBe($boss->id);
});

it('el formulario del empleado rechaza un superior que crearía un ciclo', function () {
    $this->actingAs(tap(User::factory()->create(), fn ($user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $branch = reportingBranch('Central');
    $boss = reportingEmployee('Jefe', $branch);
    $worker = reportingEmployee('Obrero', $branch);
    $worker->update(['reports_to_id' => $boss->id]);

    Livewire::test(EditEmployee::class, ['record' => $boss->getRouteKey()])
        ->fillForm(['reports_to_id' => $worker->id])
        ->call('save')
        ->assertHasFormErrors(['reports_to_id']);

    expect($boss->fresh()->reports_to_id)->toBeNull();
});
