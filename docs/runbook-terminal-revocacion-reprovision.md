# Runbook: Revocación y reprovisión de terminales offline

Guía operativa para el equipo de soporte/administración de **Nominapp** ante la pérdida, robo o reemplazo de un terminal de marcación de asistencia (offline vía PWA).

---

## Cuándo aplica

- El dispositivo físico del terminal se perdió o fue robado.
- Se reemplaza el hardware de un terminal existente (tablet/PC nueva en la misma sucursal).
- Se sospecha que el token de sincronización de un terminal fue comprometido (ej. acceso no autorizado al dispositivo).
- Un terminal se va a dar de baja definitivamente (la sucursal cierra, o se reemplaza el proceso de marcación).

En todos estos casos, el token Sanctum del terminal (`ability: terminal:sync`) sigue siendo válido hasta que se revoque explícitamente — **no expira solo**. Actuar rápido ante una pérdida es importante: quien tenga el dispositivo físico puede seguir marcando asistencias (o consultando la caché de empleados sincronizada) hasta que el token se revoque.

## Qué NO cubre este runbook

- **Borrado remoto del dispositivo perdido**: no existe hoy un mecanismo para borrar la caché de IndexedDB (empleados, descriptores faciales) de un dispositivo físico que ya no está bajo control. Revocar el token evita que ese dispositivo pueda seguir *sincronizando* con el servidor, pero los datos que ya tenía cacheados localmente permanecen en el dispositivo. Ver [Riesgos aceptados](#riesgos-aceptados) más abajo.
- Incidentes de seguridad más amplios (compromiso del servidor, credenciales de admin filtradas) — este runbook es específico para un terminal individual.

---

## Paso 1 — Revocar el token del terminal comprometido/perdido

1. Ir a **Filament → Asistencias → Terminales**.
2. Ubicar el terminal afectado (por nombre o sucursal).
3. Abrir el menú de acciones de la fila (`⋮`) y seleccionar **"Desvincular dispositivo"**.
4. Confirmar en el modal. Esto invalida inmediatamente el token Sanctum — el próximo intento de sincronización desde ese dispositivo recibirá `401`/`403` y el terminal mostrará "Terminal sin configurar" (o el mensaje equivalente de re-provisión).

**No hace falta desactivar el terminal** (`status: inactive`) al revocar el token — son conceptos independientes: `status` controla si el terminal puede usarse para marcar (vía `/terminal/{code}`), mientras que el token controla el acceso a la API de sincronización offline. Si el dispositivo físico se perdió y no se va a recuperar, desactivar el terminal además de revocar el token evita que alguien reactive el acceso reutilizando la URL pública.

## Paso 2 — Confirmar que el terminal quedó sin acceso

En la tabla de Terminales, la columna **Conectividad** del terminal revocado eventualmente pasará a "Desconectado" y luego (tras el umbral configurado en `Configuración General → Terminales de Marcación`) no se actualizará más — es esperado, ya no puede completar heartbeats. No hace falta esperar a que esto pase para continuar con la reprovisión.

## Paso 3 — Reprovisionar (dispositivo nuevo o el mismo ya recuperado)

1. En la misma fila del terminal, abrir **"Generar enlace de configuración"**.
2. Elegir la vigencia (30 min / 4 h / 24 h) y generar. El enlace/QR es de un solo uso y se muestra **una sola vez** (en la base solo queda su hash). Este paso también invalida cualquier enlace de configuración anterior sin usar.
3. Con el dispositivo físico **conectado a internet**, abrir el enlace (o escanear el QR) una única vez. Esto reclama un token Sanctum nuevo y lo guarda en el propio dispositivo (IndexedDB) — el token Sanctum nunca aparece en la URL. El token del enlace viaja en el fragmento (`#`) de la URL, que el navegador no envía al servidor en el GET; se manda por POST al reclamar. Si el enlace falla, la pantalla distingue *venció* / *ya se usó* / *inválido*.
4. Una vez completada la configuración, navegar a `/terminal/{code}` normalmente y dejar el dispositivo funcionando con conexión al menos hasta que complete el primer heartbeat y el primer sync de empleados.

## Alternativa — Ventana de vinculación (15 min)

Si el personal del local va a configurar el dispositivo sin que nadie apruebe códigos: **Abrir ventana de vinculación** (fila del terminal o acción masiva). Durante 15 minutos el primer dispositivo que pida vincularse queda vinculado sin aprobación y la ventana se cierra sola; queda registrado quién la abrió (`terminal_events`: `link_window_opened`, `pairing_auto_approved`, `link_window_closed`). Abrirla justo antes: cualquiera con la URL del terminal podría vincularse mientras esté abierta. También hay una **Hoja de instalación (PDF)** para el local, sin secretos.

## Alternativa recomendada — Vincular por código (sin enlace ni WhatsApp)

Si el terminal muestra la pantalla **"Terminal sin vincular"** con un código de 6 caracteres:

1. En el panel, **Asistencias → Solicitudes de vinculación** (llega también una notificación a la campanita; la pantalla se actualiza sola cada 15 s).
2. Buscá la solicitud del terminal (verificá sucursal, IP y dispositivo) y tocá **Aprobar**.
3. Tipeá el código **tal como lo ves en la pantalla del dispositivo** (el sistema no lo muestra en el panel a propósito) y confirmá.
4. El dispositivo se vincula solo en unos segundos y recarga; tocá **Comenzar** en la pantalla del terminal. Las marcaciones que tuviera pendientes en la cola no se pierden.

Si la solicitud marca **"Reemplaza dispositivo"**, aprobarla revoca el acceso del dispositivo actualmente vinculado. Si el código venció (10 min) o fue rechazado, el dispositivo ofrece **Pedir código nuevo**. Si el terminal aparece como desactivado, activalo primero desde su ficha.

## Paso 4 — Verificar que la reprovisión funcionó

En **Filament → Asistencias → Terminales → (ver el terminal)**, sección **Conectividad**:

- **Estado**: debe pasar a "En línea" dentro de los primeros segundos/minutos (heartbeat cada 90s mientras hay red).
- **Último sync de empleados**: debe tener una fecha reciente — confirma que la caché de empleados/descriptores se está descargando de nuevo en el dispositivo nuevo.
- **Cola de sincronización**: debe estar en "Sin pendientes" — si aparece "Con pendientes" o "Con conflictos" persistentemente, revisar `AttendanceMarkFailureResource` (filtrado por el terminal/sucursal) antes de dar el proceso por cerrado.

Si el terminal no pasa a "En línea" en un tiempo razonable, verificar:
- Que el dispositivo tenga conexión real a internet (no solo a una red local sin salida).
- Que la URL usada sea la correcta (`/terminal/{code}` con el código del terminal, no uno viejo).
- Los logs del servidor (`storage/logs/laravel-*.log`) por errores 401/403 repetidos, que indicarían que el token no se guardó correctamente en el dispositivo.

---

## Migración de un terminal de `/terminal` (legacy) a `/terminal/{code}`

Aplica a dispositivos que todavía tienen guardado el acceso viejo `/terminal` (sin código) — la arquitectura actual identifica a cada terminal por su código único en la URL (`/terminal/{code}`).

**No hace falta reprovisionar.** El token Sanctum del dispositivo vive en su IndexedDB local, no depende de qué URL lo cargó — solo hay que actualizar el acceso guardado (bookmark, ícono "agregar a inicio") en el dispositivo físico.

1. Si el dispositivo ya cargó `/terminal` (legacy) al menos una vez desde el fix de migración, va a mostrar un banner amarillo con el link directo a `/terminal/{code}` correcto (detectado automáticamente desde el `terminal_code` ya guardado en su IndexedDB, sin necesidad de red). Copiar esa URL.
2. Si el banner no aparece (dispositivo nunca cargó la página, o el navegador bloquea IndexedDB), buscar la URL manualmente: **Filament → Asistencias → Terminales → (ver el terminal)** — la ficha muestra la URL completa y un QR para escanear directo desde el dispositivo.
3. En el dispositivo físico, abrir esa URL y actualizar el acceso guardado:
   - Si está instalado como PWA ("agregado a inicio"), quitar el ícono viejo y volver a agregarlo desde `/terminal/{code}`.
   - Si es solo un bookmark del navegador, actualizarlo a la URL nueva.
4. Confirmar que el dispositivo sigue funcionando con normalidad — no requiere volver a completar heartbeat/sync inicial, ya estaba provisionado.

No hay urgencia de apurar esta migración dispositivo por dispositivo: `/terminal` (legacy) se mantiene activa mientras existan dispositivos sin migrar, y ambas URLs sirven al mismo terminal ya provisionado sin conflicto.

## Riesgos aceptados

- **Biometría sin cifrar en el dispositivo perdido**: la caché de `employees_cache` (IndexedDB) incluye los descriptores faciales de los empleados de la sucursal, en texto plano — mismo nivel de exposición que el caché server-side (`Cache::remember('employees_face_descriptors', ...)`). Revocar el token detiene la sincronización futura, pero no borra lo que ya estaba cacheado en el dispositivo perdido. Si el dispositivo se recupera físicamente, no hace falta ninguna acción adicional — el token revocado ya impide que vuelva a sincronizar sin pasar por reprovisión.
- **Ventana entre la pérdida y la revocación**: mientras el token no se revoca, el dispositivo puede seguir marcando asistencias y sincronizando eventos con normalidad. No hay alerta automática de "terminal reportado como perdido" — depende de que el equipo de soporte actúe apenas se entera del incidente.

## Checklist rápido

- [ ] Revocar el token del terminal afectado (Filament → Terminales → Desvincular dispositivo)
- [ ] Si el dispositivo no se va a recuperar: marcar el terminal como `Inactiva`
- [ ] Generar un nuevo enlace de configuración
- [ ] Provisionar el dispositivo nuevo (o el mismo, ya recuperado) con el enlace, online
- [ ] Confirmar "En línea" + sync de empleados reciente + "Sin pendientes" en la sección Conectividad
- [ ] Si el terminal fue reemplazado por hardware nuevo: actualizar los datos del dispositivo físico (marca/modelo/serie) en la ficha del terminal
