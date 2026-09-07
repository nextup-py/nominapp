# Sistema de Diseño Unificado — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidar los 3 sistemas de tokens CSS duplicados (mark/terminal/capture-face) y el panel Filament en un único archivo de tokens, self-hostear Poppins (eliminando 4 requests externos que rompen offline), producir un isotipo/favicon/set de íconos PWA funcional, y eliminar CSS muerto — sin tocar layout, UX, ni lógica de marcación.

**Architecture:** Un archivo central `resources/css/shared/tokens.css` con bloque `@theme` de Tailwind v4 (valores Tailwind-canónicos) más un bloque de alias `:root`/dark-mode con los nombres de variable legacy (`--c-*`, `--sp-*`, etc.) que el CSS de componentes ya usa, para no reescribir esos archivos. 4 entrypoints CSS existentes importan este archivo. Poppins se self-hostea vía el paquete npm `@fontsource/poppins`, consumido tanto por `tokens.css` (vistas públicas) como por el panel Filament (vía `LocalFontProvider`). El isotipo se diseña como SVG maestro y se rasteriza a los formatos/tamaños necesarios con un script Node de un solo uso.

**Tech Stack:** Laravel 12, Vite 6, Tailwind CSS v4, Filament 3.3, `@fontsource/poppins` (npm), `sharp` + `to-ico` (generación de íconos, devDependencies).

**Spec:** `docs/superpowers/specs/2026-09-07-design-system-unification-design.md`

## Global Constraints

- No se toca layout/UX de ninguna pantalla (eso es sub-proyecto E) — solo tokens, fuentes, íconos, y limpieza.
- No se toca lógica JS de marcación/offline (matcher/queue/db) — eso es sub-proyecto C.
- `resources/views/welcome.blade.php` queda fuera de alcance (sin ruta que lo sirva).
- El color `warning` se resuelve a `Color::Yellow` (Tailwind), no al ámbar que usan hoy `styles.css`/`terminal.css` — coherencia con `AdminPanelProvider.php:38`.
- `CACHE_VERSION` en `public/sw.js` se incrementa a `'nominapp-attendance-v2'` — se despliega en ventana de bajo uso, sin mecanismo de rollback especial más allá de revert de código estándar.
- Todo cambio de `package.json` (nueva dependencia) requiere confirmación del usuario en el momento de ejecutar `npm install` — no es una acción a asumir como pre-aprobada.

---

## Task 1: Self-hostear Poppins (`@fontsource/poppins` + `fonts.css`)

**Files:**
- Create: `resources/css/shared/fonts.css`
- Modify: `package.json` (nueva dependencia)
- Modify: `vite.config.js` (nuevo entrypoint)

**Interfaces:**
- Produces: `resources/css/shared/fonts.css` — hoja que declara `@font-face` para Poppins (400/500/600/700), importable por otros archivos CSS vía `@import '../shared/fonts.css';` (ruta relativa desde `resources/css/attendances/` o `resources/css/shared/`).

- [ ] **Step 1: Instalar el paquete de fuente**

```bash
npm install @fontsource/poppins
```

- [ ] **Step 2: Verificar que el paquete trae los pesos necesarios**

Run: `ls node_modules/@fontsource/poppins/ | grep -E "^(400|500|600|700)\.css$"`
Expected: lista con `400.css`, `500.css`, `600.css`, `700.css`

- [ ] **Step 3: Crear `resources/css/shared/fonts.css`**

```css
/**
 * Poppins self-hosted — sirve el peso 400/500/600/700 desde el mismo origen
 * que el resto del bundle de Vite (public/build/assets/), en vez de pedirlo
 * a Google Fonts o Bunny Fonts. Consumido por tokens.css (vistas públicas de
 * marcación) y directamente por el panel Filament vía LocalFontProvider
 * (ver AdminPanelProvider.php).
 */
@import '@fontsource/poppins/400.css';
@import '@fontsource/poppins/500.css';
@import '@fontsource/poppins/600.css';
@import '@fontsource/poppins/700.css';
```

- [ ] **Step 4: Agregar el entrypoint a `vite.config.js`**

En el array `input` de `vite.config.js`, agregar `'resources/css/shared/fonts.css'` (cualquier posición, junto a los otros `resources/css/shared/*`):

```js
input: [
    'resources/css/app.css',
    'resources/js/app.js',
    'resources/js/attendances/mark.js',
    'resources/js/attendances/device-link.js',
    'resources/css/attendances/styles.css',
    'resources/js/attendances/terminal.js',
    'resources/css/attendances/terminal.css',
    'resources/js/shared/capture-face.js',
    'resources/css/shared/capture-face.css',
    'resources/css/shared/fonts.css',
    'resources/js/planner/planner.js',
    'resources/css/planner/planner.css',
],
```

- [ ] **Step 5: Verificar que el build resuelve el import y genera los `.woff2` con hash**

```bash
npm run build
```

Run: `ls public/build/assets/ | grep -i "poppins.*woff2"`
Expected: al menos 4 archivos `.woff2` con nombres tipo `poppins-latin-400-normal-<hash>.woff2`.

- [ ] **Step 6: Commit**

```bash
git add package.json package-lock.json resources/css/shared/fonts.css vite.config.js
git commit -m "feat: self-host Poppins via @fontsource/poppins"
```

---

## Task 2: Crear `resources/css/shared/tokens.css` (sistema de tokens unificado)

**Files:**
- Create: `resources/css/shared/tokens.css`

**Interfaces:**
- Consumes: `resources/css/shared/fonts.css` (Task 1) vía `@import`.
- Produces: variables CSS legacy consumidas por el CSS de componentes existente en `styles.css`/`terminal.css`/`capture-face.css` (Task 3): `--c-primary`, `--c-primary-h`, `--c-primary-l`, `--c-primary-bg`, `--c-primary-ring`, `--c-bg`, `--c-surface`, `--c-border`, `--c-border-s`, `--c-text`, `--c-muted`, `--c-subtle`, `--c-success`, `--c-success-l`, `--c-success-bg`, `--c-success-ring`, `--c-danger`, `--c-danger-l`, `--c-danger-bg`, `--c-danger-ring`, `--c-warning`, `--c-warning-l`, `--c-warning-bg`, `--c-warning-ring`, `--c-info`, `--c-info-bg`, `--c-info-ring`, `--font`, `--sp-1..6,8,10,12`, `--r-sm,--r,--r-md,--r-lg,--r-xl,--r-2xl,--r-3xl`, `--sh-sm,--sh,--sh-md,--sh-lg,--sh-xl`, `--tr-f,--tr,--tr-s`.

- [ ] **Step 1: Escribir `resources/css/shared/tokens.css`**

```css
/**
 * Tokens de diseño unificados — fuente de verdad única para las 4 vistas
 * públicas de marcación y el panel Filament. Reemplaza las 3 copias
 * duplicadas que existían antes en styles.css/terminal.css/capture-face.css.
 *
 * Estructura en dos capas:
 * 1. @theme (Tailwind v4) — nombres canónicos de Tailwind, disponibles como
 *    utilities (bg-primary-600, etc.) para código nuevo (rediseño futuro).
 * 2. :root — alias con los nombres legacy (--c-primary, --sp-4, etc.) que
 *    ya consume el CSS de componentes existente, para no reescribirlo acá.
 */
@import './fonts.css';

@theme {
    --font-sans: 'Poppins', system-ui, -apple-system, sans-serif;

    --color-primary-50: #f0fdfa;
    --color-primary-100: #ccfbf1;
    --color-primary-200: #99f6e4;
    --color-primary-300: #5eead4;
    --color-primary-400: #2dd4bf;
    --color-primary-500: #14b8a6;
    --color-primary-600: #0d9488;
    --color-primary-700: #0f766e;
    --color-primary-800: #115e59;
    --color-primary-900: #134e4a;

    --color-success-50: #f0fdf4;
    --color-success-100: #dcfce7;
    --color-success-300: #86efac;
    --color-success-500: #22c55e;
    --color-success-600: #16a34a;

    --color-danger-50: #fef2f2;
    --color-danger-100: #fee2e2;
    --color-danger-300: #fca5a5;
    --color-danger-500: #ef4444;
    --color-danger-600: #dc2626;

    /* Color::Yellow de Filament — NO es el ámbar que usaban antes las vistas de marcación */
    --color-warning-50: #fefce8;
    --color-warning-100: #fef9c3;
    --color-warning-300: #fde047;
    --color-warning-500: #eab308;
    --color-warning-600: #ca8a04;

    --color-info-50: #eff6ff;
    --color-info-100: #dbeafe;
    --color-info-300: #93c5fd;
    --color-info-500: #3b82f6;
    --color-info-600: #2563eb;

    --color-surface-50: #f8fafc;
    --color-surface-100: #f1f5f9;
    --color-surface-200: #e2e8f0;
    --color-surface-300: #cbd5e1;
    --color-surface-400: #94a3b8;
    --color-surface-600: #64748b;
    --color-surface-700: #334155;
    --color-surface-800: #1e293b;
    --color-surface-900: #0f172a;
}

:root {
    color-scheme: light dark;
    --font: var(--font-sans);

    --c-primary:      var(--color-primary-600);
    --c-primary-h:    var(--color-primary-700);
    --c-primary-l:    var(--color-primary-500);
    --c-primary-bg:   var(--color-primary-50);
    --c-primary-ring: rgba(20, 184, 166, 0.25);

    --c-bg:       var(--color-surface-50);
    --c-surface:  #ffffff;
    --c-border:   var(--color-surface-200);
    --c-border-s: var(--color-surface-300);
    --c-text:     var(--color-surface-900);
    --c-muted:    var(--color-surface-600);
    --c-subtle:   var(--color-surface-400);

    --c-success:      var(--color-success-600);
    --c-success-l:    var(--color-success-500);
    --c-success-bg:   var(--color-success-100);
    --c-success-ring: var(--color-success-300);

    --c-danger:      var(--color-danger-600);
    --c-danger-l:    var(--color-danger-500);
    --c-danger-bg:   var(--color-danger-100);
    --c-danger-ring: var(--color-danger-300);

    --c-warning:      var(--color-warning-600);
    --c-warning-l:    var(--color-warning-500);
    --c-warning-bg:   var(--color-warning-100);
    --c-warning-ring: var(--color-warning-300);

    --c-info:      var(--color-info-600);
    --c-info-bg:   var(--color-info-100);
    --c-info-ring: var(--color-info-300);

    --sp-1: 4px;  --sp-2: 8px;   --sp-3: 12px;
    --sp-4: 16px; --sp-5: 20px;  --sp-6: 24px;
    --sp-8: 32px; --sp-10: 40px; --sp-12: 48px;

    --r-sm: 6px; --r: 8px; --r-md: 10px; --r-lg: 12px;
    --r-xl: 16px; --r-2xl: 24px; --r-3xl: 32px;

    --sh-sm: 0 1px 2px rgba(0,0,0,.05);
    --sh:    0 1px 3px rgba(0,0,0,.1);
    --sh-md: 0 4px 6px -1px rgba(0,0,0,.12);
    --sh-lg: 0 10px 25px -5px rgba(0,0,0,.18);
    --sh-xl: 0 20px 40px -10px rgba(0,0,0,.22);

    --tr-f: 150ms ease-in-out;
    --tr:   200ms ease-in-out;
    --tr-s: 300ms ease-in-out;
}

@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
        --c-bg:          #0f172a;
        --c-surface:     #1e293b;
        --c-border:      #334155;
        --c-border-s:    #475569;
        --c-text:        #f1f5f9;
        --c-muted:       #94a3b8;
        --c-subtle:      #64748b;
        --c-primary-bg:  rgba(20,184,166,.12);
        --c-success-bg:  rgba(22,163,74,.15);
        --c-success-ring:rgba(134,239,172,.3);
        --c-danger-bg:   rgba(220,38,38,.15);
        --c-danger-ring: rgba(252,165,165,.3);
        --c-warning-bg:  rgba(202,138,4,.15);
        --c-warning-ring:rgba(253,224,71,.3);
        --c-info-bg:     rgba(37,99,235,.15);
        --c-info-ring:   rgba(147,197,253,.3);
        --sh-sm: 0 1px 2px rgba(0,0,0,.3);
        --sh:    0 1px 3px rgba(0,0,0,.4);
        --sh-md: 0 4px 6px -1px rgba(0,0,0,.5);
        --sh-lg: 0 10px 25px -5px rgba(0,0,0,.5);
        --sh-xl: 0 20px 40px -10px rgba(0,0,0,.6);
    }
}
html[data-theme="dark"] {
    --c-bg:          #0f172a;
    --c-surface:     #1e293b;
    --c-border:      #334155;
    --c-border-s:    #475569;
    --c-text:        #f1f5f9;
    --c-muted:       #94a3b8;
    --c-subtle:      #64748b;
    --c-primary-bg:  rgba(20,184,166,.12);
    --c-success-bg:  rgba(22,163,74,.15);
    --c-success-ring:rgba(134,239,172,.3);
    --c-danger-bg:   rgba(220,38,38,.15);
    --c-danger-ring: rgba(252,165,165,.3);
    --c-warning-bg:  rgba(202,138,4,.15);
    --c-warning-ring:rgba(253,224,71,.3);
    --c-info-bg:     rgba(37,99,235,.15);
    --c-info-ring:   rgba(147,197,253,.3);
    --sh-sm: 0 1px 2px rgba(0,0,0,.3);
    --sh:    0 1px 3px rgba(0,0,0,.4);
    --sh-md: 0 4px 6px -1px rgba(0,0,0,.5);
    --sh-lg: 0 10px 25px -5px rgba(0,0,0,.5);
    --sh-xl: 0 20px 40px -10px rgba(0,0,0,.6);
}
```

- [ ] **Step 2: Verificar sintaxis con un build**

```bash
npm run build
```

Expected: build exitoso, sin errores de Tailwind/PostCSS.

- [ ] **Step 3: Commit**

```bash
git add resources/css/shared/tokens.css
git commit -m "feat: add unified design tokens (tokens.css)"
```

---

## Task 3: Migrar `styles.css`, `terminal.css`, `capture-face.css` y `app.css` a los tokens compartidos

**Files:**
- Modify: `resources/css/attendances/styles.css:1-112` (elimina import de Google Fonts + bloque `:root`/dark completo, agrega `@import '../shared/tokens.css';`)
- Modify: `resources/css/attendances/terminal.css:1-98` (ídem)
- Modify: `resources/css/shared/capture-face.css:1-72` (ídem)
- Modify: `resources/css/app.css` (agrega `@import './shared/tokens.css';` después del `@import 'tailwindcss';`)

**Interfaces:**
- Consumes: `resources/css/shared/tokens.css` (Task 2) — todas las variables `--c-*`/`--sp-*`/`--r-*`/`--sh-*`/`--tr-*`/`--font` usadas por el CSS de componentes que permanece sin cambios en estos 3 archivos.

- [ ] **Step 1: Editar `resources/css/attendances/styles.css`**

Reemplazar las líneas 1-112 (import de Google Fonts, comentario "DESIGN TOKENS", bloque `:root { ... }`, bloque `@media (prefers-color-scheme: dark)`, bloque `html[data-theme="dark"]`) por:

```css
@import '../shared/tokens.css';
@import 'leaflet/dist/leaflet.css';
```

El resto del archivo (desde el comentario `RESET` en adelante) no se toca.

- [ ] **Step 2: Editar `resources/css/attendances/terminal.css`**

Reemplazar las líneas 1-98 (mismo patrón: import de Google Fonts + comentario + `:root` + ambos bloques dark) por:

```css
@import '../shared/tokens.css';
```

El resto del archivo (desde el comentario `RESET` en adelante) no se toca.

- [ ] **Step 3: Editar `resources/css/shared/capture-face.css`**

Reemplazar las líneas 1-72 (mismo patrón) por:

```css
@import './tokens.css';
```

El resto del archivo (desde el comentario `RESET` en adelante) no se toca.

- [ ] **Step 4: Editar `resources/css/app.css`**

```css
@import 'tailwindcss';
@import './shared/tokens.css';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@source '../**/*.js';
```

(Se elimina el bloque `@theme { --font-sans: 'Instrument Sans', ... }` que estaba acá — ahora vive en `tokens.css` con Poppins.)

- [ ] **Step 5: Verificar que no queden imports externos de fuentes**

Run: `grep -rn "fonts.googleapis\|fonts.bunny" resources/css/`
Expected: sin resultados (0 matches).

- [ ] **Step 6: Build y verificación visual rápida**

```bash
npm run build
```

Run: `php artisan serve` (o el servidor de desarrollo ya activo) y abrir `/marcar`, `/terminal`, `/registro-facial/{token válido de prueba}`, `/admin` en el navegador.
Expected: las 4 páginas cargan sin errores de consola, con la misma apariencia visual que antes del cambio (mismo teal, misma tipografía Poppins) — es un refactor de organización, no debe haber diferencia visual perceptible salvo el color `warning` (ahora amarillo en vez de ámbar, donde se use).

- [ ] **Step 7: Commit**

```bash
git add resources/css/app.css resources/css/attendances/styles.css resources/css/attendances/terminal.css resources/css/shared/capture-face.css
git commit -m "refactor: migrate CSS entrypoints to shared design tokens"
```

---

## Task 4: Migrar `device-link.blade.php` a los tokens compartidos

**Files:**
- Create: `resources/css/attendances/device-link.css`
- Modify: `resources/views/attendances/device-link.blade.php:1-92` (elimina bloque `<style>` inline, agrega `@vite`)
- Modify: `vite.config.js` (nuevo entrypoint)

**Interfaces:**
- Consumes: `resources/css/shared/tokens.css` (Task 2).

- [ ] **Step 1: Crear `resources/css/attendances/device-link.css`**

Esta página es dark-only por diseño (no tiene toggle de tema como mark/terminal) — se mantiene esa decisión de layout (cambiarla es trabajo de E), solo se reemplazan los valores hex sueltos por los tokens equivalentes. Mapeo aplicado: `#0f172a`→`--color-surface-900`, `#1e293b`→`--color-surface-800`, `#334155`→`--color-surface-700`, `#e2e8f0`→`--color-surface-200`, `#94a3b8`→`--color-surface-400`, `#64748b`→`--c-muted` (mismo valor exacto), `#38bdf8`/`#7dd3fc` (celeste, acento visual sin relación con la marca) → `--c-primary-l`/`--color-primary-400` (teal, el acento real de marca), `#f87171`→`--c-danger-l`, `#4ade80`→`--c-success-l`, `#f59e0b`/`#fcd34d`/`#422006`/`rgba(245,158,11,.12)` (ámbar, ya no es el warning correcto) → `--c-warning-l`/`--color-warning-300`/equivalente rgba de `--color-warning-600`, `Arial, sans-serif` → `var(--font)`.

```css
@import '../shared/tokens.css';

* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: var(--font);
    background: var(--color-surface-900);
    color: var(--color-surface-200);
    min-height: 100dvh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
}
.container { max-width: 420px; width: 100%; text-align: center; }
.icon {
    width: 80px; height: 80px;
    border-radius: 50%;
    background: var(--color-surface-800);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.5rem;
    border: 2px solid var(--c-primary-l);
}
.icon svg { width: 40px; height: 40px; color: var(--c-primary-l); }
h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.75rem; }
p { font-size: 1rem; color: var(--color-surface-400); line-height: 1.6; margin-bottom: 1.5rem; }
form { text-align: left; }
label { display: block; font-size: 0.875rem; color: var(--color-surface-400); margin-bottom: 0.35rem; }
input {
    width: 100%;
    font: inherit;
    font-size: 1rem;
    padding: 0.75rem 1rem;
    border-radius: 0.6rem;
    border: 1px solid var(--color-surface-700);
    background: var(--color-surface-800);
    color: var(--color-surface-200);
    margin-bottom: 1.1rem;
}
input:focus { outline: none; border-color: var(--c-primary-l); }
button {
    width: 100%;
    font: inherit;
    font-weight: 600;
    font-size: 1rem;
    padding: 0.85rem 1.75rem;
    border-radius: 0.75rem;
    border: none;
    background: var(--c-primary-l);
    color: var(--color-surface-900);
    cursor: pointer;
}
button:disabled { opacity: 0.6; cursor: not-allowed; }
button:not(:disabled):hover { background: var(--color-primary-400); }
.status { margin-top: 1.25rem; font-size: 0.9rem; min-height: 1.5rem; text-align: center; }
.status--error { color: var(--c-danger-l); }
.status--success { color: var(--c-success-l); }
.hint { font-size: 0.8rem; color: var(--c-muted); margin-top: 1.5rem; line-height: 1.5; }
.hidden { display: none !important; }

/* Aviso: este navegador ya tiene un dispositivo vinculado */
.already-linked-warning {
    text-align: left;
    background: rgba(202, 138, 4, 0.2);
    border: 1px solid var(--c-warning-l);
    border-radius: 0.75rem;
    padding: 1.1rem 1.25rem;
    margin-bottom: 1.5rem;
}
.already-linked-warning p { color: var(--color-warning-300); font-size: 0.9rem; margin-bottom: 1rem; }
.already-linked-warning p:last-of-type { margin-bottom: 1.25rem; }
.already-linked-actions { display: flex; flex-direction: column; gap: 0.6rem; }
.btn-continue-anyway {
    width: 100%; font: inherit; font-weight: 600; font-size: 0.9rem;
    padding: 0.7rem 1.5rem; border-radius: 0.6rem; border: 1px solid var(--c-warning-l);
    background: transparent; color: var(--color-warning-300); cursor: pointer;
}
.btn-continue-anyway:hover { background: rgba(202, 138, 4, 0.12); }
.btn-cancel-relink {
    width: 100%; font: inherit; font-weight: 600; font-size: 0.9rem;
    padding: 0.7rem 1.5rem; border-radius: 0.6rem; border: none;
    background: var(--c-primary-l); color: var(--color-surface-900); cursor: pointer;
}
.btn-cancel-relink:hover { background: var(--color-primary-400); }
```

- [ ] **Step 2: Editar `resources/views/attendances/device-link.blade.php`**

Reemplazar el bloque `<style>...</style>` (líneas 10-92 exactas) por:

```blade
@vite('resources/css/attendances/device-link.css')
```

Ubicado en el mismo lugar donde estaba el `<style>`, después del `@vite('resources/js/attendances/device-link.js')` ya existente en la línea 9.

- [ ] **Step 3: Agregar el entrypoint a `vite.config.js`**

```js
'resources/css/attendances/device-link.css',
```

(Junto a los otros `resources/css/attendances/*.css` en el array `input`.)

- [ ] **Step 4: Build y verificación visual**

```bash
npm run build
```

Abrir `/vincular-dispositivo` en el navegador. Expected: misma estructura/layout que antes, pero con tipografía Poppins y acento teal en vez de Arial/celeste.

- [ ] **Step 5: Commit**

```bash
git add resources/css/attendances/device-link.css resources/views/attendances/device-link.blade.php vite.config.js
git commit -m "refactor: migrate device-link view to shared design tokens"
```

---

## Task 5: Eliminar CSS muerto

**Files:**
- Delete: `resources/css/employees/capture-face.css`
- Delete: `resources/css/enrollments/capture-face.css`

**Interfaces:** Ninguna — archivos sin referencias.

- [ ] **Step 1: Confirmar que no hay referencias antes de borrar**

Run: `grep -rln "employees/capture-face.css\|enrollments/capture-face.css" resources/ vite.config.js`
Expected: sin resultados (0 matches) — si aparece algo, DETENER y no borrar hasta investigar.

- [ ] **Step 2: Eliminar los archivos**

```bash
git rm resources/css/employees/capture-face.css resources/css/enrollments/capture-face.css
```

- [ ] **Step 3: Build de verificación**

```bash
npm run build
```

Expected: build exitoso (confirma que nada dependía de esos archivos).

- [ ] **Step 4: Commit**

```bash
git commit -m "chore: remove dead CSS (employees/enrollments capture-face.css)"
```

---

## Task 6: Diseñar el isotipo maestro y generar el set de íconos

**Files:**
- Create: `resources/svg/isotipo-master.svg`
- Create: `scripts/generate-icons.mjs`
- Modify: `package.json` (devDependencies: `sharp`, `to-ico`)
- Create (generados por el script): `public/icons/favicon.svg`, `public/icons/favicon.ico`, `public/icons/apple-touch-icon.png`, `public/icons/icon-192.png`, `public/icons/icon-512.png`, `public/icons/icon-192-maskable.png`, `public/icons/icon-512-maskable.png`

**Interfaces:**
- Produces: los 7 archivos bajo `public/icons/`, consumidos por Task 7 (referencias en `<head>`) y por el futuro sub-proyecto B (manifests PWA).

- [ ] **Step 1: Crear el SVG maestro**

`resources/svg/isotipo-master.svg` — el diseño aprobado durante el brainstorm (monograma "N" geométrico en negativo, contenedor rounded-square, teal `#0d9488` de fondo, símbolo `#f0fdfa`):

```xml
<svg width="512" height="512" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
  <rect x="17" y="17" width="478" height="478" rx="111" fill="#0d9488"/>
  <path d="M170 350 L170 162 L204 162 L342 307 L342 162 L376 162 L376 350 L342 350 L204 205 L204 350 Z" fill="#f0fdfa"/>
</svg>
```

(Proporciones escaladas 512/120 desde el mockup del brainstorm: `rx` 26/120 ≈ 0.2167 × 512 ≈ 111; el símbolo mantiene la misma proporción relativa al viewBox.)

- [ ] **Step 2: Crear la variante maskable del SVG**

`resources/svg/isotipo-master-maskable.svg` — mismo símbolo, fondo a sangre completa (sin rounded corners, el sistema operativo aplica su propia máscara), símbolo recentrado y reducido para caber en la zona segura circular (radio ~40% del lienzo, validado durante el brainstorm):

```xml
<svg width="512" height="512" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
  <rect width="512" height="512" fill="#0d9488"/>
  <path d="M196 336 L196 176 L222 176 L326 296 L326 176 L352 176 L352 336 L326 336 L222 216 L222 336 Z" fill="#f0fdfa"/>
</svg>
```

- [ ] **Step 3: Instalar las dependencias de generación**

```bash
npm install --save-dev sharp to-ico
```

- [ ] **Step 4: Crear `scripts/generate-icons.mjs`**

```js
/**
 * Genera el set de íconos PWA/favicon a partir de los SVG maestros en
 * resources/svg/. Script de un solo uso / re-ejecutable si el isotipo
 * cambia — no forma parte del pipeline de build de Vite.
 *
 * Uso: node scripts/generate-icons.mjs
 */
import sharp from 'sharp';
import toIco from 'to-ico';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';

const OUT_DIR = 'public/icons';
mkdirSync(OUT_DIR, { recursive: true });

const masterSvg = readFileSync('resources/svg/isotipo-master.svg');
const maskableSvg = readFileSync('resources/svg/isotipo-master-maskable.svg');

async function renderPng(svgBuffer, size, outPath) {
    const buffer = await sharp(svgBuffer, { density: 384 })
        .resize(size, size)
        .png()
        .toBuffer();
    writeFileSync(outPath, buffer);
    console.log(`  ${outPath} (${size}x${size})`);
}

async function main() {
    console.log('Generando íconos...');

    writeFileSync(`${OUT_DIR}/favicon.svg`, masterSvg);
    console.log(`  ${OUT_DIR}/favicon.svg`);

    await renderPng(masterSvg, 180, `${OUT_DIR}/apple-touch-icon.png`);
    await renderPng(masterSvg, 192, `${OUT_DIR}/icon-192.png`);
    await renderPng(masterSvg, 512, `${OUT_DIR}/icon-512.png`);
    await renderPng(maskableSvg, 192, `${OUT_DIR}/icon-192-maskable.png`);
    await renderPng(maskableSvg, 512, `${OUT_DIR}/icon-512-maskable.png`);

    const favicon16 = await sharp(masterSvg, { density: 384 }).resize(16, 16).png().toBuffer();
    const favicon32 = await sharp(masterSvg, { density: 384 }).resize(32, 32).png().toBuffer();
    const favicon48 = await sharp(masterSvg, { density: 384 }).resize(48, 48).png().toBuffer();
    const icoBuffer = await toIco([favicon16, favicon32, favicon48]);
    writeFileSync(`${OUT_DIR}/favicon.ico`, icoBuffer);
    console.log(`  ${OUT_DIR}/favicon.ico (16/32/48)`);

    console.log('Listo.');
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
```

- [ ] **Step 5: Ejecutar el script**

```bash
node scripts/generate-icons.mjs
```

Expected: 7 archivos listados en la salida, todos creados bajo `public/icons/`.

- [ ] **Step 6: Verificar tamaños de archivo razonables**

Run: `ls -la public/icons/`
Expected: `favicon.ico` con tamaño > 0 bytes (a diferencia del actual roto en la raíz de `public/`), PNGs entre ~1KB y ~15KB cada uno (íconos simples de 2 colores, no deberían pesar más).

- [ ] **Step 7: Commit**

```bash
git add resources/svg/ scripts/generate-icons.mjs package.json package-lock.json public/icons/
git commit -m "feat: generate isotipo favicon and PWA icon set"
```

---

## Task 7: Referenciar los íconos desde las 4 vistas públicas y el panel Filament

**Files:**
- Create: `resources/views/components/favicon-links.blade.php`
- Modify: `resources/views/attendances/mark.blade.php:4-11`
- Modify: `resources/views/attendances/terminal.blade.php:4-11`
- Modify: `resources/views/shared/capture-face.blade.php:24-31`
- Modify: `resources/views/attendances/device-link.blade.php` (head)
- Modify: `app/Providers/Filament/AdminPanelProvider.php:31` (agrega `->favicon()`)
- Delete: `public/favicon.ico` (el roto de 0 bytes en la raíz — se reemplaza por `public/icons/favicon.ico`)

**Interfaces:**
- Consumes: los archivos generados en Task 6 (`public/icons/*`).

- [ ] **Step 1: Crear el partial compartido `resources/views/components/favicon-links.blade.php`**

```blade
<link rel="icon" href="/icons/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/icons/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
```

- [ ] **Step 2: Incluirlo en `resources/views/attendances/mark.blade.php`**

Dentro de `<head>`, después de `<meta name="color-scheme" content="light dark">` (línea 9 actual):

```blade
<x-favicon-links />
```

- [ ] **Step 3: Incluirlo en `resources/views/attendances/terminal.blade.php`**

Mismo patrón, después de `<meta name="color-scheme" content="light dark">`.

- [ ] **Step 4: Incluirlo en `resources/views/shared/capture-face.blade.php`**

Dentro de `<head>`, después de `<meta name="csrf-token" ...>` (línea 28 actual).

- [ ] **Step 5: Incluirlo en `resources/views/attendances/device-link.blade.php`**

Dentro de `<head>`, después de `<meta name="csrf-token" ...>`.

- [ ] **Step 6: Configurar el favicon del panel Filament**

En `app/Providers/Filament/AdminPanelProvider.php`, agregar `->favicon(asset('icons/favicon.ico'))` a la cadena del panel, junto a `->font('Poppins')`:

```php
->font('Poppins')
->favicon(asset('icons/favicon.ico'))
```

- [ ] **Step 7: Eliminar el favicon roto de la raíz**

```bash
git rm public/favicon.ico
```

- [ ] **Step 8: Verificación visual**

Abrir cada una de las 5 superficies (`/marcar`, `/terminal`, `/registro-facial/{token}`, `/vincular-dispositivo`, `/admin`) y confirmar en la pestaña del navegador que aparece el ícono "N" en teal (antes: pestaña sin ícono o ícono roto).

- [ ] **Step 9: Commit**

```bash
git add resources/views/components/favicon-links.blade.php resources/views/attendances/mark.blade.php resources/views/attendances/terminal.blade.php resources/views/shared/capture-face.blade.php resources/views/attendances/device-link.blade.php app/Providers/Filament/AdminPanelProvider.php
git rm public/favicon.ico
git commit -m "feat: wire favicon and app icons across all public views and admin panel"
```

---

## Task 8: Self-hostear Poppins en el panel Filament (`LocalFontProvider`)

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php:31`

**Interfaces:**
- Consumes: `resources/css/shared/fonts.css` (Task 1), vía `Vite::asset()`.

- [ ] **Step 1: Editar `AdminPanelProvider.php`**

Reemplazar la línea 31:

```php
// Antes
->font('Poppins')
```

por:

```php
// Después
->font(
    'Poppins',
    url: \Illuminate\Support\Facades\Vite::asset('resources/css/shared/fonts.css'),
    provider: \Filament\FontProviders\LocalFontProvider::class,
)
```

- [ ] **Step 2: Verificar en el navegador que el admin ya no pide la fuente a Bunny Fonts**

Abrir `/admin`, DevTools → Network → filtrar por "font" o por dominio `bunny`.
Expected: cero requests a `fonts.bunny.net`; las fuentes `.woff2` se cargan desde `/build/assets/`.

- [ ] **Step 3: Confirmar visualmente que la tipografía se sigue viendo igual**

Expected: sin cambio visual perceptible en `/admin` — Poppins ya era la fuente, solo cambió el origen de carga.

- [ ] **Step 4: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php
git commit -m "feat: self-host Poppins in Filament admin panel via LocalFontProvider"
```

---

## Task 9: Ajustar el service worker (caché de íconos/imágenes + bump de versión)

**Files:**
- Modify: `public/sw.js:19` (bump de `CACHE_VERSION`)
- Modify: `public/sw.js:28-32` (agrega patrones a `CACHE_FIRST_PATTERNS`)

**Interfaces:** Ninguna nueva — modificación de constantes existentes en un script standalone (no es módulo ES, no se importa desde otro archivo).

- [ ] **Step 1: Editar `CACHE_VERSION`**

```js
// Antes
const CACHE_VERSION = 'nominapp-attendance-v1';

// Después
const CACHE_VERSION = 'nominapp-attendance-v2';
```

- [ ] **Step 2: Editar `CACHE_FIRST_PATTERNS`**

```js
const CACHE_FIRST_PATTERNS = [
    /^\/models\//, // modelos de face-api.js (tinyFaceDetector, faceLandmark68, faceRecognition)
    /^\/js\/face-api\.min\.js$/,
    /^\/build\/assets\//, // todos los bundles JS/CSS de Vite (nombres con hash de contenido — seguros de cachear indefinidamente)
    /^\/icons\//, // favicon + set de íconos PWA (Task 6) — deben estar disponibles offline en el launcher
    /^\/images\//, // ej. default-avatar.png, usado como fallback de foto en terminal.js
];
```

- [ ] **Step 3: Verificación manual en DevTools**

Con el servidor corriendo, abrir `/marcar` o `/terminal`, DevTools → Application → Service Workers → confirmar que el nuevo SW se instala (`nominapp-attendance-v2` reemplaza a `v1` en Application → Cache Storage tras recargar dos veces, ya que `skipWaiting()`+`clients.claim()` ya están implementados).

Run manual (Network → Offline en DevTools, luego recargar `/terminal`): confirmar que la página sigue cargando y que el ícono/favicon se sigue viendo.

- [ ] **Step 4: Commit**

```bash
git add public/sw.js
git commit -m "feat: cache icons/images offline and bump SW cache version"
```

---

## Task 10: Verificación final integrada

**Files:** Ninguno (solo verificación, sin cambios de código).

- [ ] **Step 1: Build completo desde cero**

```bash
rm -rf public/build
npm run build
```

Expected: build exitoso sin warnings de assets faltantes.

- [ ] **Step 2: Correr la suite de tests JS existente (regresión)**

```bash
npm run test
```

Expected: todos los tests de Vitest existentes (`mark/text-helpers.test.js`, `terminal/text-helpers.test.js`) siguen pasando — este sub-proyecto no debería haberlos afectado, ya que no toca JS de lógica.

- [ ] **Step 3: Correr la suite de tests Pest (regresión)**

```bash
php artisan test --compact
```

Expected: todos los tests pasan — este sub-proyecto no toca backend/lógica de negocio, solo assets/config de Filament.

- [ ] **Step 4: Lighthouse en las 4 vistas públicas + `/admin`**

Chrome DevTools → Lighthouse → correr auditoría (categoría "Best Practices" como mínimo) en `/marcar`, `/terminal`, `/registro-facial/{token}`, `/vincular-dispositivo`, `/admin`.
Expected: sin warnings sobre requests bloqueantes a dominios de fuentes externos (Google Fonts / Bunny Fonts ya no deberían aparecer).

- [ ] **Step 5: Verificación en dispositivo real**

En el celular y la tablet disponibles: abrir `/marcar` y `/terminal` respectivamente, confirmar que cargan y se ven correctamente (mismo teal, tipografía Poppins, favicon visible en el navegador si aplica).

- [ ] **Step 6: Verificación offline real**

Cargar `/marcar` (o `/terminal`) una vez con conexión, luego activar modo avión y recargar. Expected: la página carga desde el service worker, la tipografía Poppins se sigue viendo (no cae a system font) — esto confirma que el self-hosting resolvió el problema original de fuentes documentado en la spec.

- [ ] **Step 7: Confirmar con el usuario que el sub-proyecto A queda cerrado**

Sin commit en este paso — es un checkpoint de cierre antes de continuar con el sub-proyecto F (theming configurable desde Filament), siguiente en la secuencia A→F→B→E→D→C.
