<?php

use App\Settings\GeneralSettings;

it('trae primary_color y font con los defaults esperados tras migrar', function () {
    $settings = app(GeneralSettings::class);

    expect($settings->primary_color)->toBe('teal')
        ->and($settings->font)->toBe('poppins');
});
