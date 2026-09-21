<?php

use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds roles and assigns Super Admin to the freshly created admin user', function () {
    config(['app.env' => 'testing']);
    putenv('ADMIN_EMAIL=admin@example.com');

    $this->artisan('db:seed', ['--class' => ProductionSeeder::class])->assertSuccessful();

    expect(Role::pluck('name')->all())->toEqualCanonicalizing([
        'Super Admin', 'RRHH', 'Contador/Nómina', 'Solo Lectura',
    ]);

    $admin = User::where('email', 'admin@example.com')->first();
    expect($admin)->not->toBeNull();
    expect($admin->hasRole('Super Admin'))->toBeTrue();
});
