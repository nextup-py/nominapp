<?php

use App\Filament\Pages\EmployeeReport;
use App\Filament\Pages\ReportsIndex;
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
