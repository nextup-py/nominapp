{{--
    Overrides en runtime de las variables de theming (color primario + fuente)
    definidas en tokens.css, resueltos desde GeneralSettings vía ThemeResolver.
    Se incluye después del @vite del CSS de cada vista pública para ganar la
    cascada sobre los valores compilados estáticamente.

    --color-primary-* son consumidas automáticamente por los alias legacy
    --c-primary/--c-primary-h/--c-primary-l/--c-primary-bg (light) de
    tokens.css, que son var()-based (herencia de custom properties en tiempo
    de uso). --c-primary-ring y el --c-primary-bg del modo oscuro son valores
    rgba() literales en tokens.css (no var()-based), por eso se recalculan acá.
--}}
@php
    try {
        $settings = app(\App\Settings\GeneralSettings::class);
        $colorKey = $settings->primary_color;
        $fontKey = $settings->font;
    } catch (\Throwable) {
        $colorKey = \App\Support\ThemeResolver::DEFAULT_COLOR;
        $fontKey = \App\Support\ThemeResolver::DEFAULT_FONT;
    }

    $theme = \App\Support\ThemeResolver::primaryColorCss($colorKey);
    $fontFamily = \App\Support\ThemeResolver::fontFamily($fontKey);
@endphp
<style>
    :root {
        --color-primary-50: {{ $theme['hex'][50] }};
        --color-primary-100: {{ $theme['hex'][100] }};
        --color-primary-200: {{ $theme['hex'][200] }};
        --color-primary-300: {{ $theme['hex'][300] }};
        --color-primary-400: {{ $theme['hex'][400] }};
        --color-primary-500: {{ $theme['hex'][500] }};
        --color-primary-600: {{ $theme['hex'][600] }};
        --color-primary-700: {{ $theme['hex'][700] }};
        --color-primary-800: {{ $theme['hex'][800] }};
        --color-primary-900: {{ $theme['hex'][900] }};
        --c-primary-ring: {{ $theme['ring_rgba'] }};
        --font: '{{ $fontFamily }}', system-ui, -apple-system, sans-serif;
    }

    @media (prefers-color-scheme: dark) {
        :root:not([data-theme="light"]) {
            --c-primary-bg: {{ $theme['dark_bg_rgba'] }};
        }
    }

    html[data-theme="dark"] {
        --c-primary-bg: {{ $theme['dark_bg_rgba'] }};
    }
</style>
