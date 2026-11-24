<?php

use App\Filament\Pages\EmployeeReport;
use App\Filament\Pages\ReportsIndex;
use App\Filament\Resources\AttendanceEventResource;
use App\Models\User;
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

    expect($groups['Horarios y turnos'])->toEqualCanonicalizing(['Planificador de Turnos', 'Horarios', 'Plantillas de turno', 'Patrones de rotación'])
        ->and($groups['Asistencias'])->toEqualCanonicalizing(['Monitor en vivo', 'Por resolver', 'Asistencias', 'Ausencias'])
        ->and($groups['Sistema'])->toEqualCanonicalizing(['Salud del sistema', 'Visor de Logs'])
        ->and($groups['Licencias y vacaciones'])->toEqualCanonicalizing(['Licencias', 'Vacaciones', 'Balances de Vacaciones'])
        ->and($groups['Empleados'])->toEqualCanonicalizing(['Empleados', 'Contratos', 'Amonestaciones'])
        ->and($groups['Aguinaldo y liquidación'])->toEqualCanonicalizing(['Aguinaldo', 'Liquidaciones'])
        ->and($groups['Configuración'])->not->toContain('Salud del sistema')
        ->and(collect($panel->getNavigationGroups())->map(fn ($g) => $g->getLabel())->all())->toContain('Horarios y turnos', 'Sistema', 'Licencias y vacaciones', 'Aguinaldo y liquidación');
});

it('Marcaciones no está en el menú', function () {
    expect(AttendanceEventResource::shouldRegisterNavigation())->toBeFalse();
});
