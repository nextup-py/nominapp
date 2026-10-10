# Roles y Permisos

El sistema controla el acceso a cada módulo mediante roles con permisos configurables. Un usuario **sin ningún rol asignado no puede entrar al panel** — es el primer punto a verificar si alguien no logra iniciar sesión pese a tener credenciales correctas.

---

## Roles predefinidos

El sistema trae 4 roles ya configurados de fábrica:

| Rol | Alcance |
|-----|---------|
| **Super Admin** | Acceso total a todos los módulos y acciones, sin restricciones. No requiere que se le asignen permisos uno por uno. |
| **RRHH** | CRUD completo sobre empleados, contratos, asistencia, permisos/licencias, amonestaciones, vacaciones, horarios, dispositivos, terminales y sucursales. |
| **Contador/Nómina** | CRUD completo sobre nómina, préstamos, adelantos, retiros de mercadería, liquidación, aguinaldo, deducciones/percepciones y lotes bancarios. Solo lectura sobre empleados y contratos. |
| **Solo Lectura** | Puede ver (listado y detalle) todos los módulos, sin poder crear, editar ni eliminar nada. |

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

---

![Edición de permisos de un rol](/docs-images/22-rol-editar.png)

## Crear o editar un rol

1. Ir a **Configuración → Roles** (solo visible para usuarios con rol Super Admin)
2. Clic en **Nuevo rol** (o abrir uno existente para editarlo)
3. Ingresar el **nombre del rol**
4. Marcar los permisos deseados — están agrupados por módulo (Organización, Empleados, Asistencia, Nómina y Créditos, Configuración) en secciones colapsables; dentro de cada sección aparecen juntos los permisos CRUD y los de acción de negocio del módulo
5. Guardar

> No hace falta crear un rol desde cero para cada caso de uso — se pueden editar los 4 roles predefinidos para ajustar el alcance según las necesidades de la empresa.

---

## Asignar un rol a un usuario

1. Ir a **Configuración → Usuarios** (solo visible/editable para Super Admin)
2. Abrir el usuario (o crear uno nuevo)
3. En el campo **Roles**, seleccionar uno o varios roles
4. Guardar

> Un usuario puede tener más de un rol a la vez — sus permisos son la unión de todos los roles asignados.

---

## Notas importantes

- Solo un usuario con rol **Super Admin** puede ver el módulo de Roles y asignar roles a otros usuarios — el resto de los usuarios ni siquiera ve esas pantallas en el menú.
- Eliminar o vaciar los permisos de un rol afecta de inmediato a todos los usuarios que lo tengan asignado.
- Este módulo cubre permisos de módulos y de acciones de negocio, pero **no** un registro completo de auditoría de quién hizo cada acción en el sistema — eso corresponde al historial de cambios de cada módulo individual (ver, por ejemplo, "Historial de cambios" en el capítulo **Contratos**), no a este módulo de Roles.
