# Mis notificaciones

El sistema avisa de lo que necesita atención (un terminal sin conexión, jornadas sin salida, un contrato por vencer...) por la **campanita** y, en algunos casos, por **correo**. Cada usuario decide **qué avisos recibe, por dónde y cuándo**, sin afectar a los demás.

## Dónde se configura

Clic en el nombre de usuario (esquina superior derecha) → **Mis notificaciones**. No requiere permisos especiales: cada persona edita solo las suyas.

![Pantalla Mis notificaciones](/docs-images/24-notificaciones.png)

La pantalla muestra únicamente los avisos de los módulos a los que el usuario tiene acceso. Por ejemplo, los avisos de terminales aparecen solo para quien tiene el permiso **Editar Terminal**, y los de contratos para quien puede ver contratos. Un usuario cuyo rol no recibe avisos configurables ve un mensaje que lo indica.

## Qué se puede elegir por aviso

| Opción | Qué hace |
|--------|----------|
| **Interruptor** | Activa o desactiva el aviso. Desactivado, no llega por ningún canal |
| **Canal** | **Campanita**, **Correo** o ambos. Con el aviso activo hay que elegir al menos uno |
| **Frecuencia** | **Al instante** (llega cuando ocurre) o **En el resumen diario** (se junta con los demás y llega una vez al día) |

Los avisos que ya llegan **una vez al día por sí mismos** (**Jornadas sin salida**, **Empleados sin horario** y **Contratos por vencer o vencidos**) solo se envían al instante: la frecuencia aparece bloqueada con la indicación *Ya llega una vez al día*.

Los cambios aplican **desde el próximo aviso**; lo que ya llegó no se modifica.

## Resumen diario

Los avisos con frecuencia **En el resumen diario** no llegan uno por uno: se acumulan y el sistema envía **una sola notificación** con el conteo por tipo (por ejemplo, *3 × Terminal sin conexión · 1 × Batería baja*). Si algún aviso del resumen tiene el canal **Correo**, ese detalle llega además por correo con una línea por aviso.

La **Hora del resumen** se elige en la parte superior de la pantalla (por defecto **08:00**, hora de Asunción) y es personal de cada usuario.

## Avisos obligatorios

Dos avisos no se pueden desactivar ni mandar al resumen, porque quedarse sin enterarse frena las marcaciones o afecta la seguridad. Sí se puede elegir su canal:

- **Terminal sin vincular**: un terminal perdió su acceso y no puede marcar hasta volver a vincularlo.
- **Celular re-vinculado**: un empleado vinculó un celular nuevo y se revocó el anterior.

## Valores por defecto

Quien nunca tocó su configuración recibe los avisos como siempre, por la campanita, con estas excepciones:

- **Terminal reconectado** viene **desactivado**. La reconexión queda siempre en la **Bitácora** del terminal, y cuando un terminal vuelve a reportar, su aviso de **Terminal sin conexión** se marca como leído automáticamente: la campanita muestra solo lo que sigue caído. Quien quiera el aviso de reconexión puede activarlo.
- **Terminal provisionado** y **Celular re-vinculado** llegan además por **correo**.
- **Terminal provisionado** no le llega a quien generó el enlace de configuración, abrió la ventana de vinculación o aprobó la solicitud: ya sabe que lo hizo.

El botón **Restablecer valores** (arriba a la derecha) borra las elecciones del usuario y vuelve a estos valores y a la hora de resumen de las 08:00.

## Avisos disponibles

### Salud de terminales

Requieren el permiso **Editar Terminal**. Detalle en el capítulo **Terminales de Marcación → Avisos automáticos**.

| Aviso | Cuándo llega |
|-------|--------------|
| **Terminal sin conexión** | Un terminal pasó a Desconectado dentro de su horario de vigilancia (varios a la vez llegan en un solo aviso) |
| **Terminal reconectado** | Un terminal que había avisado su desconexión volvió a reportar (desactivado por defecto) |
| **Terminal sin vincular** | Un terminal perdió su acceso (obligatorio) |
| **Cola de marcaciones atascada** | Marcaciones pendientes que no se envían hace más del tiempo configurado |
| **Batería baja** | El terminal bajó del porcentaje configurado y no está cargando |
| **Sucursal sin empleados enrolados** | Nadie en la sucursal tiene rostro registrado |
| **Terminal sin empleados en caché** | El terminal está en línea pero no tiene empleados cargados |
| **Reloj del terminal desajustado** | La hora del dispositivo difiere del servidor |
| **App del terminal desactualizada** | El terminal sigue con una versión anterior tras un despliegue |
| **Terminal en una pestaña del navegador** | Un terminal lleva horas funcionando en una pestaña, sin instalar como app ni en modo kiosko |
| **Pestaña del terminal oculta** | La pestaña de un terminal lleva horas oculta o minimizada: el equipo puede suspenderse |

### Vinculación de terminales

Requieren el permiso **Editar Terminal**.

| Aviso | Cuándo llega |
|-------|--------------|
| **Solicitud de vinculación** | Un dispositivo pidió vincularse con un código y espera aprobación |
| **Terminal provisionado** | Un dispositivo completó su vinculación |
| **Ventana de vinculación vencida** | La ventana que abriste venció sin que ningún dispositivo la usara |
| **Comando remoto vencido** | Un comando que enviaste a un terminal venció sin aplicarse |

### Asistencia

| Aviso | Permiso necesario | Cuándo llega |
|-------|-------------------|--------------|
| **Jornadas sin salida** | Ver asistencias | Resumen diario (08:52) de jornadas con entrada y sin salida |
| **Empleados sin horario** | Editar horarios | Resumen diario (08:55) de empleados activos sin horario |
| **Conflicto de sincronización** | Editar fallos de marcación | Una marcación hecha sin conexión no pudo registrarse |
| **Autoenrolamiento facial pendiente** | Editar enrolamientos faciales | Un empleado completó su registro facial y espera aprobación |

### Contratos y dispositivos

| Aviso | Permiso necesario | Cuándo llega |
|-------|-------------------|--------------|
| **Contratos por vencer o vencidos** | Ver contratos | Aviso diario (08:00) según los días configurados |
| **Celular vinculado** | Ver dispositivos de empleados | Un empleado vinculó su celular |
| **Celular re-vinculado** | Ver dispositivos de empleados | Un empleado vinculó un celular nuevo (obligatorio) |
| **Vinculación de celular sin permiso** | Editar empleados | Un empleado no habilitado intentó vincular su celular |
| **Límite de vinculación agotado** | Ver dispositivos de empleados | Se agotaron los intentos diarios de vinculación desde una IP |

> **Quién recibe qué:** además de la elección de cada usuario, un aviso solo llega a quien tiene el permiso del módulo. Si a un usuario le cambian el rol, la lista de su pantalla se ajusta sola.

## Solución de problemas

| Síntoma | Causa probable |
|---------|----------------|
| No aparece un aviso en la pantalla | El usuario no tiene el permiso del módulo. Un administrador debe ajustar el rol (ver **Roles y Permisos**) |
| Dejé un aviso activo y no llega | Puede estar en **En el resumen diario**: llega a la hora del resumen. También puede ser que el evento no haya ocurrido |
| El correo no llega | El servidor de correo no está configurado o el correo del usuario es incorrecto. La campanita no depende del correo |
| No puedo guardar | Hay un aviso activo sin ningún canal marcado |
