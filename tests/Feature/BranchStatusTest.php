<?php

use App\Exports\BranchesExport;
use App\Filament\Resources\BranchResource\Pages\CreateBranch;
use App\Filament\Resources\BranchResource\Pages\EditBranch;
use App\Filament\Resources\BranchResource\Pages\ListBranches;
use App\Filament\Resources\BranchResource\Pages\ViewBranch;
use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\PyDepartment;
use App\Models\Terminal;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function statusAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

/** Sucursal activa y completa: ubicación, ciudad y teléfono cargados. */
function completeBranch(array $overrides = []): Branch
{
    static $n = 0;
    $n++;

    return Branch::create(array_merge([
        'company_id' => $overrides['company_id'] ?? Company::factory()->create()->id,
        'name' => "Sucursal Estado {$n}",
        'phone' => '0981123456',
        'city' => 'Luque',
        'coordinates' => ['lat' => -25.27, 'lng' => -57.48],
    ], $overrides));
}

function branchEmployee(Branch $branch, bool $withContract = true, string $status = 'active'): Employee
{
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'status' => $status]);

    if ($withContract) {
        $department = Department::create(['name' => 'Dep '.$employee->id, 'company_id' => $branch->company_id]);
        $position = Position::create(['name' => 'Cargo '.$employee->id, 'department_id' => $department->id]);

        Contract::create([
            'position_id' => $position->id,
            'department_id' => $department->id,
            'employee_id' => $employee->id,
            'type' => 'indefinido',
            'start_date' => now()->subYear(),
            'salary_type' => 'mensual',
            'salary' => 3000000,
            'payroll_type' => 'monthly',
            'status' => 'active',
        ]);
    }

    return $employee;
}

// ─── Estado ─────────────────────────────────────────────────────────────────

it('las sucursales nuevas nacen activas', function () {
    $branch = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Nueva']);

    expect($branch->fresh()->is_active)->toBeTrue()
        ->and(Branch::active()->pluck('id')->all())->toContain($branch->id);
});

it('desactiva y reactiva una sucursal sin empleados activos desde el listado', function () {
    $this->actingAs(statusAdmin());
    $branch = completeBranch();
    branchEmployee($branch, status: 'inactive');

    Livewire::test(ListBranches::class)->callTableAction('toggle_status', $branch);
    expect($branch->fresh()->is_active)->toBeFalse()
        ->and(Branch::active()->pluck('id')->all())->not->toContain($branch->id);

    Livewire::test(ListBranches::class)->callTableAction('toggle_status', $branch);
    expect($branch->fresh()->is_active)->toBeTrue();
});

it('no desactiva una sucursal con empleados activos y avisa cuántos', function () {
    $this->actingAs(statusAdmin());
    $branch = completeBranch();
    branchEmployee($branch);
    branchEmployee($branch);

    Livewire::test(ListBranches::class)
        ->callTableAction('toggle_status', $branch)
        ->assertNotified('No se puede desactivar la sucursal');

    expect($branch->fresh()->is_active)->toBeTrue()
        ->and($branch->deactivationBlockers())->toBe(['empleados activos' => 2]);
});

it('el modelo se niega a desactivar una sucursal con empleados activos aunque no pase por el panel', function () {
    $branch = completeBranch();
    branchEmployee($branch);

    expect(fn () => $branch->update(['is_active' => false]))->toThrow(DomainException::class);
    expect($branch->fresh()->is_active)->toBeTrue();
});

it('desactivar una sucursal no modifica sus terminales', function () {
    $this->actingAs(statusAdmin());
    $branch = completeBranch();
    $terminal = Terminal::create(['name' => 'Terminal X', 'branch_id' => $branch->id]);

    Livewire::test(ListBranches::class)->callTableAction('toggle_status', $branch);

    expect($branch->fresh()->is_active)->toBeFalse()
        ->and($terminal->fresh()->status)->toBe('active');
});

it('la acción de estado exige el permiso de editar sucursales', function () {
    $role = Role::firstOrCreate(['name' => 'Solo ver sucursales', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any_branch', 'guard_name' => 'web']));
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    $branch = completeBranch();

    Livewire::test(ListBranches::class)->assertTableActionHidden('toggle_status', $branch);

    expect($branch->fresh()->is_active)->toBeTrue();
});

it('el detalle permite desactivar y la edición deshabilita el interruptor con empleados activos', function () {
    $this->actingAs(statusAdmin());
    config(['filament-google-maps.keys.web_key' => 'test-key']);

    $branch = completeBranch();
    branchEmployee($branch);

    Livewire::test(ViewBranch::class, ['record' => $branch->getKey()])
        ->callAction('toggle_status')
        ->assertNotified('No se puede desactivar la sucursal');

    $field = Livewire::test(EditBranch::class, ['record' => $branch->getKey()])
        ->instance()->form->getFlatFields()['is_active'];

    expect($field->isDisabled())->toBeTrue();
});

it('el cambio de estado queda en el historial', function () {
    config(['audit.console' => true]);

    $branch = completeBranch();
    $branch->update(['is_active' => false]);

    $audit = $branch->audits()->where('event', 'updated')->first();

    expect($audit->new_values)->toBe(['is_active' => false])
        ->and($branch->formatAuditFieldsForPresentation('new_values', $audit)->toHtml())->toContain('Inactiva');
});

// ─── Datos pendientes ───────────────────────────────────────────────────────

it('marca cada dato pendiente por separado', function () {
    $empty = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Vacía']);

    $loaded = Branch::withPendingData()->find($empty->id);

    expect($loaded->pendingDataList())->toBe(['Sin ubicación en el mapa', 'Sin ciudad', 'Sin teléfono'])
        ->and((int) $loaded->pending_data_count)->toBe(3);

    $complete = Branch::withPendingData()->find(completeBranch()->id);
    expect($complete->pendingDataList())->toBe([])->and((int) $complete->pending_data_count)->toBe(0);
});

it('pide terminal solo si hay empleados activos y contrato a quienes lo necesitan', function () {
    $branch = completeBranch();
    branchEmployee($branch, withContract: false);

    $loaded = Branch::withPendingData()->find($branch->id);
    expect($loaded->pendingDataList())->toBe(['Con empleados pero sin terminal activo', 'Empleados activos sin contrato']);

    Terminal::create(['name' => 'T', 'branch_id' => $branch->id]);
    expect(Branch::withPendingData()->find($branch->id)->pendingDataList())->toBe(['Empleados activos sin contrato']);
});

it('una sucursal inactiva no tiene datos pendientes', function () {
    $branch = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Cerrada', 'is_active' => false]);

    expect(Branch::withPendingData()->find($branch->id)->pendingDataList())->toBe([])
        ->and($branch->missingDataWarnings())->toBe([]);
});

it('el detalle avisa de los datos pendientes y desaparece el aviso al completarlos', function () {
    $this->actingAs(statusAdmin());

    $incomplete = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Incompleta']);

    expect($incomplete->missingDataWarnings())->toHaveCount(3);

    Livewire::test(ViewBranch::class, ['record' => $incomplete->getKey()])
        ->assertSee('Datos pendientes')
        ->assertSee('Falta ubicarla en el mapa')
        ->assertSee('Falta el teléfono');

    $complete = completeBranch();

    Livewire::test(ViewBranch::class, ['record' => $complete->getKey()])
        ->assertDontSee('Datos pendientes');
});

// ─── Listado ────────────────────────────────────────────────────────────────

it('las pestañas separan activas, inactivas y con datos pendientes con sus conteos', function () {
    $this->actingAs(statusAdmin());

    $active = completeBranch();
    $inactive = completeBranch(['is_active' => false]);
    $pending = Branch::create(['company_id' => Company::factory()->create()->id, 'name' => 'Pendiente']);

    $list = Livewire::test(ListBranches::class);

    $list->set('activeTab', 'active')->assertCanSeeTableRecords([$active, $pending])->assertCanNotSeeTableRecords([$inactive]);
    $list->set('activeTab', 'inactive')->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active, $pending]);
    $list->set('activeTab', 'pending')->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$active, $inactive]);

    $tabs = $list->instance()->getTabs();
    expect($tabs['all']->getBadge())->toBe(3)
        ->and($tabs['active']->getBadge())->toBe(2)
        ->and($tabs['inactive']->getBadge())->toBe(1)
        ->and($tabs['pending']->getBadge())->toBe(1);
});

it('el listado muestra la empresa solo si hay más de una activa', function () {
    $this->actingAs(statusAdmin());

    Company::query()->delete();
    $branch = completeBranch(['company_id' => Company::factory()->create()->id]);

    Livewire::test(ListBranches::class)->assertTableColumnHidden('company.name');

    Company::factory()->create();

    Livewire::test(ListBranches::class)->assertTableColumnVisible('company.name');
});

it('la exportación incluye el estado de la sucursal', function () {
    $branch = completeBranch(['is_active' => false]);
    $export = new BranchesExport(null, [$branch->id]);

    expect($export->headings())->toContain('Estado')
        ->and(collect($export->map($export->query()->first()))->last())->toBe('Inactiva');
});

// ─── Formulario ─────────────────────────────────────────────────────────────

it('la ciudad se elige del catálogo oficial acotada por departamento', function () {
    $this->actingAs(statusAdmin());
    config(['filament-google-maps.keys.web_key' => 'test-key']);

    $central = PyDepartment::where('name', 'Central')->value('id');

    $form = Livewire::test(CreateBranch::class)->set('data.department', $central)->instance()->form->getFlatFields();

    expect($form['city'])->toBeInstanceOf(Select::class)
        ->and($form['city']->getOptions())->toHaveKey('Luque')
        ->and($form['city']->getOptions())->not->toHaveKey('Encarnación');
});

it('precarga el departamento al editar una sucursal con ciudad del catálogo', function () {
    $this->actingAs(statusAdmin());
    config(['filament-google-maps.keys.web_key' => 'test-key']);

    $branch = completeBranch(['city' => 'Luque']);

    Livewire::test(EditBranch::class, ['record' => $branch->getKey()])
        ->assertFormSet(['department' => PyDepartment::where('name', 'Central')->value('id'), 'city' => 'Luque']);
});

it('guarda la ciudad elegida sin guardar el departamento', function () {
    $this->actingAs(statusAdmin());
    config(['filament-google-maps.keys.web_key' => 'test-key']);

    $company = Company::factory()->create();

    Livewire::test(CreateBranch::class)
        ->fillForm(['company_id' => $company->id, 'name' => 'Con Ciudad', 'city' => 'Luque'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Branch::where('name', 'Con Ciudad')->value('city'))->toBe('Luque');
});

// ─── Selectores ─────────────────────────────────────────────────────────────

it('la sucursal inactiva no se ofrece en el formulario de empleados salvo que ya esté asignada', function () {
    $this->actingAs(statusAdmin());

    $company = Company::factory()->create();
    $active = completeBranch(['company_id' => $company->id, 'name' => 'Abierta']);
    $closed = completeBranch(['company_id' => $company->id, 'name' => 'Cerrada', 'is_active' => false]);

    $options = Livewire::test(CreateEmployee::class)
        ->set('data.company_id', $company->id)
        ->instance()->form->getFlatFields()['branch_id']->getOptions();

    expect($options)->toHaveKey($active->id)->and($options)->not->toHaveKey($closed->id);

    $employee = Employee::factory()->create(['branch_id' => $closed->id, 'status' => 'inactive']);

    $editOptions = Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->instance()->form->getFlatFields()['branch_id']->getOptions();

    expect($editOptions)->toHaveKey($closed->id);
});
