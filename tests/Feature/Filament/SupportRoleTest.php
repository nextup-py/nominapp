<?php

use App\Filament\Pages\LogViewer;
use App\Filament\Pages\ManageGeneralSettings;
use App\Filament\Pages\ManagePayrollSettings;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\RoleResource;
use App\Models\User;
use App\Support\SuggestedRoles;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BusinessActionPermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

/** Usuario con el rol indicado. */
function supportUser(string $role = 'Soporte TI'): User
{
    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

it('el rol Soporte TI se crea solo si falta y no pisa uno personalizado', function () {
    Role::findByName('Soporte TI')->delete();

    expect(SuggestedRoles::createMissing())->toBe(['Soporte TI'])
        ->and(Role::findByName('Soporte TI')->permissions->pluck('name')->all())->toContain('update_terminal', 'view_system_health');

    Role::findByName('Soporte TI')->update(['description' => 'Ajustado por el cliente']);

    expect(SuggestedRoles::createMissing())->toBe([])
        ->and(Role::findByName('Soporte TI')->description)->toBe('Ajustado por el cliente');
});

it('Soporte TI gestiona terminales y dispositivos pero no ve nómina, salarios ni configuración', function () {
    $this->actingAs(supportUser());
    $user = auth()->user();

    expect($user->can('update_terminal'))->toBeTrue()
        ->and($user->can('update_employee_device'))->toBeTrue()
        ->and($user->can('update_face_enrollment'))->toBeTrue()
        ->and($user->can('update_attendance_mark_failure'))->toBeTrue()
        ->and($user->can('view_any_employee'))->toBeTrue()
        ->and($user->can('view_employee'))->toBeFalse()
        ->and($user->can('update_employee'))->toBeFalse()
        ->and($user->can('view_any_contract'))->toBeFalse()
        ->and($user->can('view_any_payroll'))->toBeFalse()
        ->and($user->can('view_any_liquidacion'))->toBeFalse()
        ->and(ManagePayrollSettings::canAccess())->toBeFalse()
        ->and(ManageGeneralSettings::canAccess())->toBeFalse();
});

it('Salud del sistema y los logs se abren con view_system_health: Soporte TI y Super Admin sí, RR.HH. no', function () {
    $this->actingAs(supportUser());
    expect(SystemHealth::canAccess())->toBeTrue()->and(LogViewer::canAccess())->toBeTrue();

    $this->actingAs(supportUser('Super Admin'));
    expect(SystemHealth::canAccess())->toBeTrue()->and(LogViewer::canAccess())->toBeTrue();

    $this->actingAs(supportUser('RRHH'));
    expect(SystemHealth::canAccess())->toBeFalse()->and(LogViewer::canAccess())->toBeFalse();
});

it('el permiso de Salud del sistema aparece en el editor de roles, en Configuración', function () {
    expect(RoleResource::permissionOptions()['Configuración'])->toHaveKey('view_system_health');
});
