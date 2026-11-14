<?php

/**
 * Regresión: `DemoSeeder` truncaba `settings`, que crean las migraciones de `database/settings`, y
 * `SettingsSeeder` solo volvía a cargar dos grupos. La demo quedaba sin `ModuleSettings` y toda pantalla
 * fallaba con `MissingSettings`. Truncar la tabla en un test haría un commit implícito en MySQL y
 * dejaría la base sembrada, así que se verifica el código del sembrador.
 */
it('el sembrador de la demo no vacía la tabla de ajustes', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/database/seeders/DemoSeeder.php');

    expect($source)->not->toContain("'settings'");
});
