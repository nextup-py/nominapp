<?php

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('creates the 5 crud permissions for every model in the catalog', function () {
    (new PermissionSeeder())->run();

    $expected = count(PermissionSeeder::MODELS) * count(PermissionSeeder::ABILITIES);

    expect(Permission::count())->toBe($expected);
    expect(Permission::where('name', 'view_any_employee')->exists())->toBeTrue();
    expect(Permission::where('name', 'delete_user')->exists())->toBeTrue();
});

it('is idempotent', function () {
    (new PermissionSeeder())->run();
    (new PermissionSeeder())->run();

    expect(Permission::count())->toBe(count(PermissionSeeder::MODELS) * count(PermissionSeeder::ABILITIES));
});

it('every group in GROUPS only references models present in MODELS', function () {
    $modelsInGroups = collect(PermissionSeeder::GROUPS)->flatten()->all();

    expect($modelsInGroups)->toEqualCanonicalizing(PermissionSeeder::MODELS);
});

it('has a label for every model and every ability', function () {
    foreach (PermissionSeeder::MODELS as $model) {
        expect(PermissionSeeder::MODEL_LABELS)->toHaveKey($model);
    }
    foreach (PermissionSeeder::ABILITIES as $ability) {
        expect(PermissionSeeder::ABILITY_LABELS)->toHaveKey($ability);
    }
});
