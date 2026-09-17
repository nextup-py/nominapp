<?php

namespace App\Support;

use Filament\Support\Colors\Color;

/**
 * Fuente única de verdad para el color primario (Teal) y la fuente
 * (Poppins) de la marca — usado por el panel de Filament (->colors()/
 * ->font()) y por AttendanceFaceMarkController::buildManifest() (manifest
 * PWA de las vistas públicas de marcación). Antes era configurable por
 * instancia vía GeneralSettings; la marca quedó fija a Teal/Poppins y ese
 * mecanismo se retiró — este resolver sigue existiendo solo para no
 * duplicar la conversión de shades de Filament a hex/rgba en varios lugares.
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
     * Array de tonos de Filament (shade => "r, g, b"), listo para pasar a
     * Panel::colors(['primary' => ...]).
     *
     * @return array<int, string>
     */
    public static function colorPalette(): array
    {
        return Color::Teal;
    }

    /**
     * Tonos hexadecimales (50..900) más los derivados rgba() que necesitan
     * los alias legacy --c-primary-ring y --c-primary-bg (modo oscuro) de
     * tokens.css, que no son var()-based y por eso no heredan el override
     * automáticamente.
     *
     * @return array{hex: array<int, string>, ring_rgba: string, dark_bg_rgba: string}
     */
    public static function primaryColorCss(): array
    {
        $shades = self::colorPalette();

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

    /** Nombre legible de la fuente de marca ("Poppins"). */
    public static function fontFamily(): string
    {
        return 'Poppins';
    }

    /**
     * Ruta al CSS de la fuente de marca (para LocalFontProvider del panel
     * de Filament, que espera una única URL de CSS).
     */
    public static function fontAssetPath(): string
    {
        return 'resources/css/shared/fonts/'.self::DEFAULT_FONT.'.css';
    }
}
