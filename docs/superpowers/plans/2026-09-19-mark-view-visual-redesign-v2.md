# Rediseño visual v2 de mark.js (piloto) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidar el header saturado de `mark.blade.php` en un menú `⋮` (bottom sheet) que resuelve el bug de superposición tema/instalar, agregar un stepper visual de 2 pasos, y un marco de escaneo en el óvalo de cámara — todo reutilizando `tokens.css` existente, sin tocar paleta/tipografía ni lógica de detección facial.

**Architecture:** Cambios puramente de presentación (Blade + CSS) más un módulo JS nuevo, chico y testeable (`mark/menu-sheet.js`, patrón de inyección de dependencias ya usado en `shared/theme-toggle.js` y `terminal/screen-state.js`), que reemplaza el manejo disperso de apertura/cierre. Los 6 botones de acción (Sincronizar, Mis marcaciones, Pausar cámara, Desvincular, Tema, Instalar app) se mudan de markup sin tocar la lógica que ya los maneja en `mark.js` — todos se buscan por `getElementById`, indiferente a dónde viven en el DOM.

**Tech Stack:** Laravel Blade, Vite, CSS con custom properties (`tokens.css`), JS vanilla ES modules, Vitest.

**Spec:** `docs/superpowers/specs/2026-09-19-mark-view-visual-redesign-v2-design.md`

## Global Constraints

- Cero cambios a `resources/css/shared/tokens.css` — todo el diseño reutiliza los tokens existentes (colores, spacing, radios, sombras).
- Cero cambios a la lógica de captura/matching facial (`face-capture-core.js`, `offline-shared/matcher.js`) ni al motor de dwell.
- Los 6 botones movidos al menú (`btnSyncNow`, `btnMyEvents`, `btnCameraPause`, `btnUnlinkDevice`, `btnThemeToggle`, `btnInstallApp`) conservan sus IDs exactos — la lógica existente en `mark.js` que los busca por `getElementById` no se toca.
- Fuera de alcance: `terminal.blade.php`, `device-link.blade.php`, `shared/capture-face.blade.php`, `status-page.blade.php`, `terminal-setup.blade.php`, banners de offline/conflicto, splash, modal de éxito.
- Después de cada tarea: `vendor/bin/pint --dirty` y `npm run build` deben pasar sin errores.

---

### Task 1: Badge de modo — sentence-case sin versalitas trackeadas

**Files:**
- Modify: `resources/css/attendances/styles.css:233-243`

**Interfaces:** Ninguna — cambio puramente visual, no toca HTML ni JS.

- [ ] **Step 1: Editar el bloque `.app-mode-badge`**

En `resources/css/attendances/styles.css`, reemplazar:
```css
.app-mode-badge {
    background: var(--c-primary-bg);
    color: var(--c-primary);
    font-size: .6rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    padding: 3px var(--sp-2);
    border-radius: var(--r);
    border: 1px solid rgba(13,148,136,.25);
}
```
por:
```css
.app-mode-badge {
    background: var(--c-primary-bg);
    color: var(--c-primary);
    font-size: .75rem;
    font-weight: 600;
    padding: 3px var(--sp-2);
    border-radius: var(--r);
    border: 1px solid rgba(13,148,136,.25);
}
```

- [ ] **Step 2: Actualizar el texto del badge en el Blade**

En `resources/views/attendances/mark.blade.php:69`, cambiar:
```html
<span class="app-mode-badge">Marcación Facial</span>
```
por:
```html
<span class="app-mode-badge">Marcación facial</span>
```

- [ ] **Step 3: Verificar visualmente**

Correr `npm run build` (debe compilar sin error). Abrir `/marcar` en el navegador (`composer run dev` si no está corriendo) y confirmar que el badge ya no está en versalitas y se lee "Marcación facial".

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint --dirty
git add resources/css/attendances/styles.css resources/views/attendances/mark.blade.php
git commit -m "style: badge de modo en mark.js a sentence-case, sin versalitas trackeadas"
```

---

### Task 2: Consolidación del header en menú `⋮` (bottom sheet)

**Files:**
- Create: `resources/views/components/attendance/mark-menu-sheet.blade.php`
- Create: `resources/js/attendances/mark/menu-sheet.js`
- Create: `resources/js/attendances/mark/menu-sheet.test.js`
- Modify: `resources/views/attendances/mark.blade.php` (header + eliminar `.sync-status-row`)
- Modify: `resources/js/attendances/mark.js` (wiring del nuevo botón `⋮`)
- Modify: `resources/js/attendances/mark/sync-status-ui.js` (punto de estado)
- Modify: `resources/css/attendances/styles.css` (nuevo header, nuevo sheet, eliminar CSS muerto)

**Interfaces:**
- Produces: `createMenuSheet(els, doc = document)` en `menu-sheet.js`, donde `els = { sheet: HTMLElement, backdrop: HTMLElement, panel: HTMLElement, trigger: HTMLElement }`. Retorna `{ open: () => void, close: () => void, isOpen: () => boolean }`.
- Consumes (de `mark.js`, sin cambios): `btnSyncNow`, `btnUnlinkDevice`, `btnMyEvents`, `btnCameraPause`, `btnCameraPauseLabel` — mismos IDs, mismos listeners ya existentes en `mark.js`.

- [ ] **Step 1: Escribir el test del módulo del sheet (falla primero)**

Crear `resources/js/attendances/mark/menu-sheet.test.js`:
```js
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createMenuSheet } from './menu-sheet.js';

function fakeEl() {
    const classes = new Set();
    const attrs = {};
    return {
        classList: {
            add: (c) => classes.add(c),
            remove: (c) => classes.delete(c),
            contains: (c) => classes.has(c),
        },
        setAttribute: (k, v) => { attrs[k] = v; },
        getAttribute: (k) => attrs[k] ?? null,
        addEventListener: vi.fn(),
        focus: vi.fn(),
        _classes: classes,
        _attrs: attrs,
    };
}

function fakeFocusable(id) {
    const el = fakeEl();
    el.id = id;
    el.disabled = false;
    return el;
}

function fakeDoc(focusableEls) {
    let activeElement = fakeEl();
    const listeners = {};
    return {
        get activeElement() { return activeElement; },
        addEventListener: vi.fn((type, cb) => { listeners[type] = cb; }),
        removeEventListener: vi.fn((type) => { delete listeners[type]; }),
        _setActiveElement: (el) => { activeElement = el; },
        _fireKeydown: (event) => listeners.keydown?.(event),
    };
}

function makeEls() {
    const first = fakeFocusable('first');
    const last = fakeFocusable('last');
    const panel = {
        ...fakeEl(),
        querySelectorAll: vi.fn(() => [first, last]),
    };
    return {
        sheet: fakeEl(),
        backdrop: fakeEl(),
        panel,
        trigger: fakeEl(),
        first,
        last,
    };
}

describe('createMenuSheet', () => {
    it('open() agrega la clase is-open, marca aria-hidden=false y enfoca el primer elemento focuseable', () => {
        const els = makeEls();
        const doc = fakeDoc();
        const sheet = createMenuSheet(els, doc);

        sheet.open();

        expect(els.sheet._classes.has('is-open')).toBe(true);
        expect(els.sheet._attrs['aria-hidden']).toBe('false');
        expect(els.trigger._attrs['aria-expanded']).toBe('true');
        expect(els.first.focus).toHaveBeenCalledTimes(1);
        expect(sheet.isOpen()).toBe(true);
    });

    it('close() quita is-open, marca aria-hidden=true y devuelve el foco a quien lo tenía antes de abrir', () => {
        const els = makeEls();
        const previouslyFocused = fakeFocusable('previously-focused');
        const doc = fakeDoc();
        doc._setActiveElement(previouslyFocused);
        const sheet = createMenuSheet(els, doc);

        sheet.open();
        sheet.close();

        expect(els.sheet._classes.has('is-open')).toBe(false);
        expect(els.sheet._attrs['aria-hidden']).toBe('true');
        expect(els.trigger._attrs['aria-expanded']).toBe('false');
        expect(previouslyFocused.focus).toHaveBeenCalledTimes(1);
        expect(sheet.isOpen()).toBe(false);
    });

    it('Escape cierra el sheet cuando está abierto', () => {
        const els = makeEls();
        const doc = fakeDoc();
        const sheet = createMenuSheet(els, doc);

        sheet.open();
        doc._fireKeydown({ key: 'Escape', preventDefault: vi.fn() });

        expect(sheet.isOpen()).toBe(false);
    });

    it('Tab en el último elemento focuseable vuelve al primero (focus trap)', () => {
        const els = makeEls();
        const doc = fakeDoc();
        doc._setActiveElement(els.last);
        const sheet = createMenuSheet(els, doc);
        sheet.open();

        const event = { key: 'Tab', shiftKey: false, preventDefault: vi.fn() };
        doc._fireKeydown(event);

        expect(event.preventDefault).toHaveBeenCalledTimes(1);
        expect(els.first.focus).toHaveBeenCalledTimes(2); // 1 al abrir + 1 por el trap
    });

    it('click en el trigger alterna abierto/cerrado', () => {
        const els = makeEls();
        const doc = fakeDoc();
        createMenuSheet(els, doc);

        const triggerClickHandler = els.trigger.addEventListener.mock.calls
            .find(([type]) => type === 'click')[1];

        expect(els.sheet._classes.has('is-open')).toBe(false);
        triggerClickHandler();
        expect(els.sheet._classes.has('is-open')).toBe(true);
        triggerClickHandler();
        expect(els.sheet._classes.has('is-open')).toBe(false);
    });
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `npm run test -- menu-sheet`
Expected: FAIL — `Failed to resolve import "./menu-sheet.js"` (el módulo todavía no existe).

- [ ] **Step 3: Implementar `menu-sheet.js`**

Crear `resources/js/attendances/mark/menu-sheet.js`:
```js
/**
 * =============================================================================
 * MARK.JS — MENÚ DE ACCIONES SECUNDARIAS (HOJA INFERIOR)
 * =============================================================================
 *
 * @fileoverview Abrir/cerrar el menú `⋮` de mark.js con foco atrapado dentro
 * mientras está abierto (accesibilidad). Elementos DOM inyectados como
 * parámetros, no `document.getElementById` interno — mismo patrón de
 * `resources/js/shared/theme-toggle.js` (doc = document inyectable) y
 * `resources/js/attendances/terminal/screen-state.js` (elementos por
 * parámetro), para poder testear con objetos falsos sin jsdom.
 */

/**
 * @param {{ sheet: HTMLElement, backdrop: HTMLElement, panel: HTMLElement, trigger: HTMLElement }} els
 * @param {Document} [doc] - inyectable para tests; document en producción
 * @returns {{ open: () => void, close: () => void, isOpen: () => boolean }}
 */
export function createMenuSheet(els, doc = document) {
    let open = false;
    let lastFocused = null;

    function getFocusable() {
        return Array.from(els.panel.querySelectorAll('button:not([disabled])'))
            .filter((el) => !el.classList.contains('hidden'));
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            closeSheet();
            return;
        }
        if (event.key !== 'Tab') return;

        const focusable = getFocusable();
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey && doc.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && doc.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function openSheet() {
        if (open) return;
        open = true;
        lastFocused = doc.activeElement;
        els.sheet.classList.add('is-open');
        els.sheet.setAttribute('aria-hidden', 'false');
        els.trigger.setAttribute('aria-expanded', 'true');
        doc.addEventListener('keydown', onKeydown);
        getFocusable()[0]?.focus();
    }

    function closeSheet() {
        if (!open) return;
        open = false;
        els.sheet.classList.remove('is-open');
        els.sheet.setAttribute('aria-hidden', 'true');
        els.trigger.setAttribute('aria-expanded', 'false');
        doc.removeEventListener('keydown', onKeydown);
        lastFocused?.focus?.();
    }

    els.backdrop.addEventListener('click', closeSheet);
    els.trigger.addEventListener('click', () => (open ? closeSheet() : openSheet()));

    return { open: openSheet, close: closeSheet, isOpen: () => open };
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `npm run test -- menu-sheet`
Expected: PASS (5 tests)

- [ ] **Step 5: Crear el componente Blade del sheet**

Crear `resources/views/components/attendance/mark-menu-sheet.blade.php`:
```blade
{{--
    Hoja inferior con las acciones secundarias de mark.js. Reemplaza los
    botones sueltos que antes vivían en el header (tema, instalar) y en
    .sync-status-row (sincronizar, mis marcaciones, pausar cámara,
    desvincular) — mismos IDs que ya maneja mark.js, sin cambios de lógica.
--}}
<div id="menuSheet" class="menu-sheet" aria-hidden="true">
    <div id="menuSheetBackdrop" class="menu-sheet-backdrop"></div>
    <div id="menuSheetPanel" class="menu-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="menuSheetTitle">
        <div class="menu-sheet-handle" aria-hidden="true"></div>
        <h2 id="menuSheetTitle" class="sr-only">Más opciones</h2>

        <span class="sync-status-text" id="syncStatusText" aria-live="polite"></span>

        <button type="button" id="btnSyncNow" class="menu-sheet-item" aria-label="Sincronizar marcaciones pendientes">
            <svg class="menu-sheet-item-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="23 4 23 10 17 10"/>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
            </svg>
            Sincronizar
        </button>

        <button type="button" id="btnMyEvents" class="menu-sheet-item" aria-label="Ver mis marcaciones de hoy">
            <svg class="menu-sheet-item-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            Mis marcaciones
        </button>

        <button type="button" id="btnCameraPause" class="menu-sheet-item" aria-pressed="false" aria-label="Pausar identificación por cámara">
            <svg class="menu-sheet-item-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
                <circle cx="12" cy="13" r="4"/>
            </svg>
            <span id="btnCameraPauseLabel">Pausar cámara</span>
        </button>

        <div class="menu-sheet-item menu-sheet-item--theme">
            <x-theme-toggle-button />
            <span class="menu-sheet-item-label">Tema claro/oscuro</span>
        </div>

        <button type="button" id="btnInstallApp" class="menu-sheet-item hidden" aria-label="Instalar aplicación">
            <svg class="menu-sheet-item-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Instalar app
        </button>

        <button type="button" id="btnUnlinkDevice" class="menu-sheet-item menu-sheet-item--danger" aria-label="Desvincular este dispositivo">
            Desvincular dispositivo
        </button>
    </div>
</div>
```

- [ ] **Step 6: Reescribir el header y quitar `.sync-status-row` en `mark.blade.php`**

En `resources/views/attendances/mark.blade.php`, reemplazar el bloque del header (líneas 67–82):
```html
<header class="app-header">
    <div class="app-header-brand">
        <span class="app-mode-badge">Marcación Facial</span>
        <img id="headerLogo" class="header-logo hidden" alt="">
        <span id="headerLocation" class="app-location-badge"></span>
        <x-theme-toggle-button />
        <button type="button" id="btnInstallApp" class="install-toggle hidden" aria-label="Instalar aplicación">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
        </button>
    </div>
    <div class="app-clock" id="headerClock" aria-live="off" aria-label="Hora actual"></div>
</header>
```
por:
```html
<header class="app-header">
    <div class="app-header-brand">
        <span class="app-mode-badge">Marcación facial</span>
        <img id="headerLogo" class="header-logo hidden" alt="">
        <span id="headerLocation" class="app-location-badge"></span>
    </div>
    <div class="app-header-right">
        <span id="syncStatusDot" class="sync-status-dot" aria-hidden="true"></span>
        <button type="button" id="btnMenu" class="menu-trigger" aria-haspopup="dialog"
            aria-expanded="false" aria-controls="menuSheet" aria-label="Más opciones">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="5" r="1.5"/>
                <circle cx="12" cy="12" r="1.5"/>
                <circle cx="12" cy="19" r="1.5"/>
            </svg>
        </button>
        <div class="app-clock" id="headerClock" aria-live="off" aria-label="Hora actual"></div>
    </div>
</header>

<x-attendance.mark-menu-sheet />
```

Eliminar por completo el bloque `.sync-status-row` (el comentario y el `<div>` completo, líneas 116–141 del archivo original):
```html
    {{-- Estado de sincronización offline + sync manual — paridad con el botón "Sincronizar"
    del terminal (terminal-idle.blade.php). --}}
    <div class="sync-status-row" id="syncStatusRow">
        ...
    </div>
```
(el contenido de este bloque ya vive ahora en `mark-menu-sheet.blade.php`, agregado en el Step 5).

- [ ] **Step 7: Wirear el nuevo botón `⋮` en `mark.js`**

En `resources/js/attendances/mark.js`, agregar el import junto a los demás imports de `mark/` (después de la línea del import de `ui-feedback.js`):
```js
import { createMenuSheet } from './mark/menu-sheet.js';
```

Agregar las constantes de los nuevos elementos junto al resto de refs del header (cerca de `const cameraPausedOverlay = document.getElementById("cameraPausedOverlay");`, línea 129 original):
```js
    const menuSheet        = document.getElementById("menuSheet");
    const menuSheetBackdrop = document.getElementById("menuSheetBackdrop");
    const menuSheetPanel   = document.getElementById("menuSheetPanel");
    const btnMenu          = document.getElementById("btnMenu");
```

Inicializar el sheet — agregar cerca de donde se llama `initThemeToggle("mark-theme");` (buscar esa línea; es una llamada de inicialización a nivel de módulo, no dentro de una función anidada):
```js
    if (menuSheet && menuSheetBackdrop && menuSheetPanel && btnMenu) {
        createMenuSheet({
            sheet: menuSheet,
            backdrop: menuSheetBackdrop,
            panel: menuSheetPanel,
            trigger: btnMenu,
        });
    }
```

- [ ] **Step 8: Agregar el punto de estado de sincronización**

En `resources/js/attendances/mark/sync-status-ui.js`, reemplazar la función `refreshSyncStatus`:
```js
export async function refreshSyncStatus() {
    const [pending, conflicts] = await Promise.all([countPendingEvents(), countConflictEvents()]);

    if (conflicts > 0) {
        updateSyncStatus(`${conflicts} marcación(es) requieren revisión`);
    } else if (pending > 0) {
        updateSyncStatus(`${pending} marcación(es) pendiente(s) de sincronizar`);
    } else {
        updateSyncStatus(navigator.onLine ? "Sincronizado" : "Sin conexión — usando datos locales");
    }

    const conflictBanner = document.getElementById("conflictBanner");
    if (conflictBanner) {
        conflictBanner.classList.toggle("is-visible", conflicts > 0);
        conflictBanner.setAttribute("aria-hidden", String(conflicts === 0));
    }
}
```
por:
```js
export async function refreshSyncStatus() {
    const [pending, conflicts] = await Promise.all([countPendingEvents(), countConflictEvents()]);
    const dot = document.getElementById("syncStatusDot");
    const isPending = conflicts > 0 || pending > 0;

    if (conflicts > 0) {
        updateSyncStatus(`${conflicts} marcación(es) requieren revisión`);
    } else if (pending > 0) {
        updateSyncStatus(`${pending} marcación(es) pendiente(s) de sincronizar`);
    } else {
        updateSyncStatus(navigator.onLine ? "Sincronizado" : "Sin conexión — usando datos locales");
    }

    if (dot) {
        dot.classList.toggle("sync-status-dot--pending", isPending);
        dot.classList.toggle("sync-status-dot--synced", !isPending);
    }

    const conflictBanner = document.getElementById("conflictBanner");
    if (conflictBanner) {
        conflictBanner.classList.toggle("is-visible", conflicts > 0);
        conflictBanner.setAttribute("aria-hidden", String(conflicts === 0));
    }
}
```

- [ ] **Step 9: Agregar el CSS nuevo y eliminar el CSS muerto**

En `resources/css/attendances/styles.css`, eliminar por completo los bloques (ya no los usa ningún markup):
- `.install-toggle` y sus reglas relacionadas (líneas 127–139 originales, hasta antes de `.install-banner`)
- `.sync-status-row`, `.sync-status-text`, `.sync-status-btn` y sus variantes (líneas 188–232 originales, hasta antes de `.app-mode-badge`)

Agregar, en su lugar (misma zona del archivo, sección HEADER / sección nueva antes de `.app-mode-badge`):
```css
.app-header-right { display: flex; align-items: center; gap: var(--sp-3); }

.menu-trigger {
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
.menu-trigger:hover { background: var(--c-primary-bg); color: var(--c-primary); border-color: var(--c-primary); }
.menu-trigger svg { width: 18px; height: 18px; display: block; }

.sync-status-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--c-subtle);
    flex-shrink: 0;
}
.sync-status-dot--synced  { background: var(--c-success); }
.sync-status-dot--pending { background: var(--c-warning); }

/* ============================================================
   MENÚ DE ACCIONES SECUNDARIAS (HOJA INFERIOR)
   ============================================================ */
.menu-sheet {
    position: fixed; inset: 0; z-index: 1000;
    display: flex; align-items: flex-end; justify-content: center;
    visibility: hidden;
    pointer-events: none;
}
.menu-sheet.is-open { visibility: visible; pointer-events: auto; }

.menu-sheet-backdrop {
    position: absolute; inset: 0;
    background: rgba(15,23,42,.72);
    opacity: 0;
    transition: opacity var(--tr);
}
.menu-sheet.is-open .menu-sheet-backdrop { opacity: 1; }

.menu-sheet-panel {
    position: relative;
    width: 100%;
    max-width: 540px;
    background: var(--c-surface);
    border-radius: var(--r-xl) var(--r-xl) 0 0;
    padding: var(--sp-3) var(--sp-4) calc(var(--sp-4) + env(safe-area-inset-bottom, 0px));
    box-shadow: var(--sh-xl);
    display: flex;
    flex-direction: column;
    gap: var(--sp-1);
    transform: translateY(100%);
    transition: transform var(--tr-s);
}
.menu-sheet.is-open .menu-sheet-panel { transform: translateY(0); }

.menu-sheet-handle {
    width: 36px; height: 4px;
    border-radius: 99px;
    background: var(--c-border-s);
    margin: 0 auto var(--sp-2);
}

.menu-sheet .sync-status-text {
    display: block;
    text-align: center;
    font-size: .8125rem;
    color: var(--c-muted);
    padding-bottom: var(--sp-2);
    border-bottom: 1px solid var(--c-border);
    margin-bottom: var(--sp-1);
}

.menu-sheet-item {
    display: flex; align-items: center; gap: var(--sp-3);
    width: 100%;
    padding: var(--sp-3) var(--sp-2);
    background: transparent;
    border: none;
    border-radius: var(--r-md);
    font: inherit;
    font-size: .9375rem;
    font-weight: 500;
    color: var(--c-text);
    cursor: pointer;
    text-align: left;
    transition: background var(--tr-f);
}
.menu-sheet-item:hover { background: var(--c-bg); }
.menu-sheet-item[aria-pressed="true"] { color: var(--c-warning); }
.menu-sheet-item-icon { width: 20px; height: 20px; flex-shrink: 0; color: var(--c-muted); }

.menu-sheet-item--danger {
    color: var(--c-danger);
    border-top: 1px solid var(--c-border);
    margin-top: var(--sp-1);
    padding-top: var(--sp-4);
}
.menu-sheet-item--danger:hover { background: var(--c-danger-bg); }

.menu-sheet-item--theme { cursor: default; }
.menu-sheet-item--theme .theme-toggle { width: 32px; height: 32px; }
.menu-sheet-item-label { font-size: .9375rem; font-weight: 500; color: var(--c-text); }
```

- [ ] **Step 10: Build, tests y verificación manual**

```bash
npm run build
npm run test
php artisan test --compact --filter=Mark
```
Expected: todo en verde. Luego abrir `/marcar` en el navegador: confirmar que el header muestra solo logo/ubicación a la izquierda y reloj + `⋮` a la derecha, que tocar `⋮` abre la hoja inferior con las 6 acciones, que Escape y tocar el fondo la cierran, y que cada acción (Sincronizar, Mis marcaciones, Pausar cámara, Desvincular, Tema, Instalar si aplica) sigue funcionando igual que antes.

- [ ] **Step 11: Pint + commit**

```bash
vendor/bin/pint --dirty
git add resources/views/components/attendance/mark-menu-sheet.blade.php \
        resources/js/attendances/mark/menu-sheet.js \
        resources/js/attendances/mark/menu-sheet.test.js \
        resources/views/attendances/mark.blade.php \
        resources/js/attendances/mark.js \
        resources/js/attendances/mark/sync-status-ui.js \
        resources/css/attendances/styles.css
git commit -m "feat: consolidar header de mark.js en menú de acciones (hoja inferior)

Resuelve el bug de superposición entre el toggle de tema y el botón de
instalar PWA — ambos, junto con Sincronizar/Mis marcaciones/Pausar
cámara/Desvincular, se mudan a un único menú ⋮ con foco atrapado. El
header queda solo con logo/ubicación y reloj + punto de estado de
sincronización."
```

---

### Task 3: Stepper visual de 2 pasos

**Files:**
- Create: `resources/views/components/attendance/mark-stepper.blade.php`
- Modify: `resources/views/components/attendance/video-section.blade.php`
- Modify: `resources/views/components/attendance/form-section.blade.php`
- Modify: `resources/css/attendances/styles.css`

**Interfaces:** Ninguna en JS — cada sección hardcodea su propio paso activo vía prop Blade (`:active="1"` / `:active="2"`), no requiere sincronización en tiempo real porque solo una sección está visible a la vez (la otra tiene `.hidden`).

- [ ] **Step 1: Crear el componente stepper**

Crear `resources/views/components/attendance/mark-stepper.blade.php`:
```blade
@props(['active' => 1])

<div class="mark-stepper" role="list" aria-label="Progreso de marcación">
    <div class="mark-stepper-step {{ $active >= 1 ? 'is-active' : '' }} {{ $active > 1 ? 'is-done' : '' }}" role="listitem">
        <span class="mark-stepper-dot" aria-hidden="true">1</span>
        <span class="mark-stepper-label">Identificación</span>
    </div>
    <span class="mark-stepper-line {{ $active > 1 ? 'is-done' : '' }}" aria-hidden="true"></span>
    <div class="mark-stepper-step {{ $active >= 2 ? 'is-active' : '' }}" role="listitem">
        <span class="mark-stepper-dot" aria-hidden="true">2</span>
        <span class="mark-stepper-label">Confirmación</span>
    </div>
</div>
```

- [ ] **Step 2: Reemplazar el título de Paso 1**

En `resources/views/components/attendance/video-section.blade.php`, reemplazar:
```html
    <div class="card-header">
        <h2 id="{{ $sectionId }}" tabindex="-1">Paso 1 &middot; Identificación</h2>
    </div>
```
por:
```html
    <div class="card-header">
        <h2 id="{{ $sectionId }}" tabindex="-1" class="sr-only">Paso 1 · Identificación</h2>
        <x-attendance.mark-stepper :active="1" />
    </div>
```
(el `<h2>` se conserva oculto visualmente pero accesible a lectores de pantalla — sigue siendo el `aria-labelledby` de la `<section>`, no se puede eliminar sin romper el `role="region"`).

- [ ] **Step 3: Reemplazar el título de Paso 2**

En `resources/views/components/attendance/form-section.blade.php`, reemplazar:
```html
    <div class="card-header">
        <h2 id="{{ $sectionId }}" tabindex="-1">Paso 2 &middot; Datos de marcación</h2>
    </div>
```
por:
```html
    <div class="card-header">
        <h2 id="{{ $sectionId }}" tabindex="-1" class="sr-only">Paso 2 · Datos de marcación</h2>
        <x-attendance.mark-stepper :active="2" />
    </div>
```

- [ ] **Step 4: Agregar el CSS del stepper**

En `resources/css/attendances/styles.css`, agregar después del bloque `.card-header h2` (cerca de la línea 485 original):
```css
/* ============================================================
   STEPPER DE PROGRESO (Paso 1 / Paso 2)
   ============================================================ */
.mark-stepper {
    display: flex;
    align-items: center;
    gap: var(--sp-2);
    width: 100%;
}
.mark-stepper-step { display: flex; align-items: center; gap: var(--sp-2); }
.mark-stepper-dot {
    display: flex; align-items: center; justify-content: center;
    width: 24px; height: 24px;
    border-radius: 50%;
    font-size: .75rem;
    font-weight: 700;
    background: var(--c-bg);
    border: 1px solid var(--c-border-s);
    color: var(--c-muted);
    flex-shrink: 0;
    transition: background var(--tr-f), border-color var(--tr-f), color var(--tr-f);
}
.mark-stepper-label {
    font-size: .8125rem;
    font-weight: 600;
    color: var(--c-muted);
    white-space: nowrap;
    transition: color var(--tr-f);
}
.mark-stepper-line {
    flex: 1;
    height: 2px;
    background: var(--c-border-s);
    min-width: 16px;
    transition: background var(--tr-f);
}
.mark-stepper-step.is-active .mark-stepper-dot { background: var(--c-primary); border-color: var(--c-primary); color: #fff; }
.mark-stepper-step.is-active .mark-stepper-label { color: var(--c-text); }
.mark-stepper-step.is-done .mark-stepper-dot { background: var(--c-success); border-color: var(--c-success); color: #fff; }
.mark-stepper-line.is-done { background: var(--c-success); }
```

- [ ] **Step 5: Build y verificación manual**

```bash
npm run build
php artisan test --compact --filter=Mark
```
Abrir `/marcar`: confirmar que el Paso 1 muestra el stepper con "1" activo (teal) y "2" pendiente (gris), y que al identificarse el Paso 2 muestra "1" completado (verde, línea verde) y "2" activo.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty
git add resources/views/components/attendance/mark-stepper.blade.php \
        resources/views/components/attendance/video-section.blade.php \
        resources/views/components/attendance/form-section.blade.php \
        resources/css/attendances/styles.css
git commit -m "feat: stepper visual de 2 pasos en mark.js, reemplaza el título de texto plano"
```

---

### Task 4: Marco de escaneo en el óvalo de cámara

**Files:**
- Modify: `resources/views/components/attendance/video-section.blade.php`
- Modify: `resources/css/attendances/styles.css`

**Interfaces:** Ninguna — los corchetes son puramente decorativos/CSS, cambian de color reutilizando las clases de estado que `setVideoState()` ya aplica a `#videoWrap` (`video-wrap--detecting`, `video-wrap--face-found`, `video-wrap--success`, `video-wrap--error`), sin tocar JS.

- [ ] **Step 1: Agregar los 4 corchetes al markup**

En `resources/views/components/attendance/video-section.blade.php`, dentro de `<div class="video-wrap" id="videoWrap" ...>`, después del cierre de `.face-guide` (después de `</div>` que cierra `<div class="face-guide" ...>`, antes de `#captureProgress`):
```html
            <span class="scan-corner scan-corner--tl" aria-hidden="true"></span>
            <span class="scan-corner scan-corner--tr" aria-hidden="true"></span>
            <span class="scan-corner scan-corner--bl" aria-hidden="true"></span>
            <span class="scan-corner scan-corner--br" aria-hidden="true"></span>
```

- [ ] **Step 2: Agregar el CSS de los corchetes**

En `resources/css/attendances/styles.css`, agregar después del bloque `@keyframes oval-pulse` (línea 406 original):
```css
/* Marco de escaneo — corchetes en las esquinas del video, sin animación de
   brillo/pulso (ya existe oval-pulse en el óvalo; no se agrega una segunda). */
.scan-corner {
    position: absolute;
    width: 22px;
    height: 22px;
    border: 2px solid var(--c-primary);
    z-index: 2;
    pointer-events: none;
    opacity: .85;
}
.scan-corner--tl { top: 6%; left: 22%; border-right: none; border-bottom: none; border-radius: var(--r) 0 0 0; }
.scan-corner--tr { top: 6%; right: 22%; border-left: none; border-bottom: none; border-radius: 0 var(--r) 0 0; }
.scan-corner--bl { bottom: 6%; left: 22%; border-right: none; border-top: none; border-radius: 0 0 0 var(--r); }
.scan-corner--br { bottom: 6%; right: 22%; border-left: none; border-top: none; border-radius: 0 0 var(--r) 0; }
.video-wrap--detecting  .scan-corner { border-color: var(--c-warning); }
.video-wrap--face-found .scan-corner,
.video-wrap--success    .scan-corner { border-color: var(--c-success); }
.video-wrap--error      .scan-corner { border-color: var(--c-danger); }
```

- [ ] **Step 3: Build y verificación manual**

```bash
npm run build
php artisan test --compact --filter=Mark
```
Abrir `/marcar`, iniciar la cámara y confirmar que los 4 corchetes aparecen alrededor del óvalo y cambian de color junto con el óvalo según el estado (buscando/detectado/error) — sin animación de brillo.

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint --dirty
git add resources/views/components/attendance/video-section.blade.php resources/css/attendances/styles.css
git commit -m "feat: marco de escaneo con corchetes en el óvalo de cámara de mark.js"
```

---

## Self-Review

**Cobertura del spec:**
1. Consolidación del header + menú ⋮ con estado de sync → Task 2. ✅
2. Badge de modo sentence-case → Task 1. ✅
3. Stepper visual de 2 pasos → Task 3. ✅
4. Marco de escaneo con corchetes → Task 4. ✅
5. Re-estilizado del status bar existente → revisado contra el código real: `.status-bar` (styles.css:411-430) ya usa tokens semánticos, ya tiene tratamiento por estado con borde de acento, y ya combina visualmente con los tokens que también usan los corchetes nuevos (`var(--c-warning)`, `var(--c-success)`, `var(--c-danger)`, `var(--c-primary)`). No hace falta ninguna edición — anotado explícitamente en vez de inventar un cambio cosmético sin motivo real.
6. Banners offline/conflicto, splash, éxito → confirmado fuera de alcance, no se tocan en ningún task.

**Placeholders:** ninguno — cada step tiene código completo, sin "TBD" ni "ver más arriba".

**Consistencia de tipos/nombres:** `createMenuSheet(els, doc)` se define igual en Task 2 Step 3 y se consume igual en Step 7; los 6 IDs de botones (`btnSyncNow`, `btnMyEvents`, `btnCameraPause`, `btnCameraPauseLabel`, `btnUnlinkDevice`, `btnInstallApp`, `btnThemeToggle`) se mantienen idénticos entre el markup viejo (Task 2 Step 6, removido) y el nuevo (Task 2 Step 5) — verificado contra `mark.js` línea por línea antes de escribir el plan.
