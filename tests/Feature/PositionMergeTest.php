<?php

use App\Filament\Resources\PositionResource\Pages\ListPositions;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\PositionMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function mergeCompany(): Company
{
    static $n = 5100000;
    $n++;

    return Company::create(['name' => "Empresa Fusion {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
}

function mergePosition(Company $company, string $name, string $department = 'General', array $attributes = []): Position
{
    $dept = Department::firstOrCreate(['name' => $department, 'company_id' => $company->id]);

    return Position::create(['name' => $name, 'department_id' => $dept->id, ...$attributes]);
}

function mergeEmployee(Company $company, Position $position, string $first): Employee
{
    static $ci = 5100100;
    $branch = Branch::firstOrCreate(['name' => 'Central', 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => $first, 'last_name' => 'Fusion', 'ci' => (string) $ci++, 'email' => strtolower($first).$ci.'@test.com', 'branch_id' => $branch->id, 'status' => 'active']);

    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear(), 'salary_type' => 'mensual',
        'salary' => 2_550_000, 'position_id' => $position->id, 'department_id' => $position->department_id, 'status' => 'active',
    ]);

    return $employee;
}

it('fusiona un cargo duplicado: mueve contratos, dependientes y sucursales, y elimina el sobrante', function () {
    $company = mergeCompany();
    $keep = mergePosition($company, 'Supervisor de Sucursal', 'Sucursales');
    $dup = mergePosition($company, 'Supervisor De Sucursal', 'Ventas');
    $child = mergePosition($company, 'Cajero', 'Sucursales', ['parent_id' => $dup->id]);
    $employee = mergeEmployee($company, $dup, 'Ana');
    $dup->terminalBranches()->attach(Branch::firstWhere('company_id', $company->id)->id);

    $service = app(PositionMergeService::class);
    $preview = $service->preview($dup, $keep);

    expect($preview['error'])->toBeNull()
        ->and($preview['contracts'])->toBe(1)
        ->and($preview['active_employees'])->toBe(1)
        ->and($preview['children'])->toBe(1)
        ->and(implode(' ', $preview['differences']))->toContain('departamentos distintos');

    expect($service->merge($dup, $keep))->toBe(['contracts' => 1, 'children' => 1]);

    expect(Position::find($dup->id))->toBeNull()
        ->and($employee->fresh()->activeContract->position_id)->toBe($keep->id)
        ->and($employee->fresh()->activeContract->department_id)->toBe($keep->department_id)
        ->and($child->fresh()->parent_id)->toBe($keep->id)
        ->and($keep->terminalBranches()->count())->toBe(1);
});

it('si el cargo que se conserva reportaba al sobrante, pasa a reportar a donde reportaba este', function () {
    $company = mergeCompany();
    $top = mergePosition($company, 'Gerente');
    $dup = mergePosition($company, 'Encargada', attributes: ['parent_id' => $top->id]);
    $keep = mergePosition($company, 'Encargada de Sucursal', attributes: ['parent_id' => $dup->id]);

    app(PositionMergeService::class)->merge($dup->fresh(), $keep->fresh());

    expect($keep->fresh()->parent_id)->toBe($top->id);
});

it('no fusiona un cargo consigo mismo ni cargos de empresas distintas', function () {
    $a = mergeCompany();
    $b = mergeCompany();
    $one = mergePosition($a, 'Cajero');
    $other = mergePosition($b, 'Cajero');
    $service = app(PositionMergeService::class);

    expect($service->preview($one, $one)['error'])->toContain('consigo mismo')
        ->and($service->preview($one, $other)['error'])->toContain('misma empresa');

    expect(fn () => $service->merge($one, $other))->toThrow(InvalidArgumentException::class);
    expect(Position::find($one->id))->not->toBeNull();
});

it('la acción de la tabla fusiona y avisa', function () {
    $this->actingAs(tap(User::factory()->create(), fn ($user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = mergeCompany();
    $keep = mergePosition($company, 'Deposito');
    $dup = mergePosition($company, 'deposito', 'Otro');
    $employee = mergeEmployee($company, $dup, 'Beto');

    Livewire::test(ListPositions::class)
        ->callTableAction('merge_into', $dup, ['into_id' => $keep->id])
        ->assertNotified();

    expect(Position::find($dup->id))->toBeNull()
        ->and($employee->fresh()->activeContract->position_id)->toBe($keep->id);
});
