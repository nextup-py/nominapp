<?php

use App\Support\ThemeResolver;
use Filament\Support\Colors\Color;

it('resuelve la paleta Teal', function () {
    expect(ThemeResolver::colorPalette())->toBe(Color::Teal);
});

it('calcula hex y rgba derivados del color primario', function () {
    $css = ThemeResolver::primaryColorCss();

    expect($css['hex'][50])->toBe('#f0fdfa')
        ->and($css['hex'][600])->toBe('#0d9488')
        ->and($css['hex'][900])->toBe('#134e4a')
        ->and($css['ring_rgba'])->toBe('rgba(20, 184, 166, 0.25)')
        ->and($css['dark_bg_rgba'])->toBe('rgba(20, 184, 166, 0.12)');
});

it('resuelve el nombre de la fuente de marca', function () {
    expect(ThemeResolver::fontFamily())->toBe('Poppins');
});

it('resuelve la ruta del asset CSS de la fuente de marca', function () {
    expect(ThemeResolver::fontAssetPath())->toBe('resources/css/shared/fonts/poppins.css');
});
