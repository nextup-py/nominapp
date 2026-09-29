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

El tipo de jornada determina las horas mensuales de referencia y los multiplicadores de horas extra que se aplican en nómina.

## Crear un horario

1. Ir a **Organización → Horarios**
2. Clic en **Nuevo horario**
3. Ingresar el nombre (ej: "Administrativo 08:00–17:00") y seleccionar el tipo de jornada
4. Guardar

![Detalle de un horario con sus días configurados](/docs-images/03-horario-detalle.png)

### Configurar los días del horario

Dentro del horario creado, la pestaña **Días** muestra los 7 días de la semana. Para cada día:

- **Activar** el día si es laboral (los días desactivados se tratan como descanso semanal)
- Ingresar la **hora de entrada** y la **hora de salida** esperadas

> Las horas netas del día se calculan automáticamente restando la duración de los descansos configurados.

### Configurar descansos

Desde la pestaña **Descansos** (o dentro de cada día), puede agregar pausas:

1. Clic en **Nuevo descanso**
2. Ingresar nombre (ej: "Almuerzo"), hora de inicio y fin
3. Guardar

El sistema descuenta automáticamente los minutos de descanso al calcular las horas trabajadas del día.

## Asignar un horario a un empleado

La asignación se hace **desde el horario, no desde el perfil del empleado**:

1. Ir a **Organización → Horarios**
2. Abrir el horario a asignar
3. En la pestaña **Empleados Asignados**, clic en **Asignar**
4. Seleccionar uno o varios empleados (opcionalmente filtrando por sucursal) y confirmar

> Si alguno de los empleados seleccionados ya tiene un horario vigente, se muestra una advertencia (⚠) — al confirmar, ese horario anterior se cierra automáticamente (fecha de fin = hoy) y el nuevo queda vigente desde hoy.

Para quitar la asignación de un empleado sin darle otro horario, usar **Remover** en la fila del empleado (o la acción masiva "Remover seleccionados") — esto cierra la asignación con fecha de hoy, sin eliminar el historial.

> Un empleado puede tener historial de asignaciones. El sistema usa automáticamente la asignación vigente en la fecha de cada marcación para calcular la asistencia.

## Cambiar el horario de un empleado

No elimine la asignación anterior. En cambio, use **Asignar** en el horario nuevo — el sistema cierra automáticamente la asignación anterior (fecha de fin = hoy) al crear la nueva. El historial queda preservado.

## Notas importantes

- Si un empleado no tiene horario asignado, sus marcaciones se registran pero **no se calculan** horas trabajadas ni ausencias automáticamente.
- Un mismo horario puede asignarse a múltiples empleados.
- Un empleado con un **patrón de rotación** asignado (ver sección siguiente) no necesita horario fijo — la rotación tiene prioridad sobre el horario fijo al calcular el turno del día.

---

# Patrones de Rotación

Para empleados cuyo turno cambia según un ciclo (ej: 6 días de mañana, 1 franco, repetir), en lugar de un horario fijo se usa un **patrón de rotación**.

## Conceptos clave

- **Patrón de rotación:** ciclo ordenado de turnos (ej: Día 1 = Turno Mañana, Día 2 = Turno Mañana, ..., Día 7 = Franco). El ciclo se repite automáticamente una vez completado.
- **Turno del ciclo:** cada día del patrón apunta a un turno ya creado en **Organización → Plantillas de turno** (`ShiftTemplate`) — incluyendo días de franco, que también se definen como un "turno" marcado como día de descanso.
- **Asignación de rotación:** vínculo entre un empleado y un patrón, con fecha de inicio de vigencia y un **día de inicio del ciclo** (para que dos empleados con el mismo patrón puedan estar en días distintos del ciclo, ej. uno arranca en el Día 1 y otro en el Día 4).

## Crear un patrón de rotación

1. Ir a **Organización → Patrones de rotación**
2. Clic en **Nuevo Patrón**
3. Seleccionar la **empresa** y darle un nombre (ej: "3 Turnos Rotativos 21 días")
4. En **Secuencia del ciclo**, agregar un ítem por cada día del ciclo y elegir el turno correspondiente — el orden de los ítems define el orden del ciclo (ítem 1 = Día 1, ítem 2 = Día 2, etc.). Se puede arrastrar para reordenar
5. Guardar

> Los turnos deben existir previamente en **Organización → Plantillas de turno**, filtrados por la misma empresa del patrón.

## Asignar un patrón de rotación a un empleado

1. Ir a **Organización → Patrones de rotación**
2. Abrir el patrón a asignar
3. En la pestaña **Empleados Asignados**, clic en **Asignar**
4. Seleccionar uno o varios empleados (opcionalmente filtrando por sucursal)
5. Elegir el **día de inicio** — a qué día del ciclo corresponde el día de hoy para estos empleados (ej: "Día 1: Turno Mañana" si arrancan desde el principio del ciclo)
6. Confirmar

> El día de inicio elegido se aplica igual a todos los empleados seleccionados en el mismo lote. Para que un empleado arranque en un día distinto del ciclo, asignarlo por separado.

Igual que con los horarios: si un empleado ya tenía una rotación activa, se muestra una advertencia (⚠) y al confirmar la anterior se cierra automáticamente (fecha de fin = hoy).

Para quitar la rotación de un empleado, usar **Remover** en la fila del empleado (o la acción masiva) — cierra la asignación con fecha de hoy sin eliminar el historial.

## Notas importantes

- Un patrón de rotación tiene prioridad sobre el horario fijo: si un empleado tiene ambos (no debería ser el caso normal, pero el sistema no lo impide), se usa la rotación.
- Desactivar un patrón (**Desactivar** en el listado) no elimina las asignaciones existentes, pero los empleados con ese patrón dejan de tener turno calculado — usar con cuidado.

---

# Planificador de Turnos

Ir a **Asistencias → Planificador de Turnos** para ver y ajustar, en una sola grilla visual, el turno de cada empleado día por día — sin necesidad de editar patrones de rotación uno por uno.

## Qué muestra la grilla

Una tabla de **empleados × días** (filtrable por empresa y sucursal) donde cada celda muestra el turno que le corresponde a ese empleado ese día, ya sea por horario fijo o por su patrón de rotación resuelto para esa fecha. Las celdas con un punto (●) indican un **override puntual** — un cambio manual que reemplaza, solo para ese día, lo que el patrón o el horario asignarían normalmente.

![Planificador de Turnos con overrides puntuales](/docs-images/03-planificador-turnos.png)

## Asignar un turno puntual (override)

1. Clic en la celda del empleado y día a modificar
2. En el modal, elegir el nuevo turno para ese día puntual y, opcionalmente, un motivo/nota (ej: "Cambio solicitado por el empleado")
3. Guardar

La celda queda marcada como override (borde punteado). El patrón de rotación del empleado **no se modifica** — el cambio aplica únicamente a esa fecha.

## Mover un turno arrastrando (drag & drop)

Se puede arrastrar la celda de un empleado/día hacia la celda de otro empleado/día para intercambiar el turno entre ambos. Al soltar, el sistema crea automáticamente un override con el motivo "Cambio de turno" (o "Movido desde [empleado] ([día])" cuando corresponde) — sin necesidad de abrir el modal manualmente.

## Restaurar un override

Si una celda ya tiene un override aplicado, el modal de esa celda muestra el botón **Restaurar ciclo** — elimina el override puntual y la celda vuelve a mostrar el turno que le corresponde según el patrón de rotación o el horario fijo del empleado.

> El planificador es una herramienta de ajuste visual sobre las asignaciones ya existentes (horarios y rotaciones) — no reemplaza la creación de patrones ni las asignaciones masivas descritas más arriba en este capítulo. Úsalo para excepciones puntuales, no para reorganizar turnos de forma permanente.
