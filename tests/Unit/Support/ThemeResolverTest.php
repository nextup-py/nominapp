<?php

use App\Support\ThemeResolver;
use Filament\Support\Colors\Color;

it('resuelve la paleta Filament de una key de color curada válida', function () {
    expect(ThemeResolver::colorPalette('blue'))->toBe(Color::Blue);
});

it('cae a Teal cuando la key de color es desconocida', function () {
    expect(ThemeResolver::colorPalette('not-a-real-color'))->toBe(Color::Teal);
});

it('cae a Teal cuando la key de color es null', function () {
    expect(ThemeResolver::colorPalette(null))->toBe(Color::Teal);
});

it('expone opciones de color para el Select de Settings', function () {
    $options = ThemeResolver::colorOptions();

    expect($options)->toBeArray()
        ->and($options)->toHaveKey('teal')
        ->and($options)->toHaveKey('blue')
        ->and($options['teal'])->toContain('Teal');
});

it('calcula hex y rgba derivados del color primario elegido', function () {
    $css = ThemeResolver::primaryColorCss('teal');

    expect($css['hex'][50])->toBe('#f0fdfa')
        ->and($css['hex'][600])->toBe('#0d9488')
        ->and($css['hex'][900])->toBe('#134e4a')
        ->and($css['ring_rgba'])->toBe('rgba(20, 184, 166, 0.25)')
        ->and($css['dark_bg_rgba'])->toBe('rgba(20, 184, 166, 0.12)');
});

it('primaryColorCss cae a Teal cuando la key es desconocida', function () {
    $css = ThemeResolver::primaryColorCss('not-a-real-color');

    expect($css['hex'][600])->toBe('#0d9488');
});

it('resuelve el nombre de fuente de una key curada válida', function () {
    expect(ThemeResolver::fontFamily('nunito-sans'))->toBe('Nunito Sans');
});

it('cae a Poppins cuando la key de fuente es desconocida', function () {
    expect(ThemeResolver::fontFamily('not-a-real-font'))->toBe('Poppins');
});

it('cae a Poppins cuando la key de fuente es null', function () {
    expect(ThemeResolver::fontFamily(null))->toBe('Poppins');
});

it('resuelve la ruta del asset CSS de una fuente curada válida', function () {
    expect(ThemeResolver::fontAssetPath('inter'))->toBe('resources/css/shared/fonts/inter.css');
});

it('la ruta del asset cae a Poppins cuando la key es desconocida', function () {
    expect(ThemeResolver::fontAssetPath('not-a-real-font'))->toBe('resources/css/shared/fonts/poppins.css');
});

it('expone opciones de fuente para el Select de Settings', function () {
    $options = ThemeResolver::fontOptions();

    expect($options)->toBeArray()
        ->and($options)->toHaveKey('poppins')
        ->and($options)->toHaveKey('inter')
        ->and($options)->toHaveKey('roboto')
        ->and($options)->toHaveKey('nunito-sans')
        ->and($options)->toHaveKey('work-sans');
});
