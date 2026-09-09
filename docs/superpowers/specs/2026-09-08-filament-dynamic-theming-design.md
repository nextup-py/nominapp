# Theming dinámico desde Filament — Diseño

**Sub-proyecto F** de la secuencia A→F→B→E→D→C (rediseño de marcación de asistencia). Sub-proyecto A (unificación de design tokens, self-hosting de Poppins, íconos PWA) ya está mergeado a `main`.

## Objetivo

Permitir que un administrador elija, desde el panel de Filament, el color primario y la fuente de toda la aplicación (panel admin + las 4 vistas públicas de marcación: `/marcar`, `/terminal`, `/vincular-dispositivo`, `/terminal/{code}/configurar`), sin necesitar un rebuild ni un deploy.

## Alcance

- **Por instalación**, no por empresa — un solo tenant de theming, no multi-tenant.
- Aplica al **panel admin de Filament y a las 4 vistas públicas de marcación**.
- Solo el **color primario/de marca** es configurable (no la paleta semántica completa: success/danger/warning/info quedan fijos — son significativos en todo el código, verde=éxito, rojo=error, etc.).
- Color y fuente se eligen de **listas curadas**, no de un selector libre (hex picker / cualquier fuente de Google Fonts).
- Identidad de marca completa (logotipo, guidelines) queda explícitamente fuera de alcance — esto es solo el mecanismo de theming.

## Arquitectura

### `GeneralSettings` (existente, ampliada)

La versión instalada de `Filament\Pages\SettingsPage` solo soporta **una** clase de Settings por página (`protected static string $settings`, no un array) — por eso los campos nuevos van directo en `GeneralSettings`, no en una clase `ThemeSettings` separada.

```php
public string $primary_color;  // key de paleta curada, ej. 'teal'
public string $font;            // key de fuente curada, ej. 'poppins'
```

Migración (`database/settings/2026_09_08_000001_add_theme_settings_to_general_settings.php`, mismo patrón que las migraciones de settings existentes) con defaults `'teal'` / `'poppins'` — los mismos valores que hoy están hardcodeados en `AdminPanelProvider.php` y `fonts.css`, así que desplegar esto no cambia nada visualmente hasta que un admin lo edite.

### `App\Support\ThemeResolver` (nuevo)

Única fuente de verdad que traduce las keys guardadas a lo que cada consumidor necesita. Clase con métodos estáticos:

- `colorPalette(string $key): array` — array de 11 tonos (50→950, hex) de la paleta Filament correspondiente (`Filament\Support\Colors\Color::{Teal,Blue,Indigo,Violet,Purple,Pink,Rose,Cyan,Sky,Orange}`). Si la key no está en la lista curada, cae silenciosamente a `Color::Teal` (default).
- `fontFamily(string $key): string` — nombre legible de la fuente ("Poppins", "Inter", etc.). Cae a `'Poppins'` si la key es desconocida.
- `fontAssetPath(string $key): string` — ruta al CSS de esa fuente individual (`resources/css/shared/fonts/{key}.css`), para el panel de Filament. Cae a la ruta de Poppins si la key es desconocida.
- `colorOptions(): array` / `fontOptions(): array` — arrays `key => label` para poblar los `Select` del formulario de Settings.

**Fallback silencioso, no excepción:** si el valor guardado en BD ya no está en la lista curada (edición manual directa, o una fuente/color removido de la lista en el futuro), `ThemeResolver` cae al default sin romper el panel ni las vistas públicas — son rutas de producción activas, un error 500 por un dato de configuración inválido no es aceptable.

### Colores curados (candidatos)

Evitando los hues ya usados por los colores semánticos fijos (verde=success, rojo=danger, amarillo=warning): **Teal** (default/actual), Blue, Indigo, Violet, Purple, Pink, Rose, Cyan, Sky, Orange.

### Fuentes curadas

**Poppins** (default/actual), Inter, Roboto, Nunito Sans, Work Sans — 5 sans-serif estándar de UI/SaaS, todas disponibles vía `@fontsource` (mismo patrón de self-hosting ya usado para Poppins en sub-proyecto A), con pesos 400/500/600/700 y soporte completo de acentos en español.

### Mecanismo de fuentes — por qué se empaquetan las 5

El CSS público se compila una sola vez en build-time (Vite). La única forma de "elegir" fuente en runtime sin rebuild es tener las 5 fuentes curadas ya empaquetadas en el bundle compilado, y que la capa dinámica solo cambie qué familia apunta la variable `--font`. El navegador solo descarga los archivos `.woff2` de la fuente que realmente se usa (`@font-face` es perezoso) — empaquetar las 5 no cuesta ancho de banda extra en la práctica.

Se reestructura `resources/css/shared/fonts.css`:
- Un archivo por fuente: `resources/css/shared/fonts/{key}.css` (cada uno con sus propios `@import '@fontsource/{fuente}/{peso}.css'` para los pesos 400/500/600/700).
- `resources/css/shared/fonts.css` pasa a importar **las 5 fuentes completas** (no solo Poppins) — sigue siendo importado por `tokens.css`, que a su vez es importado por el CSS compilado de cada vista pública. Así las 5 quedan disponibles sin rebuild cuando el admin cambia la selección.
- El panel de Filament sigue necesitando **una sola** fuente vía `LocalFontProvider` (que espera una única URL de CSS) — `ThemeResolver::fontAssetPath($key)` devuelve el archivo individual correspondiente (`fonts/poppins.css`, `fonts/inter.css`, etc.), no el combinado.

### Integración — Panel de Filament

`AdminPanelProvider::panel()` resuelve `app(GeneralSettings::class)` y usa `ThemeResolver` para pasar el resultado a `->colors(['primary' => ThemeResolver::colorPalette($settings->primary_color), ...])` y al `->font(...)` ya existente (con el mismo guard de `file_exists(public_path('build/manifest.json'))` introducido en sub-proyecto A para evitar el `ViteManifestNotFoundException` en CI/primer deploy). Se re-evalúa en cada boot del panel — barato: una lectura de Settings ya cacheada por `spatie/laravel-settings` en memoria de request, sin I/O de red ni filesystem adicional.

### Integración — Vistas públicas

Nuevo Blade component `<x-theme-vars />` (`resources/views/components/theme-vars.blade.php`) que resuelve lo mismo vía `ThemeResolver` y renderiza un `<style>` inline con:
- Overrides de `--color-primary-{50..950}` (y el alias legacy `--c-primary` de `tokens.css`).
- `--font: '{FontFamily}', sans-serif;`

Se incluye en las 4 vistas públicas, justo después del `@vite(...)` del CSS de cada una (mismo patrón consistente en las 4: `<x-favicon-links />` → `@vite(css)` → `@vite(js)` hoy; `<x-theme-vars />` se agrega entre el `@vite(css)` y el `@vite(js)`), así el `<style>` inline gana la cascada sobre las variables ya compiladas en el bundle estático.

**Offline / service worker:** el shell HTML de `/marcar` y `/terminal` (`SHELL_PATTERNS` en `public/sw.js`) usa `stale-while-revalidate`, no cache-first puro — el `<style>` inline con los valores actuales de theming queda embebido en el HTML servido y cacheado, y se actualiza en segundo plano la próxima vez que haya red. `device-link` y `terminal-setup` no están cacheados por el service worker (son intrínsecamente online), así que ahí el valor siempre es el más reciente sin ningún delay.

### UI de configuración

Nueva `Section::make('Apariencia')` dentro del formulario existente de `ManageGeneralSettings` (no una página nueva — se agrega junto a las secciones ya existentes de Configuración Laboral, Contratos, Reconocimiento Facial, Terminales), con dos `Select`:
- `primary_color` — opciones de `ThemeResolver::colorOptions()`.
- `font` — opciones de `ThemeResolver::fontOptions()`.

Sin selector de color libre, sin preview en vivo dentro del modal/form (fuera de alcance — el admin ve el resultado al guardar y navegar).

## Testing

- **Unit** (`ThemeResolver`): `colorPalette()`/`fontFamily()`/`fontAssetPath()` devuelven lo esperado para keys curadas válidas, y caen al default (`teal`/`poppins`) para keys desconocidas o vacías.
- **Feature** (`ManageGeneralSettings`): la nueva sección "Apariencia" renderiza los 2 Selects con las opciones curadas, y guardar el formulario persiste `primary_color`/`font` en `GeneralSettings`.
- **Feature** (`<x-theme-vars />`): renderizar el componente con un valor de settings dado produce el `<style>` esperado (contiene `--color-primary-500`, `--font`, etc. con los valores correctos) — sin depender de rendering visual real ni de un navegador.
- La verificación de que el panel admin efectivamente bootea con `->colors()`/`->font()` dinámicos queda fuera de test automatizado — es configuración de boot de Filament, mismo criterio que el proyecto ya aplica a la carga de fuentes/colores del panel (sub-proyecto A).

## Fuera de alcance (explícito)

- Identidad de marca completa (logotipo, guidelines) — diferido a una iniciativa futura separada.
- Theming por empresa/multi-tenant.
- Colores semánticos configurables (success/danger/warning/info).
- Selector de color libre (hex) o lista abierta de fuentes de Google Fonts.
- Preview en vivo dentro del formulario de Settings.
