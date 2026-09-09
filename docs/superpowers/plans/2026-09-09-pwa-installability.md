# Instalabilidad PWA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir instalar `/marcar` y `/terminal/{code}` como PWAs independientes, con manifests dinámicos que reflejan el color primario de sub-proyecto F, soporte manual para iOS/Safari, y un flujo de instalación integrado en el aprovisionamiento del Terminal.

**Architecture:** Dos rutas de manifest dinámicas (`AttendanceFaceMarkController`) resuelven `theme_color`/`background_color` vía `ThemeResolver`. Un módulo JS compartido y testeable por inyección de dependencias (`resources/js/shared/install-prompt.js`) encapsula la detección de plataforma y la lógica de `beforeinstallprompt`; cada vista pública (mark, terminal-setup) lo importa y conecta a su propio DOM.

**Tech Stack:** Laravel 12, Blade, Vite, Vitest, Pest.

**Spec:** `docs/superpowers/specs/2026-09-09-pwa-installability-design.md`

## Global Constraints

- Instalables: solo `/marcar` y `/terminal/{code}`. `/vincular-dispositivo`, `/terminal/{code}/configurar`, `/registro-facial` quedan fuera — sin manifest, sin prompt de instalación.
- Mismo set de íconos de sub-proyecto A (`public/icons/icon-{192,512}.png`, `-maskable` variantes) para ambos manifests — sin diseño nuevo.
- `display: standalone` en ambos manifests — no `fullscreen`.
- `theme_color`/`background_color` de ambos manifests se resuelven dinámicamente vía `App\Support\ThemeResolver::primaryColorCss()` (shades 600/50), reflejando `GeneralSettings::$primary_color` — nunca hardcodeados.
- Dismiss del banner de instalación: permanente por dispositivo (`localStorage`, sin expiración) — el botón de respaldo fijo es la única vía posterior.
- Ninguna dependencia npm nueva — el módulo `install-prompt.js` se diseña con inyección de dependencias (recibe `userAgent`/`storage`/`target` como parámetros con defaults a los globals reales) para ser testeable con Vitest en su entorno Node por defecto, sin `jsdom`.
- Los manifests NO se agregan a `CACHE_FIRST_PATTERNS` ni `SHELL_PATTERNS` de `public/sw.js` — quedan sin interceptar, igual que las rutas de vinculación/aprovisionamiento.
- CLAUDE.md: PHPDoc en clases/métodos PHP; JSDoc en funciones JS.

---

### Task 1: Rutas de manifest dinámicas

**Files:**
- Modify: `app/Http/Controllers/AttendanceFaceMarkController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PwaManifestTest.php`

**Interfaces:**
- Consumes: `App\Settings\GeneralSettings::$primary_color`, `App\Support\ThemeResolver::primaryColorCss(?string $key): array` (ya existentes, de sub-proyecto F).
- Produces (usado por Task 2): rutas nombradas `mark.manifest` (`GET /marcar/manifest.json`) y `terminal.manifest` (`GET /terminal/{code}/manifest.json`).

- [ ] **Step 1: Escribir los tests que fallan**

Crear `tests/Feature/PwaManifestTest.php`:

```php
<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeManifestTestTerminal(): Terminal
{
    $company = Company::create(['name' => 'Empresa Manifest', 'ruc' => '90000001-1', 'employer_number' => 900001]);
    $branch = Branch::create(['name' => 'Sucursal Manifest', 'company_id' => $company->id]);

    return Terminal::create(['name' => 'Terminal Manifest', 'branch_id' => $branch->id, 'code' => 'ABC12345']);
}

it('el manifest de /marcar devuelve JSON válido con el color primario por defecto', function () {
    $response = $this->get('/marcar/manifest.json');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/manifest+json');
    $response->assertJson([
        'name' => 'Nominapp Marcación',
        'start_url' => '/marcar',
        'scope' => '/marcar',
        'display' => 'standalone',
        'theme_color' => '#0d9488',
        'background_color' => '#f0fdfa',
    ]);
});

it('el manifest de /marcar refleja un color primario distinto del default', function () {
    $settings = app(GeneralSettings::class);
    $settings->primary_color = 'rose';
    $settings->save();

    $response = $this->get('/marcar/manifest.json');

    $response->assertOk();
    $response->assertJsonPath('theme_color', '#e11d48');
    $response->assertJsonPath('background_color', '#fff1f2');
});

it('el manifest de terminal incluye el start_url específico de esa sucursal', function () {
    $terminal = makeManifestTestTerminal();

    $response = $this->get("/terminal/{$terminal->code}/manifest.json");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/manifest+json');
    $response->assertJson([
        'name' => 'Nominapp Terminal',
        'start_url' => '/terminal/ABC12345',
        'scope' => '/terminal/',
        'display' => 'standalone',
    ]);
});

it('el manifest de terminal devuelve 404 si el código no existe', function () {
    $response = $this->get('/terminal/NOEXISTE/manifest.json');

    $response->assertNotFound();
});
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

Run: `php artisan test --compact tests/Feature/PwaManifestTest.php`

Expected: FAIL — las rutas `mark.manifest`/`terminal.manifest` no existen (404 en todos los casos, incluido el que espera 404 pero por un motivo distinto).

- [ ] **Step 3: Agregar los métodos del controller**

En `app/Http/Controllers/AttendanceFaceMarkController.php`, agregar el import:

```php
use App\Settings\GeneralSettings;
use App\Support\ThemeResolver;
use Illuminate\Http\JsonResponse;
```

Agregar después del método `terminalByCode()`:

```php
    /** Manifest de PWA para el modo Marcación (dispositivo personal del empleado). */
    public function markManifest(): JsonResponse
    {
        return $this->buildManifest(
            name: 'Nominapp Marcación',
            shortName: 'Marcación',
            startUrl: '/marcar',
            scope: '/marcar',
        );
    }

    /**
     * Manifest de PWA para el modo Terminal, específico de la sucursal.
     *
     * @param  string  $code  Código único de 8 caracteres de la terminal
     */
    public function terminalManifest(string $code): JsonResponse
    {
        $terminal = Terminal::where('code', $code)->first();

        if (! $terminal) {
            abort(404);
        }

        return $this->buildManifest(
            name: 'Nominapp Terminal',
            shortName: 'Terminal',
            startUrl: "/terminal/{$terminal->code}",
            scope: '/terminal/',
        );
    }

    /**
     * Construye el JSON del manifest, resolviendo el color primario configurado
     * en Settings una sola vez por request (cae al default de ThemeResolver si
     * el settings store no está disponible — mismo criterio defensivo que
     * <x-theme-vars />: una lectura fallida no debe romper una ruta pública activa).
     * Mismo set de íconos para ambos modos — sin variantes por modo.
     */
    private function buildManifest(string $name, string $shortName, string $startUrl, string $scope): JsonResponse
    {
        try {
            $colorKey = app(GeneralSettings::class)->primary_color;
        } catch (\Throwable) {
            $colorKey = ThemeResolver::DEFAULT_COLOR;
        }

        $theme = ThemeResolver::primaryColorCss($colorKey);

        return response()->json([
            'name' => $name,
            'short_name' => $shortName,
            'start_url' => $startUrl,
            'scope' => $scope,
            'display' => 'standalone',
            'theme_color' => $theme['hex'][600],
            'background_color' => $theme['hex'][50],
            'icons' => [
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => asset('icons/icon-192-maskable.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => asset('icons/icon-512-maskable.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
```

- [ ] **Step 4: Agregar las rutas**

En `routes/web.php`, después de la línea `Route::get('/marcar', ...)->name('mark.show');`:

```php
Route::get('/marcar/manifest.json', [AttendanceFaceMarkController::class, 'markManifest'])->name('mark.manifest');
```

Después de la línea `Route::get('/terminal/{code}', ...)->name('terminal.show');`:

```php
Route::get('/terminal/{code}/manifest.json', [AttendanceFaceMarkController::class, 'terminalManifest'])->name('terminal.manifest');
```

- [ ] **Step 5: Correr los tests y verificar que pasan**

Run: `php artisan test --compact tests/Feature/PwaManifestTest.php`

Expected: PASS — 4 tests.

- [ ] **Step 6: Correr Pint**

Run: `vendor/bin/pint --dirty`

Expected: `passed`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AttendanceFaceMarkController.php routes/web.php tests/Feature/PwaManifestTest.php
git commit -m "feat: add dynamic PWA manifest routes for /marcar and /terminal/{code}"
```

---

### Task 2: Meta tags — `theme-color` y componente `<x-pwa-meta />`

**Files:**
- Modify: `resources/views/components/theme-vars.blade.php`
- Create: `resources/views/components/pwa-meta.blade.php`
- Modify: `resources/views/attendances/mark.blade.php`
- Modify: `resources/views/attendances/terminal.blade.php`
- Test: `tests/Feature/PwaMetaComponentTest.php`

**Interfaces:**
- Consumes: rutas `mark.manifest`/`terminal.manifest` de Task 1.
- Produces: nada consumido por tasks posteriores.

- [ ] **Step 1: Escribir el test que falla**

Crear `tests/Feature/PwaMetaComponentTest.php`:

```php
<?php

it('el componente pwa-meta renderiza el link de manifest y los meta tags de iOS', function () {
    $html = $this->blade(
        '<x-pwa-meta manifest-url="/marcar/manifest.json" app-title="Nominapp Marcación" />'
    )->toHtml();

    expect($html)
        ->toContain('<link rel="manifest" href="/marcar/manifest.json">')
        ->toContain('name="apple-mobile-web-app-capable" content="yes"')
        ->toContain('name="apple-mobile-web-app-title" content="Nominapp Marcación"');
});

it('theme-vars incluye el meta theme-color con el hex del color primario', function () {
    $html = $this->blade('<x-theme-vars />')->toHtml();

    expect($html)->toContain('<meta name="theme-color" content="#0d9488">');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/PwaMetaComponentTest.php`

Expected: FAIL — `<x-pwa-meta />` no existe; el meta `theme-color` no está en `theme-vars.blade.php` todavía.

- [ ] **Step 3: Agregar `theme-color` a `theme-vars.blade.php`**

En `resources/views/components/theme-vars.blade.php`, agregar la línea `<meta name="theme-color" content="{{ $theme['hex'][600] }}">` justo antes de la etiqueta `<style>` (después del bloque `@endphp`):

```blade
@endphp
<meta name="theme-color" content="{{ $theme['hex'][600] }}">
<style>
```

- [ ] **Step 4: Crear el componente `pwa-meta`**

Crear `resources/views/components/pwa-meta.blade.php`:

```blade
{{--
    Meta tags de PWA que varían por modo (manifest y título) — no van en
    favicon-links.blade.php porque ese componente es idéntico en las 5 vistas
    públicas, mientras esto solo aplica a /marcar y /terminal (las únicas
    instalables). apple-touch-icon ya lo provee <x-favicon-links />, no se
    duplica acá.
--}}
@props(['manifestUrl', 'appTitle'])
<link rel="manifest" href="{{ $manifestUrl }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ $appTitle }}">
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/PwaMetaComponentTest.php`

Expected: PASS — 2 tests.

- [ ] **Step 6: Incluir `<x-pwa-meta />` en `mark.blade.php`**

En `resources/views/attendances/mark.blade.php`, en el `<head>`, después de `<x-theme-vars />` y antes del `@vite('resources/js/attendances/mark.js')`:

```blade
    <x-theme-vars />
    <x-pwa-meta manifest-url="{{ route('mark.manifest') }}" app-title="Nominapp Marcación" />
    @vite('resources/js/attendances/mark.js')
```

- [ ] **Step 7: Incluir `<x-pwa-meta />` en `terminal.blade.php`**

`terminal.blade.php` sirve tanto la ruta legacy `/terminal` (sin `$terminal` cargado) como `/terminal/{code}` (con `$terminal` cargado) — el manifest necesita el código, así que solo se incluye cuando `$terminal` está disponible. En `resources/views/attendances/terminal.blade.php`, en el `<head>`, después de `<x-theme-vars />` y antes del `@vite('resources/js/attendances/terminal.js')`:

```blade
    <x-theme-vars />
    @isset($terminal)
        <x-pwa-meta manifest-url="{{ route('terminal.manifest', $terminal->code) }}" app-title="Nominapp Terminal" />
    @endisset
    @vite('resources/js/attendances/terminal.js')
```

- [ ] **Step 8: Correr Pint**

Run: `vendor/bin/pint --dirty`

Expected: `passed`.

- [ ] **Step 9: Commit**

```bash
git add resources/views/components/theme-vars.blade.php resources/views/components/pwa-meta.blade.php resources/views/attendances/mark.blade.php resources/views/attendances/terminal.blade.php tests/Feature/PwaMetaComponentTest.php
git commit -m "feat: add theme-color meta tag and <x-pwa-meta /> component"
```

---

### Task 3: Módulo compartido `install-prompt.js`

**Files:**
- Create: `resources/js/shared/install-prompt.js`
- Test: `resources/js/shared/install-prompt.test.js`

**Interfaces:**
- Consumes: nada (módulo independiente, primera pieza de JS del plan).
- Produces (usado por Tasks 4 y 5):
  - `captureInstallPrompt(onAvailable: (event: Event) => void, target?: EventTarget): void`
  - `triggerInstallPrompt(): Promise<'accepted'|'dismissed'|'unavailable'>`
  - `isStandalone(navigatorStandalone: boolean|undefined, displayModeStandalone: boolean): boolean`
  - `isIOS(userAgent: string, maxTouchPoints?: number): boolean`
  - `isDismissed(mode: 'mark'|'terminal', storage?: {getItem: Function}): boolean`
  - `dismiss(mode: 'mark'|'terminal', storage?: {setItem: Function}): void`

- [ ] **Step 1: Escribir el test que falla**

Crear `resources/js/shared/install-prompt.test.js`:

```js
import { describe, expect, it, vi } from 'vitest';
import {
    captureInstallPrompt,
    triggerInstallPrompt,
    isStandalone,
    isIOS,
    isDismissed,
    dismiss,
} from './install-prompt.js';

describe('isStandalone', () => {
    it('true si navigator.standalone es true (iOS instalado)', () => {
        expect(isStandalone(true, false)).toBe(true);
    });

    it('true si display-mode: standalone matchea (Android/Chrome instalado)', () => {
        expect(isStandalone(undefined, true)).toBe(true);
    });

    it('false si ninguna de las dos señales indica instalado', () => {
        expect(isStandalone(false, false)).toBe(false);
        expect(isStandalone(undefined, false)).toBe(false);
    });
});

describe('isIOS', () => {
    it('detecta iPhone por user agent', () => {
        expect(isIOS('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)')).toBe(true);
    });

    it('detecta iPad clásico por user agent', () => {
        expect(isIOS('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)')).toBe(true);
    });

    it('detecta iPadOS moderno (se anuncia como Macintosh con soporte táctil)', () => {
        expect(isIOS('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 5)).toBe(true);
    });

    it('no confunde un Mac de escritorio (sin touch) con iPadOS', () => {
        expect(isIOS('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 0)).toBe(false);
    });

    it('no detecta Android como iOS', () => {
        expect(isIOS('Mozilla/5.0 (Linux; Android 14)')).toBe(false);
    });
});

describe('isDismissed / dismiss', () => {
    function fakeStorage() {
        const store = new Map();
        return {
            getItem: (key) => store.get(key) ?? null,
            setItem: (key, value) => store.set(key, value),
        };
    }

    it('no está descartado por defecto', () => {
        const storage = fakeStorage();
        expect(isDismissed('mark', storage)).toBe(false);
    });

    it('queda descartado después de llamar dismiss()', () => {
        const storage = fakeStorage();
        dismiss('mark', storage);
        expect(isDismissed('mark', storage)).toBe(true);
    });

    it('el dismiss de un modo no afecta al otro (namespaced)', () => {
        const storage = fakeStorage();
        dismiss('mark', storage);
        expect(isDismissed('terminal', storage)).toBe(false);
    });
});

describe('captureInstallPrompt / triggerInstallPrompt', () => {
    it('captura el evento, bloquea el default, y lo dispara bajo demanda', async () => {
        const target = new EventTarget();
        const onAvailable = vi.fn();

        captureInstallPrompt(onAvailable, target);

        const preventDefault = vi.fn();
        const prompt = vi.fn();
        const event = new Event('beforeinstallprompt', { cancelable: true });
        event.preventDefault = preventDefault;
        event.prompt = prompt;
        event.userChoice = Promise.resolve({ outcome: 'accepted' });

        target.dispatchEvent(event);

        expect(preventDefault).toHaveBeenCalled();
        expect(onAvailable).toHaveBeenCalledWith(event);

        const outcome = await triggerInstallPrompt();

        expect(prompt).toHaveBeenCalled();
        expect(outcome).toBe('accepted');
    });

    it('devuelve unavailable si nunca se capturó un evento', async () => {
        // Este test corre después de que el test anterior ya consumió el
        // deferredPrompt capturado (triggerInstallPrompt lo limpia tras usarlo).
        const outcome = await triggerInstallPrompt();
        expect(outcome).toBe('unavailable');
    });
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `npm run test -- install-prompt`

Expected: FAIL — `Cannot find module './install-prompt.js'`.

- [ ] **Step 3: Implementar `install-prompt.js`**

Crear `resources/js/shared/install-prompt.js`:

```js
/**
 * Lógica de instalación de la PWA — detección de plataforma, captura del
 * evento beforeinstallprompt (Android/Chrome/Edge) y persistencia del
 * descarte del banner. Las funciones reciben sus dependencias del navegador
 * (userAgent, storage, target de eventos) como parámetros en vez de leer
 * los globals directamente, para poder testearlas sin un entorno jsdom —
 * en producción los valores por defecto ya apuntan a los globals reales.
 */

let deferredPrompt = null;

/**
 * Captura el evento beforeinstallprompt antes de que el navegador lo
 * descarte, y bloquea el mini-infobar nativo (preventDefault) para que la
 * UI propia decida cuándo mostrarlo. Debe llamarse una sola vez al cargar
 * la página — no dispara nada en iOS (ese navegador nunca emite el evento).
 * @param {(event: Event) => void} onAvailable - se invoca cuando el prompt queda disponible
 * @param {EventTarget} [target] - inyectable para tests; window en producción
 */
export function captureInstallPrompt(onAvailable, target = window) {
    target.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredPrompt = event;
        onAvailable(event);
    });
}

/**
 * Dispara el prompt nativo de instalación capturado previamente.
 * @returns {Promise<'accepted'|'dismissed'|'unavailable'>}
 */
export async function triggerInstallPrompt() {
    if (!deferredPrompt) {
        return 'unavailable';
    }

    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    deferredPrompt = null;

    return outcome;
}

/**
 * @param {boolean|undefined} navigatorStandalone - navigator.standalone (solo iOS)
 * @param {boolean} displayModeStandalone - window.matchMedia('(display-mode: standalone)').matches
 */
export function isStandalone(navigatorStandalone, displayModeStandalone) {
    return navigatorStandalone === true || displayModeStandalone === true;
}

/**
 * iPadOS moderno se anuncia como "Macintosh" en el user agent (indistinguible
 * de un Mac de escritorio por UA solo) — se distingue por soporte táctil
 * (maxTouchPoints > 1), que ningún Mac de escritorio real tiene.
 * @param {string} userAgent
 * @param {number} [maxTouchPoints]
 */
export function isIOS(userAgent, maxTouchPoints = 0) {
    const isAppleDevice = /iPad|iPhone|iPod/.test(userAgent);
    const isIpadOsDesktopUa = userAgent.includes('Macintosh') && maxTouchPoints > 1;

    return isAppleDevice || isIpadOsDesktopUa;
}

function dismissKey(mode) {
    return `nominapp_install_dismissed_${mode}`;
}

/**
 * @param {'mark'|'terminal'} mode
 * @param {{getItem: (key: string) => string|null}} [storage] - inyectable para tests; localStorage en producción
 */
export function isDismissed(mode, storage = localStorage) {
    return storage.getItem(dismissKey(mode)) === '1';
}

/**
 * @param {'mark'|'terminal'} mode
 * @param {{setItem: (key: string, value: string) => void}} [storage] - inyectable para tests; localStorage en producción
 */
export function dismiss(mode, storage = localStorage) {
    storage.setItem(dismissKey(mode), '1');
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `npm run test -- install-prompt`

Expected: PASS — 13 tests.

- [ ] **Step 5: Registrar el archivo como entry point de Vite**

En `vite.config.js`, dentro del array `input`, no hace falta agregar `install-prompt.js` — no se importa directamente vía `Vite::asset()`, solo se importa desde otros módulos JS (`mark.js` en Task 4, `terminal-setup.js` en Task 5) que sí son entry points y lo empaquetan como dependencia.

- [ ] **Step 6: Commit**

```bash
git add resources/js/shared/install-prompt.js resources/js/shared/install-prompt.test.js
git commit -m "feat: add shared install-prompt.js module with platform detection"
```

---

### Task 4: UI de instalación en `/marcar`

**Files:**
- Modify: `resources/views/attendances/mark.blade.php`
- Modify: `resources/css/attendances/styles.css`
- Modify: `resources/js/attendances/mark.js`

**Interfaces:**
- Consumes: `captureInstallPrompt`, `triggerInstallPrompt`, `isStandalone`, `isIOS`, `isDismissed`, `dismiss` de `resources/js/shared/install-prompt.js` (Task 3).
- Produces: nada consumido por tasks posteriores.

- [ ] **Step 1: Agregar el banner y el botón de respaldo al HTML**

En `resources/views/attendances/mark.blade.php`, agregar el botón de respaldo en el header, dentro de `<div class="app-header-brand">`, después del botón `btnThemeToggle` (antes del cierre de ese `<button>` y del `</div>` del brand):

```blade
            <button type="button" id="btnThemeToggle" class="theme-toggle" aria-label="Cambiar tema claro/oscuro">
                <svg class="icon-moon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
                <svg class="icon-sun" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
            </button>
            <button type="button" id="btnInstallApp" class="install-toggle hidden" aria-label="Instalar aplicación">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
            </button>
```

Agregar el banner de instalación después del `<div id="conflictBanner" ...>` (busca el cierre `</div>` de ese banner) y antes del `<div class="sync-status-row" ...>`:

```blade
    <div id="installBanner" class="install-banner" role="status" aria-live="polite">
        <span id="installBannerText">Instalá esta app en tu pantalla de inicio para acceso rápido</span>
        <button type="button" id="btnInstallNow" class="install-banner-btn">Instalar</button>
        <button type="button" id="btnDismissInstall" class="install-banner-dismiss">Ahora no</button>
    </div>
```

- [ ] **Step 2: Agregar los estilos**

En `resources/css/attendances/styles.css`, agregar después del bloque `.conflict-dismiss-btn:hover { ... }` (línea 141 aproximadamente):

```css

/* ============================================================
   BANNER E ÍCONO DE INSTALACIÓN PWA
   ============================================================ */
.install-toggle {
    display: flex; align-items: center; justify-content: center;
    width: 32px; height: 32px;
    border-radius: 50%;
    border: 1px solid var(--c-border);
    background: transparent;
    color: var(--c-muted);
    cursor: pointer;
    transition: background var(--tr-f), color var(--tr-f), border-color var(--tr-f);
    flex-shrink: 0;
}
.install-toggle:hover { background: var(--c-primary-bg); color: var(--c-primary); border-color: var(--c-primary); }
.install-toggle svg { width: 16px; height: 16px; display: block; }
.install-banner {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: var(--sp-2) var(--sp-3);
    padding: var(--sp-2) var(--sp-4);
    background: var(--c-primary-bg);
    border-bottom: 1px solid var(--c-primary-ring);
    color: var(--c-primary);
    font-size: .8125rem;
    font-weight: 600;
    overflow: hidden;
    max-height: 0;
    opacity: 0;
    transition: max-height 400ms ease, opacity 400ms ease, padding 400ms ease;
}
.install-banner.is-visible { max-height: 140px; opacity: 1; padding: var(--sp-2) var(--sp-4); }
.install-banner-btn {
    background: var(--c-primary);
    color: #fff;
    font: inherit;
    font-size: .75rem;
    font-weight: 700;
    padding: 4px var(--sp-3);
    border: none;
    border-radius: var(--r);
    cursor: pointer;
    white-space: nowrap;
}
.install-banner-btn:hover { background: var(--c-primary-h); }
.install-banner-dismiss {
    background: transparent;
    border: 1px solid var(--c-primary);
    color: var(--c-primary);
    font: inherit;
    font-size: .75rem;
    font-weight: 700;
    padding: 3px var(--sp-3);
    border-radius: var(--r);
    cursor: pointer;
    white-space: nowrap;
}
.install-banner-dismiss:hover { background: var(--c-primary); color: #fff; }
```

- [ ] **Step 3: Conectar la lógica en `mark.js`**

En `resources/js/attendances/mark.js`, agregar el import junto a los demás (después del import de `showErrorModal, initErrorModal, ...`):

```js
import {
    captureInstallPrompt,
    triggerInstallPrompt,
    isStandalone,
    isIOS,
    isDismissed,
    dismiss,
} from '../shared/install-prompt.js';
```

El archivo tiene, cerca del final (dentro del mismo listener `document.addEventListener("DOMContentLoaded", () => { ... })` que envuelve todo el archivo), este bloque exacto:

```js
    const btnThemeToggle = document.getElementById("btnThemeToggle");
    if (btnThemeToggle) {
        btnThemeToggle.addEventListener("click", () => {
            const isDark = document.documentElement.getAttribute("data-theme") === "dark";
            const next = isDark ? "light" : "dark";
            document.documentElement.setAttribute("data-theme", next);
            localStorage.setItem("mark-theme", next);
        });
    }

    // ==========================================================================
    // INICIALIZACIÓN
    // ==========================================================================
```

Insertar la siguiente función auto-invocada entre el cierre del bloque `if (btnThemeToggle)` (la línea `}`) y el comentario `// INICIALIZACIÓN` — debe quedar dentro del mismo listener `DOMContentLoaded`, al mismo nivel de indentación que `btnThemeToggle`:

```js
    (function initInstallUi() {
        const installBanner = document.getElementById("installBanner");
        const installBannerText = document.getElementById("installBannerText");
        const btnInstallNow = document.getElementById("btnInstallNow");
        const btnDismissInstall = document.getElementById("btnDismissInstall");
        const btnInstallApp = document.getElementById("btnInstallApp");

        const standalone = isStandalone(
            window.navigator.standalone,
            window.matchMedia("(display-mode: standalone)").matches
        );
        if (standalone) {
            return;
        }

        function showBanner() {
            if (isDismissed("mark")) {
                return;
            }
            installBanner.classList.add("is-visible");
        }

        function hideBanner() {
            installBanner.classList.remove("is-visible");
        }

        function showBackupButton() {
            btnInstallApp.classList.remove("hidden");
        }

        if (isIOS(window.navigator.userAgent, window.navigator.maxTouchPoints)) {
            installBannerText.textContent = 'Tocá el botón Compartir y elegí "Agregar a pantalla de inicio".';
            btnInstallNow.classList.add("hidden");
            showBanner();
            showBackupButton();
            btnInstallApp.addEventListener("click", () => installBanner.classList.add("is-visible"));
        } else {
            captureInstallPrompt(() => {
                showBanner();
                showBackupButton();
            });

            btnInstallNow.addEventListener("click", async () => {
                const outcome = await triggerInstallPrompt();
                if (outcome === "accepted") {
                    hideBanner();
                    btnInstallApp.classList.add("hidden");
                }
            });

            btnInstallApp.addEventListener("click", () => triggerInstallPrompt());
        }

        btnDismissInstall.addEventListener("click", () => {
            dismiss("mark");
            hideBanner();
        });
    })();
```

- [ ] **Step 4: Build y verificación**

Run: `npm run build`

Expected: build exitoso, sin errores.

Run: `npm run test`

Expected: todos los tests de Vitest pasan (incluidos los 13 de `install-prompt.test.js` de Task 3).

- [ ] **Step 5: Correr Pint**

Run: `vendor/bin/pint --dirty`

Expected: `passed`.

- [ ] **Step 6: Commit**

```bash
git add resources/views/attendances/mark.blade.php resources/css/attendances/styles.css resources/js/attendances/mark.js
git commit -m "feat: add install banner and backup button to /marcar"
```

---

### Task 5: Instalación en el aprovisionamiento del Terminal

**Files:**
- Create: `resources/js/attendances/terminal-setup.js`
- Modify: `resources/views/attendances/terminal-setup.blade.php`
- Modify: `resources/css/attendances/terminal-setup.css`
- Modify: `vite.config.js`

**Interfaces:**
- Consumes: `captureInstallPrompt`, `triggerInstallPrompt`, `isStandalone`, `isIOS` de `resources/js/shared/install-prompt.js` (Task 3).
- Produces: nada consumido por tasks posteriores — última tarea del plan.

`terminal-setup.blade.php` hoy tiene su lógica en un `<script>` inline (no es un entry point de Vite) — no puede usar `import` de un módulo ES sin pasar antes por Vite. Esta tarea convierte ese inline script a un archivo real (`terminal-setup.js`), habilitando la reutilización real del módulo compartido de Task 3 en vez de duplicar la lógica.

También reemplaza el `setTimeout` de redirección automática (1.2s) por un flujo explícito: tras vincular, se muestra el prompt/instrucciones de instalación, y un botón "Continuar al terminal" que el admin presiona cuando ya instaló (o si decide saltarlo). Sin este cambio, el redirect automático no deja tiempo para mostrar nada.

- [ ] **Step 1: Crear `terminal-setup.js` con la misma lógica de vinculación + instalación**

Crear `resources/js/attendances/terminal-setup.js`:

```js
/**
 * Lógica de la página de aprovisionamiento de terminal — vincula el
 * dispositivo (reclama el token Sanctum del enlace de un solo uso) y, tras
 * vincular exitosamente, ofrece instalar la PWA antes de continuar al modo
 * terminal. Extraído del <script> inline original para poder importar el
 * módulo compartido de instalación (ver resources/js/shared/install-prompt.js).
 */
import { captureInstallPrompt, triggerInstallPrompt, isStandalone, isIOS } from '../shared/install-prompt.js';

const btn = document.getElementById('btnClaim');
const statusEl = document.getElementById('status');
const installSection = document.getElementById('installSection');
const installInstructionsIos = document.getElementById('installInstructionsIos');
const btnInstallNow = document.getElementById('btnInstallNow');
const btnContinue = document.getElementById('btnContinue');
const csrf = document.querySelector('meta[name="csrf-token"]').content;

let terminalCode = null;

/**
 * Modelo real del dispositivo, solo disponible vía Client Hints en navegadores
 * Chromium sobre Android (Chrome, Edge...) — null en iOS/Safari/Firefox/desktop,
 * donde el servidor cae al parseo del User-Agent (ver DeviceHintsParser). Sirve
 * solo para prellenar marca/modelo como sugerencia editable en el panel.
 */
async function getClientHintModel() {
    if (!navigator.userAgentData?.getHighEntropyValues) {
        return null;
    }
    try {
        const hints = await navigator.userAgentData.getHighEntropyValues(['model']);
        return hints.model || null;
    } catch {
        return null;
    }
}

captureInstallPrompt(() => {
    btnInstallNow.hidden = false;
});

btn.addEventListener('click', async () => {
    btn.disabled = true;
    statusEl.textContent = 'Vinculando...';
    statusEl.className = 'status';

    try {
        const response = await fetch(window.location.pathname.replace(/\/$/, '') + '/claim', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ device_model_hint: await getClientHintModel() }),
        });
        const data = await response.json();

        if (!data.ok) {
            statusEl.textContent = data.message || 'No se pudo vincular el dispositivo.';
            statusEl.className = 'status status--error';
            btn.disabled = false;
            return;
        }

        // Almacenamiento provisorio del token — en la fase de sincronización offline
        // (IndexedDB, módulo terminal-offline/) este valor pasa a vivir en el store
        // `terminal_meta` en vez de localStorage. Se guarda el id (estable, no cambia
        // con "Cambiar URL del terminal") además del code (cambia con esa acción) —
        // terminal.js usa el id para detectar si el estado local pertenece a OTRO
        // terminal distinto, sin confundir un simple cambio de URL con eso.
        localStorage.setItem('nominapp_terminal_token', data.token);
        localStorage.setItem('nominapp_terminal_id', data.terminal.id);
        localStorage.setItem('nominapp_terminal_code', data.terminal.code);

        terminalCode = data.terminal.code;
        statusEl.textContent = 'Dispositivo vinculado correctamente.';
        statusEl.className = 'status status--success';
        btn.hidden = true;

        const standalone = isStandalone(
            window.navigator.standalone,
            window.matchMedia('(display-mode: standalone)').matches
        );

        if (standalone) {
            // Reaprovisión de un terminal ya instalado como PWA — sin necesidad de
            // volver a mostrar el flujo de instalación, continuar directo.
            window.location.href = '/terminal/' + terminalCode;
            return;
        }

        installSection.hidden = false;

        if (isIOS(window.navigator.userAgent, window.navigator.maxTouchPoints)) {
            btnInstallNow.hidden = true;
            installInstructionsIos.hidden = false;
        }
    } catch (error) {
        statusEl.textContent = 'Error de conexión. Intente nuevamente.';
        statusEl.className = 'status status--error';
        btn.disabled = false;
    }
});

btnInstallNow.addEventListener('click', () => triggerInstallPrompt());

btnContinue.addEventListener('click', () => {
    window.location.href = '/terminal/' + terminalCode;
});
```

- [ ] **Step 2: Reescribir `terminal-setup.blade.php`**

Reemplazar el contenido completo de `resources/views/attendances/terminal-setup.blade.php`:

```blade
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configurar terminal — {{ $terminal->name }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-favicon-links />
    @vite('resources/css/attendances/terminal-setup.css')
    <x-theme-vars />
</head>

<body>
    <div class="container">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
            </svg>
        </div>
        <h1>Configurar terminal</h1>
        <p>Este dispositivo se vinculará como el terminal de marcación de la sucursal indicada, habilitando la marcación sin conexión.</p>
        <div class="terminal-meta">{{ $terminal->name }} — {{ $terminal->branch?->name }}</div>

        <button type="button" id="btnClaim">Vincular este dispositivo</button>
        <div id="status" class="status" role="status" aria-live="polite"></div>

        <div id="installSection" class="install-section" hidden>
            <p>Instalá esta app en la pantalla de inicio del dispositivo para que funcione como terminal fijo.</p>
            <p id="installInstructionsIos" class="install-instructions-ios" hidden>En iOS: tocá el botón Compartir y elegí "Agregar a pantalla de inicio".</p>
            <button type="button" id="btnInstallNow" hidden>Instalar ahora</button>
            <button type="button" id="btnContinue" class="btn-secondary">Continuar al terminal</button>
        </div>
    </div>

    @vite('resources/js/attendances/terminal-setup.js')
</body>

</html>
```

- [ ] **Step 3: Agregar estilos para la sección de instalación**

En `resources/css/attendances/terminal-setup.css`, agregar al final del archivo:

```css

.install-section { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--color-surface-800); }
.install-instructions-ios { color: var(--c-primary-l); font-weight: 600; }
.btn-secondary {
    background: transparent;
    border: 1px solid var(--c-primary-l);
    color: var(--c-primary-l);
    margin-top: 0.75rem;
}
.btn-secondary:hover { background: var(--c-primary-l); color: var(--color-surface-900); }
```

- [ ] **Step 4: Registrar `terminal-setup.js` como entry point de Vite**

En `vite.config.js`, agregar `'resources/js/attendances/terminal-setup.js',` al array `input`, junto a `'resources/css/attendances/terminal-setup.css',`:

```js
                'resources/css/attendances/terminal-setup.css',
                'resources/js/attendances/terminal-setup.js',
```

- [ ] **Step 5: Build y verificación manual**

Run: `npm run build`

Expected: build exitoso, `public/build/manifest.json` incluye una entrada para `resources/js/attendances/terminal-setup.js`.

Run: `php artisan test --compact`

Expected: toda la suite pasa (los tests existentes de `TerminalSetupController` no deberían verse afectados — solo cambió el JS/blade del lado cliente, no el endpoint `/claim`).

- [ ] **Step 6: Correr Pint**

Run: `vendor/bin/pint --dirty`

Expected: `passed`.

- [ ] **Step 7: Commit**

```bash
git add resources/js/attendances/terminal-setup.js resources/views/attendances/terminal-setup.blade.php resources/css/attendances/terminal-setup.css vite.config.js
git commit -m "feat: convert terminal-setup to a Vite JS module and add install flow after claim"
```

---

### Task 6: Suite completa

**Files:** ninguno (tarea de verificación, sin cambios de código).

- [ ] **Step 1: Build limpio desde cero**

Run: `npm run build`

Expected: build exitoso, sin warnings de assets faltantes.

- [ ] **Step 2: Suite completa de Vitest**

Run: `npm run test`

Expected: todos los tests pasan (los existentes + los 13 nuevos de `install-prompt.test.js`), 0 failures.

- [ ] **Step 3: Suite completa de Pest**

Run: `php artisan test --compact`

Expected: todos los tests pasan (los existentes + los ~6 nuevos de este plan), 0 failures.

- [ ] **Step 4: Pint sobre todo el diff**

Run: `vendor/bin/pint --dirty`

Expected: `passed`.
