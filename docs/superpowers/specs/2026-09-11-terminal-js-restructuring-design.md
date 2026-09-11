# Sub-proyecto D: Reestructuración de terminal.js — Spec

**Fecha:** 2026-09-11
**Secuencia:** A (sistema de diseño unificado) → F (theming dinámico) → B (instalabilidad PWA) → E (rediseño visual/UX) → **D (este documento)** → C (unificación del motor offline)

## Contexto

`resources/js/attendances/terminal.js` (1215 líneas) es el orquestador del modo kiosko compartido de marcación facial. Ya tiene una capa bien extraída (`terminal-offline/{db,matcher,queue,sync}.js`, `terminal/{ui-feedback,sync-status-ui,manual-search,text-helpers}.js`), pero el archivo principal sigue siendo un único closure `DOMContentLoaded` con ~30 funciones que mezclan captura de cámara, detección de presencia/idle, máquina de estados de pantallas, orquestación del flujo de identificación facial, registro de marcaciones, y el bootstrap de arranque (carga de modelos, migración legacy, sync inicial).

`mark.js` (2037 líneas, celular) tiene una estructura de importaciones casi idéntica (`mobile-offline/{db,matcher,queue,sync}.js` en paralelo a `terminal-offline/*`) y presumiblemente el mismo tipo de mezcla interna — confirmando que hay lógica sustancialmente compartida entre ambos flujos que el sub-proyecto C deberá unificar. D prepara el terreno: descompone `terminal.js` en módulos con límites claros por responsabilidad, para que C pueda extraer después solo lo verdaderamente compartido sin tener que primero desenredar un archivo de 1200 líneas.

## Alcance

**Dentro de alcance:**
- Descomponer `terminal.js` en 6 módulos nuevos bajo `resources/js/attendances/terminal/`.
- Agregar cobertura Vitest a los módulos con lógica no trivial, usando el patrón dependency-injected ya establecido (`resources/js/shared/install-prompt.js`, `resources/js/shared/theme-toggle.js`).
- `terminal.js` queda como composition root delgado: refs de DOM propios del wiring, imports, y orquestación de alto nivel sin lógica de negocio.

**Fuera de alcance:**
- `mark.js` no se toca.
- La lógica interna de `terminal-offline/{db,matcher,queue,sync}.js` no se modifica.
- Ningún cambio de comportamiento visible — refactor puro, mismo comportamiento que hoy.
- Cualquier trabajo de unificación real entre terminal/mobile offline (eso es sub-proyecto C).

## Diseño

### 1. Descomposición de módulos

Todos bajo `resources/js/attendances/terminal/`, junto a los módulos ya existentes.

**`camera.js`** — Sin DI, sin tests unitarios (APIs de MediaStream/canvas, no vale la pena simularlas). Mismo estilo que `sync-status-ui.js`/`ui-feedback.js` (DOM directo).
- `loadModels()`, `startCamera()`, `stopCamera()`, `startDrawLoop()`/`stopDrawLoop()`/`drawLoop()`, `captureDescriptor(samples, intervalMs, onProgress)`

**`idle-detection.js`** — DI (`doc`/`win`/`nav` inyectables, default a los globals reales; timers vía `vi.useFakeTimers()` en los tests, sin inyección de función de timer). Tests: transición idle↔activo, reset del timer al detectar actividad, wake lock solicitado/no-op si `navigator.wakeLock` no existe.
- `resetIdleTimer()`, `clearIdleTimer()`, `enterIdle()`, `exitIdle()`, `startPresenceCheck()`, `presenceCheckLoop()`, `stopPresenceCheck()`, `acquireWakeLock()`

**`screen-state.js`** — DI (`doc` inyectable). Tests: `showScreen(name)` aplica la clase visible correcta y oculta el resto; `startCountdown`/`startDayCompleteCountdown` decrementan y disparan su callback al llegar a 0 (fake timers).
- `showScreen(screenName)`, `showSuccessScreen(employee, markData, eventType, opts)`, `showError(message)`, `showDayComplete(employee)`, `startDayCompleteCountdown(seconds)`, `startCountdown(seconds)`, `stopCountdown()`, `resetTerminal()`

**`identification-flow.js`** — DI (recibe el descriptor, la lista de empleados cacheados, la función de matching y la config de umbral/gap como parámetros o importaciones inyectables). Tests: decisión de match/no-match/ambiguo según distancia del descriptor y el gap de confianza — la lógica de negocio con más valor de reuso futuro para C.
- `identifyEmployee(descriptor)`, `startAutoIdentification()`, `stopAutoIdentification()`, `startIdentificationFlow()`

**`mark-registration.js`** — DI en `registerMark` (funciones de red/cola inyectables). Tests: decide el flujo (online directo vs. encolar offline) según conectividad simulada. `showTypeSelectionForEmployee` puede quedar sin test si resulta predominantemente DOM sin lógica de decisión propia — se evalúa al escribir el plan.
- `showTypeSelectionForEmployee(employee, allowedEvents, lastEvent, lastEventTime)`, `registerMark(employee, eventType)`

**`bootstrap.js`** — Sin DI especial, sin tests (orquestación secuencial de arranque: carga de modelos, migración legacy, sync inicial — bajo valor de test unitario, es principalmente "llamar a A, luego B, luego C y manejar errores de cada paso").
- `updateLoadingProgress(percentage, message, stepNumber)`, `checkLegacyTerminalMigration()`, `initializeSystem()`, `requestPersistentStorage()`, `initializeOfflineSync()`, `startBackgroundSync()`, `markInteraction()`

### 2. `terminal.js` como composition root

Después de la extracción conserva:
- Refs de DOM específicos de wiring (botones, listeners de eventos de UI que no pertenecen a ningún módulo de arriba).
- Imports de los 6 módulos nuevos + los ya existentes (`terminal-offline/*`, `ui-feedback.js`, `sync-status-ui.js`, `manual-search.js`, `text-helpers.js`).
- Orquestación de alto nivel: qué función de qué módulo se llama en respuesta a cada evento, sin contener la lógica en sí.
- El bloque de inicialización ya existente (`window.terminalData`, registro del service worker, `initThemeToggle`) — no se toca.

De ~1215 líneas se espera baje a un rango de ~300-400.

### 3. Manejo de errores entre módulos

Cada módulo lanza (`throw`) o retorna un resultado tipado (siguiendo el precedente ya establecido en `terminal-offline/sync.js`/`queue.js`, a definir con precisión por módulo al escribir el plan). `terminal.js` decide qué pantalla mostrar según el resultado, pero no duplica lógica de decisión de negocio — esa vive en el módulo correspondiente (principalmente `identification-flow.js` y `mark-registration.js`).

## Testing

- Vitest con el patrón DI para `idle-detection.js`, `screen-state.js`, `identification-flow.js`, y `registerMark` de `mark-registration.js`.
- `camera.js` y `bootstrap.js` sin tests unitarios — justificado arriba por módulo.
- `showTypeSelectionForEmployee` — decisión de si vale la pena testear se toma al escribir el plan, según cuánta lógica de decisión propia tiene más allá de manipulación de DOM.
- Verificación manual en navegador con un terminal de prueba real (provisión, identificación, marcación, modo offline) antes de cerrar — es refactor de comportamiento cero, así que la verificación manual es la única forma de confirmar que la experiencia real no cambió.
- Test Pest existente de la vista (`TerminalViewTest`, sub-proyecto E) no debería verse afectado — no toca la vista Blade, solo el JS.

## Notas de implementación

- Seguir las convenciones de JSDoc del proyecto en todos los módulos nuevos.
- Mantener el patrón de comentarios de cabecera de archivo ya usado en `terminal-offline/*`/`terminal/*` (`@fileoverview` con contexto de extracción).
- El orden de extracción en el plan de implementación debe considerar dependencias entre módulos (p. ej. `identification-flow.js` probablemente depende de `screen-state.js` para mostrar resultados) — a resolver al escribir las tareas.
