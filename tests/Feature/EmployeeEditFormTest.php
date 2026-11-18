<?php

use App\Filament\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\ReportingLineService;
use App\Support\Nationalities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Inicia sesión como Super Admin y crea una empresa activa con una sucursal. */
function editFormSetup(): Branch
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    $company = Company::factory()->create();

    return Branch::create(['name' => 'Central', 'company_id' => $company->id]);
}

/** Resultados de la búsqueda del selector «Reporta a» al editar a `$employee`. */
function managerSearch(Employee $employee, string $term): array
{
    $field = Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->instance()->form->getFlatFields(withHidden: true)['reports_to_id'];

    return $field->getSearchResults($term);
}

it('el título de la edición muestra el nombre del empleado', function () {
    $branch = editFormSetup();
    $employee = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id, 'first_name' => 'María', 'last_name' => 'González']);

    Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])->assertSee('Editar: María González');
});

it('oculta el campo Empresa cuando hay una sola empresa activa y lo muestra con varias', function () {
    $branch = editFormSetup();
    $employee = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id]);

    $visible = fn (): bool => Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->instance()->form->getFlatFields()['company_id'] ?? false ? true : false;

    expect($visible())->toBeFalse();

    Company::factory()->create();

    expect($visible())->toBeTrue();
});

it('el alta con una sola empresa no pide ni muestra la empresa', function () {
    $branch = editFormSetup();

    Livewire::test(CreateEmployee::class)
        ->fillForm(['ci' => '4567890', 'first_name' => 'Juan', 'last_name' => 'Pérez', 'birth_date' => '1990-05-10', 'gender' => 'masculino', 'branch_id' => $branch->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Employee::where('ci', '4567890')->firstOrFail()->branch_id)->toBe($branch->id);
});

it('«Reporta a» solo ofrece activos de la empresa que no son él ni sus subordinados', function () {
    $branch = editFormSetup();
    $boss = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id, 'first_name' => 'Ana', 'last_name' => 'Jefa']);
    $employee = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id, 'first_name' => 'Ana', 'last_name' => 'Medio', 'reports_to_id' => $boss->id]);
    $subordinate = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id, 'first_name' => 'Ana', 'last_name' => 'Sub', 'reports_to_id' => $employee->id]);
    $deepSubordinate = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id, 'first_name' => 'Ana', 'last_name' => 'Nieta', 'reports_to_id' => $subordinate->id]);
    $peer = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id, 'first_name' => 'Ana', 'last_name' => 'Par']);
    $inactive = Employee::factory()->create(['branch_id' => $branch->id, 'first_name' => 'Ana', 'last_name' => 'Baja', 'status' => 'inactive']);
    $otherBranch = Branch::create(['name' => 'Otra', 'company_id' => Company::factory()->create()->id]);
    $otherCompany = Employee::factory()->create(['status' => 'active', 'branch_id' => $otherBranch->id, 'first_name' => 'Ana', 'last_name' => 'Ajena']);

    $ids = array_keys(managerSearch($employee, 'Ana'));

    expect($ids)->toContain($boss->id, $peer->id)
        ->not->toContain($employee->id, $subordinate->id, $deepSubordinate->id, $inactive->id, $otherCompany->id);
});

it('«Reporta a» busca por CI y etiqueta con el cargo', function () {
    $branch = editFormSetup();
    $employee = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id]);
    $other = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id, 'ci' => '9876543', 'first_name' => 'Luis', 'last_name' => 'Gómez']);

    expect(managerSearch($employee, '9876543'))->toBe([$other->id => 'Gómez, Luis']);
});

it('calcula los subordinados directos e indirectos sin repetir ni colgarse con un ciclo', function () {
    $branch = editFormSetup();
    $a = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id]);
    $b = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id, 'reports_to_id' => $a->id]);
    $c = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id, 'reports_to_id' => $b->id]);
    $a->forceFill(['reports_to_id' => $c->id])->saveQuietly();

    $ids = app(ReportingLineService::class)->descendantIds($a);

    expect($ids)->toEqualCanonicalizing([$b->id, $c->id]);
});

it('la nacionalidad es una lista con Paraguaya por defecto y conserva un valor fuera del catálogo', function () {
    expect(Nationalities::options())->toHaveKeys(['Paraguaya', 'Argentina', 'Otra'])
        ->and(array_key_first(Nationalities::options()))->toBe('Paraguaya')
        ->and(Nationalities::optionsWith('Marciana'))->toHaveKey('Marciana')
        ->and(Nationalities::optionsWith('Argentina'))->not->toHaveKey('Marciana');
});

it('avisa con mensajes propios cuando la CI o el correo ya existen', function () {
    $branch = editFormSetup();
    Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id, 'ci' => '1111111', 'email' => 'repetido@empresa.com']);
    $employee = Employee::factory()->create(['status' => 'active', 'branch_id' => $branch->id]);

    Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->fillForm(['ci' => '1111111', 'email' => 'repetido@empresa.com'])
        ->call('save')
        ->assertHasFormErrors(['ci', 'email'])
        ->assertSee('Ya existe un empleado con esta cédula.')
        ->assertSee('Este correo ya está registrado en otro empleado.');
});

it('la ayuda del estado de un empleado inactivo apunta a Reingresar', function () {
    $branch = editFormSetup();
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'status' => 'inactive']);

    Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])->assertSee('usar «Reingresar» desde la ficha');
});
