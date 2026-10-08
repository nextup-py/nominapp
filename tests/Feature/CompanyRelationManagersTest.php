<?php

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\AguinaldoPeriodsRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\BankAccountsRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\BranchesRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\DepartmentsRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\EmployeesRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\PayrollPeriodsRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\TerminalsRelationManager;
use App\Models\AguinaldoPeriod;
use App\Models\AttendanceMarkFailure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $this->actingAs($user);
});

/** Monta un Relation Manager de la ficha de la empresa. */
function companyRm(string $manager, Company $company): Testable
{
    return Livewire::test($manager, ['ownerRecord' => $company, 'pageClass' => ViewCompany::class]);
}

/** Empresa con una sucursal. */
function rmCompany(): Company
{
    $company = Company::factory()->complete()->create();
    Branch::create(['company_id' => $company->id, 'name' => 'Casa Central']);

    return $company;
}

// ─── Estructura ─────────────────────────────────────────────────────────────

it('registra los relation managers en el orden pensado', function () {
    expect(CompanyResource::getRelations())->toBe([
        BranchesRelationManager::class,
        DepartmentsRelationManager::class,
        EmployeesRelationManager::class,
        TerminalsRelationManager::class,
        PayrollPeriodsRelationManager::class,
        AguinaldoPeriodsRelationManager::class,
        BankAccountsRelationManager::class,
        CompanyResource\RelationManagers\AuditsRelationManager::class,
    ]);
});

it('la ficha de la empresa renderiza con todas las pestañas', function () {
    $company = rmCompany();

    Livewire::test(ViewCompany::class, ['record' => $company->getKey()])->assertOk();
})->group('smoke');

it('los relation managers nuevos son de solo lectura', function (string $manager) {
    expect((new $manager)->isReadOnly())->toBeTrue();
})->with([
    DepartmentsRelationManager::class,
    TerminalsRelationManager::class,
    PayrollPeriodsRelationManager::class,
    AguinaldoPeriodsRelationManager::class,
]);

// ─── Departamentos ──────────────────────────────────────────────────────────

it('el RM de departamentos cuenta cargos y empleados activos sin duplicar por contratos viejos', function () {
    $company = rmCompany();
    $department = Department::create(['name' => 'Ventas', 'company_id' => $company->id]);
    $position = Position::create(['name' => 'Vendedor', 'department_id' => $department->id]);
    Position::create(['name' => 'Supervisor', 'department_id' => $department->id]);
    Department::create(['name' => 'Otra empresa', 'company_id' => Company::factory()->create()->id]);

    $branch = $company->branches()->first();
    $active = Employee::factory()->create(['branch_id' => $branch->id, 'status' => 'active']);
    $inactive = Employee::factory()->create(['branch_id' => $branch->id, 'status' => 'inactive']);

    foreach ([[$active, 'terminated'], [$active, 'active'], [$inactive, 'active']] as [$employee, $status]) {
        Contract::create([
            'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear(),
            'salary_type' => 'mensual', 'salary' => 3000000, 'payroll_type' => 'monthly',
            'position_id' => $position->id, 'department_id' => $department->id, 'status' => $status,
        ]);
    }

    $loaded = companyRm(DepartmentsRelationManager::class, $company)
        ->assertCanSeeTableRecords([$department])
        ->assertCountTableRecords(1)
        ->instance()->getTableRecords()->first();

    expect((int) $loaded->positions_count)->toBe(2)
        ->and((int) $loaded->active_employees_count)->toBe(1);
});

// ─── Terminales ─────────────────────────────────────────────────────────────

it('el RM de terminales lista los de todas las sucursales de la empresa y no los de otras', function () {
    $company = rmCompany();
    $second = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Dos']);
    $own1 = Terminal::create(['name' => 'Terminal Uno', 'branch_id' => $company->branches()->first()->id]);
    $own2 = Terminal::create(['name' => 'Terminal Dos', 'branch_id' => $second->id]);
    $foreign = Terminal::create(['name' => 'Terminal Ajeno', 'branch_id' => rmCompany()->branches()->first()->id]);

    companyRm(TerminalsRelationManager::class, $company)
        ->assertCanSeeTableRecords([$own1, $own2])
        ->assertCanNotSeeTableRecords([$foreign])
        ->filterTable('branch_id', $second->id)
        ->assertCanSeeTableRecords([$own2])
        ->assertCanNotSeeTableRecords([$own1]);
});

it('el RM de terminales muestra el estado de conectividad', function () {
    $company = rmCompany();
    $terminal = Terminal::create(['name' => 'Sin vincular', 'branch_id' => $company->branches()->first()->id]);

    companyRm(TerminalsRelationManager::class, $company)
        ->assertTableColumnFormattedStateSet('connectivity_status', Terminal::getConnectivityStatusLabels()['unlinked'], $terminal);
});

// ─── Períodos ───────────────────────────────────────────────────────────────

it('el RM de nómina lista solo los períodos de la empresa', function () {
    $company = rmCompany();
    $other = rmCompany();
    $mine = PayrollPeriod::create(['company_id' => $company->id, 'name' => 'Octubre 2026', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'frequency' => 'monthly', 'status' => 'draft']);
    $foreign = PayrollPeriod::create(['company_id' => $other->id, 'name' => 'Ajeno', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'frequency' => 'monthly', 'status' => 'draft']);

    companyRm(PayrollPeriodsRelationManager::class, $company)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign])
        ->filterTable('status', 'closed')
        ->assertCanNotSeeTableRecords([$mine]);
});

it('el RM de aguinaldo lista solo los períodos de la empresa', function () {
    $company = rmCompany();
    $other = rmCompany();
    $mine = AguinaldoPeriod::create(['company_id' => $company->id, 'year' => 2026, 'status' => 'draft']);
    $foreign = AguinaldoPeriod::create(['company_id' => $other->id, 'year' => 2026, 'status' => 'draft']);

    companyRm(AguinaldoPeriodsRelationManager::class, $company)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});

// ─── Empleados ──────────────────────────────────────────────────────────────

it('el RM de empleados filtra por departamento y por cargo', function () {
    $company = rmCompany();
    $branch = $company->branches()->first();
    $sales = Department::create(['name' => 'Ventas', 'company_id' => $company->id]);
    $ops = Department::create(['name' => 'Operaciones', 'company_id' => $company->id]);
    $seller = Position::create(['name' => 'Vendedor', 'department_id' => $sales->id]);
    $driver = Position::create(['name' => 'Chofer', 'department_id' => $ops->id]);

    $makeEmployee = function (Department $department, Position $position) use ($branch) {
        $employee = Employee::factory()->create(['branch_id' => $branch->id, 'status' => 'active']);
        Contract::create([
            'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear(),
            'salary_type' => 'mensual', 'salary' => 3000000, 'payroll_type' => 'monthly',
            'position_id' => $position->id, 'department_id' => $department->id, 'status' => 'active',
        ]);

        return $employee;
    };

    $seller1 = $makeEmployee($sales, $seller);
    $driver1 = $makeEmployee($ops, $driver);

    companyRm(EmployeesRelationManager::class, $company)
        ->filterTable('department_id', $sales->id)
        ->assertCanSeeTableRecords([$seller1])
        ->assertCanNotSeeTableRecords([$driver1])
        ->removeTableFilters()
        ->filterTable('position_id', $driver->id)
        ->assertCanSeeTableRecords([$driver1])
        ->assertCanNotSeeTableRecords([$seller1]);
});

// ─── Eliminar sucursal ──────────────────────────────────────────────────────

it('no deja eliminar una sucursal con terminales, aunque no tenga empleados', function () {
    $company = rmCompany();
    $branch = $company->branches()->first();
    Terminal::create(['name' => 'Terminal', 'branch_id' => $branch->id]);

    companyRm(BranchesRelationManager::class, $company)
        ->callTableAction('delete', $branch);

    expect(Branch::find($branch->id))->not->toBeNull()
        ->and($branch->fresh()->deletionBlockersSummary())->toBe('1 terminales');
});

it('cuenta empleados, terminales y fallas de marcación como bloqueos de la sucursal', function () {
    $company = rmCompany();
    $branch = $company->branches()->first();
    Employee::factory()->create(['branch_id' => $branch->id]);
    Terminal::create(['name' => 'Terminal', 'branch_id' => $branch->id]);
    AttendanceMarkFailure::create([
        'mode' => 'terminal', 'failure_type' => 'face_not_recognized', 'branch_id' => $branch->id,
        'failure_message' => 'x', 'occurred_at' => now(),
    ]);

    expect($branch->deletionBlockers())->toBe(['empleados' => 1, 'terminales' => 1, 'fallas de marcación' => 1])
        ->and(fn () => $branch->delete())->toThrow(DomainException::class, 'No se puede eliminar la sucursal');
});

it('elimina una sucursal vacía', function () {
    $company = rmCompany();
    $empty = Branch::create(['company_id' => $company->id, 'name' => 'Vacía']);

    companyRm(BranchesRelationManager::class, $company)
        ->callTableAction('delete', $empty);

    expect(Branch::find($empty->id))->toBeNull();
});

// ─── Eliminar cuenta bancaria ───────────────────────────────────────────────

it('no deja eliminar la cuenta principal si hay otras activas', function () {
    $company = rmCompany();
    $primary = CompanyBankAccount::factory()->create(['company_id' => $company->id, 'is_primary' => true, 'status' => 'active']);
    CompanyBankAccount::factory()->create(['company_id' => $company->id, 'is_primary' => false, 'status' => 'active']);

    companyRm(BankAccountsRelationManager::class, $company)
        ->callTableAction('delete', $primary);

    expect(CompanyBankAccount::find($primary->id))->not->toBeNull()
        ->and(fn () => $primary->delete())->toThrow(DomainException::class, 'cuenta principal');
});

it('permite eliminar la principal si es la única, y las que no son principales', function () {
    $company = rmCompany();
    $only = CompanyBankAccount::factory()->create(['company_id' => $company->id, 'is_primary' => true, 'status' => 'active']);

    companyRm(BankAccountsRelationManager::class, $company)->callTableAction('delete', $only);
    expect(CompanyBankAccount::find($only->id))->toBeNull();

    $primary = CompanyBankAccount::factory()->create(['company_id' => $company->id, 'is_primary' => true, 'status' => 'active']);
    $secondary = CompanyBankAccount::factory()->create(['company_id' => $company->id, 'is_primary' => false, 'status' => 'active']);

    companyRm(BankAccountsRelationManager::class, $company)->callTableAction('delete', $secondary);
    expect(CompanyBankAccount::find($secondary->id))->toBeNull()
        ->and(CompanyBankAccount::find($primary->id))->not->toBeNull();
});

it('permite eliminar la principal si las otras cuentas están inactivas', function () {
    $company = rmCompany();
    $primary = CompanyBankAccount::factory()->create(['company_id' => $company->id, 'is_primary' => true, 'status' => 'active']);
    CompanyBankAccount::factory()->create(['company_id' => $company->id, 'is_primary' => false, 'status' => 'inactive']);

    expect($primary->deletionBlocker())->toBeNull();
});
