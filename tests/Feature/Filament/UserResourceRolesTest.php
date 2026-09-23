<?php

use App\Filament\Resources\UserResource\Pages\ManageUsers;
use App\Models\User;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();
    (new RoleSeeder)->run();
});

it('lets Super Admin assign roles to another user', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    $this->actingAs($admin);

    $target = User::factory()->create();

    Livewire::test(ManageUsers::class)
        ->mountTableAction('edit', $target)
        ->setTableActionData(['name' => $target->name, 'email' => $target->email, 'roles' => [Role::findByName('RRHH')->id]])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($target->fresh()->hasRole('RRHH'))->toBeTrue();
});

it('hides the roles field from a non Super Admin user', function () {
    // RRHH no tiene permisos CRUD sobre 'user' en la matriz de RoleSeeder (solo
    // Super Admin gestiona usuarios). Para aislar la condición real bajo prueba
    // ("no es Super Admin"), se otorgan permisos de usuario directamente a un
    // rol de prueba, en vez de reusar un rol sembrado sin acceso a esta pantalla.
    $userManager = Role::create(['name' => 'Gestor de Usuarios', 'guard_name' => 'web']);
    $userManager->syncPermissions(['view_any_user', 'view_user', 'update_user']);

    $manager = User::factory()->create();
    $manager->assignRole($userManager);
    $this->actingAs($manager);

    $target = User::factory()->create();

    Livewire::test(ManageUsers::class)
        ->mountTableAction('edit', $target)
        ->assertFormFieldIsHidden('roles', 'mountedTableActionForm');
});
