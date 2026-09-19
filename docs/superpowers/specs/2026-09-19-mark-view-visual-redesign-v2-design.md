# Rediseño visual v2 de `mark.js` (piloto) — Spec

**Fecha:** 2026-09-19
**Relación con specs previas:** continúa el trabajo de `2026-09-10-attendance-views-visual-redesign-design.md` (sub-proyecto E), que llevó todas las vistas públicas al mismo sistema de tokens pero dejó explícitamente fuera de alcance "cualquier cambio visual adicional en mark.blade.php/terminal.blade.php". Este documento sí entra en ese frente, pero solo dentro de `mark.blade.php` y sin tocar la lógica de captura facial / dwell detection (sigue fuera de alcance, como en E — ver sección 5).

## Contexto

El sistema de tokens (`tokens.css`), la paleta teal y Poppins ya están unificados en las 6 vistas públicas desde el sub-proyecto A/E. Pese a eso, la vista de marcación móvil (`mark.blade.php`) se percibe genérica: header saturado (6 acciones sueltas, dos de las cuales — toggle de tema y botón de instalar PWA — se superponen visualmente en pantallas angostas) y títulos de paso en texto plano sin jerarquía visual. El feedback de cámara en tiempo real (`setStatusBar()`) ya existe y funciona bien — solo necesita un ajuste visual para combinar con el nuevo marco de escaneo, no un rediseño funcional.

Se evaluaron capturas de referencia con estética de mockup genérico de IA (fondo navy, anillos con glow, confetti). Se descartó adoptar esa decoración — se rescata solo la señal estructural real: stepper numerado, tarjeta de identidad agrupada, badges de estado.

## Alcance

**Dentro de alcance (piloto — solo `mark.blade.php` y su JS/CSS):**
1. Consolidación del header: logo + ubicación (izquierda), reloj (derecha), ícono `⋮` con indicador de estado de sincronización.
2. Menú `⋮` como bottom sheet, con: Sincronizar (+ última sincronización), Mis marcaciones, Pausar cámara, Desvincular dispositivo, Tema claro/oscuro, Instalar app.
3. Badge de modo (`MARCACIÓN FACIAL`) de versalitas trackeadas a texto sentence-case.
4. Stepper visual de 2 pasos (Identificación → Confirmación) reemplazando el título de texto plano en ambos pasos.
5. Marco de escaneo con corchetes en las esquinas del óvalo guía de cámara (sin anillo con glow).
6. Restyling del marco/status bar existente (`#statusBar`/`#statusDot`/`#statusText`, ya dinámico vía `setStatusBar()`) para que combine visualmente con el nuevo marco de escaneo — sin tocar su lógica.

**Fuera de alcance (explícitamente, no se tocan en este documento):**
- Terminal.js, capture-face, device-link, status-page, terminal-setup — se propagan en una fase posterior, una vez validado el piloto (incluida prueba en dispositivo real).
- Banners de "Sin conexión" y "Conflicto de sincronización" — quedan con su estilo actual.
- Splash inicial y modal de éxito — ya funcionan bien, sin cambios de estructura (el splash ya tiene el footer de marca agregado en un trabajo previo).
- Paleta de colores, tipografía, `tokens.css` — cero cambios; todo el diseño reutiliza los tokens existentes.
- Lógica de matching facial / precisión del modelo — solo se expone como texto un estado que el motor ya calcula, no se modifica el algoritmo de detección.

## Diseño

### 1. Header

**Estructura HTML** (`mark.blade.php`, dentro de `.app-header`):
```
.app-header
  .app-header-brand
    span.app-mode-badge          -- "Marcación Facial", sentence-case
    img#headerLogo
    span#headerLocation
  .app-header-actions
    span#syncStatusDot           -- punto de color sobre/junto al ícono ⋮
    button#btnMenu[aria-haspopup="dialog"]  -- ícono ⋮
  .app-clock#headerClock
```

**CSS:** `.app-mode-badge` pierde `text-transform: uppercase; letter-spacing: .1em`, pasa a `font-size: .75rem; font-weight: 600` sin tracking. Se eliminan del header los botones sueltos `<x-theme-toggle-button />` y `#btnInstallApp` (se mueven al bottom sheet, ver más abajo) — esto resuelve de raíz el bug reportado (ambos íconos competían por el mismo espacio).

**Punto de estado de sincronización:** `#syncStatusDot`, círculo de 8px, `background: var(--c-success)` cuando `lastSyncStatus === 'synced'`, `var(--c-warning)` cuando hay eventos pendientes o está sincronizando. Se alimenta de la misma variable de estado que hoy pinta el texto "Sincronizado ✓" (mark.js ya la calcula, solo se re-renderiza en un elemento nuevo en vez del texto suelto que desaparece).

### 2. Menú `⋮` (bottom sheet)

Nuevo componente `resources/views/components/attendance/mark-menu-sheet.blade.php`, reutilizando el patrón de overlay ya usado por el splash (`position: fixed; inset: 0`) pero anclado abajo (`translateY` desde `100%` a `0`, con backdrop semitransparente). Contenido, en orden:

1. Sincronizar (icono refresh + texto "Última sincronización: hace N min" o "Sincronizando…")
2. Mis marcaciones
3. Pausar cámara / Reanudar cámara (label dinámico según estado actual)
4. Desvincular dispositivo (estilo `danger`, con separador visual antes)
5. Tema claro/oscuro (toggle, reutiliza `initThemeToggle` existente)
6. Instalar app (solo visible si `beforeinstallprompt` disponible o iOS — mismo criterio que hoy)

Cierra con: tap en backdrop, botón X, o tecla Escape (accesibilidad). Foco atrapado dentro del sheet mientras está abierto.

**Testing:** JS del bottom sheet (abrir/cerrar, foco, mapeo de estado de sync al dot) se testea con Vitest en un archivo colocado junto al módulo (`mark-menu-sheet.test.js`), siguiendo la convención ya usada en el proyecto (ej. `resources/js/attendances/mark/text-helpers.test.js`) — no requiere Pest porque no toca backend.

### 3. Stepper visual (Paso 1 y Paso 2)

Nuevo componente `resources/views/components/attendance/mark-stepper.blade.php`, recibe `:active="1"` o `:active="2"`:
```html
<div class="mark-stepper" role="list" aria-label="Progreso de marcación">
    <div class="mark-stepper-step {{ $active >= 1 ? 'is-active' : '' }} {{ $active > 1 ? 'is-done' : '' }}">
        <span class="mark-stepper-dot">1</span>
        <span class="mark-stepper-label">Identificación</span>
    </div>
    <span class="mark-stepper-line {{ $active > 1 ? 'is-done' : '' }}"></span>
    <div class="mark-stepper-step {{ $active >= 2 ? 'is-active' : '' }}">
        <span class="mark-stepper-dot">2</span>
        <span class="mark-stepper-label">Confirmación</span>
    </div>
</div>
```
Reemplaza los `<h2>Paso 1 - Identificación</h2>` / `<h2>Paso 2 - Datos de marcación</h2>` actuales. Color activo `var(--c-primary)`, completado `var(--c-success)`, pendiente `var(--c-border-s)` — sin nuevos tokens.

### 4. Marco de escaneo (óvalo de cámara)

Se agregan 4 `<span>` con `border` en cada esquina (`.scan-corner--tl/tr/bl/br`) posicionados absolutos sobre el `.video-wrap`, color `var(--c-primary)`, sin animación de brillo/pulso — solo aparecen sólidos, consistentes con el resto del sistema (ya existe `splashRingPulse` como único precedente de animación sutil en la vista, no se agrega una segunda).

### 5. Estado de detección — ya existe, solo se re-estiliza

**Corrección tras revisar el código:** el texto dinámico de estado **ya está implementado** — `resources/js/attendances/mark/ui-feedback.js` exporta `setStatusBar(text, dotClass)`, llamada desde el `drawLoop` de `mark.js` en cada transición real (rostro ausente → "Coloque su rostro dentro del óvalo · 30–50 cm"; rostro muy chico → "Acérquese un poco más a la cámara"; rostro en posición → "Quédate quieto...", que dispara el dwell). También anima el cambio de texto (`status-text--new`) y colorea `#statusBar`/`#statusDot`/`#videoWrap` según el estado. No hay nada nuevo que construir en JS para esto.

Lo único que cambia en este documento es visual: el contenedor `#statusBar`/`#statusDot`/`#statusText` se re-estiliza para combinar con el nuevo marco de escaneo (corchetes en las esquinas) — mismos elementos y lógica, tratamiento CSS actualizado.

## Rollout

`mark.blade.php` está en uso real por empleados marcando asistencia en producción (Bar777, Arca, Macro/Sedacosmetica). Antes de mergear:
1. Test suite completa (Pest + Vitest) en verde.
2. Verificación manual en dispositivo real (celular Android/iOS) del flujo completo: identificación, menú `⋮` (las 6 acciones deben seguir funcionando igual que hoy, solo cambia dónde viven), confirmación, éxito.
3. Deploy en ventana de bajo uso, mismo criterio que sub-proyectos previos de esta iniciativa (sin feature flag — revert + redeploy estándar si algo falla).

## Fuera de este documento (fases futuras, no comprometidas todavía)

Una vez validado el piloto, propagar el mismo lenguaje (header consolidado, menú `⋮`, stepper donde aplique, tarjetas de estado) a `terminal.blade.php`, `device-link.blade.php`, `shared/capture-face.blade.php` y las 4 pantallas de `status-page.blade.php`. Cada vista tiene su propia lista de acciones secundarias y no todas necesitan las 6 opciones de mark.js (ej. terminal no tiene "Desvincular dispositivo" individual) — el detalle de qué va en cada menú se define en el spec de esa fase, no acá.
