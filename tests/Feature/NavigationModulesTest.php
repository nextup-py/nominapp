<?php

use App\Filament\Pages\AttendanceModes;
use App\Filament\Pages\TerminalPairingInbox;
use App\Filament\Resources\AttendanceMarkFailureResource;
use App\Filament\Resources\EmployeeLeaveResource;
use App\Filament\Resources\LoanResource;
use App\Models\User;
use App\Settings\ModuleSettings;
use App\Support\NavigationLayout;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Apaga o enciende todos los módulos opcionales de la instalación. */
function setAllModules(bool $enabled): void
{
    $modules = app(ModuleSettings::class);

    foreach (['loans', 'advances', 'merchandise_withdrawals', 'vacations', 'aguinaldo', 'warnings', 'employee_leaves', 'biometric_attendance', 'disbursement_batches'] as $module) {
        $modules->{$module.'_enabled'} = $enabled;
    }

    $modules->save();
    app()->forgetInstance(ModuleSettings::class);
    NavigationLayout::flush();
}

/**
 * Grupos y pantallas visibles para un Super Admin con el formato de menú indicado.
 *
 * @return array<string, list<string>>
 */
function visibleMenu(string $layout): array
{
    $user = User::factory()->create(['navigation_layout' => $layout]);
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    test()->actingAs($user);

    $panel = Filament::getPanel('admin');
    $menu = [];

    foreach (array_merge($panel->getResources(), $panel->getPages()) as $class) {
        if ($class::shouldRegisterNavigation() && $class::canAccess() && $class::getNavigationGroup() !== null) {
            $menu[$class::getNavigationGroup()][] = (string) $class::getNavigationLabel();
        }
    }

    return $menu;
}

it('con la marcación biométrica apagada, sus pantallas desaparecen del menú', function () {
    setAllModules(false);

    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $this->actingAs($user);

    expect(AttendanceMarkFailureResource::shouldRegisterNavigation())->toBeFalse()
        ->and(AttendanceMarkFailureResource::canViewAny())->toBeFalse()
        ->and(TerminalPairingInbox::canAccess())->toBeFalse()
        ->and(AttendanceModes::canAccess())->toBeFalse();
});

it('con todos los módulos apagados no queda ningún grupo de terminales ni de créditos', function (string $layout) {
    setAllModules(false);

    $groups = array_keys(visibleMenu($layout));

    expect($groups)->not->toContain('Terminales', 'Terminales y dispositivos', 'Créditos', 'Licencias y vacaciones');
})->with([NavigationLayout::SIDEBAR, NavigationLayout::TOP]);

it('en el menú superior ningún grupo queda con una sola pantalla, salvo Ayuda', function (bool $modules) {
    setAllModules($modules);

    $single = collect(visibleMenu(NavigationLayout::TOP))
        ->filter(fn (array $items, string $group) => count($items) === 1 && $group !== NavigationLayout::KEEP_GROUP)
        ->keys()
        ->all();

    expect($single)->toBe([]);
})->with([true, false]);

it('el menú superior respeta el máximo de grupos con todos los módulos activos y con ninguno', function (bool $modules) {
    setAllModules($modules);

    expect(count(visibleMenu(NavigationLayout::TOP)))->toBeLessThanOrEqual(NavigationLayout::MAX_TOP_GROUPS);
})->with([true, false]);

it('una pantalla que queda sola en su grupo superior pasa a enlace directo y en la barra lateral conserva su grupo', function () {
    $role = Role::firstOrCreate(['name' => 'Solo licencias', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any_employee_leave', 'guard_name' => 'web']));

    $user = User::factory()->create(['navigation_layout' => NavigationLayout::TOP]);
    $user->assignRole($role);
    $this->actingAs($user);

    expect(EmployeeLeaveResource::canAccess())->toBeTrue()
        ->and(EmployeeLeaveResource::getNavigationGroup())->toBeNull();

    $user->forceFill(['navigation_layout' => NavigationLayout::SIDEBAR])->save();
    $this->actingAs($user->fresh());
    NavigationLayout::flush();

    expect(EmployeeLeaveResource::getNavigationGroup())->toBe('Licencias y vacaciones');
});

it('un módulo apagado oculta su pantalla aunque el usuario tenga el permiso', function () {
    setAllModules(false);

    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
    $this->actingAs($user);

    expect(LoanResource::shouldRegisterNavigation())->toBeFalse();
});
