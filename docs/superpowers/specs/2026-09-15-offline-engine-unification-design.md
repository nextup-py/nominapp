# Sub-proyecto C: Unificación del motor offline (mobile/terminal) — Spec

**Fecha:** 2026-09-15
**Secuencia:** A (sistema de diseño unificado) → F (theming dinámico) → B (instalabilidad PWA) → E (rediseño visual/UX) → D (reestructuración de terminal.js) → **C (este documento, último eslabón)**

## Contexto

Nominapp tiene dos motores de marcación offline paralelos: `resources/js/attendances/terminal-offline/` (kiosko compartido, N empleados cacheados) y `resources/js/attendances/mobile-offline/` (dispositivo personal, 1 empleado implícito). Ambos replican la misma arquitectura de 4 módulos (`db.js`, `matcher.js`, `queue.js`, `sync.js`), pero **ninguno tiene tests** pese a ser el código más crítico del proyecto — corre en terminales y celulares reales, incluida la ruta de fallback sin red que decide si un empleado puede marcar su asistencia.

Un análisis línea por línea de los 8 archivos encontró:

- **`matcher.js` es 100% idéntico** entre ambos (mismo algoritmo de distancia euclidiana + umbral/gap) — solo difieren los comentarios.
- **`db.js` tiene una porción genérica mecánicamente idéntica** (get/set de metadata, toda la cola de eventos `outbound_events`, `employee_status_cache`, `logSync`) y una porción genuinamente divergente (`employees_cache` con N candidatos en terminal vs. `own_employee` único en mobile — un modelo de dominio distinto, no descuido).
- **`queue.js` tiene el mismo patrón**: el loop de `flushQueue()` que parte en lotes de 200 es mecánicamente idéntico; el resto (resolución de estado, forma del evento encolado) difiere genuinamente por el modelo N-vs-1.
- **`sync.js` es genuinamente distinto** en ambos — endpoints, manejo de errores de auth (`TerminalAuthError`/`MobileAuthError`), y forma de los datos no tienen partes "gratis" para unificar.

## Alcance

**Dentro de alcance:**
1. Agregar `fake-indexeddb` como dependencia de desarrollo.
2. Tests de caracterización para los 8 archivos actuales (`terminal-offline/*` y `mobile-offline/*`), **antes** de cualquier extracción — capturan el comportamiento real actual como red de seguridad.
3. Extraer a `resources/js/attendances/offline-shared/`:
   - `matcher.js` — completo, sin cambios de lógica.
   - `generic-db.js` — CRUD genérico de metadata/cola/status-cache/sync-log, parametrizado por un handle `idb` ya abierto.
   - `submit-in-chunks.js` — el loop de batching de `flushQueue()` (200 eventos por lote, manejo de fallo de red), parametrizado por `submitFn` y la forma del evento.
4. Re-ejecutar la suite de caracterización contra el código extraído para probar cero cambio de comportamiento.
5. Verificación manual de humo (marcar en `/marcar` y en un terminal de prueba, online y offline).

**Fuera de alcance:**
- Cualquier unificación del modelo N-empleados-vs-1-empleado.
- `sync.js` — queda completo y separado en cada carpeta.
- Cambios de comportamiento visible en `mark.js`, `terminal.js`/módulos de terminal (sub-proyecto D), o cualquier vista Blade.
- Nueva funcionalidad — este sub-proyecto es refactor + cobertura de tests, no features.

## Diseño

### 1. Orden de trabajo: tests antes que extracción

Cada archivo recibe tests de caracterización **contra su implementación actual, sin modificarla**, antes de que empiece cualquier extracción:

- **`matcher.js` (ambos)** — tests puros de la función de distancia euclidiana + criterio de umbral/gap. Sin dependencias externas, DI no aplica.
- **`db.js` (ambos)** — tests con `fake-indexeddb` inyectando una base real en memoria: creación de schema (`upgrade()` callback), CRUD de cada store, comportamiento ante datos ya existentes (idempotencia de `applyEmployeesDelta`, etc.).
- **`queue.js` (ambos)** — tests con las funciones de `db.js` mockeadas (`vi.mock`), siguiendo el patrón ya establecido en sub-proyecto D: `resolveEmployeeStatus`/`resolveOwnStatus`, `getEmployeeStatus`/`getOwnStatus` (online + fallback offline), `enqueueMark`, `flushQueue` (incluyendo el chunking en lotes de 200 y el manejo de fallo de red a mitad de lote).
- **`sync.js` (ambos)** — tests con `fetch` mockeado (`vi.stubGlobal('fetch', ...)` o equivalente): `apiFetch` (token ausente/revocado → `TerminalAuthError`/`MobileAuthError`), `heartbeat`, `syncEmployees` (terminal) / heartbeat-con-descriptor (mobile).

Solo después de que estas 8 suites pasan contra el código actual (commiteadas primero) empieza la extracción.

### 2. Extracción de módulos compartidos

**`resources/js/attendances/offline-shared/matcher.js`** — el archivo completo movido tal cual. `terminal-offline/matcher.js` y `mobile-offline/matcher.js` se eliminan; los imports en `identification-flow.js` (terminal) y el módulo equivalente de mobile se actualizan para apuntar al compartido. Los tests de caracterización de matcher se mueven junto con el archivo y se re-ejecutan sin cambios de aserciones.

**`resources/js/attendances/offline-shared/generic-db.js`** — recibe el handle de base ya abierto (`db`) como parámetro en cada función (no llama a `getDb()` internamente, no conoce `DB_NAME`). Expone:
- `getMeta(db, storeName, key)` / `setMeta(db, storeName, key, value)`
- `queueEvent(db, event)` / `getPendingEvents(db)` / `removeQueuedEvent(db, clientEventId)` / `markQueuedEventConflict(db, clientEventId, message)` / `incrementQueuedEventAttempts(db, clientEventId)` / `countPendingEvents(db)` / `countConflictEvents(db)` / `dismissConflictEvents(db)` — todos sobre el store `outbound_events` (nombre de store fijo, ya que ambos usan el mismo nombre literal).
- `getEmployeeStatusCache(db, employeeId)` / `setEmployeeStatusCache(db, employeeId, status)` — sobre `employee_status_cache`.
- `logSync(db, type, ok, detail)` — sobre `sync_log`.

Cada `terminal-offline/db.js` y `mobile-offline/db.js` mantiene: su propio `getDb()` (con su `DB_NAME`/`DB_VERSION`/`upgrade()` — incluyendo el store `employees_cache` solo en terminal), sus funciones específicas de empleados (`applyEmployeesDelta`/`applyBreakFlags`/`getCachedEmployee(s)`/`countCachedEmployees` en terminal; `getOwnEmployee`/`setOwnEmployee`/`resetDb` en mobile), y re-exporta las funciones genéricas ya resolviendo el parámetro `db` internamente (ej. `export const getMeta = (key) => genericDb.getMeta(await getDb(), 'terminal_meta', key)`), para que **el resto del proyecto no note el cambio** — `queue.js`/`sync.js`/`identification-flow.js` siguen llamando `getMeta(key)` exactamente como hoy, sin conocer la extracción.

**`resources/js/attendances/offline-shared/submit-in-chunks.js`** — exporta una función `submitInChunks(events, submitFn, chunkSize = 200)` que reproduce el loop actual: parte `events` en lotes de `chunkSize`, llama `submitFn(batch)` por lote, detiene el resto de los lotes si uno falla por red, y acumula los `results` de los lotes que sí llegaron. Cada `queue.js` arma su propio `batch.map(...)` con la forma de evento que le corresponde (con o sin `employee_id`/`location`) antes de pasarlo a `submitFn`.

### 3. Prueba de cero cambio de comportamiento

Después de cada extracción, la MISMA suite de caracterización (mismas aserciones, ahora importando desde los archivos ya extraídos) debe seguir pasando sin modificaciones — esa es la prueba de que el refactor no cambió nada observable. Cualquier aserción que necesite cambiar durante la extracción es una señal de alarma a investigar, no un ajuste de rutina.

## Testing

- `fake-indexeddb` como dependencia de desarrollo nueva.
- Los 8 archivos actuales (terminal-offline/mobile-offline × db/matcher/queue/sync) reciben tests de caracterización antes de tocarse.
- Tras la extracción, se agregan tests propios a los 3 módulos nuevos de `offline-shared/` (o se reutilizan/mueven los de matcher.js, y se agregan los de `generic-db.js`/`submit-in-chunks.js` que antes vivían implícitos dentro de cada `db.js`/`queue.js`).
- Verificación manual de humo en `/marcar` y un terminal de prueba (online y offline) antes de cerrar el sub-proyecto — dado que toca el código más crítico del proyecto y no hay forma de probar IndexedDB/cámara real de punta a punta con Vitest.

## Notas de implementación

- Seguir las convenciones de JSDoc del proyecto en todos los módulos nuevos.
- El orden de extracción en el plan de implementación debe respetar: primero TODOS los tests de caracterización (8 archivos), commiteados; recién después las 3 extracciones, cada una con su propia re-verificación contra la suite existente.
- `DB_VERSION` de terminal (2) y mobile (1) son asimetrías preexistentes por evolución independiente — no se tocan ni se sincronizan en este sub-proyecto.
