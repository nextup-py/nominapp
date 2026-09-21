<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder())->run();
});

it('creates the four base roles', function () {
    (new RoleSeeder())->run();

    expect(Role::pluck('name')->all())->toEqualCanonicalizing([
        'Super Admin', 'RRHH', 'Contador/Nómina', 'Solo Lectura',
    ]);
});

it('gives RRHH crud permissions on employee but not on payroll', function () {
    (new RoleSeeder())->run();

    $rrhh = Role::findByName('RRHH');

    expect($rrhh->hasPermissionTo('update_employee'))->toBeTrue();
    expect($rrhh->hasPermissionTo('update_payroll'))->toBeFalse();
});

it('gives Contador/Nómina full crud on payroll but only view on employee', function () {
    (new RoleSeeder())->run();

    $contador = Role::findByName('Contador/Nómina');

    expect($contador->hasPermissionTo('update_payroll'))->toBeTrue();
    expect($contador->hasPermissionTo('view_employee'))->toBeTrue();
    expect($contador->hasPermissionTo('update_employee'))->toBeFalse();
});

it('gives Solo Lectura only view permissions across every model', function () {
    (new RoleSeeder())->run();

    $readOnly = Role::findByName('Solo Lectura');

    foreach (PermissionSeeder::MODELS as $model) {
        expect($readOnly->hasPermissionTo("view_any_{$model}"))->toBeTrue();
        expect($readOnly->hasPermissionTo("create_{$model}"))->toBeFalse();
    }
});

it('assigns Super Admin to existing users without any role', function () {
    $user = User::factory()->create();

    (new RoleSeeder())->run();

    expect($user->fresh()->hasRole('Super Admin'))->toBeTrue();
});

it('does not touch a user that already has a role', function () {
    (new RoleSeeder())->run();

    $user = User::factory()->create();
    $user->assignRole('RRHH');

    (new RoleSeeder())->run();

    expect($user->fresh()->hasRole('Super Admin'))->toBeFalse();
    expect($user->fresh()->hasRole('RRHH'))->toBeTrue();
});

it('is idempotent on the permission matrix', function () {
    (new RoleSeeder())->run();
    (new RoleSeeder())->run();

    expect(Role::count())->toBe(4);
});
