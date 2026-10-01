<?php

use App\Filament\Resources\AguinaldoResource;
use App\Filament\Resources\LoanResource;
use App\Filament\Resources\TerminalResource;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\ModuleSettings;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();
    (new RoleSeeder)->run();

    app(GeneralSettings::class)->setup_completed = true;
    app(GeneralSettings::class)->save();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
    $this->actingAs($this->admin);
});

it('oculta y bloquea un Resource cuando su módulo está desactivado', function () {
    app(ModuleSettings::class)->fill(['loans_enabled' => false])->save();

    expect(LoanResource::shouldRegisterNavigation())->toBeFalse();
    expect(LoanResource::canViewAny())->toBeFalse();

    $this->get(LoanResource::getUrl('index'))->assertForbidden();
});

it('muestra y permite un Resource cuando su módulo está activado', function () {
    app(ModuleSettings::class)->fill(['loans_enabled' => true])->save();

    expect(LoanResource::shouldRegisterNavigation())->toBeTrue();
    expect(LoanResource::canViewAny())->toBeTrue();

    $this->get(LoanResource::getUrl('index'))->assertOk();
});

it('bloquea el Resource compartido de marcación biométrica cuando su módulo está desactivado', function () {
    app(ModuleSettings::class)->fill(['biometric_attendance_enabled' => false])->save();

    expect(TerminalResource::canViewAny())->toBeFalse();
    $this->get(TerminalResource::getUrl('index'))->assertForbidden();
});

it('AguinaldoResource sigue oculto de navegación sin importar el flag de módulo (regresión trait/property)', function () {
    app(ModuleSettings::class)->fill(['aguinaldo_enabled' => true])->save();
    expect(AguinaldoResource::shouldRegisterNavigation())->toBeFalse();

    app(ModuleSettings::class)->fill(['aguinaldo_enabled' => false])->save();
    expect(AguinaldoResource::shouldRegisterNavigation())->toBeFalse();
});

it('AguinaldoResource mantiene canCreate() en false sin importar el flag de módulo', function () {
    app(ModuleSettings::class)->fill(['aguinaldo_enabled' => true])->save();
    expect(AguinaldoResource::canCreate())->toBeFalse();
});
