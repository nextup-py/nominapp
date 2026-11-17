# Roles y Permisos

El sistema controla el acceso a cada módulo mediante roles con permisos configurables. Un usuario **sin ningún rol asignado no puede entrar al panel** — es el primer punto a verificar si alguien no logra iniciar sesión pese a tener credenciales correctas.

---

## Roles predefinidos

El sistema trae 4 roles ya configurados de fábrica, más 3 roles sugeridos (se crean solos en una instalación nueva y, en una ya existente, con el botón **Crear roles sugeridos** de **Configuración → Roles**, que no toca ningún rol que ya exista):

| Rol | Alcance |
|-----|---------|
| **Super Admin** | Acceso total a todos los módulos y acciones, sin restricciones. No requiere que se le asignen permisos uno por uno. |
| **RRHH** | CRUD completo sobre empleados, contratos, asistencia, permisos/licencias, amonestaciones, vacaciones, horarios, dispositivos, terminales y sucursales. |
| **Contador/Nómina** | CRUD completo sobre nómina, préstamos, adelantos, retiros de mercadería, liquidación, aguinaldo, deducciones/percepciones y lotes bancarios. Solo lectura sobre empleados y contratos. |
| **Solo Lectura** | Puede ver (listado y detalle) todos los módulos, sin poder crear, editar ni eliminar nada. |
| **Jefe de equipo** *(sugerido)* | Ve en **Mi equipo** el estado de hoy de las personas que le reportan, directa o indirectamente (solo consulta). No accede al resto del panel. |
| **Supervisor de sucursal** *(sugerido)* | Igual que Jefe de equipo, y además ve a todo el personal de su sucursal. |
| **Encargado de asistencia** *(sugerido)* | Aprueba tardanzas y horas extra, corrige marcaciones y resuelve ausencias de toda la empresa. No ve nómina ni salarios. |
| **RRHH** (documentos) | Además de lo anterior, gestiona los **documentos de los legajos** y el catálogo de **tipos de documento**. Los permisos son propios (**Documento del Empleado** y **Tipo de Documento**): ni Contador/Nómina ni Soporte TI los tienen por defecto. |
| **Soporte TI** *(sugerido)* | Gestiona terminales y su vinculación, dispositivos de empleados, enrolamiento facial y fallos de marcación, y ve **Salud del sistema** y los **logs**. Ve solo el listado de empleados (nombre, CI): no accede a contratos, salarios, nómina ni configuración. |

> El primer usuario que se crea en una instalación nueva (vía `ProductionSeeder` o `DemoSeeder`) queda automáticamente como **Super Admin**. Cualquier usuario existente que se quede sin rol asignado también pasa a Super Admin automáticamente la próxima vez que corran los seeders — pensado para instalaciones que migran a este sistema de roles.

---

## Cómo funcionan los permisos

Cada módulo con Resource en el panel (Empleados, Contratos, Préstamos, etc.) tiene 5 permisos base:

| Permiso | Habilita |
|---------|----------|
| **Ver listado** | Acceder a la tabla del módulo |
| **Ver detalle** | Abrir el registro individual |
| **Crear** | Dar de alta nuevos registros |
| **Editar** | Modificar registros existentes |
| **Eliminar** | Borrar registros |

Además, los módulos con flujo de aprobación (Préstamos, Adelantos, Retiros de Mercadería, Nómina, Liquidación, Aguinaldo, Permisos/Licencias, Ausencias, Contratos, Lotes de Pagos Bancarios) tienen **permisos de acción de negocio** adicionales — por ejemplo "Aprobar Préstamo", "Cerrar Planilla" o "Desembolsar Adelanto" — independientes de los permisos CRUD básicos. Un usuario puede tener permiso para *ver* un préstamo sin tener permiso para *aprobarlo*.

> Las acciones puramente administrativas (cambiar el método de pago, editar un borrador, descargar un archivo ya generado) no tienen permiso de negocio propio — quedan cubiertas por los permisos CRUD estándar (Editar / Ver).

> **Asistencia:** tampoco tienen permiso de negocio propio y se cubren con **Editar** del módulo correspondiente: **Aprobar** y **Descartar** un fallo de marcación (individual o en bloque) requieren **Editar Falla de Marcación**; **Revocar** un dispositivo de empleado, **Editar Dispositivo de Empleado**; y **Corregir marcaciones** en el listado de Asistencias, **Editar Marcación de Asistencia**. Quien solo tiene **Ver** puede consultar el fallo y abrir su **Diagnóstico**, pero no ve los botones que modifican datos.

> **Terminales:** vincular terminales no tiene un permiso propio. Aprobar o rechazar solicitudes de vinculación, abrir o cerrar la ventana de vinculación, generar enlaces de configuración, enviar **comandos remotos** (forzar sincronización, recargar, limpiar caché, reenviar reporte), revocar el acceso de un terminal, activarlo o desactivarlo y editar su **Monitoreo** requieren el permiso **Editar Terminal** — el mismo que ya protege la gestión de terminales. Ver el detalle, la **Bitácora**, los **Comandos** enviados y imprimir la **hoja de instalación** requiere **Ver Terminal**. Los avisos de la campanita (solicitudes de vinculación, ventanas vencidas y alertas de salud) llegan solo a quienes tienen **Editar Terminal**. Con ese permiso (y el módulo de Marcación biométrica activo) aparece **Asistencias → Solicitudes de vinculación** y llegan las notificaciones de nuevas solicitudes. Ver el capítulo **Terminales de Marcación**.

> **Notificaciones:** los avisos de la campanita y el correo solo llegan a quien tiene el permiso del módulo (por ejemplo **Editar Terminal** para los de terminales). Cada usuario ajusta cuáles recibe, por qué canal y con qué frecuencia desde su menú de usuario → **Mis notificaciones**, sin necesidad de un permiso extra (ver el capítulo **Mis notificaciones**).

## Reportes, configuración y descargas

Además de los módulos, estas pantallas y descargas también dependen del rol:

| Pantalla o descarga | Qué se necesita |
|---------------------|-----------------|
| **Configuración de nómina**, **Configuración general** y **Registros del sistema** (logs) | Solo el rol **Super Admin** |
| **Reportes** (Salarios, Empleados, Préstamos, Adelantos, Retiros de Mercadería, Contratos, Asistencia, Vacaciones) y sus PDF o Excel | **Ver listado** del módulo correspondiente (por ejemplo, el Reporte de Salarios pide **Ver listado** de Nóminas) |
| **Simulación de Nómina** y su descarga en Excel | **Ver listado** de Períodos de Nómina |
| **Modos de marcación** | **Ver listado** de Terminales |
| **Planificador de turnos** | **Ver listado** de Horarios; **Editar Horario** para modificar turnos |
| Tarjetas del **Escritorio** | Cada tarjeta se muestra solo si el usuario tiene **Ver listado** del módulo que resume |
| **PDF de un registro** (recibo, contrato, préstamo, adelanto, amonestación, liquidación, legajo, etc.) | **Ver detalle** del módulo correspondiente |

> Un usuario con un rol sin esos permisos no ve la pantalla en el menú y, si abre el enlace directo de una descarga, recibe un error de acceso denegado.

---

![Edición de permisos de un rol](/docs-images/22-rol-editar.png)

## Crear o editar un rol

1. Ir a **Configuración → Roles** (solo visible para usuarios con rol Super Admin)
2. Clic en **Nuevo rol** (o abrir uno existente para editarlo)
3. Ingresar el **nombre del rol** y, si se quiere, una **descripción** (se muestra en el listado)
4. Marcar los permisos deseados — están agrupados por módulo (Organización, Empleados, Asistencia, Nómina y Créditos, Configuración) en secciones colapsables; dentro de cada sección aparecen juntos los permisos CRUD y los de acción de negocio del módulo. Cada sección tiene un **buscador** y la opción de **marcar o desmarcar todo** el módulo
5. Guardar

**Otras acciones del listado de roles:**

- **Ver:** muestra la descripción, los usuarios que tienen el rol y sus permisos resumidos por módulo. Desde ahí, **Comparar con otro rol** lista qué tiene cada uno que el otro no.
- **Duplicar:** crea una copia con otro nombre y los mismos permisos, para ajustarla después (lo más práctico para armar un rol parecido a uno existente).
- La columna **Usuarios** indica cuántos usuarios tiene cada rol.

> **Protecciones:** el rol **Super Admin** no se puede editar, renombrar ni eliminar (todo el sistema lo reconoce por su nombre), y un rol que todavía tiene usuarios asignados no se puede eliminar: primero hay que cambiarles el rol.

> No hace falta crear un rol desde cero para cada caso de uso — se pueden editar los 4 roles predefinidos para ajustar el alcance según las necesidades de la empresa.

---

## Asignar un rol a un usuario

1. Ir a **Configuración → Usuarios** (solo visible/editable para Super Admin)
2. Abrir el usuario (o crear uno nuevo)
3. En el campo **Roles**, seleccionar uno o varios roles
4. Guardar

> Un usuario puede tener más de un rol a la vez — sus permisos son la unión de todos los roles asignados.

---

## Gestionar usuarios

En **Configuración → Usuarios** el listado tiene pestañas **Todos**, **Activos**, **Inactivos** y **Sin rol** (cada una con su cantidad), filtro por **Rol** y las columnas **Roles**, **Estado** y **Último acceso** (la ficha de empleado vinculada se puede mostrar desde el selector de columnas).

| Acción | Qué hace |
|--------|----------|
| **Nuevo Usuario** | Crea el usuario definiendo su contraseña. |
| **Invitar por correo** | Crea el usuario sin contraseña y le envía un enlace para que la defina él mismo. |
| **Desactivar / Activar** (menú de la fila) | Un usuario inactivo no puede entrar al panel y se le cierran las sesiones abiertas; conserva su historial. |
| **Enviar enlace de contraseña** | Envía por correo un enlace para definir una contraseña nueva (también sirve si la olvidó). |
| **Cerrar sesiones** | Cierra todas las sesiones abiertas del usuario. |
| **Eliminar** | Borra el usuario para siempre; si solo hay que impedir que entre, usar Desactivar. |

> No se puede desactivar ni eliminar al propio usuario ni al **último Super Admin activo**. El envío de enlaces e invitaciones requiere el correo configurado en **Configuración General → Correo**; si no sale, el usuario igualmente se crea y se puede reenviar el enlace después. La pantalla de ingreso muestra **¿Olvidaste tu contraseña?** para que cada persona lo pida sola.

También se puede crear el usuario de un empleado desde su ficha (**Empleados → ver empleado → Crear usuario**): usa el correo de la ficha, vincula la ficha al usuario y envía la invitación; solo aparece si el empleado está activo y aún no tiene usuario. Quien tiene el permiso **Crear Usuario** puede invitar, pero solo un Super Admin puede asignar roles.

---

## Notas importantes

- Solo un usuario con rol **Super Admin** puede ver el módulo de Roles y asignar roles a otros usuarios — el resto de los usuarios ni siquiera ve esas pantallas en el menú.
- Eliminar o vaciar los permisos de un rol afecta de inmediato a todos los usuarios que lo tengan asignado.
- Este módulo cubre permisos de módulos y de acciones de negocio, pero **no** un registro completo de auditoría de quién hizo cada acción en el sistema — eso corresponde al historial de cambios de cada módulo individual (ver, por ejemplo, "Historial de cambios" en el capítulo **Contratos**), no a este módulo de Roles.

---

## Permisos especiales

- **Ver Salud del sistema y logs** (grupo *Configuración*): abre **Configuración → Salud del sistema** y el visor de logs. Antes eran solo del Super Admin; ahora se puede dar a otros roles, como **Soporte TI**.
- **Anular** licencias (módulo *Permiso/Licencia*): permite anular una licencia aprobada deshaciendo sus efectos. RR.HH. lo trae.
