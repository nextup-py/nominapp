<?php

namespace App\Support;

use Filament\Support\Colors\Color;

final class ThemeResolver
{
    public const DEFAULT_COLOR = 'teal';

    public const DEFAULT_FONT = 'poppins';

    private const CSS_SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];

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

    public static function colorPalette(?string $key): array
    {
        return self::colors()[$key ?? '']['shades'] ?? Color::Teal;
    }

    public static function colorOptions(): array
    {
        return collect(self::colors())
            ->map(fn (array $color): string => $color['label'])
            ->all();
    }

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

    public static function fontFamily(?string $key): string
    {
        return self::fonts()[$key ?? '']['family'] ?? 'Poppins';
    }

    public static function fontAssetPath(?string $key): string
    {
        $resolvedKey = array_key_exists($key ?? '', self::fonts()) ? $key : self::DEFAULT_FONT;

        return "resources/css/shared/fonts/{$resolvedKey}.css";
    }

    public static function fontOptions(): array
    {
        return collect(self::fonts())
            ->map(fn (array $font): string => $font['label'])
            ->all();
    }
}
