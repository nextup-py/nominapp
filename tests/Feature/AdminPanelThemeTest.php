<?php

use App\Providers\Filament\AdminPanelProvider;
use App\Settings\GeneralSettings;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

it('cae a Teal/Poppins si el settings store no está disponible al bootear el panel', function () {
    // Simula la tabla `settings` inexistente/no poblada (fresh install o CI
    // antes de migrar) sin dropear la tabla realmente: bindea una
    // implementación que lanza al resolver GeneralSettings del container.
    app()->bind(GeneralSettings::class, function () {
        throw new RuntimeException('settings unavailable');
    });

    $provider = new AdminPanelProvider(app());
    $panel = $provider->panel(Panel::make());

    expect($panel->getColors()['primary'])->toBe(Color::Teal);

    app()->forgetInstance(GeneralSettings::class);
});

it('no revienta el boot si el manifest de Vite existe pero le falta la entrada de la fuente elegida', function () {
    // Reproduce el fallo real de deploy #136 (nextup-demo, 2026-09-09): en el
    // pipeline de deploy composer install corre antes que npm run build, así
    // que durante la ventana del deploy el manifest.json en disco es el del
    // deploy ANTERIOR — presente (pasa file_exists()), pero sin la entrada de
    // resources/css/shared/fonts/{key}.css si ese archivo es nuevo en este
    // deploy (ej. el split de fonts.css en archivos por fuente, sub-proyecto
    // F). Vite::asset() tira ViteException en ese caso — el ->font() debe
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
