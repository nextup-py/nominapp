<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('blocks panel access for a user without any role', function () {
    $user = User::factory()->create();

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('allows panel access for a user with at least one role', function () {
    Role::create(['name' => 'RRHH', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('RRHH');

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});
