<?php

use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renderiza los overrides de color y fuente elegidos en Settings', function () {
    $settings = app(GeneralSettings::class);
    $settings->primary_color = 'blue';
    $settings->font = 'roboto';
    $settings->save();

    $html = (string) $this->blade('<x-theme-vars />');

    expect($html)
        ->toContain('--color-primary-600: #2563eb;')
        ->toContain("--font: 'Roboto', system-ui, -apple-system, sans-serif;")
        ->toContain('--c-primary-ring: rgba(59, 130, 246, 0.25);')
        ->toContain('--c-primary-bg: rgba(59, 130, 246, 0.12);');
});

it('usa los defaults Teal/Poppins cuando Settings no fue personalizado', function () {
    $html = (string) $this->blade('<x-theme-vars />');

    expect($html)
        ->toContain('--color-primary-600: #0d9488;')
        ->toContain("--font: 'Poppins', system-ui, -apple-system, sans-serif;");
});
