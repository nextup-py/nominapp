<?php

/**
 * Red de seguridad para el parche de patches/eightynine-filament-docs-fix-commonmark-converter.patch.
 *
 * cweagans/composer-patches solo aplica un parche cuando el paquete objetivo se
 * instala o actualiza de cero — si vendor/ ya tenía el paquete sin cambios de
 * versión (deploys que reusan el mismo checkout en vez de una instalación
 * limpia), el hook nunca se dispara y el bug vuelve a colarse en silencio.
 *
 * Este script corre en post-autoload-dump, que composer siempre ejecuta al
 * final de install/update sin importar qué paquetes tocó, y aplica el mismo
 * fix directo sobre el archivo si todavía no está.
 */

$file = __DIR__.'/../../vendor/eightynine/filament-docs/src/Pages/DocsPage.php';

if (! file_exists($file)) {
    // Paquete no instalado (ej. composer install --no-dev en un contexto que lo excluye) — nada que hacer.
    exit(0);
}

$content = file_get_contents($file);

if (str_contains($content, 'new MarkdownConverter($environment)')) {
    // Ya parcheado (por composer-patches o por esta misma red de seguridad).
    exit(0);
}

if (! str_contains($content, 'new CommonMarkConverter([], $environment)')) {
    fwrite(STDERR, "ensure-filament-docs-patch: ni el bug ni el fix esperado están presentes en DocsPage.php — el paquete pudo haber cambiado, revisar el parche manualmente.\n");
    exit(0);
}

$patched = str_replace(
    "use League\CommonMark\CommonMarkConverter;\nuse League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;\nuse League\CommonMark\Environment\Environment;",
    "use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;\nuse League\CommonMark\Environment\Environment;\nuse League\CommonMark\MarkdownConverter;",
    $content
);

$patched = str_replace(
    'new CommonMarkConverter([], $environment)',
    'new MarkdownConverter($environment)',
    $patched
);

file_put_contents($file, $patched);

echo "ensure-filament-docs-patch: aplicado directamente (composer-patches no tocó el paquete en esta corrida).\n";
