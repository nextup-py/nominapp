<?php

use App\Providers\Filament\AdminPanelProvider;
use App\Settings\GeneralSettings;
use Filament\Panel;
use Filament\Support\Colors\Color;

it('usa el color primario configurado en GeneralSettings al construir el panel', function () {
    $settings = app(GeneralSettings::class);
    $settings->primary_color = 'rose';
    $settings->save();

    // No usar app(AdminPanelProvider::class): un ServiceProvider recibe $app
    // por constructor y el container no lo autowirea vía make() fuera del
    // ciclo normal de boot — instanciar directo con app() como argumento.
    $provider = new AdminPanelProvider(app());
    $panel = $provider->panel(Panel::make());

    expect($panel->getColors()['primary'])->toBe(Color::Rose);
});

it('cae al color Teal si el valor guardado no es una key curada válida', function () {
    $settings = app(GeneralSettings::class);
    $settings->primary_color = 'not-a-real-color';
    $settings->save();

    $provider = new AdminPanelProvider(app());
    $panel = $provider->panel(Panel::make());

    expect($panel->getColors()['primary'])->toBe(Color::Teal);
});
