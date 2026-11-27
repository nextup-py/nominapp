<?php

use App\Filament\Pages\EmployeeReport;
use App\Filament\Pages\ReportsIndex;
use App\Filament\Resources\AttendanceEventResource;
use App\Models\User;
use App\Support\NavigationLayout;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('cada entrada del menú tiene un orden único dentro de su grupo', function () {
    $panel = Filament::getPanel('admin');
    $seen = [];

    foreach (array_merge($panel->getResources(), $panel->getPages()) as $class) {
        if (! $class::shouldRegisterNavigation()) {
            continue;
        }

        $group = $class::getNavigationGroup() ?? '-';
        $sort = $class::getNavigationSort();
        $seen[$group][$sort ?? 'null'][] = $class;
    }

    $dupes = collect($seen)->flatMap(fn ($sorts, $group) => collect($sorts)->filter(fn ($c) => count($c) > 1)->map(fn ($c, $sort) => "{$group}#{$sort}"))->values()->all();

    expect($dupes)->toBe([]);
});

it('los reportes salen del menú y se agrupan en una sola entrada', function () {
    expect(EmployeeReport::shouldRegisterNavigation())->toBeFalse()
        ->and(ReportsIndex::getNavigationGroup())->toBeNull();
});

it('la página Reportes muestra solo los reportes que el rol puede abrir', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Contador', 'guard_name' => 'web']));
    $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any_loan', 'guard_name' => 'web']));

    Livewire::actingAs($user)->test(ReportsIndex::class)
        ->assertSee('Reporte de Préstamos')
        ->assertDontSee('Reporte de Empleados');
});

it('los turnos viven en su propio grupo y Asistencias queda con lo de uso diario', function () {
    $panel = Filament::getPanel('admin');
    $groups = [];

    foreach (array_merge($panel->getResources(), $panel->getPages()) as $class) {
        if ($class::shouldRegisterNavigation() && $class::getNavigationGroup() !== null) {
            $groups[$class::getNavigationGroup()][] = (string) $class::getNavigationLabel();
        }
    }

    expect($groups['Horarios y turnos'])->toEqualCanonicalizing(['Planificador de Turnos', 'Horarios', 'Turnos', 'Patrones de rotación'])
        ->and($groups['Asistencias'])->toEqualCanonicalizing(['Monitor en vivo', 'Por resolver', 'Asistencias', 'Ausencias', 'Pedidos de horas extra'])
        ->and($groups['Sistema'])->toEqualCanonicalizing(['Salud del sistema', 'Visor de Logs'])
        ->and($groups['Licencias y vacaciones'])->toEqualCanonicalizing(['Licencias', 'Vacaciones', 'Balances de Vacaciones'])
        ->and($groups['Empleados'])->toEqualCanonicalizing(['Empleados', 'Contratos', 'Amonestaciones'])
        ->and($groups['Nóminas'])->toContain('Aguinaldo', 'Liquidaciones')
        ->and($groups['Configuración'])->not->toContain('Salud del sistema')
        ->and(collect($panel->getNavigationGroups())->map(fn ($g) => $g->getLabel())->all())->toContain('Horarios y turnos', 'Sistema', 'Licencias y vacaciones');
});

it('Marcaciones no está en el menú', function () {
    expect(AttendanceEventResource::shouldRegisterNavigation())->toBeFalse();
});

/**
 * Grupos de menú de todas las pantallas visibles con el formato que eligió el usuario.
 *
 * @return array<string, list<string>>
 */
function navigationGroupsFor(string $layout): array
{
    $user = User::factory()->create(['navigation_layout' => $layout]);
    test()->actingAs($user);

    $panel = Filament::getPanel('admin');
    $groups = [];

    foreach (array_merge($panel->getResources(), $panel->getPages()) as $class) {
        if ($class::shouldRegisterNavigation() && $class::getNavigationGroup() !== null) {
            $groups[$class::getNavigationGroup()][] = $class::getNavigationSort().'|'.$class;
        }
    }

    return $groups;
}

it('el menú superior cabe en siete grupos y cada grupo conocido está registrado', function () {
    $groups = navigationGroupsFor(NavigationLayout::TOP);
    $registered = collect(Filament::getPanel('admin')->getNavigationGroups())->map(fn ($g) => $g->getLabel())->all();

    expect(count($groups))->toBeLessThanOrEqual(NavigationLayout::MAX_TOP_GROUPS)
        ->and(array_diff(array_keys($groups), $registered))->toBe([])
        ->and(array_diff(array_keys($groups), NavigationLayout::topGroupNames()))->toBe([]);
});

it('en el menú superior cada entrada tiene un orden único dentro de su grupo', function () {
    $dupes = [];

    foreach (navigationGroupsFor(NavigationLayout::TOP) as $group => $entries) {
        $sorts = array_map(fn ($e) => explode('|', $e)[0], $entries);
        foreach (array_count_values($sorts) as $sort => $count) {
            if ($count > 1) {
                $dupes[] = "{$group}#{$sort}";
            }
        }
    }

    expect($dupes)->toBe([]);
});

it('la barra lateral sigue siendo el formato por defecto y conserva sus grupos', function () {
    $groups = navigationGroupsFor(NavigationLayout::SIDEBAR);

    expect($groups)->toHaveKeys(['Asistencias', 'Horarios y turnos', 'Sistema', 'Licencias y vacaciones'])
        ->and(Filament::getPanel('admin')->hasTopNavigation())->toBeFalse();
});

it('cada grupo de la barra lateral tiene su equivalente en el menú superior', function () {
    $sidebarGroups = array_keys(navigationGroupsFor(NavigationLayout::SIDEBAR));

    expect(array_diff($sidebarGroups, array_keys(NavigationLayout::TOP_GROUPS)))->toBe([]);
});
