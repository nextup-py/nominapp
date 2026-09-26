<?php

use App\Filament\Pages\SetupWizardPage;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\ModuleSettings;
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

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
    $this->actingAs($this->admin);
});

it('crea company, sucursales, departamentos y cargos, y marca setup_completed', function () {
    Livewire::test(SetupWizardPage::class)
        ->fillForm([
            'company.name' => 'Mi Empresa SA',
            'company.trade_name' => 'Mi Empresa',
            'company.ruc' => '80012345-6',
            'company.employer_number' => '12345',
            'branch_mode' => 'multiple',
            'branches' => [
                ['name' => 'Sucursal Centro', 'address' => 'Calle 1', 'city' => 'Asunción'],
            ],
            'departments' => [
                ['name' => 'Ventas', 'positions' => [['name' => 'Vendedor']]],
            ],
            'modules.loans_enabled' => false,
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    $company = Company::first();
    expect($company)->not->toBeNull();
    expect($company->name)->toBe('Mi Empresa SA');
    expect($company->ruc)->toBe('80012345-6');

    expect(Branch::where('company_id', $company->id)->count())->toBe(1);
    expect(Branch::first()->name)->toBe('Sucursal Centro');

    $department = Department::where('company_id', $company->id)->first();
    expect($department)->not->toBeNull();
    expect($department->name)->toBe('Ventas');
    expect(Position::where('department_id', $department->id)->pluck('name')->all())->toBe(['Vendedor']);

    expect(app(ModuleSettings::class)->loans_enabled)->toBeFalse();
    expect(app(GeneralSettings::class)->setup_completed)->toBeTrue();
});

it('permite saltar el paso de estructura organizacional sin crear departamentos', function () {
    Livewire::test(SetupWizardPage::class)
        ->fillForm([
            'company.name' => 'Empresa Sin Depto',
            'company.ruc' => '80099999-1',
            'company.employer_number' => '11111',
            'branch_mode' => 'single',
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    expect(Department::count())->toBe(0);
    expect(app(GeneralSettings::class)->setup_completed)->toBeTrue();
});

it('el modo de una sola sucursal crea la sucursal por defecto sin datos del repeater', function () {
    Livewire::test(SetupWizardPage::class)
        ->fillForm([
            'company.name' => 'Empresa Unica Sucursal',
            'company.ruc' => '80088888-1',
            'company.employer_number' => '22222',
            'branch_mode' => 'single',
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    $company = Company::first();
    expect(Branch::where('company_id', $company->id)->count())->toBe(1);
    expect(Branch::first()->name)->toBe('Casa Matriz');
});
