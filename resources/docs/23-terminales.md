# Terminales de Marcación

Un **terminal** es el dispositivo fijo de una sucursal (tablet o PC) desde el que todos los empleados marcan asistencia con reconocimiento facial. Este capítulo explica cómo crearlo, **vincularlo** al sistema, monitorear su estado y resolver los problemas más comunes.

> Para el modo de marcación con el celular propio del empleado, ver **Dispositivo personal (vinculación)** en el capítulo **Asistencias**. Para el reconocimiento facial y la revisión de fallos de marcación, ver el mismo capítulo.

**Requisitos para gestionar terminales**

- El módulo de **Marcación biométrica** debe estar activo (se elige en el asistente de configuración inicial). Si no lo está, el menú de terminales no aparece.
- El usuario necesita el permiso **Editar Terminal** (grupo *Asistencia* en la pantalla de roles). Lo tienen los roles **Super Admin** y **RRHH**. Es el mismo permiso que se exige para aprobar vinculaciones, revocar accesos y desactivar terminales (ver **Roles y Permisos**).

---

## Cómo funciona

1. El administrador **crea el terminal** en el panel y obtiene su URL.
2. El dispositivo físico abre esa URL. La primera vez, hay que **vincularlo**: el servidor le entrega un acceso propio que queda guardado en su navegador.
3. Una vez vinculado, el terminal descarga los rostros de los empleados activos de su sucursal y puede **marcar aunque se corte internet**: guarda las marcaciones y las envía cuando vuelve la conexión.
4. Cada ~90 segundos informa al servidor que sigue vivo. Con esa señal el panel muestra el estado de conectividad.

> Cada terminal tiene **un solo dispositivo vinculado**. Si se vincula otro, el acceso del anterior se revoca de inmediato.

---

## Crear un terminal

Ir a **Asistencias → Terminales**.

![Listado de terminales](/docs-images/23-terminales-listado.png)

1. Clic en **Nuevo Terminal**
2. Completar:
   - **Nombre** — identificador descriptivo (ej: "Terminal Recepción")
   - **Sucursal** — sucursal a la que pertenece. Solo se ofrecen sucursales de empresas activas
   - **Estado** — Activo (por defecto) o Inactivo
   - **Dispositivo** — marca, modelo, número de serie, MAC y notas (todo opcional, sirve para inventario). Marca y modelo se completan solos al vincular cuando el navegador los informa
   - **Fecha de instalación** e **Instalado por** (opcional)
3. Guardar

Al guardar, el sistema abre el detalle del terminal con el cuadro para **generar el enlace de configuración** (ver más abajo). Si se va a vincular por código o con una ventana de vinculación, ese cuadro se puede cerrar.

### URL y código QR

El sistema genera un **código único de 8 caracteres** que forma la URL del terminal (ej: `https://sistema.com/terminal/a3x9bc7q`). Desde el detalle del terminal se ven:

- La **URL de acceso**, con botón para copiarla
- Un **código QR** de esa URL

![Detalle de un terminal](/docs-images/23-terminal-detalle.png)

> La URL y su QR **solo cargan la pantalla** del terminal. Abrirlos no vincula el dispositivo: para eso hay que seguir uno de los métodos de las secciones siguientes.

### Marcación en sucursal diferente

Si un empleado marca desde un terminal de una sucursal distinta a la suya, el sistema registra el evento igualmente pero lo marca internamente como **marcación en sucursal diferente** (`branch_mismatch`). Esto permite detectar situaciones atípicas sin bloquear al empleado.

---

## Vincular un terminal con código (método recomendado)

No hace falta generar ni enviar enlaces: el propio dispositivo muestra un código y un administrador lo aprueba desde el panel. Puede hacerlo una persona en el local y otra, a distancia, desde el panel.

### En el dispositivo

1. Abrir la **URL del terminal** en el navegador definitivo del dispositivo y tocar **Comenzar**.
2. Si el terminal no está vinculado, aparece **Terminal sin vincular** con un **código de 6 letras y números**.

![Pantalla de terminal sin vincular](/docs-images/23-terminal-sin-vincular.png)

3. Dejar esa pantalla abierta y avisar el código al administrador. El código **vence a los 10 minutos**; al recargar la página se conserva el mismo código mientras siga vigente.

### En el panel

1. Ir a **Asistencias → Solicitudes de vinculación**. El menú muestra un contador con las pendientes y a los usuarios con permiso les llega una notificación en la campanita.
2. Ubicar la solicitud del terminal (nombre, sucursal, modelo e IP del dispositivo) y hacer clic en **Aprobar**.
3. Escribir el código **tal como se ve en la pantalla del dispositivo** y confirmar con **Sí, aprobar**.

![Aprobar una vinculación](/docs-images/23-aprobar-vinculacion.png)

> El código **no aparece** en el panel ni en la notificación, a propósito: hay que leerlo del dispositivo. Así se evita aprobar por error la solicitud de otro dispositivo cuando hay más de una pendiente.

### Al aprobar

En unos segundos el dispositivo recibe su acceso y recarga la página. Hay que tocar **Comenzar** otra vez. A partir de ahí descarga los empleados y puede marcar. Las marcaciones que el dispositivo tuviera pendientes de enviar **no se pierden** al vincularse de nuevo.

### Si ya había un dispositivo vinculado

La solicitud aparece marcada como **Reemplaza dispositivo** y el cuadro de aprobación lo advierte, con la fecha del último latido del dispositivo actual. Aprobarla **revoca el acceso del dispositivo anterior**. Aprobar solo si realmente se quiere cambiar el equipo.

---

## Bandeja de solicitudes de vinculación

Ir a **Asistencias → Solicitudes de vinculación** para ver todas las solicitudes de todos los terminales. La tabla se actualiza sola cada 15 segundos.

![Solicitudes de vinculación](/docs-images/23-solicitudes-vinculacion.png)

| Columna | Qué muestra |
|---------|-------------|
| **Terminal** | Nombre y sucursal |
| **Estado** | Pendiente, Aprobada, Rechazada, Vencida o Vinculada (el dispositivo ya recibió el acceso) |
| **Reemplazo** | *Primer vínculo* o *Reemplaza dispositivo* |
| **Dispositivo** | Modelo (si el navegador lo informa) e IP |
| **Solicitada** | Hace cuánto se pidió |
| **Aprobada por** | Usuario que la aprobó |

- Por defecto se muestran solo las **Pendientes**. El filtro **Estado** permite ver las resueltas o vencidas.
- **Aprobar** y **Rechazar** están en cada fila. Al rechazar, el dispositivo lo informa y ofrece pedir un código nuevo.
- Cada terminal admite hasta **3 solicitudes pendientes** a la vez; si se piden más, vence la más antigua.
- Las mismas solicitudes, filtradas por terminal, están en la pestaña **Solicitudes de vinculación** del detalle de cada terminal.

---

## Vincular con enlace de configuración (alternativa)

Sirve cuando alguien está físicamente con el dispositivo y prefiere un enlace o QR en lugar de un código.

1. Abrir el detalle del terminal y hacer clic en **Generar enlace de configuración**.
2. Elegir la **Vigencia del enlace** (**30 minutos**, **4 horas** o **24 horas**) y hacer clic en **Generar enlace**.
3. Se muestra un **código QR** y la URL del enlace (con botón **Copiar**), con su fecha de vencimiento.
4. Abrir ese enlace **una sola vez**, con el dispositivo conectado a internet y **en el navegador donde va a quedar el terminal**, y tocar **Vincular este dispositivo**.

![Enlace de configuración](/docs-images/23-terminal-enlace-configuracion.png)

- El enlace es de **un solo uso** y vence según la vigencia elegida.
- El enlace se muestra **una sola vez**: el sistema no lo guarda, así que al cerrar el cuadro no se puede volver a ver. Si hace falta, **Generar enlace de configuración** crea uno nuevo, que **invalida el anterior** aunque no se haya usado.
- Si el enlace no sirve, la pantalla dice por qué: **venció** (generar uno nuevo), **ya se usó** (si hay que vincular otro dispositivo, generar uno nuevo) o es **inválido** (por ejemplo, fue reemplazado por uno más nuevo).
- Si el terminal ya estaba vinculado, quien use el enlace nuevo **reemplaza** al dispositivo actual: su acceso queda revocado.
- Usar el enlace desde otro navegador o perfil diferente del que va a usar el terminal es la causa más común de terminales que no marcan: el acceso queda guardado en el navegador que abrió el enlace.

---

## Ventana de vinculación (sin aprobar cada código)

Sirve cuando el **personal del local** va a configurar el dispositivo y no hay nadie para aprobar el código en el momento, o cuando se instalan varios terminales seguidos.

1. En el listado (menú de acciones de la fila) o en el detalle del terminal (**Más acciones**), elegir **Abrir ventana de vinculación** y confirmar con **Sí, abrir ventana**.
2. Durante **15 minutos**, el **primer dispositivo** que abra la URL del terminal y pida vincularse queda vinculado **sin que nadie apruebe el código**.
3. La ventana se **cierra sola** en cuanto un dispositivo la aprovecha (o a los 15 minutos). También se puede cerrar antes con **Cerrar ventana de vinculación**.

- Para abrir la ventana en **varios terminales a la vez**, seleccionarlos en el listado y usar **Abrir ventana de vinculación** del menú de acciones masivas. Se omiten los terminales inactivos y los que ya tienen la ventana abierta.
- Mientras la ventana está abierta, el listado muestra la etiqueta **Abierta hasta HH:MM** en la columna **Ventana de vinculación** (y se puede filtrar con **Ventana de vinculación abierta**), y el detalle muestra la sección **Ventana de vinculación** con la hora de cierre y **quién la abrió**.
- Si la ventana **vence sin que ningún dispositivo la use**, se cierra sola y a quien la abrió le llega una notificación en la campanita (*La ventana de vinculación venció sin usarse*).
- Requiere el mismo permiso que el resto de la gestión de terminales (**Editar Terminal**).

![Confirmación para abrir la ventana de vinculación](/docs-images/23-terminal-ventana-vinculacion.png)

> ⚠️ Mientras la ventana está abierta, **cualquiera que abra la URL del terminal** podría vincular su dispositivo. Abrirla **justo antes** de que el personal del local configure el equipo. Si el terminal ya tenía un dispositivo vinculado, el nuevo lo reemplaza.

Quién abrió la ventana queda registrado: en el detalle, **Vinculado mediante** indica *Ventana de vinculación (abierta por …)*.

---

## Hoja de instalación para el local

Para que el personal del local pueda configurar el dispositivo sin depender de instrucciones sueltas, el detalle del terminal ofrece una **hoja imprimible**: **Más acciones → Hoja de instalación (PDF)** (también en el menú de acciones de cada fila del listado).

La hoja incluye el **código QR y la URL del terminal**, los pasos para vincularlo (con código o con ventana de vinculación) y cómo dejarlo listo (cámara, instalar la app, prueba de marcación). **No contiene ningún enlace de configuración ni código de vinculación**: se puede imprimir y dejar en el local sin riesgo.

---

## Estados y monitoreo

El listado y el detalle muestran el estado de cada terminal.

### Estado

| Estado | Significado |
|--------|-------------|
| **Activo** | Puede marcar y sincronizar |
| **Inactivo** | Fuera de servicio (ver **Activar y desactivar**) |

### Conectividad

Se calcula con el último latido exitoso del terminal. Los estados se evalúan en este orden:

| Estado | Significado | Qué hacer |
|--------|-------------|-----------|
| **Sin vincular** | No tiene un acceso vigente: nunca se vinculó, se revocó o el acceso venció | Vincularlo (con código o enlace) |
| **Nunca conectado** | Está vinculado, pero todavía no envió ningún latido | Esperar un par de minutos tras vincular; si persiste, abrir la URL en el dispositivo y tocar **Comenzar** |
| **En línea** | Envió un latido dentro del umbral configurado | — |
| **Desconectado** | Pasó más tiempo que el umbral sin recibir un latido | Revisar que el dispositivo esté encendido, con internet y con la página del terminal abierta |

> El umbral de **Desconectado** se configura en **Configuración → Configuración General → Terminales de Marcación → Umbral de desconexión** (por defecto 2 horas). Los estados *Sin vincular* y *Nunca conectado* no dependen de ese valor.

### Cola de sincronización

Complementa a la conectividad: un terminal puede verse **En línea** y tener marcaciones atascadas.

| Estado | Significado |
|--------|-------------|
| **Sin pendientes** | Todas las marcaciones se enviaron |
| **Con pendientes** | Hay marcaciones guardadas en el dispositivo esperando conexión |
| **Con conflictos** | Alguna marcación hecha sin conexión fue rechazada al sincronizar. Revisar en **Asistencias → Fallos de Marcación** |

### Detalle del terminal

![Conectividad de un terminal](/docs-images/23-terminal-conectividad.png)

La sección **Conectividad** del detalle muestra el estado, el **último latido**, el último sync de empleados y de marcaciones, la cola de sincronización (pendientes y en conflicto) y la última carga de página. Debajo hay tres pestañas: **Solicitudes de vinculación**, **Bitácora** (ver más abajo) y **Marcaciones** (las registradas desde este terminal).

La sección **Dispositivo vinculado** (solo si hay un acceso vigente) muestra desde cuándo está vinculado, **cómo se vinculó** (enlace de configuración, código aprobado por un usuario o ventana de vinculación), la IP desde la que se vinculó, el dispositivo y su último contacto.

![Dispositivo vinculado](/docs-images/23-terminal-dispositivo-vinculado.png)

### Bitácora

La pestaña **Bitácora** del detalle es de solo lectura y lista, de lo más nuevo a lo más viejo, cada cambio de vinculación del terminal: **fecha**, **evento**, **usuario** que lo provocó (si lo hizo el dispositivo o el sistema figura *Dispositivo o sistema*) y **detalle** (IP, vigencia del enlace, motivo del cierre de una ventana...). Se puede filtrar por tipo de evento.

![Bitácora de un terminal](/docs-images/23-terminal-bitacora.png)

| Evento | Cuándo se registra |
|--------|--------------------|
| **Solicitud de vinculación** | Un dispositivo pidió un código |
| **Vinculación aprobada** / **rechazada** | Un usuario aprobó o rechazó la solicitud |
| **Vinculación aprobada por ventana** | La solicitud se aprobó sola porque había una ventana abierta |
| **Código canjeado por el dispositivo** | El dispositivo recibió su acceso tras la aprobación |
| **Ventana de vinculación abierta** / **cerrada** | Quién la abrió y por qué se cerró: a mano, la aprovechó un dispositivo o venció sin usarse |
| **Enlace de configuración generado** | Quién lo generó y con qué vigencia |
| **Dispositivo vinculado** | Vía enlace o código, e IP |
| **Dispositivo desvinculado** | Quién lo desvinculó |

No se registra un evento por cada latido: solo los cambios.

### Filtros del listado

**Empresa** (si hay más de una), **Sucursal**, **Estado**, **Conectividad**, **Ventana de vinculación abierta** y **Cola de sync**. Por ejemplo, filtrar por *Conectividad: Sin vincular* lista de una vez los terminales que no pueden marcar.

---

## Activar y desactivar

- **Desactivar** — el terminal deja de aceptar marcaciones y su pantalla muestra **Terminal fuera de servicio**. Útil para mantenimiento o equipos retirados.
- **Activar** — vuelve a habilitarlo.

> ⚠️ Al desactivar, el sistema **revoca el acceso** del dispositivo. Al reactivar el terminal hay que **vincularlo de nuevo** (con código o enlace).

---

## Desvincular el dispositivo

Si el dispositivo se perdió, se reemplazó o se sospecha que está comprometido, usar **Desvincular dispositivo** (menú de acciones del terminal; solo aparece si hay un acceso vigente).

- El dispositivo pierde el acceso **de inmediato**: no puede sincronizar ni marcar.
- Si la página sigue abierta, pasa a **Terminal sin vincular** y muestra un código para volver a vincularse.
- La desvinculación queda registrada en la **Bitácora** del terminal, con el usuario que la hizo.

---

## Cambiar la URL del terminal

Si la URL fue compartida por error, usar **Cambiar URL del terminal** (menú **Más acciones** del detalle) para generar un código nuevo.

> ⚠️ La URL anterior deja de funcionar y el dispositivo debe abrir la nueva. **No** afecta el acceso ya vinculado: el terminal sigue sincronizando mientras tanto.

---

## Qué se ve en el dispositivo

| Pantalla | Cuándo aparece |
|----------|----------------|
| **Comenzar** | Al abrir la URL. Hay que tocar el botón: el navegador lo exige para activar cámara y sonido |
| **Terminal sin vincular** | No hay acceso vigente. Muestra el código para aprobar en el panel |
| **Terminal fuera de servicio** | El terminal está desactivado |
| **Reposo** | Terminal listo; se activa al detectar a una persona o al tocar la pantalla |
| **Sin conexión** | Aviso en la parte superior. Se sigue marcando: las marcaciones se guardan y se envían al volver internet |

Desde el menú **⋮** del dispositivo se puede **Sincronizar ahora** y ver la hora de la última sincronización (**Últ. sync**).

---

## Problemas frecuentes

| Problema | Causa probable | Qué hacer |
|----------|----------------|-----------|
| El dispositivo no muestra un código | Está viendo una versión guardada de la página | Recargar la página una o dos veces y tocar **Comenzar** |
| "El código venció" | Pasaron más de 10 minutos | Tocar **Pedir código nuevo** y avisar el nuevo código |
| El código no coincide al aprobar | Se pidió un código nuevo y se leyó el anterior | Usar el que se ve en la pantalla en este momento |
| No aparece **Solicitudes de vinculación** en el menú | El usuario no tiene permiso de Editar terminales, o el módulo de marcación biométrica está desactivado | Revisar el rol del usuario (ver **Roles y Permisos**) y los módulos activos |
| Un terminal figura **Sin vincular** pero "andaba" | Alguien lo desvinculó, lo desactivó o vinculó otro dispositivo | Revisar con quién gestiona los terminales y volver a vincular |
| Aparece un aviso de que el navegador pertenece a **otro terminal** | Ese navegador ya estaba vinculado a un terminal distinto | Cancelar el aviso y abrir la URL correcta. Aceptar borra los datos locales del terminal anterior, **incluidas sus marcaciones pendientes de enviar**. Usar un navegador o perfil exclusivo para cada terminal |
| Dice **Sin empleados sincronizados** | El terminal todavía no descargó rostros | Verificar conexión y tocar **Sincronizar ahora**. Solo se descargan los empleados **activos** de la **sucursal del terminal** que tengan su **registro facial** completo |
| El terminal figura **Desconectado** | Dispositivo apagado, sin internet o con la página cerrada | Encenderlo, verificar internet y abrir la URL del terminal |

---

## Seguridad

- Sin **aprobación explícita** de un usuario con permiso (o un enlace de un solo uso, o una ventana de vinculación abierta a propósito) no se entrega acceso: el terminal descarga rostros de empleados, que son datos sensibles.
- El código de vinculación es de **un solo uso y vida corta** (10 minutos) y hay límites de intentos por IP y por terminal.
- El código no se muestra en el panel ni en la notificación: se verifica contra la pantalla del dispositivo.
- Cada terminal tiene **un solo dispositivo vinculado**; vincular otro revoca el anterior.
- Quién aprobó cada vinculación se ve en la columna **Aprobada por** de la bandeja (en las vinculadas por ventana figura quien la abrió); aprobaciones, rechazos, ventanas abiertas y cerradas, enlaces generados y desvinculaciones quedan además en la pestaña **Bitácora** del terminal.
- El enlace de configuración viaja en el **fragmento** de la URL (lo que va después del `#`), que el navegador no envía al servidor al abrirla, así que no queda en los registros de acceso. En la base solo se guarda su huella (hash), nunca el enlace.
- Los enlaces de configuración con el formato anterior (token en la ruta) ya no se aceptan: hay que generar uno nuevo.
