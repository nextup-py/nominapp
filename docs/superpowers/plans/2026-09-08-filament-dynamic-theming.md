# Theming Dinámico desde Filament Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir elegir color primario y fuente de toda la app (panel Filament + 4 vistas públicas de marcación) desde una nueva sección "Apariencia" en `ManageGeneralSettings`, sin necesitar rebuild ni deploy.

**Architecture:** `GeneralSettings` gana dos campos (`primary_color`, `font`). `App\Support\ThemeResolver` es la única fuente de verdad que traduce esas keys curadas a lo que necesita cada consumidor: `AdminPanelProvider` las usa para `->colors()`/`->font()` en cada boot del panel; un nuevo Blade component `<x-theme-vars />` las usa para renderizar overrides de variables CSS inline en las 4 vistas públicas.

**Tech Stack:** Laravel 12, Filament 3.3, `spatie/laravel-settings`, `@fontsource/*` (self-hosting de fuentes vía Vite), Pest.

**Spec:** `docs/superpowers/specs/2026-09-08-filament-dynamic-theming-design.md`

## Global Constraints

- Alcance por instalación (no multi-tenant/por empresa).
- Solo el color **primario** es configurable — success/danger/warning/info quedan fijos.
- Color y fuente se eligen de listas **curadas** (Selects), nunca de un picker libre.
- Colores curados: `teal` (default), `blue`, `indigo`, `violet`, `purple`, `pink`, `rose`, `cyan`, `sky`, `orange`.
- Fuentes curadas: `poppins` (default), `inter`, `roboto`, `nunito-sans`, `work-sans` — todas self-hosted vía `@fontsource/*`, pesos 400/500/600/700.
- Defaults (`teal`/`poppins`) deben coincidir exactamente con los valores hoy hardcodeados — desplegar este plan no cambia nada visualmente hasta que un admin edite la configuración.
- Toda key inválida/desconocida guardada en BD debe caer silenciosamente al default correspondiente — nunca una excepción (rutas de producción activas).
- El guard `file_exists(public_path('build/manifest.json'))` alrededor de `->font()` en `AdminPanelProvider` (introducido en sub-proyecto A) debe preservarse.

---

### Task 1: Reestructurar fuentes self-hosted — una fuente por archivo + 4 fuentes nuevas

**Files:**
- Create: `resources/css/shared/fonts/poppins.css`
- Create: `resources/css/shared/fonts/inter.css`
- Create: `resources/css/shared/fonts/roboto.css`
- Create: `resources/css/shared/fonts/nunito-sans.css`
- Create: `resources/css/shared/fonts/work-sans.css`
- Modify: `resources/css/shared/fonts.css`
- Modify: `vite.config.js`
- Modify: `package.json`

**Interfaces:**
- Consumes: nada de tareas anteriores (primera tarea del plan).
- Produces: 5 archivos CSS individuales bajo `resources/css/shared/fonts/{key}.css` (uno por fuente curada), cada uno resoluble vía `Vite::asset('resources/css/shared/fonts/{key}.css')` — Task 5 (AdminPanelProvider) y Task 2 (ThemeResolver) dependen de que estas rutas existan exactamente con estos nombres: `poppins`, `inter`, `roboto`, `nunito-sans`, `work-sans`.

- [ ] **Step 1: Instalar los 4 paquetes de fuentes nuevas**

Verificado contra el registro de npm: `@fontsource/inter`, `@fontsource/roboto`, `@fontsource/nunito-sans` y `@fontsource/work-sans` en versión `5.3.0` incluyen los 4 archivos de peso `400.css`/`500.css`/`600.css`/`700.css` — mismo patrón que `@fontsource/poppins` (ya instalado).

Run: `npm install @fontsource/inter@^5.3.0 @fontsource/roboto@^5.3.0 @fontsource/nunito-sans@^5.3.0 @fontsource/work-sans@^5.3.0`

Expected: `package.json` gana las 4 líneas nuevas en `dependencies`, junto a la ya existente `"@fontsource/poppins": "^5.3.0"`.

- [ ] **Step 2: Crear el archivo individual de Poppins**

Crear `resources/css/shared/fonts/poppins.css`:

```css
/**
 * Poppins self-hosted — pesos 400/500/600/700 desde el mismo origen que el
 * resto del bundle de Vite (public/build/assets/). Archivo individual: lo
 * consume LocalFontProvider del panel Filament cuando 'poppins' es la
 * fuente elegida (ver App\Support\ThemeResolver::fontAssetPath()).
 */
@import '@fontsource/poppins/400.css';
@import '@fontsource/poppins/500.css';
@import '@fontsource/poppins/600.css';
@import '@fontsource/poppins/700.css';
```

- [ ] **Step 3: Crear el archivo individual de Inter**

Crear `resources/css/shared/fonts/inter.css`:

```css
/** Inter self-hosted — pesos 400/500/600/700. Ver poppins.css para contexto. */
@import '@fontsource/inter/400.css';
@import '@fontsource/inter/500.css';
@import '@fontsource/inter/600.css';
@import '@fontsource/inter/700.css';
```

- [ ] **Step 4: Crear el archivo individual de Roboto**

Crear `resources/css/shared/fonts/roboto.css`:

```css
/** Roboto self-hosted — pesos 400/500/600/700. Ver poppins.css para contexto. */
@import '@fontsource/roboto/400.css';
@import '@fontsource/roboto/500.css';
@import '@fontsource/roboto/600.css';
@import '@fontsource/roboto/700.css';
```

- [ ] **Step 5: Crear el archivo individual de Nunito Sans**

Crear `resources/css/shared/fonts/nunito-sans.css`:

```css
/** Nunito Sans self-hosted — pesos 400/500/600/700. Ver poppins.css para contexto. */
@import '@fontsource/nunito-sans/400.css';
@import '@fontsource/nunito-sans/500.css';
@import '@fontsource/nunito-sans/600.css';
@import '@fontsource/nunito-sans/700.css';
```

- [ ] **Step 6: Crear el archivo individual de Work Sans**

Crear `resources/css/shared/fonts/work-sans.css`:

```css
/** Work Sans self-hosted — pesos 400/500/600/700. Ver poppins.css para contexto. */
@import '@fontsource/work-sans/400.css';
@import '@fontsource/work-sans/500.css';
@import '@fontsource/work-sans/600.css';
@import '@fontsource/work-sans/700.css';
```

- [ ] **Step 7: Reescribir `fonts.css` para importar las 5 fuentes completas**

`fonts.css` es importado por `tokens.css`, que a su vez compila dentro del CSS de las 4 vistas públicas. Las 5 fuentes deben estar **todas** empaquetadas ahí para poder elegir cualquiera en runtime sin rebuild — el navegador solo descarga los `.woff2` de la fuente realmente usada (`@font-face` es perezoso).

Reemplazar el contenido completo de `resources/css/shared/fonts.css`:

```css
/**
 * Las 5 fuentes curadas, self-hosted, empaquetadas completas. Consumido por
 * tokens.css (vistas públicas de marcación) — el navegador solo descarga
 * los .woff2 de la fuente que <x-theme-vars /> activa vía --font en tiempo
 * de request (@font-face es perezoso, no hay costo de red por las que no
 * se usan). El panel Filament NO usa este archivo combinado — usa el
 * archivo individual de una sola fuente vía
 * App\Support\ThemeResolver::fontAssetPath() (ver fonts/*.css).
 */
@import './fonts/poppins.css';
@import './fonts/inter.css';
@import './fonts/roboto.css';
@import './fonts/nunito-sans.css';
@import './fonts/work-sans.css';
```

- [ ] **Step 8: Registrar los 5 archivos individuales como entry points de Vite**

`AdminPanelProvider` necesitará `Vite::asset()` sobre cada archivo individual (uno a la vez, según la fuente elegida) — cada uno debe ser un entry point propio en el manifest de Vite. El combinado `resources/css/shared/fonts.css` deja de necesitarse como entry point directo (ya nadie lo pide vía `Vite::asset()`; solo se importa a build-time desde `tokens.css`).

En `vite.config.js`, dentro del array `input`, reemplazar la línea:

```js
'resources/css/shared/fonts.css',
```

por:

```js
'resources/css/shared/fonts/poppins.css',
'resources/css/shared/fonts/inter.css',
'resources/css/shared/fonts/roboto.css',
'resources/css/shared/fonts/nunito-sans.css',
'resources/css/shared/fonts/work-sans.css',
```

- [ ] **Step 9: Build y verificación manual**

Run: `npm run build`

Expected: build limpio, sin errores. Verificar que `public/build/manifest.json` contiene una entrada para cada uno de los 5 archivos `resources/css/shared/fonts/{key}.css` y que `resources/css/attendances/styles.css` sigue compilando (su cadena de `@import` pasa por `tokens.css` → `fonts.css` → los 5 archivos individuales).

Run: `grep -c "shared/fonts/" public/build/manifest.json`

Expected: `5` (una entrada de manifest por cada archivo individual).

- [ ] **Step 10: Commit**

```bash
git add package.json package-lock.json vite.config.js resources/css/shared/fonts.css resources/css/shared/fonts/
git commit -m "feat: split self-hosted fonts.css into 5 curated per-font files"
```

---

### Task 2: `App\Support\ThemeResolver`

**Files:**
- Create: `app/Support/ThemeResolver.php`
- Test: `tests/Unit/Support/ThemeResolverTest.php`

**Interfaces:**
- Consumes: los 5 archivos `resources/css/shared/fonts/{key}.css` de Task 1 (solo referenciados por ruta string, no se leen en runtime).
- Produces (usado por Tasks 4, 5 y 6):
  - `ThemeResolver::colorPalette(?string $key): array` — array Filament (`Color::X`-shaped, shades `50..950` como strings `"r, g, b"`), listo para `Panel::colors(['primary' => ...])`.
  - `ThemeResolver::colorOptions(): array` — `array<string, string>` key => label, para el `Select` de Settings.
  - `ThemeResolver::primaryColorCss(?string $key): array` — `['hex' => array<int, string> (shades 50..900), 'ring_rgba' => string, 'dark_bg_rgba' => string]`, para `<x-theme-vars />`.
  - `ThemeResolver::fontFamily(?string $key): string` — nombre legible ("Poppins", "Nunito Sans", ...).
  - `ThemeResolver::fontAssetPath(?string $key): string` — `"resources/css/shared/fonts/{key}.css"`.
  - `ThemeResolver::fontOptions(): array` — `array<string, string>` key => label, para el `Select` de Settings.
  - `ThemeResolver::DEFAULT_COLOR` (`'teal'`) y `ThemeResolver::DEFAULT_FONT` (`'poppins'`).

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Unit/Support/ThemeResolverTest.php`:

```php
<?php

use App\Support\ThemeResolver;
use Filament\Support\Colors\Color;

it('resuelve la paleta Filament de una key de color curada válida', function () {
    expect(ThemeResolver::colorPalette('blue'))->toBe(Color::Blue);
});

it('cae a Teal cuando la key de color es desconocida', function () {
    expect(ThemeResolver::colorPalette('not-a-real-color'))->toBe(Color::Teal);
});

it('cae a Teal cuando la key de color es null', function () {
    expect(ThemeResolver::colorPalette(null))->toBe(Color::Teal);
});

it('expone opciones de color para el Select de Settings', function () {
    $options = ThemeResolver::colorOptions();

    expect($options)->toBeArray()
        ->and($options)->toHaveKey('teal')
        ->and($options)->toHaveKey('blue')
        ->and($options['teal'])->toContain('Teal');
});

it('calcula hex y rgba derivados del color primario elegido', function () {
    $css = ThemeResolver::primaryColorCss('teal');

    expect($css['hex'][50])->toBe('#f0fdfa')
        ->and($css['hex'][600])->toBe('#0d9488')
        ->and($css['hex'][900])->toBe('#134e4a')
        ->and($css['ring_rgba'])->toBe('rgba(20, 184, 166, 0.25)')
        ->and($css['dark_bg_rgba'])->toBe('rgba(20, 184, 166, 0.12)');
});

it('primaryColorCss cae a Teal cuando la key es desconocida', function () {
    $css = ThemeResolver::primaryColorCss('not-a-real-color');

    expect($css['hex'][600])->toBe('#0d9488');
});

it('resuelve el nombre de fuente de una key curada válida', function () {
    expect(ThemeResolver::fontFamily('nunito-sans'))->toBe('Nunito Sans');
});

it('cae a Poppins cuando la key de fuente es desconocida', function () {
    expect(ThemeResolver::fontFamily('not-a-real-font'))->toBe('Poppins');
});

it('cae a Poppins cuando la key de fuente es null', function () {
    expect(ThemeResolver::fontFamily(null))->toBe('Poppins');
});

it('resuelve la ruta del asset CSS de una fuente curada válida', function () {
    expect(ThemeResolver::fontAssetPath('inter'))->toBe('resources/css/shared/fonts/inter.css');
});

it('la ruta del asset cae a Poppins cuando la key es desconocida', function () {
    expect(ThemeResolver::fontAssetPath('not-a-real-font'))->toBe('resources/css/shared/fonts/poppins.css');
});

it('expone opciones de fuente para el Select de Settings', function () {
    $options = ThemeResolver::fontOptions();

    expect($options)->toBeArray()
        ->and($options)->toHaveKey('poppins')
        ->and($options)->toHaveKey('inter')
        ->and($options)->toHaveKey('roboto')
        ->and($options)->toHaveKey('nunito-sans')
        ->and($options)->toHaveKey('work-sans');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Unit/Support/ThemeResolverTest.php`

Expected: FAIL — `Class "App\Support\ThemeResolver" not found`.

- [ ] **Step 3: Implementar `ThemeResolver`**

Crear `app/Support/ThemeResolver.php`:

```php
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
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Unit/Support/ThemeResolverTest.php`

Expected: PASS — 13 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Support/ThemeResolver.php tests/Unit/Support/ThemeResolverTest.php
git commit -m "feat: add ThemeResolver to bridge curated theme keys to Filament colors and CSS"
```

---

### Task 3: Campos `primary_color`/`font` en `GeneralSettings`

**Files:**
- Modify: `app/Settings/GeneralSettings.php`
- Create: `database/settings/2026_09_08_000001_add_theme_settings_to_general_settings.php`

**Interfaces:**
- Consumes: nada (independiente de Tasks 1 y 2).
- Produces (usado por Tasks 4, 5 y 6): `GeneralSettings::$primary_color` (string, default `'teal'`) y `GeneralSettings::$font` (string, default `'poppins'`), persistidos en el group `'general'`.

- [ ] **Step 1: Escribir la migración de settings**

Crear `database/settings/2026_09_08_000001_add_theme_settings_to_general_settings.php`:

```php
<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Mismos valores que hoy están hardcodeados en AdminPanelProvider.php
        // y fonts.css — desplegar esto no cambia nada visualmente.
        $this->migrator->add('general.primary_color', 'teal');
        $this->migrator->add('general.font', 'poppins');
    }

    public function down(): void
    {
        $this->migrator->delete('general.primary_color');
        $this->migrator->delete('general.font');
    }
};
```

- [ ] **Step 2: Correr la migración**

Run: `php artisan migrate`

Expected: `Migrating: 2026_09_08_000001_add_theme_settings_to_general_settings` seguido de `Migrated:` — sin errores.

- [ ] **Step 3: Agregar las propiedades a `GeneralSettings`**

En `app/Settings/GeneralSettings.php`, agregar después de `public int $terminal_stale_threshold_hours;`:

```php
    public string $primary_color;

    public string $font;
```

- [ ] **Step 4: Escribir un test rápido de que las propiedades existen con sus defaults**

Crear `tests/Unit/Settings/GeneralSettingsThemeTest.php`:

```php
<?php

use App\Settings\GeneralSettings;

it('trae primary_color y font con los defaults esperados tras migrar', function () {
    $settings = app(GeneralSettings::class);

    expect($settings->primary_color)->toBe('teal')
        ->and($settings->font)->toBe('poppins');
});
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Unit/Settings/GeneralSettingsThemeTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Settings/GeneralSettings.php database/settings/2026_09_08_000001_add_theme_settings_to_general_settings.php tests/Unit/Settings/GeneralSettingsThemeTest.php
git commit -m "feat: add primary_color and font fields to GeneralSettings"
```

---

### Task 4: Sección "Apariencia" en `ManageGeneralSettings`

**Files:**
- Modify: `app/Filament/Pages/ManageGeneralSettings.php`
- Test: `tests/Feature/ManageGeneralSettingsAppearanceTest.php`

**Interfaces:**
- Consumes: `ThemeResolver::colorOptions()`/`ThemeResolver::fontOptions()` (Task 2), `GeneralSettings::$primary_color`/`$font` (Task 3).
- Produces: nada consumido por tasks posteriores — es una hoja del árbol de dependencias.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/ManageGeneralSettingsAppearanceTest.php`:

```php
<?php

use App\Filament\Pages\ManageGeneralSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('guarda el color primario y la fuente elegidos desde la sección Apariencia', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm([
            'primary_color' => 'indigo',
            'font' => 'inter',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(GeneralSettings::class);

    expect($settings->primary_color)->toBe('indigo')
        ->and($settings->font)->toBe('inter');
});

it('rechaza un color primario fuera de la lista curada', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['primary_color' => 'not-a-real-color'])
        ->call('save')
        ->assertHasFormErrors(['primary_color']);
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/ManageGeneralSettingsAppearanceTest.php`

Expected: FAIL — el campo `primary_color` no existe en el form todavía (`Unable to call component method`, o el segundo test no falla por validación porque el campo no está definido).

- [ ] **Step 3: Agregar la sección "Apariencia" al form**

En `app/Filament/Pages/ManageGeneralSettings.php`, agregar el import:

```php
use App\Support\ThemeResolver;
```

Agregar una nueva `Section` al final del array `->schema([...])` del método `form()`, después de la sección "Terminales de Marcación":

```php
                Section::make('Apariencia')
                    ->description('Color primario y fuente de la aplicación (panel y vistas de marcación)')
                    ->icon('heroicon-o-paint-brush')
                    ->columns(2)
                    ->schema([
                        Select::make('primary_color')
                            ->label('Color primario')
                            ->options(ThemeResolver::colorOptions())
                            ->native(false)
                            ->default(ThemeResolver::DEFAULT_COLOR)
                            ->required()
                            ->helperText('Color de acento del panel admin y las vistas de marcación'),

                        Select::make('font')
                            ->label('Fuente')
                            ->options(ThemeResolver::fontOptions())
                            ->native(false)
                            ->default(ThemeResolver::DEFAULT_FONT)
                            ->required()
                            ->helperText('Tipografía del panel admin y las vistas de marcación'),
                    ]),
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/ManageGeneralSettingsAppearanceTest.php`

Expected: PASS — 2 tests.

- [ ] **Step 5: Correr Pint**

Run: `vendor/bin/pint --dirty`

Expected: `passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/ManageGeneralSettings.php tests/Feature/ManageGeneralSettingsAppearanceTest.php
git commit -m "feat: add Apariencia section to ManageGeneralSettings"
```

---

### Task 5: `AdminPanelProvider` — color y fuente dinámicos

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Test: `tests/Feature/AdminPanelThemeTest.php`

**Interfaces:**
- Consumes: `ThemeResolver::colorPalette()`/`ThemeResolver::fontAssetPath()`/`ThemeResolver::fontFamily()` (Task 2), `GeneralSettings::$primary_color`/`$font` (Task 3), los 5 archivos de fuente registrados como entry points de Vite (Task 1).
- Produces: nada consumido por tasks posteriores.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/AdminPanelThemeTest.php`:

```php
<?php

use App\Providers\Filament\AdminPanelProvider;
use App\Settings\GeneralSettings;
use Filament\Support\Colors\Color;

it('usa el color primario configurado en GeneralSettings al construir el panel', function () {
    $settings = app(GeneralSettings::class);
    $settings->primary_color = 'rose';
    $settings->save();

    // No usar app(AdminPanelProvider::class): un ServiceProvider recibe $app
    // por constructor y el container no lo autowirea vía make() fuera del
    // ciclo normal de boot — instanciar directo con app() como argumento.
    $provider = new AdminPanelProvider(app());
    $panel = $provider->panel(\Filament\Panel::make());

    expect($panel->getColors()['primary'])->toBe(Color::Rose);
});

it('cae al color Teal si el valor guardado no es una key curada válida', function () {
    $settings = app(GeneralSettings::class);
    $settings->primary_color = 'not-a-real-color';
    $settings->save();

    $provider = new AdminPanelProvider(app());
    $panel = $provider->panel(\Filament\Panel::make());

    expect($panel->getColors()['primary'])->toBe(Color::Teal);
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/AdminPanelThemeTest.php`

Expected: FAIL — `$panel->getColors()['primary']` sigue siendo `Color::Teal` incluso con `'rose'` guardado (el primer test falla).

- [ ] **Step 3: Hacer dinámicos `->colors()` y `->font()`**

En `app/Providers/Filament/AdminPanelProvider.php`, agregar los imports:

```php
use App\Settings\GeneralSettings;
use App\Support\ThemeResolver;
```

Reemplazar el bloque `->colors([...])` (que hoy hardcodea `'primary' => Color::Teal`) y el bloque `if (file_exists(...)) { $panel->font(...) }` completo:

```php
    public function panel(Panel $panel): Panel
    {
        $settings = app(GeneralSettings::class);

        $panel = $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->profile(isSimple: false)
            ->favicon(asset('icons/favicon.ico'))
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => ThemeResolver::colorPalette($settings->primary_color),
                'secondary' => Color::Amber,
                'success' => Color::Green,
                'danger' => Color::Red,
                'warning' => Color::Yellow,
                'info' => Color::Blue,
                'light' => Color::Gray,
                'dark' => Color::Slate,
            ])
```

(el resto del método —`navigationGroups`, `databaseNotifications`, `discoverResources`, `middleware`, `authMiddleware`— no cambia).

Y reemplazar el bloque final:

```php
        if (file_exists(public_path('build/manifest.json'))) {
            $panel->font(
                'Poppins',
                url: Vite::asset('resources/css/shared/fonts.css'),
                provider: LocalFontProvider::class,
            );
        }

        return $panel;
```

por:

```php
        if (file_exists(public_path('build/manifest.json'))) {
            $panel->font(
                ThemeResolver::fontFamily($settings->font),
                url: Vite::asset(ThemeResolver::fontAssetPath($settings->font)),
                provider: LocalFontProvider::class,
            );
        }

        return $panel;
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `npm run build && php artisan test --compact tests/Feature/AdminPanelThemeTest.php`

Expected: PASS — 2 tests. (`npm run build` es necesario porque el test ejercita el path con guard de manifest — sin build, `->font()` simplemente no se llama, pero `->colors()` no depende del manifest y ya debería pasar solo con `artisan test`; se corre el build igual para validar el flujo completo end-to-end).

- [ ] **Step 5: Correr Pint**

Run: `vendor/bin/pint --dirty`

Expected: `passed`.

- [ ] **Step 6: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php tests/Feature/AdminPanelThemeTest.php
git commit -m "feat: resolve Filament panel color and font dynamically from GeneralSettings"
```

---

### Task 6: `<x-theme-vars />` + integración en las 4 vistas públicas

**Files:**
- Create: `resources/views/components/theme-vars.blade.php`
- Modify: `resources/views/attendances/mark.blade.php`
- Modify: `resources/views/attendances/terminal.blade.php`
- Modify: `resources/views/attendances/device-link.blade.php`
- Modify: `resources/views/attendances/terminal-setup.blade.php`
- Test: `tests/Feature/ThemeVarsComponentTest.php`

**Interfaces:**
- Consumes: `ThemeResolver::primaryColorCss()`/`ThemeResolver::fontFamily()` (Task 2), `GeneralSettings::$primary_color`/`$font` (Task 3).
- Produces: nada consumido por tasks posteriores — última tarea del plan.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/ThemeVarsComponentTest.php`:

```php
<?php

use App\Settings\GeneralSettings;

it('renderiza los overrides de color y fuente elegidos en Settings', function () {
    $settings = app(GeneralSettings::class);
    $settings->primary_color = 'blue';
    $settings->font = 'roboto';
    $settings->save();

    $html = $this->blade('<x-theme-vars />')->toHtml();

    expect($html)
        ->toContain('--color-primary-600: #2563eb;')
        ->toContain("--font: 'Roboto', system-ui, -apple-system, sans-serif;")
        ->toContain('--c-primary-ring: rgba(59, 130, 246, 0.25);')
        ->toContain('--c-primary-bg: rgba(59, 130, 246, 0.12);');
});

it('usa los defaults Teal/Poppins cuando Settings no fue personalizado', function () {
    $html = $this->blade('<x-theme-vars />')->toHtml();

    expect($html)
        ->toContain('--color-primary-600: #0d9488;')
        ->toContain("--font: 'Poppins', system-ui, -apple-system, sans-serif;");
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/ThemeVarsComponentTest.php`

Expected: FAIL — `View [components.theme-vars] not found` (o el componente `<x-theme-vars />` no existe).

- [ ] **Step 3: Crear el componente**

Crear `resources/views/components/theme-vars.blade.php`:

```blade
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
    $settings = app(\App\Settings\GeneralSettings::class);
    $theme = \App\Support\ThemeResolver::primaryColorCss($settings->primary_color);
    $fontFamily = \App\Support\ThemeResolver::fontFamily($settings->font);
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
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/ThemeVarsComponentTest.php`

Expected: PASS — 2 tests.

- [ ] **Step 5: Incluir `<x-theme-vars />` en las 4 vistas públicas**

En `resources/views/attendances/mark.blade.php`, entre el `@vite('resources/css/attendances/styles.css')` y el `@vite('resources/js/attendances/mark.js')`:

```blade
    <x-favicon-links />
    @vite('resources/css/attendances/styles.css')
    <x-theme-vars />
    @vite('resources/js/attendances/mark.js')
```

En `resources/views/attendances/terminal.blade.php`, mismo patrón:

```blade
    <x-favicon-links />
    @vite('resources/css/attendances/terminal.css')
    <x-theme-vars />
    @vite('resources/js/attendances/terminal.js')
```

En `resources/views/attendances/device-link.blade.php` (el orden actual es JS primero, CSS después — mantener ese orden, agregar el componente después del `@vite` del CSS):

```blade
    <x-favicon-links />
    @vite('resources/js/attendances/device-link.js')
    @vite('resources/css/attendances/device-link.css')
    <x-theme-vars />
```

En `resources/views/attendances/terminal-setup.blade.php`:

```blade
    <x-favicon-links />
    @vite('resources/css/attendances/terminal-setup.css')
    <x-theme-vars />
```

- [ ] **Step 6: Verificación manual end-to-end**

Run: `npm run build`

Levantar el servidor (`composer run dev` o `php artisan serve` + `npm run dev` en otra terminal) y visitar `/marcar` y `/terminal` — confirmar que cargan sin errores de consola y que el `<style>` de `<x-theme-vars />` aparece en el `<head>` con los valores Teal/Poppins por default.

- [ ] **Step 7: Correr Pint**

Run: `vendor/bin/pint --dirty`

Expected: `passed`.

- [ ] **Step 8: Commit**

```bash
git add resources/views/components/theme-vars.blade.php resources/views/attendances/mark.blade.php resources/views/attendances/terminal.blade.php resources/views/attendances/device-link.blade.php resources/views/attendances/terminal-setup.blade.php tests/Feature/ThemeVarsComponentTest.php
git commit -m "feat: add <x-theme-vars /> and wire it into the 4 public attendance views"
```

---

### Task 7: Suite completa

**Files:** ninguno (tarea de verificación, sin cambios de código).

**Interfaces:**
- Consumes: todo lo producido por Tasks 1-6.
- Produces: nada.

- [ ] **Step 1: Build limpio desde cero**

Run: `npm run build`

Expected: build exitoso, sin warnings de assets faltantes.

- [ ] **Step 2: Suite completa de Pest**

Run: `php artisan test --compact`

Expected: todos los tests pasan (los existentes + los ~19 nuevos de este plan), 0 failures.

- [ ] **Step 3: Pint sobre todo el diff**

Run: `vendor/bin/pint --dirty`

Expected: `passed`.
