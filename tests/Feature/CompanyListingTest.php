<?php

use App\Exports\CompaniesExport;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $this->actingAs($user);
});

/** Empresa con logo, sucursal y cuenta bancaria principal: sin ningún dato pendiente. */
function completeCompany(array $overrides = []): Company
{
    $company = Company::factory()->complete()->create($overrides);
    Branch::create(['company_id' => $company->id, 'name' => 'Casa Central']);
    CompanyBankAccount::factory()->create(['company_id' => $company->id, 'is_primary' => true, 'status' => 'active']);

    return $company;
}

/** Empleado activo en la primera sucursal de la empresa, con o sin contrato vigente. */
function companyEmployee(Company $company, bool $withContract = false): Employee
{
    $employee = Employee::factory()->create([
        'branch_id' => $company->branches()->first()->id,
        'status' => 'active',
    ]);

    if ($withContract) {
        $department = Department::create(['name' => 'Dep '.$employee->id, 'company_id' => $company->id]);
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

// ─── Datos pendientes ───────────────────────────────────────────────────────

it('marca cada dato pendiente por separado', function () {
    $company = Company::factory()->create(); // sin logo, sucursales ni banco

    $loaded = Company::withPendingData()->find($company->id);

    expect($loaded->pending_data_count)->toBe(3)
        ->and($loaded->pendingDataList())->toBe(['Sin logo', 'Sin sucursales', 'Sin cuenta bancaria principal']);
});

it('una empresa completa no tiene datos pendientes', function () {
    $company = completeCompany();
    companyEmployee($company, withContract: true);

    $loaded = Company::withPendingData()->find($company->id);

    expect($loaded->pending_data_count)->toBe(0)
        ->and($loaded->pendingDataList())->toBe([]);
});

it('cuenta como pendiente a los empleados activos sin contrato vigente', function () {
    $company = completeCompany();
    companyEmployee($company);

    expect(Company::withPendingData()->find($company->id)->pendingDataList())
        ->toBe(['Empleados activos sin contrato']);
});

it('una cuenta bancaria principal inactiva no cuenta', function () {
    $company = completeCompany();
    $company->bankAccounts()->update(['status' => 'inactive']);

    expect(Company::withPendingData()->find($company->id)->pendingDataList())
        ->toBe(['Sin cuenta bancaria principal']);
});

// ─── Pestañas ───────────────────────────────────────────────────────────────

it('las pestañas muestran los conteos y filtran el listado', function () {
    $complete = completeCompany();
    $incomplete = Company::factory()->create();
    $inactive = completeCompany(['is_active' => false]);

    Livewire::test(ListCompanies::class)
        ->assertCanSeeTableRecords([$complete, $incomplete, $inactive])
        ->set('activeTab', 'active')
        ->assertCanSeeTableRecords([$complete, $incomplete])
        ->assertCanNotSeeTableRecords([$inactive])
        ->set('activeTab', 'inactive')
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$complete, $incomplete])
        ->set('activeTab', 'pending')
        ->assertCanSeeTableRecords([$incomplete])
        ->assertCanNotSeeTableRecords([$complete, $inactive]);

    $tabs = Livewire::test(ListCompanies::class)->instance()->getTabs();

    expect($tabs['all']->getBadge())->toBe(3)
        ->and($tabs['active']->getBadge())->toBe(2)
        ->and($tabs['inactive']->getBadge())->toBe(1)
        ->and($tabs['pending']->getBadge())->toBe(1);
});

it('la columna de datos muestra el resumen de pendientes', function () {
    $company = Company::factory()->create();

    Livewire::test(ListCompanies::class)
        ->assertTableColumnFormattedStateSet('pending_data_count', '3 pendientes', Company::withPendingData()->find($company->id));
});

// ─── Activar / desactivar ───────────────────────────────────────────────────

it('desactiva y reactiva una empresa desde el listado', function () {
    $company = completeCompany();

    Livewire::test(ListCompanies::class)
        ->callTableAction('toggle_status', $company)
        ->assertHasNoTableActionErrors();

    expect($company->fresh()->is_active)->toBeFalse();

    Livewire::test(ListCompanies::class)
        ->callTableAction('toggle_status', $company->fresh());

    expect($company->fresh()->is_active)->toBeTrue();
});

it('no deja desactivar una empresa con empleados activos', function () {
    $company = completeCompany();
    companyEmployee($company, withContract: true);

    Livewire::test(ListCompanies::class)
        ->callTableAction('toggle_status', $company);

    expect($company->fresh()->is_active)->toBeTrue();
});

it('el modelo bloquea la desactivación con empleados activos, venga de donde venga', function () {
    $company = completeCompany();
    companyEmployee($company);

    expect(fn () => $company->update(['is_active' => false]))
        ->toThrow(DomainException::class, '1 empleados activos');

    Employee::query()->update(['status' => 'inactive']);

    $company->update(['is_active' => false]);
    expect($company->fresh()->is_active)->toBeFalse();
});

it('el formulario de edición no guarda como inactiva una empresa con empleados activos', function () {
    $company = completeCompany();
    companyEmployee($company, withContract: true);

    Livewire::test(EditCompany::class, ['record' => $company->getKey()])
        ->fillForm(['is_active' => false])
        ->call('save');

    expect($company->fresh()->is_active)->toBeTrue();
});

it('la vista de detalle permite desactivar una empresa sin empleados', function () {
    $company = completeCompany();

    Livewire::test(ViewCompany::class, ['record' => $company->getKey()])
        ->callAction('toggle_status');

    expect($company->fresh()->is_active)->toBeFalse();
});

// ─── Título y búsqueda ──────────────────────────────────────────────────────

it('usa la razón social como título cuando no hay nombre comercial', function () {
    $company = Company::factory()->create(['name' => 'Solo Razon SA', 'trade_name' => null]);

    expect(CompanyResource::getRecordTitle($company))->toBe('Solo Razon SA');
});

// ─── Export ─────────────────────────────────────────────────────────────────

it('exporta solo las empresas del listado (pestaña activa) con las columnas predeterminadas', function () {
    Excel::fake();
    Carbon::setTestNow('2026-10-08 10:00:00');

    $active = completeCompany(['name' => 'Activa SA']);
    completeCompany(['name' => 'Inactiva SA', 'is_active' => false]);

    Livewire::test(ListCompanies::class)
        ->set('activeTab', 'active')
        ->callAction('export_excel')
        ->assertHasNoActionErrors();

    Excel::assertDownloaded('empresas_2026_10_08_10_00_00.xlsx', fn (CompaniesExport $export) => $export->headings() === array_values(array_intersect_key(CompaniesExport::availableColumns(), array_flip(CompaniesExport::defaultColumns())))
        && $export->query()->get()->pluck('id')->all() === [$active->id]);
});

it('el export mapea las columnas calculadas', function () {
    $company = Company::factory()->create(['name' => 'Mapa SA']);
    $export = new CompaniesExport(['name', 'branches', 'pending_data', 'status']);

    $row = $export->map($export->query()->first());

    expect($row)->toBe(['Mapa SA', 0, 'Sin logo, Sin sucursales, Sin cuenta bancaria principal', 'Activa']);
});

it('el export ignora columnas desconocidas y usa las predeterminadas si no se indican', function () {
    expect((new CompaniesExport(['name', 'inexistente']))->headings())->toBe(['Razón Social'])
        ->and((new CompaniesExport)->headings())->toHaveCount(count(CompaniesExport::defaultColumns()));
});
