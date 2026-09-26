<?php

use App\Models\Company;
use App\Settings\GeneralSettings;
use App\Support\InstallationDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('detecta instalación nueva cuando companies está vacía', function () {
    expect(Company::query()->count())->toBe(0);
    expect(InstallationDetector::hasExistingCompany())->toBeFalse();
});

it('detecta instalación existente cuando ya hay al menos una company', function () {
    Company::create([
        'name' => 'Empresa Existente',
        'ruc' => '80000000-1',
        'employer_number' => 1,
        'is_active' => true,
    ]);

    expect(InstallationDetector::hasExistingCompany())->toBeTrue();
});

it('deja setup_completed en false para una instalación nueva tras la migración de settings', function () {
    expect(app(GeneralSettings::class)->setup_completed)->toBeFalse();
});
