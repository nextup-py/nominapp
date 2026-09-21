<?php

use App\Models\User;
use App\Policies\EmployeePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('resolves the permission name from the policy class name by convention', function () {
    Permission::create(['name' => 'view_any_employee', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->givePermissionTo('view_any_employee');

    expect((new EmployeePolicy())->viewAny($user))->toBeTrue();
});

it('blocks viewAny when the user lacks the permission', function () {
    Permission::create(['name' => 'view_any_employee', 'guard_name' => 'web']);
    $user = User::factory()->create();

    expect((new EmployeePolicy())->viewAny($user))->toBeFalse();
});

it('checks create/update/delete against their own permission names', function () {
    Permission::create(['name' => 'create_employee', 'guard_name' => 'web']);
    Permission::create(['name' => 'update_employee', 'guard_name' => 'web']);
    Permission::create(['name' => 'delete_employee', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->givePermissionTo(['create_employee', 'update_employee']);

    $policy = new EmployeePolicy();
    $employee = new \App\Models\Employee();

    expect($policy->create($user))->toBeTrue();
    expect($policy->update($user, $employee))->toBeTrue();
    expect($policy->delete($user, $employee))->toBeFalse();
});
