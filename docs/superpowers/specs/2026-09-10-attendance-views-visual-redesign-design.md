# Sub-proyecto E: Rediseño visual/UX de las vistas públicas de marcación — Spec

**Fecha:** 2026-09-10
**Secuencia:** A (sistema de diseño unificado) → F (theming dinámico) → B (instalabilidad PWA) → **E (este documento)** → D (reestructuración de terminal.js) → C (unificación del motor offline)

## Contexto

A dejó un sistema de tokens de diseño unificado (`resources/css/shared/tokens.css`) y lo aplicó de forma consistente en `mark.blade.php` (celular) y `terminal.blade.php` (kiosko) — ambas ya están componentizadas (`x-attendance.*`), respetan claro/oscuro vía `data-theme`, y B les agregó instalabilidad PWA (manifests dinámicos, banners de instalación).

El resto de las vistas públicas del módulo de marcación quedaron rezagadas: `device-link.blade.php` (vinculación de dispositivo personal), la vista compartida `shared/capture-face.blade.php` (captura facial, usada tanto para auto-registro como para re-captura por un admin), y 4 pantallas de estado/error (`terminal-inactive`, `terminal-setup-invalid`, `enrollments/already-submitted`, `enrollments/expired`). Todas ellas tienen colores hardcodeados (algunas con CSS externo, otras con `<style>` inline completo), no respetan el toggle de tema, y en algunos casos usan patrones de JS obsoletos (script inline, `onclick=""`, `style="display:none"`).

Este sub-proyecto nivela esas vistas al mismo estándar visual y de código que ya tienen mark/terminal, sin rediseñar desde cero lo que ya funciona bien.

## Alcance

**Dentro de alcance:**
1. `device-link.blade.php` + `device-link.css` + JS inline → módulo Vite
2. `shared/capture-face.blade.php` (afecta también a sus dos consumidores: `enrollments/capture-face.blade.php` y `employees/capture-face.blade.php`) + `capture-face.css` + JS
3. 4 pantallas de estado: `terminal-inactive.blade.php`, `terminal-setup-invalid.blade.php`, `enrollments/already-submitted.blade.php`, `enrollments/expired.blade.php`
4. Extracción de un componente `<x-theme-toggle-button />` + módulo JS compartido, consumido también por `mark.blade.php`/`terminal.blade.php` (reemplazando su bloque duplicado, sin más cambios en esas dos vistas)
5. Pequeño cambio de backend: `MobileLinkController::claim()` agrega `company_name`/`company_logo` a la respuesta JSON de éxito

**Fuera de alcance:**
- Cualquier cambio visual adicional en `mark.blade.php`/`terminal.blade.php` más allá de consumir `<x-theme-toggle-button />`
- Lógica de captura facial / dwell detection (`FaceCaptureApp.js`, `face-capture-core.js`) — es motor, no UI
- Discriminar el mensaje de error de `device-link` por campo (CI vs fecha) — decisión de seguridad deliberada documentada en el controller, se mantiene el mensaje genérico
- Soporte PWA/offline en `device-link` o `capture-face` (son flujos que requieren conexión por diseño — identidad/enrolamiento)
- Reestructuración de `terminal.js` (sub-proyecto D) y unificación del motor offline mobile/terminal (sub-proyecto C)

## Diseño

### 1. Componente compartido: toggle de tema

- `resources/views/components/theme-toggle-button.blade.php` — el mismo botón SVG luna/sol que hoy está duplicado en `mark.blade.php` y `terminal.blade.php`.
- `resources/js/shared/theme-toggle.js` — exporta `initThemeToggle(storageKey, doc = document, win = window, storage = localStorage)`. Aplica el tema guardado (o `prefers-color-scheme` si no hay nada guardado) seteando `data-theme` en `<html>`, y engancha el click del botón `#btnThemeToggle` para alternar y persistir en `storage` bajo `storageKey`. Mismo patrón dependency-injected que `install-prompt.js` (sub-proyecto B) — testeable con Vitest sin jsdom.
- `mark.js` y `terminal.js` reemplazan su IIFE de tema duplicada por `initThemeToggle('mark-theme')` / `initThemeToggle('terminal-theme')` respectivamente (siguen sin compartir estado entre sí, a propósito — mismo dispositivo podría usarse en los dos modos).
- `device-link.js` y `capture-face.js` (o el módulo que corresponda) usan `initThemeToggle('device-link-theme')` / `initThemeToggle('capture-face-theme')`.

### 2. `device-link.blade.php`

**JS:** se crea `resources/js/attendances/device-link.js` como entry point Vite (agregado a `vite.config.js`), migrando el `<script>` inline completo: mismo comportamiento (submit del form, fetch a `claim()`, manejo de "ya vinculado", `getClientHintModel()`), pero con `addEventListener` en vez de atributos inline y estructura de módulo testeable.

**CSS:** `device-link.css` reemplaza sus colores hardcodeados (`--color-surface-900`, `--color-surface-800`, etc. usados directo) por los tokens semánticos (`--c-bg`, `--c-surface`, `--c-text`, `--c-muted`, `--c-border`, `--c-danger-bg`) que ya responden a `data-theme`/`prefers-color-scheme`. Se agrega un `<header>` mínimo (hoy no existe — el `.container` va directo dentro de `<body>`) que aloja `<x-theme-toggle-button />`. Sin logo/marca en este header — el formulario es neutro porque el empleado todavía no fue identificado.

**Branding post-éxito:** `MobileLinkController::claim()` agrega al JSON de éxito:
```php
'employee' => [
    ...
    'company_name' => $employee->branch?->company?->name,
    'company_logo' => $employee->branch?->company?->logo_thumbnail,
],
```
El JS muestra el logo (si existe) junto al mensaje "Dispositivo vinculado. ¡Hola, {nombre}!" antes del redirect a `/marcar` — mismo patrón de `<img>` con data URI que ya usan `mark.js`/`terminal.js` para `headerLogo`.

**Error genérico:** el mensaje de `.status--error` se mantiene sin discriminar campo. Se le agrega tratamiento visual de alerta: ícono de advertencia + fondo `--c-danger-bg` + borde `--c-danger-ring`, reutilizando la misma composición visual que los modales de error de `mark.blade.php` (sin convertirlo en modal — sigue siendo inline bajo el form).

**Aviso "ya vinculado":** sin cambios de lógica ni de estructura HTML — solo migra sus colores a los tokens semánticos.

### 3. `shared/capture-face.blade.php`

**face-api.js local:** se reemplaza
```html
<script defer src="https://unpkg.com/face-api.js@0.22.2/dist/face-api.min.js" crossorigin="anonymous" referrerpolicy="no-referrer" onerror="handleScriptError()"></script>
```
por
```html
<script defer src="{{ asset('js/face-api.min.js') }}"></script>
```
Se elimina el `<link rel="preconnect" href="https://unpkg.com">` del `<head>` y la función `handleScriptError()` (ya no hay carga externa que pueda fallar en este punto).

**Patrones JS:** `onclick="handleCancel()"` en el botón Cancelar → `addEventListener('click', handleCancel)` registrado desde el módulo JS (`resources/js/shared/capture-face.js` o `FaceCaptureApp.js`, según dónde viva mejor). El modal de confirmación cambia `style="display: none;"` por la clase `.hidden` ya usada en el resto del proyecto (mostrar/ocultar vía `classList`, no `style` inline).

**Toggle de tema:** se agrega `<x-theme-toggle-button />` al `.app-header` existente (ya usa tokens semánticos correctos vía `capture-face.css`, solo le falta el botón — hoy solo seguía `prefers-color-scheme` sin override manual).

**Consumidores:** `enrollments/capture-face.blade.php` (modo `enrollment`) y `employees/capture-face.blade.php` (modo `employee`, admin re-captura) heredan todos estos cambios automáticamente al ser wrappers finos de `shared/capture-face.blade.php` — no requieren cambios propios.

### 4. Pantallas de estado compartidas

Nuevo componente `resources/views/components/status-page.blade.php`:

```blade
@props([
    'icon',       // path(s) SVG del ícono central, HTML crudo del <path>
    'variant' => 'danger', // 'danger' | 'success' | 'warning' — color de ícono/label
    'label' => null,       // texto superior opcional en mayúsculas (ej. "Registro Enviado")
    'title',
    'description' => [],   // array de párrafos
])
```

Usa tokens semánticos (`--c-bg`, `--c-surface`, `--c-text`, `--c-muted`, y `--c-{variant}`/`--c-{variant}-bg` para el círculo del ícono) vía un nuevo `resources/css/shared/status-page.css`. Respeta `data-theme`/`prefers-color-scheme` igual que el resto (sin toggle manual — son pantallas de una sola visita, sin interacción).

Los 4 archivos quedan reducidos a una invocación:
```blade
{{-- terminal-inactive.blade.php --}}
<x-status-page
    variant="danger"
    icon="<path stroke-linecap='round' stroke-linejoin='round' d='M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z' />"
    title="Terminal fuera de servicio"
    :description="['Esta terminal no está disponible en este momento.', 'Por favor, comuníquese con el administrador.']"
>
    <x-slot:extra>{{ $terminal->name }} — {{ $terminal->branch?->name }}</x-slot:extra>
</x-status-page>
```
(`terminal-setup-invalid`, `already-submitted`, `expired` análogos, sin el slot `extra`.)

## Testing

- **`theme-toggle.js`:** Vitest — aplica tema guardado, cae a `prefers-color-scheme` si no hay nada guardado, alterna y persiste al hacer click, con `storage`/`doc`/`win` fake inyectados.
- **`device-link.js`:** Vitest — submit exitoso, error genérico mostrado, flujo "ya vinculado" (cancelar/continuar), branding mostrado cuando `company_logo` viene en la respuesta y omitido cuando no.
- **`MobileLinkControllerTest.php` (nuevo, Pest):** el JSON de éxito de `claim()` incluye `company_name`/`company_logo`; el mensaje de error sigue siendo el genérico documentado (no se rompe la garantía de seguridad).
- **`<x-theme-toggle-button />` y `<x-status-page>`:** tests Pest de renderizado (que el componente monta, que las 4 vistas de estado lo incluyen con las props correctas) — mismo patrón que `PwaMetaComponentTest.php` de B.
- **Verificación manual:** cada vista tocada se revisa en navegador en claro/oscuro y viewport mobile antes de cerrar su tarea — no hay forma de testear automáticamente que "se ve bien".

## Notas de implementación

- Todas las rutas afectadas son públicas (sin auth) salvo `employees/capture-face.blade.php` — mantener el patrón de throttling existente sin modificarlo.
- `vite.config.js` necesita la nueva entrada `resources/js/attendances/device-link.js`.
- Seguir la convención de PHPDoc/JSDoc y comentarios de sección Blade del proyecto (CLAUDE.md) en todo el código nuevo.
