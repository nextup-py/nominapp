<?php

use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('el panel siempre usa el color primario Teal de marca', function () {
    // No usar app(AdminPanelProvider::class): un ServiceProvider recibe $app
    // por constructor y el container no lo autowirea vía make() fuera del
    // ciclo normal de boot — instanciar directo con app() como argumento.
    $provider = new AdminPanelProvider(app());
    $panel = $provider->panel(Panel::make());

    expect($panel->getColors()['primary'])->toBe(Color::Teal);
});

it('no revienta el boot si el manifest de Vite existe pero le falta la entrada de la fuente de marca', function () {
    // Reproduce el fallo real de deploy #136 (nextup-demo, 2026-09-09): en el
    // pipeline de deploy composer install corre antes que npm run build, así
    // que durante la ventana del deploy el manifest.json en disco es el del
    // deploy ANTERIOR — presente (pasa file_exists()), pero sin la entrada de
    // resources/css/shared/fonts/poppins.css si ese archivo es nuevo en este
    // deploy. Vite::asset() tira ViteException en ese caso — el ->font() debe
    // estar protegido más allá del file_exists().
    $manifestPath = public_path('build/manifest.json');
    $originalManifest = file_get_contents($manifestPath);
    file_put_contents($manifestPath, '{}');

    try {
        $provider = new AdminPanelProvider(app());
        $panel = $provider->panel(Panel::make());

        expect($panel->getColors()['primary'])->toBe(Color::Teal);
    } finally {
        file_put_contents($manifestPath, $originalManifest);
    }
});
