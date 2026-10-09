<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\OrgChartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $this->actingAs($user);

    $this->company = Company::factory()->complete()->create();
    $this->branch = Branch::create(['company_id' => $this->company->id, 'name' => 'Casa Central']);
    $this->sales = Department::create(['name' => 'Ventas', 'company_id' => $this->company->id]);
    $this->ops = Department::create(['name' => 'Operaciones', 'company_id' => $this->company->id]);
});

/**
 * Empleado activo con contrato en el cargo indicado; sin contrato (y por lo tanto sin cargo) si `$position` es null.
 * `$contractStatus` permite crear contratos suspendidos; `$employeeStatus`, empleados inactivos.
 */
function orgEmployee(Branch $branch, ?Position $position, string $first = 'Ana', string $contractStatus = 'active', string $employeeStatus = 'active'): Employee
{
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'status' => $employeeStatus, 'first_name' => $first, 'last_name' => 'Pérez']);

    if ($position !== null) {
        Contract::create([
            'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => '2022-03-15',
            'salary_type' => 'mensual', 'salary' => 3000000, 'payroll_type' => 'monthly',
            'position_id' => $position->id, 'department_id' => $position->department_id,
            'status' => $contractStatus,
        ]);
    }

    return $employee;
}

/** @return array<int, string> Nombres de cargos del árbol, aplanados. */
function orgPositionNames(array $tree): array
{
    $names = [];
    $walk = function (array $nodes) use (&$walk, &$names): void {
        foreach ($nodes as $node) {
            $names[] = $node['name'];
            $walk($node['children']);
        }
    };
    foreach ($tree as $department) {
        $walk($department['positions']);
    }

    return $names;
}

function orgBuild(Company $company, array $filters = []): array
{
    return app(OrgChartService::class)->build($company, $filters);
}

// ─── Árbol ──────────────────────────────────────────────────────────────────

it('arma el árbol de cargos con sus empleados y los ancestros', function () {
    $manager = Position::create(['name' => 'Gerente', 'department_id' => $this->sales->id]);
    $seller = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id, 'parent_id' => $manager->id]);
    orgEmployee($this->branch, $seller, 'Luis');

    $data = orgBuild($this->company, ['vacancies' => false]);

    expect($data['tree'])->toHaveCount(1)
        ->and($data['tree'][0]['name'])->toBe('Ventas')
        ->and($data['tree'][0]['positions'][0]['name'])->toBe('Gerente')
        ->and($data['tree'][0]['positions'][0]['children'][0]['name'])->toBe('Vendedor')
        ->and($data['tree'][0]['positions'][0]['children'][0]['employees'][0]['name'])->toContain('Luis');
});

it('no hace una consulta por cargo (sin N+1)', function () {
    $parent = null;
    foreach (range(1, 12) as $i) {
        $position = Position::create(['name' => "Cargo {$i}", 'department_id' => $this->sales->id, 'parent_id' => $parent?->id]);
        orgEmployee($this->branch, $position, "Emp{$i}");
        $parent = $position;
    }

    DB::enableQueryLog();
    orgBuild($this->company);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(10);
});

it('no se cuelga ni pierde cargos cuando hay un ciclo de padres', function () {
    $a = Position::create(['name' => 'Cargo A', 'department_id' => $this->sales->id]);
    $b = Position::create(['name' => 'Cargo B', 'department_id' => $this->sales->id, 'parent_id' => $a->id]);
    $a->update(['parent_id' => $b->id]);
    orgEmployee($this->branch, $a, 'Ciclo');

    $names = orgPositionNames(orgBuild($this->company)['tree']);

    expect($names)->toContain('Cargo A')->toContain('Cargo B');
});

it('un cargo cuyo padre está en otro departamento aparece como raíz del suyo', function () {
    $boss = Position::create(['name' => 'Jefe de operaciones', 'department_id' => $this->ops->id]);
    $seller = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id, 'parent_id' => $boss->id]);
    orgEmployee($this->branch, $seller);

    $data = orgBuild($this->company, ['vacancies' => false]);
    $sales = collect($data['tree'])->firstWhere('name', 'Ventas');

    expect($sales['positions'][0]['name'])->toBe('Vendedor');
});

// ─── Empleados ──────────────────────────────────────────────────────────────

it('marca a los de contrato suspendido y deja afuera a los empleados inactivos', function () {
    $position = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id]);
    orgEmployee($this->branch, $position, 'Activo');
    orgEmployee($this->branch, $position, 'Suspendido', contractStatus: 'suspended');
    orgEmployee($this->branch, $position, 'Inactivo', employeeStatus: 'inactive');

    $employees = collect(orgBuild($this->company)['tree'][0]['positions'][0]['employees']);

    expect($employees)->toHaveCount(2)
        ->and($employees->firstWhere(fn ($e) => str_contains($e['name'], 'Suspendido'))['suspended'])->toBeTrue()
        ->and($employees->contains(fn ($e) => str_contains($e['name'], 'Inactivo')))->toBeFalse();
});

it('trae la fecha de ingreso y la sucursal (que solo se muestra con varias)', function () {
    $position = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id]);
    orgEmployee($this->branch, $position);

    $data = orgBuild($this->company);
    $employee = $data['tree'][0]['positions'][0]['employees'][0];

    expect($employee['since'])->toBe('03/2022')
        ->and($employee['branch'])->toBe('Casa Central')
        ->and($data['showBranch'])->toBeFalse();

    Branch::create(['company_id' => $this->company->id, 'name' => 'Sucursal Dos']);

    expect(orgBuild($this->company)['showBranch'])->toBeTrue();
});

it('los empleados activos sin contrato vigente van aparte', function () {
    orgEmployee($this->branch, null, 'Sincargo');

    $data = orgBuild($this->company, ['vacancies' => false]);

    expect($data['unassigned'])->toHaveCount(1)
        ->and($data['unassigned'][0]['name'])->toContain('Sincargo');
});

// ─── Vacantes ───────────────────────────────────────────────────────────────

it('muestra los cargos sin ocupante como vacantes y permite ocultarlos', function () {
    $filled = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id]);
    Position::create(['name' => 'Supervisor', 'department_id' => $this->sales->id]);
    orgEmployee($this->branch, $filled);

    $with = orgBuild($this->company, ['vacancies' => true]);
    $without = orgBuild($this->company, ['vacancies' => false]);

    expect(orgPositionNames($with['tree']))->toContain('Supervisor')
        ->and($with['stats']['vacancies'])->toBe(1)
        ->and(orgPositionNames($without['tree']))->not->toContain('Supervisor')
        ->and($without['stats']['vacancies'])->toBe(0);
});

it('un departamento sin empleados aparece solo por sus vacantes', function () {
    Position::create(['name' => 'Jefe de operaciones', 'department_id' => $this->ops->id]);

    expect(collect(orgBuild($this->company, ['vacancies' => true])['tree'])->pluck('name')->all())->toBe(['Operaciones'])
        ->and(orgBuild($this->company, ['vacancies' => false])['tree'])->toBe([]);
});

it('un cargo ocupado en otra sucursal no es vacante al filtrar por sucursal', function () {
    $other = Branch::create(['company_id' => $this->company->id, 'name' => 'Sucursal Dos']);
    $position = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id]);
    orgEmployee($other, $position);

    $data = orgBuild($this->company, ['branch' => $this->branch->id, 'vacancies' => true]);

    expect(orgPositionNames($data['tree']))->not->toContain('Vendedor')
        ->and($data['stats']['vacancies'])->toBe(0);
});

// ─── Filtros ────────────────────────────────────────────────────────────────

it('filtra por sucursal', function () {
    $other = Branch::create(['company_id' => $this->company->id, 'name' => 'Sucursal Dos']);
    $position = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id]);
    orgEmployee($this->branch, $position, 'Central');
    orgEmployee($other, $position, 'Segunda');

    $names = collect(orgBuild($this->company, ['branch' => $other->id])['tree'][0]['positions'][0]['employees'])->pluck('name')->implode(',');

    expect($names)->toContain('Segunda')->not->toContain('Central');
});

it('filtra por departamento y deja afuera a los sin cargo', function () {
    $seller = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id]);
    $driver = Position::create(['name' => 'Chofer', 'department_id' => $this->ops->id]);
    orgEmployee($this->branch, $seller);
    orgEmployee($this->branch, $driver);
    orgEmployee($this->branch, null, 'Sincargo');

    $data = orgBuild($this->company, ['department' => $this->ops->id, 'vacancies' => false]);

    expect(orgPositionNames($data['tree']))->toBe(['Chofer'])
        ->and($data['unassigned'])->toBe([]);
});

it('busca por nombre de empleado y por nombre de cargo', function () {
    $seller = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id]);
    $driver = Position::create(['name' => 'Chofer', 'department_id' => $this->ops->id]);
    orgEmployee($this->branch, $seller, 'Marcelo');
    orgEmployee($this->branch, $seller, 'Otro');
    orgEmployee($this->branch, $driver, 'Zulma');

    $byEmployee = orgBuild($this->company, ['search' => 'marce']);
    $byPosition = orgBuild($this->company, ['search' => 'chof']);

    expect(orgPositionNames($byEmployee['tree']))->toBe(['Vendedor'])
        ->and($byEmployee['tree'][0]['positions'][0]['employees'])->toHaveCount(1)
        ->and(orgPositionNames($byPosition['tree']))->toBe(['Chofer'])
        ->and($byPosition['tree'][0]['positions'][0]['employees'])->toHaveCount(1);
});

it('la búsqueda no lista vacantes salvo que el nombre del cargo coincida', function () {
    Position::create(['name' => 'Supervisor', 'department_id' => $this->sales->id]);

    expect(orgPositionNames(orgBuild($this->company, ['search' => 'zzz'])['tree']))->toBe([])
        ->and(orgPositionNames(orgBuild($this->company, ['search' => 'superv'])['tree']))->toBe(['Supervisor']);
});

// ─── Vistas ─────────────────────────────────────────────────────────────────

it('la vista muestra vacantes, suspendidos y los filtros', function () {
    $seller = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id]);
    Position::create(['name' => 'Supervisor', 'department_id' => $this->sales->id]);
    orgEmployee($this->branch, $seller, 'Suspe', contractStatus: 'suspended');
    Branch::create(['company_id' => $this->company->id, 'name' => 'Sucursal Dos']);

    $this->get(route('org-chart.show', $this->company))
        ->assertOk()
        ->assertSee('Vacante')
        ->assertSee('Suspendido')
        ->assertSee('Desde 03/2022')
        ->assertSee('Mostrar vacantes')
        ->assertSee('Sucursal Dos');
});

it('la vista respeta los filtros de la URL y los pasa al PDF', function () {
    $seller = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id]);
    Position::create(['name' => 'Supervisor', 'department_id' => $this->sales->id]);
    orgEmployee($this->branch, $seller);

    $response = $this->get(route('org-chart.show', ['company' => $this->company, 'vacancies' => 0, 'q' => 'vend']));

    $response->assertOk()
        ->assertDontSee('Supervisor')
        ->assertSee('vacancies=0', false)
        ->assertSee('q=vend', false);
});

it('rechaza una sucursal o departamento de otra empresa', function () {
    $foreign = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Ajena']);

    $this->get(route('org-chart.show', ['company' => $this->company, 'branch' => $foreign->id]))
        ->assertSessionHasErrors('branch');
});

it('el PDF se genera con los filtros y deja constancia de ellos', function () {
    $seller = Position::create(['name' => 'Vendedor', 'department_id' => $this->sales->id]);
    orgEmployee($this->branch, $seller);

    $this->get(route('org-chart.pdf', ['company' => $this->company, 'department' => $this->sales->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('cache-control', 'must-revalidate, no-cache, no-store, private');
});

it('muestra el estado vacío cuando los filtros no encuentran nada', function () {
    $this->get(route('org-chart.show', ['company' => $this->company, 'q' => 'inexistente']))
        ->assertOk()
        ->assertSee('Ningún cargo ni empleado coincide');
});

it('la vista usa los estilos compartidos del panel y ofrece el interruptor de tema', function () {
    $html = $this->get(route('org-chart.show', $this->company))->assertOk()->getContent();

    expect($html)
        ->toContain('id="btnThemeToggle"')
        ->toContain('color-scheme')
        ->not->toContain('<style>');
});
