<?php

use App\Filament\Pages\SetupWizardPage;
use App\Models\User;
use App\Settings\GeneralSettings;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();
    (new RoleSeeder)->run();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
});

it('redirige al wizard cuando el setup no está completo', function () {
    app(GeneralSettings::class)->setup_completed = false;
    app(GeneralSettings::class)->save();

    $this->actingAs($this->admin)
        ->get('/employees')
        ->assertRedirect(SetupWizardPage::getUrl());
});

it('no redirige cuando el setup ya está completo', function () {
    app(GeneralSettings::class)->setup_completed = true;
    app(GeneralSettings::class)->save();

    $this->actingAs($this->admin)
        ->get('/employees')
        ->assertOk();
});

it('permite acceder a la propia página del wizard aunque el setup no esté completo', function () {
    app(GeneralSettings::class)->setup_completed = false;
    app(GeneralSettings::class)->save();

    $this->actingAs($this->admin)
        ->get(SetupWizardPage::getUrl())
        ->assertOk();
});

it('permite hacer logout aunque el setup no esté completo', function () {
    app(GeneralSettings::class)->setup_completed = false;
    app(GeneralSettings::class)->save();

    $response = $this->actingAs($this->admin)->post('/logout');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toBe(SetupWizardPage::getUrl());
});

it('no afecta a usuarios invitados (no autenticados)', function () {
    app(GeneralSettings::class)->setup_completed = false;
    app(GeneralSettings::class)->save();

    $this->get('/employees')->assertRedirect('/login');
});
