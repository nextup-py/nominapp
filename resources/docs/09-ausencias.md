# Ausencias

Las ausencias registran los días en que un empleado no asistió sin justificación previa. El sistema las detecta automáticamente al calcular la asistencia diaria.

---

## Estados de una ausencia

| Estado | Descripción |
|--------|-------------|
| **Pendiente** | Sin revisión todavía |
| **Justificada** | El empleado presentó justificación válida — sin descuento |
| **Injustificada** | Sin justificación válida — genera descuento automático en nómina |

---

## Revisar una ausencia

1. Ir a **Empleados → Ausencias**
2. Clic sobre la ausencia a revisar
3. Desde el detalle, según el estado actual, están disponibles:

| Acción | Disponible desde | Qué hace |
|--------|-------------------|----------|
| **Registrar asistencia** | Pendiente o Injustificada | El empleado sí estuvo presente pero no marcó — crea las marcaciones y justifica la ausencia automáticamente (si había una deducción generada, se elimina) |
| **Justificar** | Pendiente o Injustificada | El empleado no estuvo pero tiene razón válida — vincula (o crea) un permiso que cubra la fecha |
| **Marcar Injustificada** | Pendiente o Justificada | Confirma la ausencia sin justificación y genera la deducción salarial |

> Estas acciones son **reversibles entre sí**: por ejemplo, una ausencia ya marcada como Injustificada puede pasarse a Justificada, y en ese caso el sistema elimina automáticamente la deducción que había generado.

---

## Justificar una ausencia

Al hacer clic en **Justificar**, el modal ofrece dos formas de resolver la ausencia:

- **Vincular a permiso ya existente** — si el empleado ya tiene un permiso **aprobado** que cubre esa fecha, se elige de la lista (el sistema los precarga automáticamente).
- **Crear permiso nuevo ahora** — si no existe ninguno, se puede cargar uno en el momento sin salir del modal: tipo de permiso, fecha de fin (por defecto el mismo día) y motivo opcional. Al confirmar, el sistema crea el permiso, lo aprueba automáticamente y justifica la ausencia (y cualquier otra ausencia del mismo período que quede cubierta).

> Si el empleado no tiene permisos aprobados para la fecha, el modal preselecciona automáticamente el modo "Crear permiso nuevo ahora".

Para más información sobre permisos y licencias ver el capítulo **Permisos y Licencias**.

---

## Descuento por ausencia injustificada

El monto descontado se calcula según el tipo de contrato del empleado:

| Tipo de contrato | Fórmula |
|-----------------|---------|
| **Mensual** | Salario base ÷ 30 |
| **Jornalero** | Tarifa diaria pactada |

El descuento se aplica automáticamente en la siguiente nómina del empleado.

---

## Acciones masivas y creación manual

Desde el listado, seleccionando varias ausencias con el checkbox, están disponibles:

- **Justificar seleccionadas** — justifica en bloque (requiere que cada una tenga cómo justificarse).
- **Marcar injustificadas** — marca en bloque y genera las deducciones correspondientes.

El listado también tiene pestañas para filtrar rápido: **Todas**, **Pendientes**, **Justificadas**, **Injustificadas**.

**Nueva Ausencia** (botón del encabezado): permite crear manualmente el registro de ausencia para un día que el sistema ya marcó como ausente (`AttendanceDay` en estado "ausente") pero que todavía no tiene un registro de `Absence` asociado — un caso excepcional de limpieza de datos, no la forma normal en que se generan las ausencias.

---

## Reporte de ausencias

Ir a **Reportes → Reporte de Asistencia**, pestaña **Ausencias** (comparte pantalla con las pestañas Asistencias y Horas Extras y Tardanzas, con los mismos filtros de período, empresa, sucursal y departamento), para ver un resumen por período con:
- Total de ausencias por empleado
- Ausencias justificadas vs. injustificadas
- Monto total de descuentos generados

Desde ahí también se puede exportar el detalle y el resumen a Excel.
