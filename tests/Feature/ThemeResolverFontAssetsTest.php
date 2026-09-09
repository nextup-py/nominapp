<?php

use App\Support\ThemeResolver;
use Illuminate\Support\Facades\Vite;

it('cada fuente curada resuelve a un asset CSS realmente compilado por Vite', function (string $key) {
    // Regresión: si una entrada de vite.config.js se borra accidentalmente
    // (como pasó una vez con device-link.css en este mismo plan),
    // ThemeResolver::fontAssetPath() sigue devolviendo la ruta fuente sin
    // error — pero Vite::asset(), que es lo que AdminPanelProvider llama
    // en producción, lanza una excepción si el manifest.json compilado no
    // conoce esa entrada. Requiere `npm run build` fresco
    // (public/build/manifest.json actualizado) para ser significativo.
    $path = ThemeResolver::fontAssetPath($key);

    $url = Vite::asset($path);

    $relativePath = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

    expect(public_path($relativePath))->toBeFile();
})->with(fn () => array_keys(ThemeResolver::fontOptions()));
