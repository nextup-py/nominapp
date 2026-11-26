# Horarios de Trabajo

Los horarios definen los turnos laborales de los empleados. Se usan para calcular horas trabajadas, horas extra, tardanzas y ausencias.

## Conceptos clave

- **Horario:** plantilla reutilizable con un tipo de jornada y la configuración de cada día de la semana
- **Día del horario:** define si ese día es laboral, y cuáles son las horas de entrada y salida esperadas
- **Descanso:** pausa dentro del turno (ej: almuerzo 12:00–13:00). Se descuenta de las horas netas del día
- **Asignación:** vínculo entre un empleado y un horario, con fecha de inicio y fin opcional

## Tipos de jornada

| Valor | Descripción |
|-------|-------------|
| **Diurno** | Turno entre 06:00 y 20:00 |
| **Nocturno** | Turno entre 20:00 y 06:00 |
| **Mixto** | Combinación de ambos |

> **Turnos que cruzan medianoche** (ej. 23:00 a 07:00): la salida se entiende como del día siguiente. Las horas esperadas, la tardanza, la salida anticipada y las horas extra se calculan sobre ese tramo completo, y las horas extra de madrugada (00:00 a 06:00) cuentan como nocturnas.

El tipo de jornada determina las horas mensuales de referencia y los multiplicadores de horas extra que se aplican en nómina.

## Crear un horario

1. Ir a **Organización → Horarios**
2. Clic en **Nuevo horario**
3. Ingresar el nombre (ej: "Administrativo 08:00–17:00"; no puede repetirse con el de otro horario) y seleccionar el tipo de jornada
4. Guardar

![Detalle de un horario con su resumen semanal y la tabla de días](/docs-images/03-horario-detalle.png)

### Detalle del horario

El detalle muestra, en **Información General**, el tipo de jornada, el estado y un resumen: la **jornada semanal** (días con turno y horas netas por semana, p. ej. «6 días · 47 h por semana», con los descansos ya descontados), los **empleados asignados** hoy (o «Sin empleados asignados») y, si algún día termina al día siguiente, la etiqueta **Cruza medianoche**. **Configuración de Días** es una tabla de una fila por día con entrada, salida (con «día siguiente» si cruza medianoche), descansos y horas netas; los días sin turno figuran como **Libre**.

En el encabezado, **Editar** queda a la vista y el menú **Más acciones** agrupa **Duplicar** y, al final y en rojo, **Desactivar** (o **Activar** si está inactivo).

### Configurar los días del horario

Dentro del horario creado, la pestaña **Días** muestra los 7 días de la semana. Para cada día:

- **Activar** el día si es laboral (los días desactivados se tratan como descanso semanal)
- Ingresar la **hora de entrada** y la **hora de salida** esperadas

- **Copiar a otros días:** el ícono de copiar de cada día activo abre una ventana para elegir los días de destino (p. ej. cargar el lunes y copiarlo de martes a viernes). Copia la entrada, la salida y los descansos, activa los días elegidos y reemplaza lo que tuvieran cargado. Los cambios se guardan al guardar el horario.
- **Horas netas:** bajo cada día activo se muestran sus horas netas (con los descansos descontados) y, arriba de la lista, el resumen semanal («6 días · 47 h netas por semana»).

> Las horas netas del día se calculan automáticamente restando la duración de los descansos configurados.

### Configurar descansos

Desde la pestaña **Descansos** (o dentro de cada día), puede agregar pausas:

1. Clic en **Nuevo descanso**
2. Ingresar nombre (ej: "Almuerzo"), hora de inicio y fin
3. Guardar

El sistema descuenta automáticamente los minutos de descanso al calcular las horas trabajadas del día.

### Avisos del formulario

- **Empleados afectados:** al editar un horario que tiene empleados asignados, un aviso al inicio indica cuántos son. Los cambios rigen desde hoy y las jornadas ya calculadas no cambian hasta recalcularlas.
- **Nombre:** se guarda con la primera letra en mayúscula; el resto se respeta como se escribió.

- **Tipo de jornada sugerido:** debajo del campo se indica el tipo que corresponde según las horas cargadas (diurno si no pasa de las 20:00 ni empieza antes de las 06:00; nocturno si algún día tiene 3,5 horas o más en la franja 20:00–06:00; mixto en el resto). Es solo una sugerencia: el tipo se elige a mano.
- **Pocos días activos:** si el horario tiene uno o dos días activos, aparece un aviso. Quien lo tenga asignado se considera libre los demás días, así que un turno que se reparte en varios horarios por día conviene cargarlo en uno solo con todos sus días.

![Formulario de un horario con el tipo de jornada sugerido y el aviso de pocos días activos](/docs-images/03-horario-formulario.png)

### Listado de horarios

Cada fila muestra el nombre, el estado, el tipo de jornada, el **Resumen semanal** (p. ej. «6 días · 47 h por semana», con la marca «Cruza la medianoche» si algún día termina al día siguiente) y los empleados asignados hoy. Arriba hay pestañas **Activos** / **Inactivos** / **Todos** y filtros por tipo de jornada y por empleados asignados.

En cada fila, **Asignar a Empleados** está a la vista; **Duplicar** y **Desactivar** están en el menú de tres puntos. En la ventana de asignación, el filtro de sucursal ofrece solo sucursales activas y un texto bajo **Empleados** indica cuántos quedan disponibles. **Exportar** descarga solo los horarios que muestra el listado (según pestaña, filtros y búsqueda).

Para cambiar el estado de varios horarios a la vez, seleccionarlos y usar **Desactivar seleccionados** o **Activar seleccionados**. Al desactivar se omiten los que tienen asignaciones vigentes o futuras y los que ya estaban inactivos; la notificación informa cuántos se cambiaron y cuántos se omitieron.

> **Permisos:** asignar y quitar empleados (en el listado y en las pestañas del detalle) requiere el permiso de edición de horarios; quien solo puede ver horarios no ve esos botones, pero sí puede exportar. La búsqueda global (**Ctrl+K**) encuentra horarios por nombre, incluidos los inactivos, y abre su detalle.

### Duplicar un horario

La acción **Duplicar** (en el listado y, en el detalle del horario, dentro de **Más acciones**) copia los días, horas y descansos con un nombre nuevo, para partir de un horario parecido en vez de armarlo desde cero. No copia los empleados asignados. Requiere permiso para crear horarios.

![Ventana Duplicar horario con el nombre propuesto](/docs-images/03-horario-duplicar.png)

### Pestañas del detalle

Debajo de la tabla de días, el detalle del horario tiene cuatro pestañas:

| Pestaña | Qué muestra |
|---------|-------------|
| **Empleados Asignados** | Quienes tienen el horario hoy, con **Vigente desde** y **Hasta** (la fecha de fin programada, o «Sin fecha de fin»). Filtros por sucursal, cargo y departamento. La columna **Empresa** solo aparece si hay más de una empresa activa. Acciones **Asignar**, **Remover** y **Exportar**. |
| **Historial de asignaciones** | Todas las asignaciones del horario (vigentes, futuras y finalizadas), con las notas de cada una. Filtros por **Empleado**, por **Estado** y por **Tuvo el horario entre** (dos fechas: muestra quien lo tuvo en algún momento de ese período). **Exportar** descarga a Excel lo que muestra la lista, con los filtros aplicados. |
| **Cargos con este horario** | Los cargos que lo tienen como horario sugerido (solo lectura; clic en un cargo abre su ficha). Sirve para saber qué cargos se ven afectados antes de cambiar o desactivar el horario. |
| **Historial de cambios** | Quién modificó el nombre, la descripción, el tipo de jornada, el estado o los días, horas y descansos, y cuándo, con los valores anteriores y los nuevos. Los cambios de días muestran solo los días que cambiaron. |

![Pestaña Historial de cambios de un horario](/docs-images/03-horario-historial.png)

> El historial de cambios registra desde que se instaló esta versión: los cambios anteriores no figuran.

### Eliminar o desactivar un horario

Un horario con asignaciones de empleados —vigentes **o finalizadas**— o usado como horario fijo de algún empleado **no se puede eliminar** y el botón **Eliminar** ni aparece (solo **Desactivar**): borrarlo perdería el historial con el que se calculan las jornadas pasadas. Quitar a un empleado de un horario (**Remover**) solo cierra su asignación (en la fecha que se elija, hoy por defecto); la asignación finalizada sigue contando y por eso el horario no se puede eliminar aunque ya no tenga a nadie.

Para dejar de usar un horario sin perder el historial, **desactivarlo**:

- **Desactivar** está en el listado, en el detalle (menú **Más acciones**) y en el interruptor **Activo** del formulario de edición.
- Un horario inactivo **no se ofrece** al asignar horarios a empleados, ni como horario inicial del alta, ni como horario sugerido de un cargo, ni en la sugerencia según marcaciones. Sigue valiendo para quienes ya lo tienen asignado y no se pierde ningún dato.
- Solo se puede desactivar si **no tiene asignaciones vigentes ni futuras** ni empleados con ese horario fijo. Las asignaciones finalizadas no lo impiden, y tampoco una que termina hoy (lo que deja **Remover** con la fecha de hoy). Si las tiene, primero hay que asignar a esos empleados otro horario.
- El listado abre en la pestaña **Activos**; las pestañas **Inactivos** y **Todos** (con su cantidad) permiten ver el resto, y **Activar** vuelve a ofrecer el horario.

La pestaña **Historial de asignaciones** del horario lista todas sus asignaciones (empleado, desde, hasta, estado —Vigente, Futura o Finalizada— y quién la asignó), y permite ver qué impide eliminarlo. La pestaña **Empleados Asignados** muestra solo las asignaciones que siguen vigentes después de hoy.

## Asignar un horario a un empleado

La asignación se hace **desde el horario, no desde el perfil del empleado**:

1. Ir a **Organización → Horarios**
2. Abrir el horario a asignar
3. En la pestaña **Empleados Asignados**, clic en **Asignar**
4. Seleccionar uno o varios empleados (opcionalmente filtrando por sucursal) y confirmar

En el mismo formulario se elige **Rige desde** (por defecto hoy), **Hasta** (opcional) y notas. Una fecha futura programa el cambio: el horario actual sigue vigente hasta el día anterior. Los días ya calculados no se recalculan solos: para que las jornadas ya registradas tomen el horario nuevo, active **Recalcular las jornadas ya registradas desde esa fecha** (solo con fecha de inicio de hoy o anterior).

> Si alguno de los empleados seleccionados ya tiene un horario vigente, se indica junto a su nombre (**— hoy: nombre del horario**) — al confirmar, ese horario anterior se cierra automáticamente (el día anterior a **Rige desde**) y el nuevo queda vigente desde esa fecha.

### Recalcular jornadas ya registradas

Un recálculo normal en **Asistencias** conserva las horas esperadas con que se calculó cada jornada. Por eso, al asignar un horario a un empleado que ya tenía marcaciones, la opción **Recalcular las jornadas ya registradas desde esa fecha** vuelve a calcular esas jornadas con el horario nuevo (horas esperadas, tardanza, extras). Está desactivada por defecto y, al terminar, la notificación informa cuántas jornadas se recalcularon y cuántas se omitieron:

- Las que tienen **horas extra o tardanza aprobadas**, **ajuste manual** o **doble turno aceptado como jornada normal**: no se pisa lo que RR.HH. ya decidió.
- Las de períodos con **nómina ya generada** para ese empleado.

Las ausencias no se generan hacia atrás: solo se crean automáticamente las de hoy y ayer.

### Horario sugerido según las marcaciones

Al abrir **Asignar horario** sobre un empleado (fila del listado de **Empleados**), si tiene al menos 3 jornadas completas (con entrada y salida) en los últimos 30 días, el sistema compara sus horas reales con los días activos de cada horario y **preselecciona el que mejor coincide**, siempre que el desvío promedio sea de 60 minutos o menos. La descripción de la ventana indica cuántas jornadas se usaron. Es solo una ayuda: se puede elegir otro horario antes de confirmar, y nunca se asigna nada sin confirmar. Un horario que tiene libres los días en que el empleado trabajó se penaliza.

![Ventana Asignar horario con el horario sugerido preseleccionado](/docs-images/03-asignar-horario-sugerido.png)

### Asignar desde el listado de Empleados

También se puede asignar sin abrir el horario. En **Empleados**, menú de la fila → **Asignar horario**, o seleccione varios empleados y use **Asignar horario** en las acciones masivas. Se elige el horario y la vigencia (**Rige desde**, **Hasta**, notas). Los empleados inactivos y los que ya tienen ese mismo horario vigente se omiten, y la notificación informa cuántos se asignaron. Requiere permiso de edición sobre horarios.

Para encontrar a quienes **no tienen horario fijo, rotación ni turnos planificados** (los turnos cargados en el Planificador en los últimos 14 días o a futuro cuentan como horario), use el filtro **Sin horario asignado** del listado de Empleados. El mismo aviso aparece (con un enlace al **Planificador de Turnos** para quienes se organizan por turnos) como tarjeta **Empleados sin horario** en **Asistencias → Por resolver** y como mensaje bajo el título de **Asistencias**, con enlace al listado ya filtrado.

Para quitar la asignación de un empleado sin darle otro horario, usar **Remover** en la fila del empleado (o la acción masiva "Remover seleccionados") — pide hasta qué fecha conserva el horario (hoy por defecto, inclusive; una fecha futura programa el cambio) y cierra la asignación sin eliminar el historial. Con hoy o una fecha pasada la persona deja de figurar en **Empleados Asignados** y queda en la pestaña **Historial de asignaciones** del horario.

> Un empleado puede tener historial de asignaciones. El sistema usa automáticamente la asignación vigente en la fecha de cada marcación para calcular la asistencia.

## Cambiar el horario de un empleado

No elimine la asignación anterior. En cambio, use **Asignar** en el horario nuevo — el sistema cierra automáticamente la asignación anterior (fecha de fin = hoy) al crear la nueva. El historial queda preservado.

## Notas importantes

- Si un empleado no tiene horario asignado, sus marcaciones se registran pero **no se calculan** horas trabajadas ni ausencias automáticamente.
- Un mismo horario puede asignarse a múltiples empleados.
- Un empleado con un **patrón de rotación** asignado (ver sección siguiente) no necesita horario fijo — la rotación tiene prioridad sobre el horario fijo al calcular el turno del día.

---

# Turnos

Un **turno** es una franja de trabajo reutilizable (Mañana, Tarde, Noche, Franco) que se usa para armar **patrones de rotación** y para asignar **turnos puntuales** en el Planificador. Se gestionan en **Horarios y turnos → Turnos**.

## Listado

- Pestañas **Activos** (la que abre por defecto), **Inactivos** y **Todos**, con el conteo de cada una.
- Cada fila muestra el color, el nombre, la jornada, el **horario con las horas netas** (ej. `07:00–15:00 · 7 h netas`, con la marca «Cruza la medianoche» si corresponde) y el estado. Los francos muestran «Franco».
- La columna y el filtro de **Empresa** aparecen solo cuando hay más de una empresa activa.
- Un clic en la fila abre el detalle. El menú de la fila ofrece **Duplicar**, **Editar** y **Desactivar / Activar**.
- Acciones masivas **Desactivar seleccionados** y **Activar seleccionados**: omiten los que ya están en ese estado y, al desactivar, los que están en uso.

## Crear o editar un turno

1. Clic en **Nuevo Turno**.
2. Completar el **nombre** (único dentro de la empresa; solo se pone en mayúscula la primera letra), el **tipo de jornada** y el **color** con que se verá en el Planificador. La **empresa** se pide solo si hay más de una activa; con una sola se asigna sola.
3. En **Horario**, indicar entrada, salida y minutos de descanso, o activar **Es día libre (Franco)**. El formulario calcula las **horas netas** y avisa si el turno cruza la medianoche (salida menor que la entrada).
4. Guardar.

> Con el turno ya en uso, el formulario lo avisa: los cambios de horario rigen desde ya para esos patrones, pero las jornadas ya calculadas no cambian hasta recalcularlas. La empresa no se puede cambiar mientras el turno esté en uso.

## Detalle del turno

Muestra el horario, el descanso, el color, el estado y la empresa. Debajo hay tres pestañas de solo lectura:

| Pestaña | Contenido |
|---------|-----------|
| **Patrones de rotación** | Patrones cuyo ciclo incluye el turno y cuántos días del ciclo lo usan |
| **Turnos puntuales** | Empleados y fechas del Planificador que usan el turno (con filtro «Solo de hoy en adelante») |
| **Historial de cambios** | Quién modificó nombre, jornada, horario, color o estado, y cuándo |

En el encabezado, **Editar** queda a la vista y **Más acciones** agrupa **Duplicar** y **Desactivar / Activar**.

## Desactivar, activar y eliminar

- **Desactivar:** el turno deja de ofrecerse al armar patrones y en el Planificador, pero se conserva el historial. Se bloquea mientras esté en un **patrón de rotación activo** o en un **turno puntual de hoy en adelante**; el aviso indica cuántos son. Patrones inactivos y turnos puntuales pasados no lo impiden.
- **Activar:** vuelve a ofrecerlo.
- **Eliminar:** solo aparece (en **Editar**) si el turno **nunca se usó** en un patrón ni en el Planificador. Si alguna vez se usó, el camino es desactivarlo.
- **Duplicar:** crea una copia con otro nombre (mismo horario, color y notas); no copia el uso.

## Permisos

Crear, editar, duplicar y activar o desactivar requieren permisos de edición sobre turnos; con un rol de solo lectura esas acciones no se muestran. Los turnos también se encuentran con la búsqueda global (**Ctrl+K**).

---

# Patrones de Rotación

Para empleados cuyo turno cambia según un ciclo (ej: 6 días de mañana, 1 franco, repetir), en lugar de un horario fijo se usa un **patrón de rotación**. Se gestionan en **Horarios y turnos → Patrones de rotación**.

## Conceptos clave

- **Patrón de rotación:** ciclo ordenado de turnos (ej: Día 1 = Turno Mañana, Día 2 = Turno Mañana, ..., Día 7 = Franco). El ciclo se repite automáticamente una vez completado.
- **Turno del ciclo:** cada día del patrón apunta a un turno ya creado en **Horarios y turnos → Turnos** — incluyendo días de franco, que también se definen como un "turno" marcado como día de descanso.
- **Asignación de rotación:** vínculo entre un empleado y un patrón, con fecha de inicio de vigencia y un **día de inicio del ciclo** (para que dos empleados con el mismo patrón puedan estar en días distintos del ciclo, ej. uno arranca en el Día 1 y otro en el Día 4).

## Listado

- Pestañas **Activos** (la que abre por defecto), **Inactivos** y **Todos**, con el conteo de cada una.
- Cada fila muestra el nombre, el **resumen del ciclo** (ej. `5 Mañana, 2 Franco · 7 días`), los **empleados vigentes** y el estado. La columna y el filtro de **Empresa** aparecen solo con más de una empresa activa.
- Un clic en la fila abre el detalle. El menú de la fila ofrece **Editar** y **Desactivar / Activar**.
- Acciones masivas **Desactivar seleccionados** y **Activar seleccionados**: omiten los que ya están en ese estado y, al desactivar, los que tienen empleados asignados vigentes o futuros.

## Crear un patrón de rotación

1. Clic en **Nuevo Patrón**
2. Darle un nombre, único dentro de la empresa (ej: "3 Turnos Rotativos 21 días"); solo se pone en mayúscula la primera letra. La **empresa** se pide solo si hay más de una activa
3. En **Secuencia del ciclo**, agregar un ítem por cada día del ciclo y elegir el turno correspondiente — el orden de los ítems define el orden del ciclo (ítem 1 = Día 1, ítem 2 = Día 2, etc.). Se puede arrastrar para reordenar
4. Guardar

> Los turnos deben existir previamente en **Horarios y turnos → Turnos**, y solo se ofrecen los de la misma empresa que estén activos. Con empleados asignados, el formulario avisa que los cambios del ciclo rigen desde hoy y que las jornadas ya calculadas no cambian hasta recalcularlas; la empresa no se puede cambiar.

## Detalle del patrón

Muestra el estado, el resumen del ciclo, los empleados vigentes y la tabla **Ciclo día por día** (turno y horario de cada día). Debajo hay tres pestañas:

| Pestaña | Contenido |
|---------|-----------|
| **Empleados Asignados** | Quién tiene hoy el patrón, desde cuándo, **hasta cuándo** (si ya se programó su salida) y su día de inicio |
| **Historial de asignaciones** | Todas las asignaciones, vigentes, futuras y finalizadas, con filtros por empleado y estado |
| **Historial de cambios** | Quién modificó el nombre, el ciclo o el estado, y cuándo |

En el encabezado, **Editar** queda a la vista y **Más acciones** agrupa **Desactivar / Activar**.

## Asignar un patrón de rotación a un empleado

1. Abrir el patrón a asignar
2. En la pestaña **Empleados Asignados**, clic en **Asignar**
3. Seleccionar uno o varios empleados (opcionalmente filtrando por sucursal)
4. Elegir el **día de inicio** — a qué día del ciclo corresponde el día de hoy para estos empleados (ej: "Día 1: Mañana" si arrancan desde el principio del ciclo)
5. Confirmar

> El día de inicio elegido se aplica igual a todos los empleados seleccionados en el mismo lote. Para que un empleado arranque en un día distinto del ciclo, asignarlo por separado.

Si un empleado ya tenía otra rotación, el selector lo indica entre paréntesis y al confirmar la anterior se cierra automáticamente.

## Quitar a un empleado del patrón

**Remover** (en la fila o como acción masiva) pregunta **«Tiene este patrón hasta»**: el último día, inclusive, en que la persona conserva el patrón. Por defecto es hoy; una fecha futura programa el cambio. Con hoy o una fecha pasada el empleado deja de figurar en la lista y en el contador, pero la asignación queda en el **Historial de asignaciones**.

## Desactivar y eliminar

- **Desactivar:** el patrón deja de ofrecerse al asignar, pero se conserva el historial. Se bloquea mientras tenga **asignaciones vigentes o futuras**; el aviso indica cuántas. Las finalizadas no lo impiden.
- **Activar:** vuelve a ofrecerlo.
- **Eliminar:** solo aparece (en **Editar**) si el patrón **nunca tuvo asignaciones**. Si alguna vez se usó, el camino es desactivarlo.

## Permisos

Crear, editar, asignar, remover y activar o desactivar requieren permisos de edición sobre patrones de rotación; con un rol de solo lectura esas acciones no se muestran. Los patrones también se encuentran con la búsqueda global (**Ctrl+K**).

## Notas importantes

- Un patrón de rotación tiene prioridad sobre el horario fijo: si un empleado tiene ambos (no debería ser el caso normal, pero el sistema no lo impide), se usa la rotación.

---

# Planificador de Turnos

Ir a **Horarios y turnos → Planificador de Turnos** para ver y ajustar, en una sola grilla visual, el turno de cada empleado día por día — sin necesidad de editar patrones de rotación uno por uno.

## Qué muestra la grilla

Una tabla de **empleados × días** (filtrable por empresa y sucursal) donde cada celda muestra el turno que le corresponde a ese empleado ese día según su patrón de rotación. Las celdas con un punto (●) indican un **turno puntual** — un cambio manual que reemplaza, solo para ese día, lo que el patrón asignaría normalmente. La última columna, **Horas**, suma las horas netas planificadas de cada empleado en el rango visible.

Un turno que se desactivó después de asignarse sigue mostrándose donde ya estaba, con la marca **inactivo**.

![Planificador de Turnos con turnos puntuales](/docs-images/03-planificador-turnos.png)

## Asignar un turno puntual

1. Clic en la celda del empleado y día a modificar
2. En el modal, elegir el nuevo turno para ese día y, opcionalmente, un motivo/nota (ej: "Cubre a García")
3. Guardar

Solo se ofrecen turnos **activos de la misma empresa** del empleado, y solo se puede asignar a empleados activos; si algo no corresponde, el modal muestra el motivo. La celda queda marcada como turno puntual. El patrón de rotación del empleado **no se modifica** — el cambio aplica únicamente a esa fecha.

## Mover un turno arrastrando (drag & drop)

Se puede arrastrar la celda de un empleado/día hacia la celda de otro empleado/día: el turno se **copia** al destino como turno puntual con el motivo "Cambio de turno" y la nota "Movido desde [empleado] ([día])"; la celda de origen no cambia (si hay que quitárselo a quien lo cedió, asignarle otro turno o restaurar su ciclo).

## Restaurar el ciclo

Si una celda ya tiene un turno puntual, el modal muestra el botón **Restaurar ciclo**: lo elimina y la celda vuelve al turno del patrón.

## Quién cambió un turno puntual

Cada alta, cambio y restauración queda registrada con el usuario y la fecha. Se consulta en **Horarios y turnos → Turnos → (turno) → Turnos puntuales**, con **Historial** en cada fila, que muestra qué turno tenía antes y cuál tiene ahora; la columna **Registrado por** indica quién lo cargó.

## Permisos

Ver la grilla requiere acceso de lectura a horarios; modificar turnos requiere permiso de edición de horarios. Con solo lectura, la grilla se muestra sin el botón «+», sin arrastrar y con el modal en modo consulta.

> El planificador es una herramienta de ajuste visual sobre las asignaciones ya existentes (rotaciones) — no reemplaza la creación de patrones ni las asignaciones masivas descritas más arriba en este capítulo. Úsalo para excepciones puntuales, no para reorganizar turnos de forma permanente.
