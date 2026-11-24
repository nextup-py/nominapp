<?php

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\CreateCompany;
use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\AuditsRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PyCity;
use App\Models\PyDepartment;
use App\Models\User;
use Database\Seeders\ParaguayRegionsSeeder;
use Filament\Forms\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function companyAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

/** @return array<string, mixed> Datos válidos de formulario para crear una empresa. */
function validCompanyForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'Industrias Test S.A.',
        'ruc' => '80012345-6',
        'employer_number' => 12345678,
        'legal_type' => 'SA',
        'legal_rep_name' => 'Juan Pérez',
        'legal_rep_ci' => 1234567,
        'address' => 'Av. Siempre Viva 123',
        'city' => 'Asunción',
        'phone' => '0981123456',
        'email' => 'contacto@test.com',
    ], $overrides);
}

beforeEach(fn () => $this->actingAs(companyAdmin()));

// ─── Factory ────────────────────────────────────────────────────────────────

it('la factory crea empresas únicas y válidas', function () {
    $companies = Company::factory()->count(3)->create();

    expect($companies->pluck('ruc')->unique())->toHaveCount(3)
        ->and($companies->every(fn (Company $c) => $c->is_active))->toBeTrue();

    expect(Company::factory()->complete()->create()->missingDataWarnings())
        ->toHaveCount(1); // solo falta la cuenta bancaria principal
});

// ─── Creación ───────────────────────────────────────────────────────────────

it('crea una empresa con todos los datos obligatorios', function () {
    Livewire::test(CreateCompany::class)
        ->fillForm(validCompanyForm())
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Company::where('ruc', '80012345-6')->first())
        ->name->toBe('Industrias Test S.A.')
        ->city->toBe('Asunción');
});

it('exige los campos legales y de contacto', function (string $field) {
    Livewire::test(CreateCompany::class)
        ->fillForm(validCompanyForm([$field => null]))
        ->call('create')
        ->assertHasFormErrors([$field => 'required']);
})->with(['legal_type', 'legal_rep_name', 'legal_rep_ci', 'address', 'city', 'phone', 'email']);

it('rechaza RUC, número patronal y teléfono inválidos o duplicados', function () {
    Company::factory()->create(['ruc' => '80012345-6', 'employer_number' => 12345678]);

    Livewire::test(CreateCompany::class)
        ->fillForm(validCompanyForm(['phone' => '123']))
        ->call('create')
        ->assertHasFormErrors(['ruc' => 'unique', 'employer_number' => 'unique', 'phone' => 'regex']);

    Livewire::test(CreateCompany::class)
        ->fillForm(validCompanyForm(['ruc' => '8001234', 'employer_number' => 1]))
        ->call('create')
        ->assertHasFormErrors(['ruc' => 'regex']);
});

// ─── Ciudades ───────────────────────────────────────────────────────────────

it('usa la lista fija de ciudades si el catálogo oficial no fue sembrado', function () {
    PyCity::query()->delete(); // la migración de datos ya lo siembra en la base de tests
    PyDepartment::query()->delete();

    expect(PyCity::count())->toBe(0)
        ->and(Company::citiesOptions())->toHaveKey('Asunción')
        ->and(Company::citiesOptions())->toHaveCount(count(Company::$cities));
});

it('ofrece las ciudades del catálogo oficial, acotadas por departamento', function () {
    $this->seed(ParaguayRegionsSeeder::class); // idempotente: ya viene cargado por la migración

    $all = Company::citiesOptions();
    $central = PyDepartment::where('name', 'Central')->value('id');

    expect($all)->toHaveCount(PyCity::distinct('name')->count('name'))->and(count($all))->toBeGreaterThan(200)
        ->and(Company::citiesOptions($central))->toHaveKey('Luque')->not->toHaveKey('Concepción');
});

it('conserva en las opciones una ciudad guardada que no figura en el catálogo', function () {
    $this->seed(ParaguayRegionsSeeder::class);

    expect(Company::citiesOptions(null, 'Ciudad Vieja'))->toHaveKey('Ciudad Vieja')
        ->and(Company::departmentIdForCity('Luque'))->toBe(PyDepartment::where('name', 'Central')->value('id'))
        ->and(Company::departmentIdForCity('Ciudad Vieja'))->toBeNull()
        ->and(Company::departmentIdForCity(null))->toBeNull();
});

it('precarga el departamento al editar una empresa con ciudad del catálogo', function () {
    $this->seed(ParaguayRegionsSeeder::class);
    $company = Company::factory()->complete()->create(['city' => 'Luque']);

    Livewire::test(EditCompany::class, ['record' => $company->getKey()])
        ->assertFormSet(['department' => PyDepartment::where('name', 'Central')->value('id'), 'city' => 'Luque']);
});

// ─── Eliminación protegida ──────────────────────────────────────────────────

it('una empresa vacía se puede eliminar', function () {
    $company = Company::factory()->create();

    Livewire::test(EditCompany::class, ['record' => $company->getKey()])
        ->callAction('delete')
        ->assertHasNoActionErrors();

    expect(Company::find($company->id))->toBeNull();
});

it('bloquea la eliminación si tiene datos asociados y explica cuáles', function () {
    $company = Company::factory()->create();
    Branch::create(['company_id' => $company->id, 'name' => 'Casa Central']);
    Department::create(['company_id' => $company->id, 'name' => 'Ventas']);
    CompanyBankAccount::factory()->create(['company_id' => $company->id]);

    expect($company->deletionBlockers())->toBe(['sucursal|sucursales' => 1, 'departamento|departamentos' => 1, 'cuenta bancaria|cuentas bancarias' => 1])
        ->and($company->deletionBlockersSummary())->toBe('1 sucursal, 1 departamento y 1 cuenta bancaria');

    Livewire::test(EditCompany::class, ['record' => $company->getKey()])
        ->assertActionHidden('delete');

    expect(Company::find($company->id))->not->toBeNull();
});

it('el modelo se niega a eliminar una empresa con datos aunque no pase por el panel', function () {
    $company = Company::factory()->create();
    Branch::create(['company_id' => $company->id, 'name' => 'Casa Central']);

    expect(fn () => $company->delete())->toThrow(DomainException::class, '1 sucursal');
    expect(Company::find($company->id))->not->toBeNull();
});

// ─── Detalle ────────────────────────────────────────────────────────────────

it('el detalle avisa de los datos pendientes y desaparece el aviso al completarlos', function () {
    $company = Company::factory()->create();

    expect($company->missingDataWarnings())->toHaveCount(4);

    Livewire::test(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSee('Datos pendientes')
        ->assertSee('Falta el logo')
        ->assertSee('Sin cuenta bancaria principal');

    $complete = Company::factory()->complete()->create();
    CompanyBankAccount::factory()->create(['company_id' => $complete->id, 'is_primary' => true]);

    expect($complete->missingDataWarnings())->toBe([]);

    Livewire::test(ViewCompany::class, ['record' => $complete->getKey()])
        ->assertDontSee('Datos pendientes')
        ->assertDontSee('Sin cuenta bancaria principal');
});

it('el detalle muestra la cuenta bancaria principal', function () {
    $company = Company::factory()->complete()->create();
    CompanyBankAccount::factory()->create([
        'company_id' => $company->id,
        'is_primary' => true,
        'bank' => 'banco_itau',
        'account_number' => '9988776655',
        'holder_name' => 'Titular Demo',
    ]);

    Livewire::test(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSee('Banco Itaú Paraguay')
        ->assertSee('9988776655')
        ->assertSee('Titular Demo');
});

it('cuenta empleados activos sin contrato vigente', function () {
    $company = Company::factory()->create();
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Casa Central']);

    Employee::factory()->count(2)->create(['branch_id' => $branch->id, 'status' => 'active']);
    Employee::factory()->create(['branch_id' => $branch->id, 'status' => 'inactive']);

    expect($company->activeEmployeesWithoutContractCount())->toBe(2)
        ->and($company->activeTerminalsCount())->toBe(0)
        ->and($company->payrollPeriodsCount())->toBe(0);
});

// ─── Listado ────────────────────────────────────────────────────────────────

it('el listado filtra por estado', function () {
    $active = Company::factory()->create();
    $inactive = Company::factory()->inactive()->create();

    Livewire::test(ListCompanies::class)
        ->assertCanSeeTableRecords([$active, $inactive])
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive])
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$active]);
});

it('el listado busca por razón social, RUC y nombre comercial', function () {
    $a = Company::factory()->create(['name' => 'Alfa Importadora', 'ruc' => '1111111-1']);
    $b = Company::factory()->create(['name' => 'Beta Servicios', 'ruc' => '2222222-2', 'trade_name' => 'Taller Beta']);

    Livewire::test(ListCompanies::class)
        ->searchTable('Alfa')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
        ->searchTable('2222222')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])
        ->searchTable('Taller')->assertCanSeeTableRecords([$b]);
});

it('exporta el listado a Excel', function () {
    Company::factory()->count(2)->create();

    Livewire::test(ListCompanies::class)
        ->callAction('export_excel')
        ->assertHasNoActionErrors();
});

// ─── Auditoría ──────────────────────────────────────────────────────────────

it('audita los cambios de la empresa y los muestra en el historial', function () {
    config(['audit.console' => true]); // los tests corren en consola, donde la auditoría viene apagada

    $company = Company::factory()->create(['phone' => '0981111111']);
    $company->update(['phone' => '0982222222', 'city' => 'Luque']);

    $audit = $company->audits()->where('event', 'updated')->first();

    expect($audit->old_values)->toBe(['phone' => '0981111111', 'city' => null])
        ->and($audit->new_values)->toBe(['phone' => '0982222222', 'city' => 'Luque'])
        ->and($company->audits()->where('event', 'created')->exists())->toBeTrue();

    Livewire::test(AuditsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => ViewCompany::class])
        ->assertCanSeeTableRecords($company->audits)
        ->assertSee('Teléfono');
});

it('no audita campos fuera de la lista, como el thumbnail del logo', function () {
    config(['audit.console' => true]);

    $company = Company::factory()->create();
    $company->update(['logo_thumbnail' => 'data:image/png;base64,AAAA']);

    expect($company->audits()->where('event', 'updated')->get()->pluck('new_values')->flatten()->all())->toBe([]);
});

// ─── Textos y orden del formulario ───────────────────────────────────────────

it('el RUC y el número patronal repetidos muestran un mensaje propio', function () {
    Company::factory()->create(['ruc' => '80012345-6', 'employer_number' => 12345678]);

    Livewire::test(CreateCompany::class)
        ->fillForm(validCompanyForm())
        ->call('create')
        ->assertHasFormErrors(['ruc' => 'Ya existe una empresa con este RUC.', 'employer_number' => 'Ya existe una empresa con este número patronal.']);
});

it('el título de la edición lleva el nombre de la empresa', function () {
    $company = Company::factory()->create(['name' => 'Industrias Test S.A.', 'trade_name' => 'El Taller']);

    Livewire::test(EditCompany::class, ['record' => $company->getKey()])
        ->assertSee('Editar: '.$company->display_name);
});

it('la sección Estado solo existe al editar', function () {
    $company = Company::factory()->create();

    Livewire::test(CreateCompany::class)->assertFormFieldDoesNotExist('is_active');

    Livewire::test(EditCompany::class, ['record' => $company->getKey()])->assertFormFieldExists('is_active');
});

it('el logo va en la sección de identidad, antes de la razón social', function () {
    $keys = collect(CompanyResource::form(Form::make(Livewire::test(CreateCompany::class)->instance()))->getComponents())
        ->flatMap(fn ($section) => collect($section->getChildComponents())->map(fn ($c) => $c->getName()))
        ->filter()->values();

    expect($keys->search('logo'))->toBeLessThan($keys->search('name'));
});

it('las secciones opcionales se abren solas cuando ya tienen datos', function () {
    $component = fn (Company $company, string $label) => collect(
        Livewire::test(EditCompany::class, ['record' => $company->getKey()])->instance()->form->getComponents()
    )->first(fn ($section) => $section->getHeading() === $label);

    $empty = Company::factory()->create();
    $filled = Company::factory()->create([
        'mobile_marking_allowed' => true,
        'privacy_notice' => 'Texto',
        'profile_requirements' => ['phone'],
    ]);

    foreach (['Marcación desde el celular', 'Aviso de privacidad', 'Ficha del empleado'] as $label) {
        expect($component($empty, $label)->isCollapsed())->toBeTrue("{$label} vacía")
            ->and($component($filled, $label)->isCollapsed())->toBeFalse("{$label} con datos");
    }
});
