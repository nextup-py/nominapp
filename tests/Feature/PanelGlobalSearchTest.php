<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\PanelGlobalSearchProvider;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Usuario con solo los permisos indicados (o Super Admin con `null`). */
function searchUser(?array $permissions = null): User
{
    $user = User::factory()->create();

    if ($permissions === null) {
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $user;
    }

    $user->assignRole(Role::firstOrCreate(['name' => 'Buscador', 'guard_name' => 'web']));
    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    return $user;
}

function searchEmployee(string $first, string $last, string $ci, string $status = 'active'): Employee
{
    static $n = 1;
    $company = Company::create(['name' => "EmpGS {$n}", 'ruc' => "{$n}00-1", 'employer_number' => 8800000 + $n]);
    $branch = Branch::create(['name' => "Sucursal GS {$n}", 'company_id' => $company->id]);
    $n++;

    return Employee::create(['first_name' => $first, 'last_name' => $last, 'ci' => $ci, 'branch_id' => $branch->id, 'status' => $status]);
}

/** Resultados como lista plana de [categoría => títulos]. */
function searchTitles(string $query): array
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return collect((new PanelGlobalSearchProvider)->getResults($query)->getCategories())
        ->map(fn ($results) => collect($results)->map(fn ($r) => (string) $r->title)->all())
        ->all();
}

it('encuentra empleados por nombre, apellido, CI y por varias palabras', function () {
    searchEmployee('Marcelo', 'Ferreira', '4123456');
    searchEmployee('Marta', 'Noguera', '5987654');
    $this->actingAs(searchUser());

    expect(searchTitles('marcelo')['Empleados'])->toBe(['Marcelo Ferreira'])
        ->and(searchTitles('noguera')['Empleados'])->toBe(['Marta Noguera'])
        ->and(searchTitles('4123')['Empleados'])->toBe(['Marcelo Ferreira'])
        ->and(searchTitles('marta nog')['Empleados'])->toBe(['Marta Noguera']);
});

it('muestra CI, sucursal y estado, y deja los inactivos al final y marcados', function () {
    $inactive = searchEmployee('Ana', 'Zapata', '111', 'inactive');
    $active = searchEmployee('Ana', 'Benitez', '222');
    $this->actingAs(searchUser());
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $results = (new PanelGlobalSearchProvider)->getResults('ana')->getCategories()['Empleados'];

    expect(collect($results)->map(fn ($r) => (string) $r->title)->all())->toBe(['Ana Benitez', 'Ana Zapata (Inactivo)'])
        ->and($results[0]->details)->toBe(['CI' => '222', 'Sucursal' => $active->branch->name, 'Estado' => 'Activo'])
        ->and($results[1]->url)->toContain((string) $inactive->id);
});

it('no busca empleados con una sola letra ni sin permiso de ver empleados', function () {
    searchEmployee('Marcelo', 'Ferreira', '4123456');

    $this->actingAs(searchUser());
    expect(searchTitles('m'))->not->toHaveKey('Empleados');

    $this->actingAs(searchUser(['view_any_terminal']));
    expect(searchTitles('marcelo'))->not->toHaveKey('Empleados');
});

it('encuentra pantallas sin importar tildes y solo las que el usuario puede abrir', function () {
    $this->actingAs(searchUser());
    expect(searchTitles('terminales')['Pantallas'])->toContain('Terminales')
        ->and(searchTitles('salud del sist')['Pantallas'])->toContain('Salud del sistema')
        ->and(searchTitles('REPORTES')['Pantallas'])->not->toBeEmpty();

    $this->actingAs(searchUser(['view_any_employee']));
    expect(searchTitles('terminales'))->not->toHaveKey('Pantallas')
        ->and(searchTitles('salud'))->not->toHaveKey('Pantallas');
});

it('el panel tiene la búsqueda global con atajo de teclado', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getGlobalSearchProvider())->toBeInstanceOf(PanelGlobalSearchProvider::class)
        ->and($panel->getGlobalSearchKeyBindings())->toContain('ctrl+k', 'command+k');
});

it('Marcaciones no está en el menú pero la búsqueda la encuentra si el usuario puede verla', function () {
    $this->actingAs(searchUser(['view_any_attendance_event']));
    expect(searchTitles('marcaciones')['Pantallas'] ?? [])->toContain('Marcaciones');

    $this->actingAs(searchUser(['view_any_loan']));
    expect(searchTitles('marcaciones')['Pantallas'] ?? [])->not->toContain('Marcaciones');
});
