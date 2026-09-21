<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lets Super Admin bypass any permission check, even one that is not assigned to the role', function () {
    Permission::create(['name' => 'delete_employee', 'guard_name' => 'web']);
    Role::create(['name' => 'Super Admin', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    expect($user->can('delete_employee'))->toBeTrue();
});

it('still blocks a regular role without the permission', function () {
    Permission::create(['name' => 'delete_employee', 'guard_name' => 'web']);
    Role::create(['name' => 'RRHH', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->assignRole('RRHH');

    expect($user->can('delete_employee'))->toBeFalse();
});
