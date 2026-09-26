<?php

use App\Filament\Pages\ManageGeneralSettings;
use App\Filament\Pages\SetupWizardPage;
use App\Models\Company;
use App\Models\User;
use App\Settings\GeneralSettings;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();
    (new RoleSeeder)->run();

    app(GeneralSettings::class)->setup_completed = true;
    app(GeneralSettings::class)->save();

    Company::create([
        'name' => 'Empresa Ya Configurada',
        'ruc' => '80000000-1',
        'employer_number' => 1,
        'is_active' => true,
    ]);
});

it('Super Admin ve la acción de reabrir el asistente en Configuración General', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    $this->actingAs($admin);

    Livewire::test(ManageGeneralSettings::class)
        ->assertActionVisible('reopenSetupWizard');
});

it('un usuario sin rol Super Admin no ve la acción de reabrir el asistente', function () {
    $rrhh = User::factory()->create();
    $rrhh->assignRole('RRHH');
    $this->actingAs($rrhh);

    Livewire::test(ManageGeneralSettings::class)
        ->assertActionHidden('reopenSetupWizard');
});

it('reabrir el wizard no bloquea el resto del panel', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    $this->actingAs($admin);

    $this->get(SetupWizardPage::getUrl())->assertOk();
    $this->get('/employees')->assertOk();
});

it('reabrir el wizard actualiza la company existente en lugar de duplicarla', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    $this->actingAs($admin);

    Livewire::test(SetupWizardPage::class)
        ->fillForm([
            'company.name' => 'Empresa Renombrada',
            'company.ruc' => '80000000-1',
        ])
        ->call('submit');

    expect(Company::count())->toBe(1);
    expect(Company::first()->name)->toBe('Empresa Renombrada');
});
