<?php

use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('trae primary_color y font con los defaults esperados tras migrar', function () {
    $settings = app(GeneralSettings::class);

    expect($settings->primary_color)->toBe('teal')
        ->and($settings->font)->toBe('poppins');
});
