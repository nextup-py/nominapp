# Arquitectura: marcación de asistencia offline (terminal y dispositivo)

Documento de referencia de la arquitectura de marcación de asistencia por reconocimiento facial con soporte offline, cubriendo tanto el terminal de sucursal como el dispositivo personal del empleado. Consolida el trabajo de tres iniciativas relacionadas: ajuste de reconocimiento facial, marcación offline vía PWA en el terminal, y marcación offline vía PWA en el dispositivo personal.

Para el resumen corto orientado a desarrollo día a día, ver la sección "Módulo de Asistencia — Marcación Offline" en `CLAUDE.md`. Este documento tiene el detalle completo de arquitectura y decisiones.

---

## Contexto

El reconocimiento facial corre 100% en el navegador (face-api.js, descriptores de 128 elementos) — lo único que originalmente dependía del servidor era comparar el descriptor capturado contra la base de empleados y guardar el evento. Esto habilitó llevar todo el flujo a una PWA con soporte offline real, sin necesitar una app nativa.

Hay dos dispositivos distintos que marcan asistencia, con modelos de confianza y caché muy diferentes:

| | Terminal | Dispositivo personal |
|---|---|---|
| Propiedad | De la empresa, en la sucursal | Del empleado |
| Uso | Compartido, N empleados | Individual, 1 empleado |
| Caché de descriptores | Todos los empleados activos de la sucursal | Únicamente el propio |
| Vinculación | Enlace de un solo uso generado por un admin | Self-service (CI + fecha de nacimiento) |
| Dispositivos simultáneos | N/A (es un dispositivo fijo) | 1 a la vez (vincular uno nuevo revoca el anterior) |

---

## Autenticación: Sanctum polimórfico

Ambos flujos usan **Laravel Sanctum**, con un único guard `auth:sanctum` sirviendo tokens de dos modelos distintos (`Terminal` y `Employee`) sin ninguna configuración adicional en `config/auth.php`. `personal_access_tokens.tokenable_type` resuelve el modelo correcto por token — alcanza con que cada modelo tenga `HasApiTokens` (Sanctum) y, solo para que `Sanctum::actingAs()` funcione en tests, `Illuminate\Auth\Authenticatable` + `Illuminate\Contracts\Auth\Authenticatable`.

- `Terminal::claimSanctumToken()` — ability `terminal:sync`.
- `Employee::claimMobileToken()` — ability `mobile:sync`.

Ambos siguen el mismo patrón de provisión: nunca se expone un token de larga vida en una URL. El terminal usa un enlace de configuración de un solo uso generado por un admin; el dispositivo usa una credencial self-service (ver abajo). Los dos revocan cualquier token previo de ese tipo antes de emitir uno nuevo — un dispositivo activo a la vez.

---

## Parte A — Tuning de reconocimiento facial

Ajustes basados en datos reales de fallos (`AttendanceMarkFailure`, 30 días, 2935 registros: 55% `face_no_match`, 43% `face_ambiguous`):

- `face_threshold`: `0.45` → `0.50` (`GeneralSettings`).
- `face_min_confidence_gap`: `0.1` → `0.05` (`GeneralSettings`).
- Feedback en tiempo real durante la captura (cara muy chica / mala luz se avisa antes de completar el ciclo de 5 muestras), cooldown post-fallo reducido.
- Lógica de captura común (muestreo, promediado, validación de tamaño mínimo) extraída a `resources/js/shared/face-capture-core.js`, compartida por `mark.js`, `terminal.js` y `FaceCaptureApp.js` — antes estaba duplicada con configuraciones que ya habían divergido.
- Nuevo reporte de tasa de fallos por empleado en `AttendanceMarkFailureResource`, para detectar proactivamente a quién le conviene re-inscribirse.

---

## Parte B — Terminal offline

### Capa de API (`routes/api.php`, prefijo `v1/terminal`)

- `GET /employees/sync?since=...` — sync incremental (delta) de empleados activos con descriptor facial de la sucursal del terminal, con tombstones para los que dejaron de calificar.
- `GET /employees/{employee}/status` — último evento / eventos permitidos de un empleado puntual (el terminal no tiene esto cacheado localmente para todos).
- `POST /events/sync` — envío en lote (máx. 200) de eventos con `client_event_id` (UUID generado al capturar) para deduplicar reintentos.
- `POST /heartbeat` — mantiene vivo `last_heartbeat_at`, devuelve config vigente (`face_threshold` etc.) y reporta contadores de cola (`pending_events`/`conflict_events`).

### Cliente (`resources/js/attendances/terminal-offline/`)

- `db.js` — IndexedDB `nominapp-terminal`, 4 stores: `terminal_meta`, `employees_cache`, `outbound_events`, `sync_log`.
- `matcher.js` — port a JS de la comparación por distancia euclidiana + umbral + gap de confianza que antes solo existía en el servidor.
- `sync.js` — cliente HTTP de `/api/v1/terminal/*`.
- `queue.js` — máquina de estados local (port de `AttendanceEvent::allowedNextEventTypes()`), cola de eventos pendientes (`enqueueMark()`/`flushQueue()`), resolución de estado combinando lo confirmado por el servidor con lo encolado localmente.

### Service worker (`public/sw.js`)

Hand-rolled (sin Workbox) — cache-first para `/models/*` y `/build/assets/*`, stale-while-revalidate para el shell HTML del terminal. `skipWaiting()` + `clients.claim()` en cada deploy, con caché versionada.

### Resolución de conflictos

Si el terminal sincroniza un evento offline cuya secuencia ya no es válida en el servidor (ej. otro origen ya registró un evento posterior mientras estaba desconectado), el servidor lo rechaza puntualmente (`AttendanceEventSyncService::recordSyncFailure()`) y lo registra en `AttendanceMarkFailure` (`failure_type: sync_conflict`) — nunca se descarta silenciosamente ni se fuerza. Un admin lo revisa desde Filament (`AttendanceMarkFailureResource`) y puede **aprobar** (reconstruye el evento, revalidando la secuencia contra el estado *actual*, con opción de ajustar tipo/hora) o **descartar**.

### Heartbeat / staleness (`Terminal`)

`connectivity_status` (`never_connected` / `online` / `stale`, según `GeneralSettings->terminal_stale_threshold_minutes`) y `sync_queue_status` (`ok` / `pending` / `conflict`), visibles en `TerminalResource` (columnas, filtros, infolist).

### Hardening

`navigator.storage.persist()`, corrección de deriva de reloj (`server_clock_offset_ms` calculado en cada heartbeat), chunking de `flushQueue()` a lotes de 200 (bug real encontrado con una prueba de carga: sin chunking, una cola de más de 200 eventos quedaba atascada indefinidamente porque el servidor rechazaba el batch completo).

---

## Parte C — Dispositivo personal offline

### Vinculación (`/vincular-dispositivo`)

El empleado se identifica una vez, online, con **CI + fecha de nacimiento** — ambos datos ya existen en `Employee`, sin necesitar un PIN nuevo que administrar ni una integración de SMS/magic-link. Es deliberadamente **no** un factor de autenticación completo: funciona como credencial para "reclamar" el dispositivo, mientras que la marcación en sí sigue exigiendo un match facial exitoso contra el descriptor cacheado (el segundo factor real). Mensaje de error genérico (no distingue CI inexistente de fecha incorrecta), throttling agresivo (`throttle:5,1` + `throttle:15,1440`, con prefijos explícitos por clave — ver nota de bug abajo).

Al validar, `Employee::claimMobileToken()` revoca cualquier token `mobile:%` previo y cachea únicamente el descriptor facial del propio empleado.

**Bug real encontrado en el throttling**: apilar dos middlewares `throttle:X,Y` sin prefijo explícito hace que ambos compartan la misma clave de rate limit (`ThrottleRequests::resolveRequestSignature()` solo usa dominio+IP, no los parámetros del middleware) — el límite más agresivo se agotaba antes de lo esperado. Se corrigió agregando prefijos explícitos (`throttle:5,1,device-link-minute`, etc.).

### API (`routes/api.php`, prefijo `v1/mobile`)

- `POST /heartbeat` — config vigente + descriptor facial propio actualizado (no existe "sync de empleados" separado: el dispositivo solo cachea su propio descriptor, y una re-inscripción facial se propaga automáticamente en el próximo heartbeat).
- `GET /status` — último evento / eventos permitidos del propio empleado (implícito vía `$request->user()`, sin parámetro de ruta).
- `POST /events/sync` — mismo contrato que el del terminal pero sin `employee_id` por evento (el empleado es el dueño del token).

Los tres endpoints revocan el token y responden `403` si el empleado ya no está `active` — autodesvinculación ante una baja, sin esperar acción manual de un admin.

`MobileEventSyncService` es un servicio **paralelo** a `AttendanceEventSyncService`, deliberadamente no compartido: el acoplamiento de este último a `Terminal` (`terminal_id`, fallback de GPS a coordenadas de sucursal, `employee_id` por evento) no encaja con "un empleado ya autenticado, con GPS real, sin `employee_id` por evento". Misma idempotencia por `client_event_id` y misma revalidación de secuencia. Los conflictos van al mismo `AttendanceMarkFailure` (`mode: 'mobile'`), reusando el flujo de aprobar/descartar de Filament.

### Cliente (`resources/js/attendances/mobile-offline/`)

Reusa `db.js`/`matcher.js`/`queue.js` del terminal con cambios mínimos (nombre de IndexedDB `nominapp-mobile`, para no chocar si algún día ambos flujos coexistieran en el mismo navegador — `matcher.js` funciona igual con un array de 1 solo candidato). `sync.js` es una variante propia apuntando a `/api/v1/mobile/*` (el original tenía `API_BASE` fijo a `/api/v1/terminal`).

### Integración con `mark.js`

`mark.js` (2171 líneas, UI de wizard con splash/mini-mapa GPS Leaflet/animaciones, sin modularizar) se editó **en el lugar** — mismo criterio que ya había funcionado con `terminal.js` — en dos puntos acotados: identificación (antes `POST /marcar/identificar` con el descriptor crudo al servidor; ahora matching local contra el único descriptor cacheado) y registro de marcación (antes `POST /marcar` síncrono con CSRF de sesión; ahora `enqueueMark()` + intento de `flushQueue()`, convergiendo a Sanctum). Se agregó la pantalla de "vincular este dispositivo" para cuando no hay token.

### Hardening

Notificación a admins (`MobileDeviceRelinkedNotification`) cuando un empleado que ya tenía dispositivo vinculado vincula uno nuevo — mitiga el riesgo de que alguien con el CI+fecha de otro empleado revoque silenciosamente su dispositivo legítimo (denegación de servicio dirigida). Columna `mobile_last_heartbeat_at` en `EmployeeResource` para saber si el dispositivo vinculado sigue sincronizando.

---

## Riesgos aceptados / deuda técnica conocida

- **Biometría sin cifrar en IndexedDB** — mismo nivel de exposición que el caché server-side previo (texto plano). Cifrado real requeriría PIN de dispositivo o clave de hardware.
- **Borrado remoto de un dispositivo perdido no es posible** — revocar el token evita que siga *sincronizando*, pero no borra lo que ya tenía cacheado localmente. Ver los runbooks.
- **CI + fecha de nacimiento como credencial de vinculación** es de baja entropía — mitigado con throttling agresivo y notificación de re-vinculación, no con un segundo factor real (ese rol lo cumple el match facial).
- **Background Sync API no soportada en Safari/WebKit** — el fallback es que la sincronización ocurre mientras la pestaña/PWA sigue abierta.
- **Heartbeat/staleness del dispositivo tiene menor prioridad que el del terminal** — son N:1 por empleado, no un activo físico de la empresa a monitorear activamente; hoy solo hay un timestamp visible en `EmployeeResource`, sin badge de "desconectado" ni alertas.
- **`Employee::getAdvanceReferenceSalary()`** y otras áreas no relacionadas con asistencia no se tocaron en esta iniciativa.

## Runbooks relacionados

- `docs/runbook-terminal-revocacion-reprovision.md` — pérdida/robo/reemplazo de un terminal.
- `docs/runbook-dispositivo-vinculacion-revocacion.md` — pérdida/robo/reemplazo del dispositivo de un empleado, o revocación manual desde Filament.

## Archivos clave

**Backend compartido:** `app/Models/AttendanceEvent.php` (máquina de estados `allowedNextEventTypes()`), `app/Models/AttendanceMarkFailure.php` (registro y resolución de conflictos), `app/Filament/Resources/AttendanceMarkFailureResource.php`.

**Terminal:** `app/Models/Terminal.php`, `app/Http/Controllers/Api/Terminal{EmployeeSync,EventSync,Heartbeat}Controller.php`, `app/Services/{EmployeeDescriptorSyncService,AttendanceEventSyncService}.php`, `app/Filament/Resources/TerminalResource.php`, `resources/js/attendances/{terminal,terminal-offline/*}.js`, `public/sw.js`.

**Dispositivo:** `app/Models/Employee.php` (sección "Marcación offline por dispositivo"), `app/Http/Controllers/MobileLinkController.php`, `app/Http/Controllers/Api/Mobile{Heartbeat,Status,EventSync}Controller.php`, `app/Services/MobileEventSyncService.php`, `resources/js/attendances/{mark,mobile-offline/*}.js`, `resources/views/attendances/device-link.blade.php`.

**Configuración:** `app/Settings/GeneralSettings.php` (`face_threshold`, `face_min_confidence_gap`, `terminal_stale_threshold_minutes`), `config/attendance.php`.
