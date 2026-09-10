# Sub-proyecto E: Rediseño visual/UX de vistas públicas de marcación — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nivelar `device-link.blade.php`, `shared/capture-face.blade.php` (+ sus 2 wrappers) y 4 pantallas de estado/error al mismo estándar visual y de código (tokens de diseño, toggle de tema, módulos Vite testeables) que ya tienen `mark.blade.php`/`terminal.blade.php`.

**Architecture:** Se extraen dos componentes Blade compartidos (`<x-theme-toggle-button>`, `<x-status-page>`) con su JS/CSS correspondiente, siguiendo el patrón de módulos JS dependency-injected ya validado en `resources/js/shared/install-prompt.js` (parámetros con default a los globals reales, testeables sin jsdom). Las vistas rezagadas migran sus estilos inline/hardcodeados a los tokens semánticos de `tokens.css`, y su JS a módulos Vite con `addEventListener` en vez de atributos inline.

**Tech Stack:** Laravel 12 Blade components, Vite (vanilla JS modules, sin framework), Tailwind CSS v4 tokens (`resources/css/shared/tokens.css`), Vitest, Pest.

**Spec:** docs/superpowers/specs/2026-09-10-attendance-views-visual-redesign-design.md

## Global Constraints

- No se modifica la lógica de captura facial (`FaceCaptureApp.js`, `face-capture-core.js`) — motor, no UI.
- El mensaje de error genérico de `device-link` (CI/fecha incorrectos) se mantiene sin discriminar campo — decisión de seguridad deliberada, documentada en `MobileLinkController::claim()`. Solo se mejora su presentación visual.
- `mark.blade.php`/`terminal.blade.php` solo reciben el cambio de extracción del toggle de tema — ningún otro cambio visual/estructural en esas dos vistas.
- Todo archivo PHP nuevo/modificado lleva PHPDoc; todo JS exportado lleva JSDoc; bloques Blade relevantes llevan comentario `{{-- --}}` — convención del proyecto (CLAUDE.md).
- Módulos JS nuevos siguen el patrón dependency-injected de `resources/js/shared/install-prompt.js`: dependencias del navegador (storage, document, window) como parámetros con default al global real.
- `device-link.js` YA EXISTE (`resources/js/attendances/device-link.js`) y ya está registrado en `vite.config.js` — maneja hoy solo el aviso de "dispositivo ya vinculado" vía `mobile-offline/db.js`. Las tareas que lo tocan EXTIENDEN este archivo, no lo recrean.
- Comandos de test: `php artisan test --compact --filter=<Test>` (Pest), `npm run test -- <archivo>` (Vitest, revisar `package.json` por el script exacto antes de la Tarea 1).
- Correr `vendor/bin/pint --dirty` antes de cada commit que toque PHP.

---

### Task 1: Componente compartido de toggle de tema

**Files:**
- Create: `resources/views/components/theme-toggle-button.blade.php`
- Create: `resources/js/shared/theme-toggle.js`
- Test: `resources/js/shared/theme-toggle.test.js`
- Test: `tests/Feature/ThemeToggleButtonComponentTest.php`

**Interfaces:**
- Consumes: nada de tareas previas.
- Produces: componente Blade `<x-theme-toggle-button />` (sin props, botón con `id="btnThemeToggle"`, SVGs luna/sol, `aria-label="Cambiar tema claro/oscuro"` — HTML idéntico al que hoy está duplicado en `mark.blade.php:72-82` y `terminal.blade.php:40-50`). Función JS exportada `initThemeToggle(storageKey, doc = document, win = window, storage = localStorage)` — aplica el tema guardado (o `prefers-color-scheme` si no hay nada guardado) seteando `data-theme` en `doc.documentElement`, engancha el click de `doc.getElementById('btnThemeToggle')` para alternar y persistir en `storage` bajo `storageKey`. Tareas 3 (status-page, NO la usa — sin toggle), 5 (device-link), 7 (capture-face) y 8 (mark/terminal) consumen ambos.

- [ ] **Step 1: Crear el componente Blade**

```blade
{{-- resources/views/components/theme-toggle-button.blade.php --}}
{{--
    Botón de alternancia de tema claro/oscuro. Requiere que el JS de la
    página llame a initThemeToggle('<clave-propia>') (resources/js/shared/theme-toggle.js)
    para que el click funcione — este componente solo aporta el markup.
--}}
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
```

- [ ] **Step 2: Escribir el test de componente que falla**

```php
<?php
// tests/Feature/ThemeToggleButtonComponentTest.php

it('renderiza el botón de toggle de tema con su id y aria-label', function () {
    $html = (string) $this->blade('<x-theme-toggle-button />');

    expect($html)->toContain('id="btnThemeToggle"')
        ->toContain('aria-label="Cambiar tema claro/oscuro"')
        ->toContain('icon-moon')
        ->toContain('icon-sun');
});
```

- [ ] **Step 3: Correr el test y confirmar que pasa (el componente ya existe del Step 1)**

Run: `php artisan test --compact --filter=ThemeToggleButtonComponentTest`
Expected: PASS (1 test)

- [ ] **Step 4: Escribir el módulo JS con JSDoc**

```js
// resources/js/shared/theme-toggle.js
/**
 * Toggle de tema claro/oscuro compartido por las vistas públicas de
 * marcación. Cada vista usa su propia clave de localStorage (no comparten
 * estado entre sí a propósito — un mismo dispositivo podría usarse en más
 * de un modo). Las dependencias del navegador se reciben como parámetros
 * con default al global real, para poder testear sin jsdom — mismo patrón
 * que resources/js/shared/install-prompt.js.
 */

/**
 * Aplica el tema guardado (o prefers-color-scheme si no hay nada guardado)
 * y engancha el click del botón de toggle para alternar y persistir.
 * @param {string} storageKey - clave de localStorage propia de esta vista (ej. 'mark-theme')
 * @param {Document} [doc] - inyectable para tests; document en producción
 * @param {Window} [win] - inyectable para tests; window en producción
 * @param {{getItem: (key: string) => string|null, setItem: (key: string, value: string) => void}} [storage] - inyectable para tests; localStorage en producción
 */
export function initThemeToggle(storageKey, doc = document, win = window, storage = localStorage) {
    const saved = storage.getItem(storageKey);
    const prefersDark = win.matchMedia('(prefers-color-scheme: dark)').matches;
    const isDark = saved === 'dark' || (!saved && prefersDark);
    doc.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');

    const btn = doc.getElementById('btnThemeToggle');
    if (!btn) return;

    btn.addEventListener('click', () => {
        const currentlyDark = doc.documentElement.getAttribute('data-theme') === 'dark';
        const next = currentlyDark ? 'light' : 'dark';
        doc.documentElement.setAttribute('data-theme', next);
        storage.setItem(storageKey, next);
    });
}
```

- [ ] **Step 5: Escribir los tests Vitest que fallan**

```js
// resources/js/shared/theme-toggle.test.js
import { describe, it, expect, vi } from 'vitest';
import { initThemeToggle } from './theme-toggle.js';

function fakeStorage(initial = {}) {
    const data = { ...initial };
    return {
        getItem: (key) => (key in data ? data[key] : null),
        setItem: (key, value) => { data[key] = value; },
        _data: data,
    };
}

function fakeDoc(hasButton = true) {
    const html = { attrs: {}, setAttribute(name, value) { this.attrs[name] = value; }, getAttribute(name) { return this.attrs[name] ?? null; } };
    const btn = { listeners: {}, addEventListener(evt, fn) { this.listeners[evt] = fn; } };
    return {
        documentElement: html,
        getElementById: (id) => (id === 'btnThemeToggle' && hasButton ? btn : null),
        _btn: btn,
    };
}

function fakeWin(prefersDark = false) {
    return { matchMedia: () => ({ matches: prefersDark }) };
}

describe('initThemeToggle', () => {
    it('aplica data-theme="dark" si hay un valor guardado "dark"', () => {
        const doc = fakeDoc();
        initThemeToggle('mark-theme', doc, fakeWin(false), fakeStorage({ 'mark-theme': 'dark' }));
        expect(doc.documentElement.getAttribute('data-theme')).toBe('dark');
    });

    it('cae a prefers-color-scheme si no hay nada guardado', () => {
        const doc = fakeDoc();
        initThemeToggle('mark-theme', doc, fakeWin(true), fakeStorage());
        expect(doc.documentElement.getAttribute('data-theme')).toBe('light');
        // segunda verificación con prefersDark=false
        const doc2 = fakeDoc();
        initThemeToggle('mark-theme', doc2, fakeWin(false), fakeStorage());
        expect(doc2.documentElement.getAttribute('data-theme')).toBe('light');
    });

    it('alterna y persiste en storage bajo la clave propia al hacer click', () => {
        const doc = fakeDoc();
        const storage = fakeStorage({ 'mark-theme': 'light' });
        initThemeToggle('mark-theme', doc, fakeWin(false), storage);

        doc._btn.listeners.click();

        expect(doc.documentElement.getAttribute('data-theme')).toBe('dark');
        expect(storage.getItem('mark-theme')).toBe('dark');
    });

    it('no revienta si el botón no existe en el DOM', () => {
        const doc = fakeDoc(false);
        expect(() => initThemeToggle('mark-theme', doc, fakeWin(false), fakeStorage())).not.toThrow();
    });

    it('no comparte estado entre distintas storageKey', () => {
        const storage = fakeStorage({ 'terminal-theme': 'dark' });
        const doc = fakeDoc();
        initThemeToggle('mark-theme', doc, fakeWin(false), storage);
        expect(doc.documentElement.getAttribute('data-theme')).toBe('light');
    });
});
```

- [ ] **Step 6: Correr los tests Vitest y confirmar que pasan**

Run: `npx vitest run resources/js/shared/theme-toggle.test.js`
Expected: PASS (5 tests)

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/theme-toggle-button.blade.php resources/js/shared/theme-toggle.js resources/js/shared/theme-toggle.test.js tests/Feature/ThemeToggleButtonComponentTest.php
git commit -m "feat: extract shared theme-toggle-button component and JS module"
```

---

### Task 2: Componente compartido de pantalla de estado (`<x-status-page>`)

**Files:**
- Create: `resources/views/components/status-page.blade.php`
- Create: `resources/css/shared/status-page.css`
- Test: `tests/Feature/StatusPageComponentTest.php`

**Interfaces:**
- Consumes: `resources/css/shared/tokens.css` (import).
- Produces: componente Blade `<x-status-page :variant :icon :title :description :label>` con slot opcional `extra`. Tarea 4 lo consume para migrar las 4 pantallas de estado.

- [ ] **Step 1: Escribir el CSS compartido**

```css
/* resources/css/shared/status-page.css */
@import './tokens.css';

/* ============================================================
   PANTALLA DE ESTADO — layout compartido por las 4 vistas de
   error/estado (terminal-inactive, terminal-setup-invalid,
   enrollments/already-submitted, enrollments/expired).
   Sin toggle de tema manual: son pantallas de una sola visita,
   sin interacción — solo siguen prefers-color-scheme.
   ============================================================ */
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: var(--font);
    background: var(--c-bg);
    color: var(--c-text);
    min-height: 100dvh;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: var(--sp-8);
}
.status-page-container { max-width: 480px; }
.status-page-icon {
    width: 80px; height: 80px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto var(--sp-6);
    border: 2px solid;
}
.status-page-icon svg { width: 40px; height: 40px; }

.status-page-icon--danger  { background: var(--c-danger-bg);  border-color: var(--c-danger-l);  }
.status-page-icon--danger svg  { color: var(--c-danger-l); }
.status-page-icon--success { background: var(--c-success-bg); border-color: var(--c-success-l); }
.status-page-icon--success svg { color: var(--c-success-l); }
.status-page-icon--warning { background: var(--c-warning-bg); border-color: var(--c-warning-l); }
.status-page-icon--warning svg { color: var(--c-warning-l); }

.status-page-label {
    font-size: .75rem; font-weight: 600; letter-spacing: .15em;
    text-transform: uppercase; margin-bottom: var(--sp-3);
}
.status-page-label--danger  { color: var(--c-danger-l); }
.status-page-label--success { color: var(--c-success-l); }
.status-page-label--warning { color: var(--c-warning-l); }

.status-page-title { font-size: 1.5rem; font-weight: 700; margin-bottom: var(--sp-3); color: var(--c-text); }
.status-page-description { font-size: 1rem; color: var(--c-muted); line-height: 1.6; margin-bottom: var(--sp-2); }
.status-page-extra { font-size: .875rem; color: var(--c-subtle); margin-top: var(--sp-8); }
```

- [ ] **Step 2: Escribir el componente Blade**

```blade
{{-- resources/views/components/status-page.blade.php --}}
@props([
    'icon',
    'variant' => 'danger',
    'label' => null,
    'title',
    'description' => [],
])
{{--
    Layout compartido por las 4 pantallas de estado/error de los flujos
    de marcación y enrolamiento (terminal desactivado, enlace inválido,
    enlace expirado, registro ya enviado). $icon es el contenido crudo
    de los <path>/<circle>/etc. del SVG central — cada vista pasa el suyo.
--}}
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="color-scheme" content="light dark">
    <x-favicon-links />
    @vite('resources/css/shared/status-page.css')
    <x-theme-vars />
</head>

<body>
    <div class="status-page-container">
        <div class="status-page-icon status-page-icon--{{ $variant }}" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                {!! $icon !!}
            </svg>
        </div>

        @if ($label)
            <div class="status-page-label status-page-label--{{ $variant }}">{{ $label }}</div>
        @endif

        <h1 class="status-page-title">{{ $title }}</h1>

        @foreach ($description as $paragraph)
            <p class="status-page-description">{{ $paragraph }}</p>
        @endforeach

        {{ $extra ?? '' }}
    </div>
</body>

</html>
```

- [ ] **Step 3: Escribir el test que falla**

```php
<?php
// tests/Feature/StatusPageComponentTest.php

it('renderiza título, descripción, variante y contenido extra', function () {
    $html = (string) $this->blade(<<<'BLADE'
        <x-status-page
            variant="danger"
            icon="<path d='M1 1' />"
            label="Prueba"
            title="Título de prueba"
            :description="['Primer párrafo.', 'Segundo párrafo.']"
        >
            <x-slot:extra>Contenido extra</x-slot:extra>
        </x-status-page>
    BLADE);

    expect($html)->toContain('Título de prueba')
        ->toContain('Primer párrafo.')
        ->toContain('Segundo párrafo.')
        ->toContain('Contenido extra')
        ->toContain('status-page-icon--danger')
        ->toContain('status-page-label--danger')
        ->toContain('Prueba');
});

it('omite el label y el extra cuando no se pasan', function () {
    $html = (string) $this->blade(<<<'BLADE'
        <x-status-page
            variant="success"
            icon="<path d='M1 1' />"
            title="Sin extras"
            :description="['Solo un párrafo.']"
        />
    BLADE);

    expect($html)->toContain('Sin extras')
        ->not->toContain('status-page-label');
});
```

- [ ] **Step 4: Correr los tests y confirmar que pasan**

Run: `php artisan test --compact --filter=StatusPageComponentTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/status-page.blade.php resources/css/shared/status-page.css tests/Feature/StatusPageComponentTest.php
git commit -m "feat: add shared status-page component for error/status screens"
```

---

### Task 3: Migrar las 4 pantallas de estado a `<x-status-page>`

**Files:**
- Modify: `resources/views/attendances/terminal-inactive.blade.php` (reescritura completa)
- Modify: `resources/views/attendances/terminal-setup-invalid.blade.php` (reescritura completa)
- Modify: `resources/views/enrollments/already-submitted.blade.php` (reescritura completa)
- Modify: `resources/views/enrollments/expired.blade.php` (reescritura completa)
- Test: `tests/Feature/AttendanceStatusPagesTest.php`

**Interfaces:**
- Consumes: `<x-status-page>` de la Tarea 2 (props: `icon`, `variant`, `label`, `title`, `description`, slot `extra`).
- Produces: nada consumido por tareas posteriores.

- [ ] **Step 1: Escribir los tests que fallan (verifican contenido clave tras la migración)**

Primero revisar cómo se renderizan hoy estas 4 vistas (rutas y variables esperadas) — `terminal-inactive` recibe `$terminal`, `$title` no aplica (título fijo); `terminal-setup-invalid` no recibe variables; `enrollments/already-submitted` y `enrollments/expired` no reciben variables.

```php
<?php
// tests/Feature/AttendanceStatusPagesTest.php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;

it('terminal-inactive muestra el nombre del terminal y su sucursal', function () {
    $company = Company::create(['name' => 'Empresa Test', 'ruc' => '80012345-6']);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Centro']);
    $terminal = Terminal::create(['branch_id' => $branch->id, 'name' => 'Terminal 1', 'code' => 'ABC123', 'is_active' => false]);

    $html = (string) $this->blade('@include(\'attendances.terminal-inactive\', ["terminal" => $terminal])', ['terminal' => $terminal]);

    expect($html)->toContain('Terminal fuera de servicio')
        ->toContain('Terminal 1')
        ->toContain('Sucursal Centro');
});

it('terminal-setup-invalid muestra el mensaje de enlace inválido', function () {
    $html = (string) $this->blade('@include(\'attendances.terminal-setup-invalid\')');

    expect($html)->toContain('Enlace de configuración inválido')
        ->toContain('Solicite un nuevo enlace');
});

it('enrollments.already-submitted muestra el mensaje de registro enviado', function () {
    $html = (string) $this->blade('@include(\'enrollments.already-submitted\')');

    expect($html)->toContain('Su rostro ya fue registrado')
        ->toContain('pendiente de aprobación');
});

it('enrollments.expired muestra el mensaje de enlace expirado', function () {
    $html = (string) $this->blade('@include(\'enrollments.expired\')');

    expect($html)->toContain('Este enlace ya no es válido')
        ->toContain('Contacte al administrador');
});
```

- [ ] **Step 2: Correr los tests contra las vistas actuales para confirmar que ya pasan (baseline antes de migrar)**

Run: `php artisan test --compact --filter=AttendanceStatusPagesTest`
Expected: PASS (4 tests) — son solo verificaciones de texto, deben pasar igual antes y después de la migración.

- [ ] **Step 3: Migrar `terminal-inactive.blade.php`**

```blade
{{-- resources/views/attendances/terminal-inactive.blade.php --}}
<x-status-page
    variant="danger"
    title="Terminal fuera de servicio"
    :description="['Esta terminal no está disponible en este momento.', 'Por favor, comuníquese con el administrador.']"
    icon="<path stroke-linecap='round' stroke-linejoin='round' d='M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z' />"
>
    <x-slot:extra>{{ $terminal->name }} — {{ $terminal->branch?->name }}</x-slot:extra>
</x-status-page>
```

- [ ] **Step 4: Migrar `terminal-setup-invalid.blade.php`**

```blade
{{-- resources/views/attendances/terminal-setup-invalid.blade.php --}}
<x-status-page
    variant="danger"
    title="Enlace de configuración inválido"
    :description="['Este enlace ya fue usado, expiró, o no corresponde a ningún terminal.', 'Solicite un nuevo enlace de configuración desde el panel de administración (Terminales).']"
    icon="<path stroke-linecap='round' stroke-linejoin='round' d='M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z' />"
/>
```

- [ ] **Step 5: Migrar `enrollments/already-submitted.blade.php`**

```blade
{{-- resources/views/enrollments/already-submitted.blade.php --}}
<x-status-page
    variant="success"
    label="Registro Enviado"
    title="Su rostro ya fue registrado"
    :description="['Su registro facial se encuentra pendiente de aprobación por el administrador. No es necesario realizar ninguna acción adicional.']"
    icon="<path stroke-linecap='round' stroke-linejoin='round' d='M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' />"
/>
```

- [ ] **Step 6: Migrar `enrollments/expired.blade.php`**

```blade
{{-- resources/views/enrollments/expired.blade.php --}}
<x-status-page
    variant="danger"
    label="Enlace Expirado"
    title="Este enlace ya no es válido"
    :description="['El enlace de registro facial ha expirado. Contacte al administrador para obtener uno nuevo.']"
    icon="<path stroke-linecap='round' stroke-linejoin='round' d='M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z' />"
/>
```

- [ ] **Step 7: Correr los tests y confirmar que siguen pasando tras la migración**

Run: `php artisan test --compact --filter=AttendanceStatusPagesTest`
Expected: PASS (4 tests)

- [ ] **Step 8: Verificación manual en navegador**

Visitar `/terminal/setup-invalido-cualquiera` (o forzar la vista vía tinker/ruta) en claro y oscuro, confirmar que el ícono, colores y layout se ven correctos y responden a `prefers-color-scheme`.

- [ ] **Step 9: Commit**

```bash
git add resources/views/attendances/terminal-inactive.blade.php resources/views/attendances/terminal-setup-invalid.blade.php resources/views/enrollments/already-submitted.blade.php resources/views/enrollments/expired.blade.php tests/Feature/AttendanceStatusPagesTest.php
git commit -m "refactor: migrate 4 status/error screens to shared x-status-page component"
```

---

### Task 4: Backend — branding en la respuesta de `MobileLinkController::claim()`

**Files:**
- Modify: `app/Http/Controllers/MobileLinkController.php:79-89`
- Test: `tests/Feature/MobileLinkControllerTest.php` (nuevo — no existe todavía, verificado por `Glob`)

**Interfaces:**
- Consumes: `Employee::branch` (`BelongsTo`, ya existe en `app/Models/Employee.php:126`), `Branch::company` (relación ya usada en el resto del proyecto), `Company::logo_thumbnail` (columna ya usada por `CompanyLogoThumbnailService`, ver CLAUDE.md sección "Logo de empresa").
- Produces: el JSON de éxito de `POST /vincular-dispositivo` gana los campos `employee.company_name` (`?string`) y `employee.company_logo` (`?string`, data URI o null). La Tarea 5 (device-link.js) consume estos dos campos nuevos.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// tests/Feature/MobileLinkControllerTest.php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('incluye company_name y company_logo en la respuesta exitosa de vinculación', function () {
    $company = Company::create(['name' => 'Empresa Test', 'ruc' => '80012345-6', 'logo_thumbnail' => 'data:image/png;base64,abc123']);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Centro']);
    $employee = Employee::create([
        'branch_id' => $branch->id,
        'first_name' => 'Juan',
        'last_name' => 'Pérez',
        'ci' => '1234567',
        'birth_date' => '1990-05-20',
        'status' => 'active',
        'face_descriptor' => json_encode(array_fill(0, 128, 0.1)),
    ]);

    $response = $this->postJson(route('device-link.claim'), [
        'ci' => '1234567',
        'birth_date' => '1990-05-20',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('employee.company_name', 'Empresa Test');
    $response->assertJsonPath('employee.company_logo', 'data:image/png;base64,abc123');
});

it('company_logo es null si la empresa no tiene logo, sin romper la respuesta', function () {
    $company = Company::create(['name' => 'Empresa Sin Logo', 'ruc' => '80012345-6']);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Centro']);
    $employee = Employee::create([
        'branch_id' => $branch->id,
        'first_name' => 'Ana',
        'last_name' => 'García',
        'ci' => '7654321',
        'birth_date' => '1988-02-10',
        'status' => 'active',
        'face_descriptor' => json_encode(array_fill(0, 128, 0.1)),
    ]);

    $response = $this->postJson(route('device-link.claim'), [
        'ci' => '7654321',
        'birth_date' => '1988-02-10',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('employee.company_name', 'Empresa Sin Logo');
    $response->assertJsonPath('employee.company_logo', null);
});

it('el mensaje de error de datos inválidos se mantiene genérico', function () {
    $response = $this->postJson(route('device-link.claim'), [
        'ci' => '0000000',
        'birth_date' => '2000-01-01',
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))
        ->toBe('CI o fecha de nacimiento incorrectos, o el empleado no está habilitado para marcar desde su dispositivo.');
});
```

Nota: revisar la migración de `employees` y `companies` para confirmar los nombres exactos de columnas requeridas (`ruc`, `face_descriptor`, etc.) antes de correr — ajustar el factory/creación si algún campo obligatorio no está listado aquí.

- [ ] **Step 2: Correr el test y confirmar que falla (company_name/company_logo no existen todavía)**

Run: `php artisan test --compact --filter=MobileLinkControllerTest`
Expected: FAIL — `employee.company_name` no está en la respuesta JSON.

- [ ] **Step 3: Modificar el controller**

```php
// app/Http/Controllers/MobileLinkController.php:79-89 — reemplazar el response()->json() actual por:

        return response()->json([
            'ok' => true,
            'token' => $plainTextToken,
            'employee' => [
                'id' => $employee->id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'ci' => $employee->ci,
                'face_descriptor' => $employee->face_descriptor,
                'company_name' => $employee->branch?->company?->name,
                'company_logo' => $employee->branch?->company?->logo_thumbnail,
            ],
        ]);
```

- [ ] **Step 4: Correr el test y confirmar que pasa**

Run: `php artisan test --compact --filter=MobileLinkControllerTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MobileLinkController.php tests/Feature/MobileLinkControllerTest.php
git commit -m "feat: include company branding in device-link claim response"
```

---

### Task 5: Migrar el script inline de `device-link.blade.php` al módulo `device-link.js` existente

**Files:**
- Modify: `resources/js/attendances/device-link.js` (extender, ya existe y maneja el aviso de re-vinculación)
- Modify: `resources/views/attendances/device-link.blade.php:51-114` (eliminar el `<script>` inline)
- Test: `resources/js/attendances/device-link.test.js` (nuevo)

**Interfaces:**
- Consumes: campos `company_name`/`company_logo` en `employee` del JSON de `claim()` (Tarea 4). `getMeta`/`getOwnEmployee`/`migrateTokenFromLocalStorage` de `mobile-offline/db.js` (ya importados en el archivo existente, sin cambios en su firma).
- Produces: `device-link.blade.php` ya no tiene lógica de submit inline — todo vive en el módulo. Tarea 6 (rewrite del blade/CSS) asume que el módulo ya cubre todo el comportamiento y solo ajusta el HTML/CSS alrededor.

- [ ] **Step 1: Escribir los tests que fallan para la nueva función de submit**

```js
// resources/js/attendances/device-link.test.js
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('./mobile-offline/db.js', () => ({
    getMeta: vi.fn(),
    getOwnEmployee: vi.fn(),
    migrateTokenFromLocalStorage: vi.fn(),
}));

import { handleLinkSubmit } from './device-link.js';

function fakeStorage() {
    const data = {};
    return { getItem: (k) => data[k] ?? null, setItem: (k, v) => { data[k] = v; }, _data: data };
}

describe('handleLinkSubmit', () => {
    let fetchMock;

    beforeEach(() => {
        fetchMock = vi.fn();
        globalThis.fetch = fetchMock;
    });

    it('en éxito guarda el token y employee_id y devuelve datos de branding', async () => {
        fetchMock.mockResolvedValue({
            json: () => Promise.resolve({
                ok: true,
                token: 'tok-123',
                employee: { id: 5, first_name: 'Juan', company_name: 'Empresa Test', company_logo: 'data:image/png;base64,xyz' },
            }),
        });
        const storage = fakeStorage();

        const result = await handleLinkSubmit({ ci: '1234567', birth_date: '1990-05-20' }, '/vincular-dispositivo', 'csrf-token', storage);

        expect(result.ok).toBe(true);
        expect(result.employee.company_name).toBe('Empresa Test');
        expect(storage.getItem('nominapp_mobile_token')).toBe('tok-123');
        expect(storage.getItem('nominapp_mobile_employee_id')).toBe('5');
    });

    it('en error devuelve ok:false con el mensaje del servidor', async () => {
        fetchMock.mockResolvedValue({
            json: () => Promise.resolve({ ok: false, message: 'CI o fecha de nacimiento incorrectos.' }),
        });
        const storage = fakeStorage();

        const result = await handleLinkSubmit({ ci: '0000000', birth_date: '2000-01-01' }, '/vincular-dispositivo', 'csrf-token', storage);

        expect(result.ok).toBe(false);
        expect(result.message).toBe('CI o fecha de nacimiento incorrectos.');
        expect(storage.getItem('nominapp_mobile_token')).toBeNull();
    });

    it('en fallo de red devuelve ok:false con mensaje de conexión', async () => {
        fetchMock.mockRejectedValue(new Error('network down'));
        const storage = fakeStorage();

        const result = await handleLinkSubmit({ ci: '1234567', birth_date: '1990-05-20' }, '/vincular-dispositivo', 'csrf-token', storage);

        expect(result.ok).toBe(false);
        expect(result.message).toBe('Error de conexión. Intente nuevamente.');
    });
});
```

- [ ] **Step 2: Correr los tests y confirmar que fallan (handleLinkSubmit no existe)**

Run: `npx vitest run resources/js/attendances/device-link.test.js`
Expected: FAIL — `handleLinkSubmit is not exported`

- [ ] **Step 3: Extender `device-link.js`**

Agregar al archivo existente (después de los imports actuales, antes del `document.addEventListener('DOMContentLoaded', ...)` ya presente — el listener existente se mantiene tal cual para el aviso de re-vinculación; se agrega la lógica de submit del form como código nuevo dentro del mismo listener, más las funciones exportadas para poder testearlas):

```js
// resources/js/attendances/device-link.js — agregar al final del fileoverview existente:
/**
 * @fileoverview (continúa el fileoverview existente) También maneja el
 *               envío del formulario de vinculación (antes vivía como
 *               <script> inline en device-link.blade.php) y la obtención
 *               del modelo de dispositivo vía Client Hints.
 */

// (mantener el import existente y agregar:)
import { getMeta, getOwnEmployee, migrateTokenFromLocalStorage } from './mobile-offline/db.js';

/**
 * Modelo real del dispositivo, solo disponible vía Client Hints en
 * navegadores Chromium sobre Android — null en iOS/Safari/Firefox/desktop,
 * donde el servidor cae al parseo del User-Agent (DeviceHintsParser).
 * @param {Navigator} [nav] - inyectable para tests; navigator en producción
 * @returns {Promise<string|null>}
 */
export async function getClientHintModel(nav = navigator) {
    if (!nav.userAgentData?.getHighEntropyValues) return null;
    try {
        const hints = await nav.userAgentData.getHighEntropyValues(['model']);
        return hints.model || null;
    } catch {
        return null;
    }
}

/**
 * Envía CI + fecha de nacimiento al backend y persiste el token en éxito.
 * @param {{ci: string, birth_date: string}} formValues
 * @param {string} endpoint - URL a la que hacer POST (window.location.pathname sin barra final)
 * @param {string} csrfToken
 * @param {{getItem: Function, setItem: Function}} [storage] - inyectable para tests; localStorage en producción
 * @returns {Promise<{ok: boolean, message?: string, employee?: object}>}
 */
export async function handleLinkSubmit(formValues, endpoint, csrfToken, storage = localStorage) {
    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({
                ci: formValues.ci,
                birth_date: formValues.birth_date,
                device_model_hint: await getClientHintModel(),
            }),
        });
        const data = await response.json();

        if (!data.ok) {
            return { ok: false, message: data.message || 'No se pudo vincular el dispositivo.' };
        }

        // Almacenamiento provisorio del token — en la fase de sincronización
        // offline (mobile-offline/) este valor pasa a vivir en su propio store.
        storage.setItem('nominapp_mobile_token', data.token);
        storage.setItem('nominapp_mobile_employee_id', String(data.employee.id));

        return { ok: true, employee: data.employee };
    } catch {
        return { ok: false, message: 'Error de conexión. Intente nuevamente.' };
    }
}

/** Engancha el submit del formulario de vinculación al DOM real. */
function initLinkForm() {
    const form = document.getElementById('linkForm');
    const btn = document.getElementById('btnLink');
    const statusEl = document.getElementById('status');
    if (!form || !btn || !statusEl) return;

    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const brandingEl = document.getElementById('linkSuccessBranding');
    const brandingLogo = document.getElementById('linkSuccessLogo');
    const brandingName = document.getElementById('linkSuccessCompanyName');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        btn.disabled = true;
        statusEl.textContent = 'Vinculando...';
        statusEl.className = 'status';

        const endpoint = window.location.pathname.replace(/\/$/, '');
        const result = await handleLinkSubmit(
            {
                ci: document.getElementById('ci').value.trim(),
                birth_date: document.getElementById('birth_date').value,
            },
            endpoint,
            csrf,
        );

        if (!result.ok) {
            statusEl.textContent = result.message;
            statusEl.className = 'status status--error';
            btn.disabled = false;
            return;
        }

        if (result.employee.company_logo && brandingLogo && brandingEl) {
            brandingLogo.src = result.employee.company_logo;
            brandingLogo.classList.remove('hidden');
        }
        if (result.employee.company_name && brandingName && brandingEl) {
            brandingName.textContent = result.employee.company_name;
        }
        brandingEl?.classList.remove('hidden');

        statusEl.textContent = `Dispositivo vinculado. ¡Hola, ${result.employee.first_name}! Redirigiendo...`;
        statusEl.className = 'status status--success';

        setTimeout(() => {
            window.location.href = '/marcar';
        }, 1200);
    });
}

// (dentro del document.addEventListener('DOMContentLoaded', ...) existente,
// al final del callback, agregar la llamada:)
//     initLinkForm();
```

- [ ] **Step 4: Correr los tests Vitest y confirmar que pasan**

Run: `npx vitest run resources/js/attendances/device-link.test.js`
Expected: PASS (3 tests)

- [ ] **Step 5: Eliminar el `<script>` inline de `device-link.blade.php`**

Quitar el bloque `<script>...</script>` completo (líneas 51-114 del archivo actual) — el comportamiento ya vive en `device-link.js`. El `@vite('resources/js/attendances/device-link.js')` ya está en el `<head>` (línea 10), no requiere cambios ahí.

- [ ] **Step 6: Correr el resto de tests de JS y Pest relacionados para confirmar que no se rompió nada**

Run: `npx vitest run resources/js/attendances/device-link.test.js resources/js/shared/theme-toggle.test.js`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add resources/js/attendances/device-link.js resources/js/attendances/device-link.test.js resources/views/attendances/device-link.blade.php
git commit -m "refactor: move device-link inline submit script into device-link.js module"
```

---

### Task 6: Reescribir `device-link.blade.php` + `device-link.css` con tokens, theme-toggle y branding

**Files:**
- Modify: `resources/views/attendances/device-link.blade.php` (reescritura del `<body>`, ya sin `<script>` tras la Tarea 5)
- Modify: `resources/css/attendances/device-link.css` (reescritura completa)
- Test: `tests/Feature/DeviceLinkViewTest.php` (nuevo)

**Interfaces:**
- Consumes: `<x-theme-toggle-button />` (Tarea 1), `initThemeToggle` de `resources/js/shared/theme-toggle.js` (Tarea 1), `handleLinkSubmit`/`initLinkForm` ya cableados en `device-link.js` (Tarea 5).
- Produces: nada consumido por tareas posteriores.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// tests/Feature/DeviceLinkViewTest.php

it('la vista de vinculación incluye el toggle de tema y los elementos de branding post-éxito', function () {
    $response = $this->get(route('device-link.show'));

    $response->assertSuccessful();
    $response->assertSee('id="btnThemeToggle"', false);
    $response->assertSee('id="linkSuccessBranding"', false);
    $response->assertSee('id="linkSuccessLogo"', false);
    $response->assertSee('id="linkSuccessCompanyName"', false);
    $response->assertSee('id="linkForm"', false);
});
```

- [ ] **Step 2: Correr el test y confirmar que falla**

Run: `php artisan test --compact --filter=DeviceLinkViewTest`
Expected: FAIL — los ids nuevos no existen todavía.

- [ ] **Step 3: Reescribir `device-link.css` con tokens semánticos**

```css
/* resources/css/attendances/device-link.css */
@import '../shared/tokens.css';

* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: var(--font);
    background: var(--c-bg);
    color: var(--c-text);
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: var(--sp-8) var(--sp-4);
}
.device-link-header {
    width: 100%;
    max-width: 420px;
    display: flex;
    justify-content: flex-end;
    margin-bottom: var(--sp-4);
}
.container { max-width: 420px; width: 100%; text-align: center; }
.icon {
    width: 80px; height: 80px;
    border-radius: 50%;
    background: var(--c-surface);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto var(--sp-6);
    border: 2px solid var(--c-primary-l);
}
.icon svg { width: 40px; height: 40px; color: var(--c-primary-l); }
h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: var(--sp-3); color: var(--c-text); }
p { font-size: 1rem; color: var(--c-muted); line-height: 1.6; margin-bottom: var(--sp-6); }
form { text-align: left; }
label { display: block; font-size: .875rem; color: var(--c-muted); margin-bottom: var(--sp-1); }
input {
    width: 100%;
    font: inherit;
    font-size: 1rem;
    padding: var(--sp-3) var(--sp-4);
    border-radius: var(--r-md);
    border: 1px solid var(--c-border-s);
    background: var(--c-surface);
    color: var(--c-text);
    margin-bottom: var(--sp-4);
}
input:focus { outline: none; border-color: var(--c-primary-l); }
button {
    width: 100%;
    font: inherit;
    font-weight: 600;
    font-size: 1rem;
    padding: var(--sp-3) var(--sp-6);
    border-radius: var(--r-lg);
    border: none;
    background: var(--c-primary);
    color: #fff;
    cursor: pointer;
}
button:disabled { opacity: .6; cursor: not-allowed; }
button:not(:disabled):hover { background: var(--c-primary-h); }

.status { margin-top: var(--sp-5); font-size: .9rem; min-height: 1.5rem; text-align: center; }
.status--error {
    color: var(--c-danger);
    background: var(--c-danger-bg);
    border: 1px solid var(--c-danger-ring);
    border-radius: var(--r-md);
    padding: var(--sp-3) var(--sp-4);
}
.status--success { color: var(--c-success); }
.hint { font-size: .8rem; color: var(--c-subtle); margin-top: var(--sp-6); line-height: 1.5; }
.hidden { display: none !important; }

/* Branding post-vinculación exitosa */
.link-success-branding {
    display: flex; align-items: center; justify-content: center; gap: var(--sp-2);
    margin-top: var(--sp-4);
}
.link-success-logo { max-height: 32px; max-width: 120px; }
.link-success-company-name { font-size: .875rem; color: var(--c-muted); font-weight: 600; }

/* Aviso: este navegador ya tiene un dispositivo vinculado */
.already-linked-warning {
    text-align: left;
    background: var(--c-warning-bg);
    border: 1px solid var(--c-warning-l);
    border-radius: var(--r-lg);
    padding: var(--sp-5);
    margin-bottom: var(--sp-6);
}
.already-linked-warning p { color: var(--c-warning); font-size: .9rem; margin-bottom: var(--sp-4); }
.already-linked-warning p:last-of-type { margin-bottom: var(--sp-5); }
.already-linked-actions { display: flex; flex-direction: column; gap: var(--sp-2); }
.btn-continue-anyway {
    width: 100%; font: inherit; font-weight: 600; font-size: .9rem;
    padding: var(--sp-3) var(--sp-6); border-radius: var(--r-md); border: 1px solid var(--c-warning-l);
    background: transparent; color: var(--c-warning); cursor: pointer;
}
.btn-continue-anyway:hover { background: var(--c-warning-bg); }
.btn-cancel-relink {
    width: 100%; font: inherit; font-weight: 600; font-size: .9rem;
    padding: var(--sp-3) var(--sp-6); border-radius: var(--r-md); border: none;
    background: var(--c-primary); color: #fff; cursor: pointer;
}
.btn-cancel-relink:hover { background: var(--c-primary-h); }
```

- [ ] **Step 4: Reescribir el `<body>` de `device-link.blade.php`**

```blade
{{-- resources/views/attendances/device-link.blade.php --}}
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vincular dispositivo — Marcación de asistencia</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <x-favicon-links />
    @vite('resources/js/attendances/device-link.js')
    @vite('resources/css/attendances/device-link.css')
    <x-theme-vars />
</head>

<body>
    <header class="device-link-header">
        <x-theme-toggle-button />
    </header>

    <div class="container">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75A2.25 2.25 0 0015.75 1.5H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
            </svg>
        </div>
        <h1>Vincular este dispositivo</h1>
        <p>Ingresá tu CI y fecha de nacimiento para vincular este dispositivo. Vas a poder marcar tu asistencia aunque no tengas conexión a internet.</p>

        {{-- Solo se muestra si device-link.js detecta un token ya vinculado en este
        navegador (IndexedDB) — evita re-vinculaciones accidentales que disparan una
        alerta de seguridad real (MobileDeviceRelinkedNotification) a los admins. --}}
        <div id="alreadyLinkedWarning" class="already-linked-warning hidden">
            <p><strong>Este dispositivo ya está vinculado</strong> a <span id="alreadyLinkedName"></span>.</p>
            <p>Si continuás y vinculás uno nuevo, el dispositivo actual va a dejar de funcionar y se le va a avisar a RRHH.</p>
            <div class="already-linked-actions">
                <button type="button" id="btnCancelRelink" class="btn-cancel-relink">Ya tengo un dispositivo — volver a marcar</button>
                <button type="button" id="btnContinueAnyway" class="btn-continue-anyway">Continuar y vincular este de todos modos</button>
            </div>
        </div>

        <form id="linkForm">
            <label for="ci">CI</label>
            <input type="text" id="ci" name="ci" inputmode="numeric" autocomplete="off" required>

            <label for="birth_date">Fecha de nacimiento</label>
            <input type="date" id="birth_date" name="birth_date" required>

            <button type="submit" id="btnLink">Vincular este dispositivo</button>
        </form>
        <div id="status" class="status" role="status" aria-live="polite"></div>

        {{-- Branding de la empresa del empleado recién identificado — oculto
        hasta la vinculación exitosa, poblado por device-link.js con los
        campos company_name/company_logo de la respuesta de claim(). --}}
        <div id="linkSuccessBranding" class="link-success-branding hidden">
            <img id="linkSuccessLogo" class="link-success-logo hidden" alt="">
            <span id="linkSuccessCompanyName" class="link-success-company-name"></span>
        </div>

        <p class="hint">Solo se puede vincular un dispositivo a la vez. Si vinculás uno nuevo, el anterior deja de funcionar automáticamente.</p>
    </div>
</body>

</html>
```

- [ ] **Step 5: Agregar `initThemeToggle` a `device-link.js`**

Al import existente de la Tarea 5, agregar:
```js
import { initThemeToggle } from '../shared/theme-toggle.js';
```
Y al inicio del `document.addEventListener('DOMContentLoaded', ...)` existente (antes de `await migrateTokenFromLocalStorage()`):
```js
    initThemeToggle('device-link-theme');
```

- [ ] **Step 6: Correr el test Pest y confirmar que pasa**

Run: `php artisan test --compact --filter=DeviceLinkViewTest`
Expected: PASS (1 test)

- [ ] **Step 7: Correr también los tests de la Tarea 5 para confirmar que no se rompieron**

Run: `npx vitest run resources/js/attendances/device-link.test.js`
Expected: PASS (3 tests)

- [ ] **Step 8: Verificación manual en navegador**

Visitar `/vincular-dispositivo` en claro y oscuro, confirmar el toggle de tema funciona, el layout se ve correcto en mobile, y (con un empleado de prueba) que el branding aparece tras vincular exitosamente.

- [ ] **Step 9: Commit**

```bash
git add resources/views/attendances/device-link.blade.php resources/css/attendances/device-link.css resources/js/attendances/device-link.js tests/Feature/DeviceLinkViewTest.php
git commit -m "refactor: rewrite device-link view with design tokens, theme toggle and branding"
```

---

### Task 7: `shared/capture-face.blade.php` — face-api.js local, patrones JS y toggle de tema

**Files:**
- Modify: `resources/views/shared/capture-face.blade.php`
- Modify: `resources/js/shared/FaceCaptureApp.js` (cambiar el manejo del botón Cancelar)
- Test: `tests/Feature/CaptureFaceViewTest.php` (nuevo)

**Interfaces:**
- Consumes: `<x-theme-toggle-button />` y `initThemeToggle` (Tarea 1).
- Produces: nada consumido por tareas posteriores.

- [ ] **Step 1: Localizar el manejo actual del botón Cancelar en `FaceCaptureApp.js`**

Antes de escribir el test, revisar `resources/js/shared/FaceCaptureApp.js` para confirmar si `btnCancel` ya se engancha ahí vía `addEventListener` (la vista lo referencia con `onclick="handleCancel()"`, función definida inline en el blade) o si el enganche real está en otro lado. El objetivo del Step 4 es que `handleCancel` deje de vivir como función global inline y pase a ser un `addEventListener` normal, sea en `FaceCaptureApp.js` o en `capture-face.js`.

- [ ] **Step 2: Escribir el test que falla**

```php
<?php
// tests/Feature/CaptureFaceViewTest.php

it('la vista de captura facial usa face-api.js local, no CDN externo, e incluye el toggle de tema', function () {
    $html = (string) $this->blade('@include(\'shared.capture-face\', $data)', [
        'data' => [
            'mode' => 'enrollment',
            'css' => 'resources/css/shared/capture-face.css',
            'js' => 'resources/js/shared/capture-face.js',
            'formAction' => '/registro-facial/token-de-prueba',
            'enrollment' => (object) ['expires_at' => now()->addDay(), 'token' => 'token-de-prueba'],
            'employee' => (object) ['id' => 1, 'name' => null, 'first_name' => 'Juan', 'last_name' => 'Pérez', 'document_number' => null],
        ],
    ]);

    expect($html)->toContain("asset('js/face-api.min.js')")
        ->not->toContain('unpkg.com')
        ->not->toContain('onclick="handleCancel()"')
        ->not->toContain('style="display: none;"')
        ->toContain('id="btnThemeToggle"');
});
```

Nota: si `@include` con variables via array no compila directo (los `@php` del partial esperan variables sueltas, no un array `$data`), usar en su lugar `view('shared.capture-face', [...])->render()` con las mismas claves — ajustar según cuál funcione al correr el Step 3.

- [ ] **Step 3: Correr el test y confirmar que falla**

Run: `php artisan test --compact --filter=CaptureFaceViewTest`
Expected: FAIL — todavía usa unpkg, `onclick`, `style="display: none;"`, sin `btnThemeToggle`.

- [ ] **Step 4: Modificar `resources/views/shared/capture-face.blade.php`**

Cambios puntuales sobre el archivo actual:

1. Eliminar `<link rel="preconnect" href="https://unpkg.com">` del `<head>`.
2. En el `<header class="app-header">`, agregar `<x-theme-toggle-button />` junto a `.app-employee-info` (dentro de `.app-header-brand`, después de `.app-title`).
3. Cambiar el botón Cancelar de:
```blade
<button id="btnCancel" type="button" class="btn-red" onclick="handleCancel()" aria-label="Cancelar y regresar">
```
a:
```blade
<button id="btnCancel" type="button" class="btn-red" aria-label="Cancelar y regresar">
```
4. Cambiar el modal de:
```blade
<div id="confirmationModal" class="modal" style="display: none;" role="dialog" aria-modal="true"
```
a:
```blade
<div id="confirmationModal" class="modal hidden" role="dialog" aria-modal="true"
```
(revisar en `FaceCaptureApp.js`/`capture-face.js` cómo se muestra/oculta hoy este modal — si usa `style.display = 'block'/'none'` directamente, cambiarlo a `classList.remove('hidden')`/`classList.add('hidden')` en el mismo archivo).
5. Cambiar el `<script defer src="https://unpkg.com/face-api.js@0.22.2/...">` por:
```blade
<script defer src="{{ asset('js/face-api.min.js') }}"></script>
```
6. Eliminar la función `handleScriptError()` del `<script>` final (ya no hay CDN externo que pueda fallar) y el atributo `onerror="handleScriptError()"` (ya removido en el paso 5).
7. Reemplazar la función `handleCancel()` inline por un `addEventListener` en el mismo bloque `<script>` final:
```blade
    <script>
        document.getElementById('btnCancel')?.addEventListener('click', () => {
            const msg = @json(
                $isEnrollment
                    ? '¿Está seguro de que desea cancelar el registro?'
                    : '¿Está seguro de que desea cancelar la captura? Se perderá el progreso actual.');
            if (confirm(msg)) window.history.back();
        });
    </script>
```

- [ ] **Step 5: Agregar el toggle de tema al JS**

En `resources/js/shared/capture-face.js`, agregar al inicio del archivo (después de los imports existentes):
```js
import { initThemeToggle } from './theme-toggle.js';
```
Y en el punto donde se instancia `FaceCaptureApp`/`EnrollmentCaptureApp` (buscar el `document.addEventListener('DOMContentLoaded', ...)` o equivalente en el archivo), agregar antes de esa instanciación:
```js
initThemeToggle('capture-face-theme');
```

- [ ] **Step 6: Buscar y actualizar el toggle del modal en `FaceCaptureApp.js` si usa `style.display`**

Si el modal se muestra/oculta con `element.style.display = 'flex'`/`'none'` en vez de clases, cambiar a `element.classList.remove('hidden')`/`element.classList.add('hidden')`, y confirmar que `.hidden { display: none !important; }` ya existe en `resources/css/shared/capture-face.css` (si no existe, agregarlo).

- [ ] **Step 7: Correr el test Pest y confirmar que pasa**

Run: `php artisan test --compact --filter=CaptureFaceViewTest`
Expected: PASS (1 test)

- [ ] **Step 8: Correr el resto del suite de captura facial para confirmar que no se rompió nada**

Run: `php artisan test --compact --filter=Capture`
Run: `npx vitest run` (o el filtro correspondiente a los tests de `FaceCaptureApp`/`capture-face`, si existen)
Expected: PASS

- [ ] **Step 9: Verificación manual en navegador**

Visitar `/registro-facial/{token-válido}` (generar uno de prueba) y `/empleados/{id}/capture-face` (autenticado) en claro y oscuro; confirmar que face-api.js carga desde local (Network tab, sin requests a unpkg.com), el botón Cancelar funciona, el modal de confirmación se muestra/oculta correctamente, y el toggle de tema funciona.

- [ ] **Step 10: Commit**

```bash
git add resources/views/shared/capture-face.blade.php resources/js/shared/capture-face.js resources/js/shared/FaceCaptureApp.js resources/css/shared/capture-face.css tests/Feature/CaptureFaceViewTest.php
git commit -m "refactor: migrate capture-face to local face-api.js, class-based patterns and theme toggle"
```

---

### Task 8: Consumir `<x-theme-toggle-button>` en `mark.blade.php` y `terminal.blade.php`

**Files:**
- Modify: `resources/views/attendances/mark.blade.php:72-82`
- Modify: `resources/views/attendances/terminal.blade.php:40-50`
- Modify: `resources/js/attendances/mark.js:1919-1936`
- Modify: `resources/js/attendances/terminal.js` (bloque equivalente de tema — ubicar con `Grep` antes de tocar, mismo patrón que `mark.js:1919-1936`)
- Test: `tests/Feature/AttendanceMarkViewTest.php` o el test existente que cubra `mark.show`/`terminal.show` (revisar si ya existe antes de crear uno nuevo)

**Interfaces:**
- Consumes: `<x-theme-toggle-button />` y `initThemeToggle` (Tarea 1).
- Produces: nada consumido por tareas posteriores — última tarea del plan.

- [ ] **Step 1: Ubicar el bloque de tema equivalente en `terminal.js`**

```bash
grep -n "theme\|btnThemeToggle" resources/js/attendances/terminal.js
```
Confirmar el nombre exacto de la clave de `localStorage` usada hoy (se espera `"terminal-theme"`, a verificar) antes de escribir el Step 5.

- [ ] **Step 2: Escribir/ubicar el test que cubre las 2 vistas**

Si ya existe un test Feature que visita `mark.show`/`terminal.show` (buscar con `Glob` en `tests/Feature/`), extenderlo; si no, crear:

```php
<?php
// tests/Feature/AttendanceMarkViewTest.php (agregar si el archivo ya existe, o crear si no)

it('la vista de marcación incluye el botón de toggle de tema compartido', function () {
    $response = $this->get(route('mark.show'));

    $response->assertSuccessful();
    $response->assertSee('id="btnThemeToggle"', false);
});
```

```php
<?php
// tests/Feature/TerminalViewTest.php (agregar si el archivo ya existe, o crear si no)

use App\Models\Branch;
use App\Models\Company;
use App\Models\Terminal;

it('la vista de terminal incluye el botón de toggle de tema compartido', function () {
    $company = Company::create(['name' => 'Empresa Test', 'ruc' => '80012345-6']);
    $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal Centro']);
    $terminal = Terminal::create(['branch_id' => $branch->id, 'name' => 'Terminal 1', 'code' => 'XYZ789', 'is_active' => true]);

    $response = $this->get(route('terminal.show', $terminal->code));

    $response->assertSuccessful();
    $response->assertSee('id="btnThemeToggle"', false);
});
```

- [ ] **Step 3: Correr los tests y confirmar que ya pasan (el id no cambia, solo el origen del markup)**

Run: `php artisan test --compact --filter=AttendanceMarkViewTest`
Run: `php artisan test --compact --filter=TerminalViewTest`
Expected: PASS — estos tests no distinguen si el botón viene del componente o estaba hardcodeado, así que deben pasar tanto antes como después del cambio.

- [ ] **Step 4: Reemplazar el bloque hardcodeado en `mark.blade.php:72-82`**

De:
```blade
            <button type="button" id="btnThemeToggle" class="theme-toggle" aria-label="Cambiar tema claro/oscuro">
                <svg class="icon-moon" ...>...</svg>
                <svg class="icon-sun" ...>...</svg>
            </button>
```
a:
```blade
            <x-theme-toggle-button />
```

- [ ] **Step 5: Reemplazar el bloque equivalente en `terminal.blade.php:40-50`**

Mismo reemplazo: el `<button id="btnThemeToggle" class="theme-toggle" ...>...</button>` completo por `<x-theme-toggle-button />`.

- [ ] **Step 6: Reemplazar la IIFE de tema en `mark.js:1919-1936` por `initThemeToggle`**

De:
```js
    (function initTheme() {
        const saved = localStorage.getItem("mark-theme");
        const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
        const isDark = saved === "dark" || (!saved && prefersDark);
        document.documentElement.setAttribute("data-theme", isDark ? "dark" : "light");
    })();

    const btnThemeToggle = document.getElementById("btnThemeToggle");
    if (btnThemeToggle) {
        btnThemeToggle.addEventListener("click", () => {
            const isDark = document.documentElement.getAttribute("data-theme") === "dark";
            const next = isDark ? "light" : "dark";
            document.documentElement.setAttribute("data-theme", next);
            localStorage.setItem("mark-theme", next);
        });
    }
```
a:
```js
    initThemeToggle("mark-theme");
```
Y agregar el import correspondiente al inicio del archivo:
```js
import { initThemeToggle } from '../shared/theme-toggle.js';
```

- [ ] **Step 7: Reemplazar el bloque equivalente en `terminal.js`**

Mismo tratamiento: reemplazar el bloque de tema ubicado en el Step 1 por `initThemeToggle("terminal-theme");` (usar la clave exacta confirmada en el Step 1), agregando el mismo import.

- [ ] **Step 8: Correr los tests Pest y confirmar que siguen pasando**

Run: `php artisan test --compact --filter=AttendanceMarkViewTest`
Run: `php artisan test --compact --filter=TerminalViewTest`
Expected: PASS

- [ ] **Step 9: Correr el suite completo de Vitest y Pest para detectar regresiones**

Run: `npx vitest run`
Run: `php artisan test --compact`
Expected: PASS en ambos (todo el suite, no solo lo tocado en este plan — última tarea, cierre de sub-proyecto).

- [ ] **Step 10: Verificación manual en navegador**

Visitar `/marcar` y `/terminal/{code}` en claro y oscuro, confirmar que el toggle sigue funcionando exactamente igual que antes (mismo comportamiento, distinto origen del markup/JS).

- [ ] **Step 11: Commit**

```bash
git add resources/views/attendances/mark.blade.php resources/views/attendances/terminal.blade.php resources/js/attendances/mark.js resources/js/attendances/terminal.js tests/Feature/AttendanceMarkViewTest.php tests/Feature/TerminalViewTest.php
git commit -m "refactor: consume shared theme-toggle-button component in mark and terminal views"
```

---

## Cierre del sub-proyecto

Tras la Tarea 8, correr `vendor/bin/pint --dirty` sobre cualquier archivo PHP pendiente, confirmar el suite completo verde (`php artisan test --compact` + `npx vitest run`), y proceder con `superpowers:finishing-a-development-branch`.
