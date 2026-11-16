<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Terminal;
use App\Services\EmployeeDescriptorSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Supervisoras que pueden marcar en terminales de otras sucursales según el acceso de su cargo. */
function termCompany(): Company
{
    return Company::first() ?? Company::create(['name' => 'EmpTA', 'ruc' => '5500000-1', 'employer_number' => 5500000]);
}

function termBranch(string $name): Branch
{
    return Branch::firstOrCreate(['name' => $name, 'company_id' => termCompany()->id]);
}

function termEmployee(Branch $branch, string $access = 'own', array $selected = [], string $first = 'Sup'): Employee
{
    static $n = 5500100;
    $n++;

    $employee = Employee::create([
        'first_name' => $first, 'last_name' => "Term{$n}", 'ci' => (string) $n, 'email' => "ta{$n}@test.com",
        'branch_id' => $branch->id, 'status' => 'active', 'face_descriptor' => array_fill(0, 128, 0.1),
    ]);

    $department = Department::firstOrCreate(['name' => 'DepTA', 'company_id' => termCompany()->id]);
    $position = Position::create(['name' => "Cargo {$n}", 'department_id' => $department->id, 'terminal_access' => $access]);
    $position->terminalBranches()->sync(collect($selected)->map->id->all());

    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear()->toDateString(),
        'salary_type' => 'mensual', 'salary' => 3_044_000, 'payroll_type' => 'monthly', 'payment_method' => 'cash',
        'work_modality' => 'presencial', 'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
    ]);

    return $employee->fresh();
}

it('por defecto solo marca en la sucursal propia', function () {
    $central = termBranch('Central');
    $luque = termBranch('Luque');
    $employee = termEmployee($central);

    expect($employee->canMarkAtBranch($central->id))->toBeTrue()
        ->and($employee->canMarkAtBranch($luque->id))->toBeFalse()
        ->and(Employee::markableAtBranch($luque->id)->whereKey($employee->id)->exists())->toBeFalse();
});

it('un cargo con acceso a cualquier sucursal marca en todas', function () {
    $employee = termEmployee(termBranch('Central'), 'all');

    expect($employee->canMarkAtBranch(termBranch('Luque')->id))->toBeTrue()
        ->and(Employee::markableAtBranch(termBranch('Luque')->id)->whereKey($employee->id)->exists())->toBeTrue();
});

it('un cargo con sucursales elegidas marca en la propia y en las elegidas, no en otras', function () {
    $central = termBranch('Central');
    $luque = termBranch('Luque');
    $norte = termBranch('Norte');
    $employee = termEmployee($central, 'selected', [$luque]);

    expect($employee->canMarkAtBranch($central->id))->toBeTrue()
        ->and($employee->canMarkAtBranch($luque->id))->toBeTrue()
        ->and($employee->canMarkAtBranch($norte->id))->toBeFalse()
        ->and(Employee::markableAtBranch($norte->id)->whereKey($employee->id)->exists())->toBeFalse();
});

it('el scope SQL coincide con el cálculo en PHP', function () {
    $branches = [termBranch('Central'), termBranch('Luque'), termBranch('Norte')];
    $employees = [
        termEmployee($branches[0]),
        termEmployee($branches[0], 'all'),
        termEmployee($branches[0], 'selected', [$branches[1]]),
        termEmployee($branches[1], 'selected', [$branches[2]]),
    ];

    foreach ($branches as $branch) {
        $sql = Employee::markableAtBranch($branch->id)->pluck('id')->all();
        foreach ($employees as $employee) {
            expect(in_array($employee->id, $sql, true))->toBe($employee->fresh()->canMarkAtBranch($branch->id));
        }
    }
});

it('el terminal descarga a quienes pueden marcar ahí, con su rostro, y no a los demás', function () {
    $central = termBranch('Central');
    $luque = termBranch('Luque');
    $propia = termEmployee($luque, first: 'Propia');
    $visitante = termEmployee($central, 'all', first: 'Visita');
    $ajena = termEmployee($central, first: 'Ajena');
    $terminal = Terminal::create(['name' => 'T Luque', 'code' => 'LUQ1', 'branch_id' => $luque->id, 'status' => 'active']);

    $ids = collect(app(EmployeeDescriptorSyncService::class)->deltaSince($terminal, null)['employees'])->pluck('id')->all();

    expect($ids)->toContain($propia->id, $visitante->id)->not->toContain($ajena->id);
});

it('al quitarle el acceso al cargo, el terminal recibe al empleado como baja', function () {
    $central = termBranch('Central');
    $luque = termBranch('Luque');
    $visitante = termEmployee($central, 'all');
    $terminal = Terminal::create(['name' => 'T Luque', 'code' => 'LUQ2', 'branch_id' => $luque->id, 'status' => 'active']);
    $since = now()->subMinute();

    $visitante->activeContract->position->update(['terminal_access' => 'own']);

    $delta = app(EmployeeDescriptorSyncService::class)->deltaSince($terminal, $since);

    expect($delta['tombstones'])->toContain($visitante->id)
        ->and(collect($delta['employees'])->pluck('id')->all())->not->toContain($visitante->id);
});

it('al darle acceso al cargo, el sync incremental ya incluye al empleado', function () {
    $central = termBranch('Central');
    $luque = termBranch('Luque');
    $supervisora = termEmployee($central);
    $terminal = Terminal::create(['name' => 'T Luque', 'code' => 'LUQ3', 'branch_id' => $luque->id, 'status' => 'active']);
    $since = now()->addSeconds(5);
    $this->travel(10)->seconds();

    $supervisora->activeContract->position->update(['terminal_access' => 'all']);

    $ids = collect(app(EmployeeDescriptorSyncService::class)->deltaSince($terminal, $since)['employees'])->pluck('id')->all();

    expect($ids)->toContain($supervisora->id);
});

it('el estado de marcación responde para quien puede marcar en otra sucursal y 404 para los demás', function () {
    $central = termBranch('Central');
    $luque = termBranch('Luque');
    $visitante = termEmployee($central, 'all');
    $ajena = termEmployee($central);
    $terminal = Terminal::create(['name' => 'T Luque', 'code' => 'LUQ4', 'branch_id' => $luque->id, 'status' => 'active']);
    Sanctum::actingAs($terminal, ['terminal:sync']);

    $this->getJson("/api/v1/terminal/employees/{$visitante->id}/status")->assertOk();
    $this->getJson("/api/v1/terminal/employees/{$ajena->id}/status")->assertNotFound();
});
