<?php

namespace App\Support;

use Filament\Support\Colors\Color;

/**
 * Traduce las keys curadas de color y fuente (guardadas en GeneralSettings)
 * a lo que necesita el panel de Filament (->colors()/->font()) y las vistas
 * públicas de marcación (variables CSS vía <x-theme-vars />). Única fuente
 * de verdad para ambos — evita que el panel y las vistas públicas diverjan.
 *
 * Toda key desconocida (dato inválido en BD, o una entrada removida de la
 * lista curada en el futuro) cae silenciosamente al default correspondiente
 * en vez de lanzar una excepción: estas son rutas de producción activas.
 */
final class ThemeResolver
{
    public const DEFAULT_COLOR = 'teal';

    public const DEFAULT_FONT = 'poppins';

    /**
     * Tonos que tokens.css define realmente en @theme (50..900 — no incluye
     * 950, a diferencia del array completo de Filament\Support\Colors\Color).
     *
     * @var array<int, int>
     */
    private const CSS_SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];

    /**
     * @return array<string, array{label: string, shades: array<int, string>}>
     */
    private static function colors(): array
    {
        return [
            'teal' => ['label' => 'Teal (predeterminado)', 'shades' => Color::Teal],
            'blue' => ['label' => 'Blue', 'shades' => Color::Blue],
            'indigo' => ['label' => 'Indigo', 'shades' => Color::Indigo],
            'violet' => ['label' => 'Violet', 'shades' => Color::Violet],
            'purple' => ['label' => 'Purple', 'shades' => Color::Purple],
            'pink' => ['label' => 'Pink', 'shades' => Color::Pink],
            'rose' => ['label' => 'Rose', 'shades' => Color::Rose],
            'cyan' => ['label' => 'Cyan', 'shades' => Color::Cyan],
            'sky' => ['label' => 'Sky', 'shades' => Color::Sky],
            'orange' => ['label' => 'Orange', 'shades' => Color::Orange],
        ];
    }

    /**
     * @return array<string, array{label: string, family: string}>
     */
    private static function fonts(): array
    {
        return [
            'poppins' => ['label' => 'Poppins (predeterminada)', 'family' => 'Poppins'],
            'inter' => ['label' => 'Inter', 'family' => 'Inter'],
            'roboto' => ['label' => 'Roboto', 'family' => 'Roboto'],
            'nunito-sans' => ['label' => 'Nunito Sans', 'family' => 'Nunito Sans'],
            'work-sans' => ['label' => 'Work Sans', 'family' => 'Work Sans'],
        ];
    }

    /**
     * Array de tonos de Filament (shade => "r, g, b"), listo para pasar a
     * Panel::colors(['primary' => ...]).
     *
     * @return array<int, string>
     */
    public static function colorPalette(?string $key): array
    {
        return self::colors()[$key ?? '']['shades'] ?? Color::Teal;
    }

    /**
     * @return array<string, string> key => label
     */
    public static function colorOptions(): array
    {
        return collect(self::colors())
            ->map(fn (array $color): string => $color['label'])
            ->all();
    }

    /**
     * Tonos hexadecimales (50..900) más los derivados rgba() que necesitan
     * los alias legacy --c-primary-ring y --c-primary-bg (modo oscuro) de
     * tokens.css, que no son var()-based y por eso no heredan el override
     * automáticamente.
     *
     * @return array{hex: array<int, string>, ring_rgba: string, dark_bg_rgba: string}
     */
    public static function primaryColorCss(?string $key): array
    {
        $shades = self::colorPalette($key);

        $hex = [];
        foreach (self::CSS_SHADES as $shade) {
            $hex[$shade] = self::rgbStringToHex($shades[$shade]);
        }

        return [
            'hex' => $hex,
            'ring_rgba' => self::rgbStringToRgba($shades[500], 0.25),
            'dark_bg_rgba' => self::rgbStringToRgba($shades[500], 0.12),
        ];
    }

    private static function rgbStringToHex(string $rgb): string
    {
        [$r, $g, $b] = array_map('trim', explode(',', $rgb));

        return sprintf('#%02x%02x%02x', (int) $r, (int) $g, (int) $b);
    }

    private static function rgbStringToRgba(string $rgb, float $alpha): string
    {
        [$r, $g, $b] = array_map('trim', explode(',', $rgb));

        return sprintf('rgba(%d, %d, %d, %s)', (int) $r, (int) $g, (int) $b, $alpha);
    }

    /**
     * Nombre legible de la fuente ("Poppins", "Nunito Sans", ...).
     */
    public static function fontFamily(?string $key): string
    {
        return self::fonts()[$key ?? '']['family'] ?? 'Poppins';
    }

    /**
     * Ruta al CSS individual de una sola fuente (para LocalFontProvider del
     * panel de Filament, que espera una única URL de CSS).
     */
    public static function fontAssetPath(?string $key): string
    {
        $resolvedKey = array_key_exists($key ?? '', self::fonts()) ? $key : self::DEFAULT_FONT;

        return "resources/css/shared/fonts/{$resolvedKey}.css";
    }

    /**
     * @return array<string, string> key => label
     */
    public static function fontOptions(): array
    {
        return collect(self::fonts())
            ->map(fn (array $font): string => $font['label'])
            ->all();
    }
}
