# Vacaciones

El módulo de Vacaciones gestiona el saldo anual de días de descanso remunerado de cada empleado y las solicitudes de uso de esos días. El saldo se calcula automáticamente según la antigüedad.

---

## Días a los que tiene derecho (según antigüedad)

| Antigüedad | Días hábiles |
|------------|--------------|
| Menos de 1 año | Sin derecho |
| 1 a 5 años | 12 días |
| 6 a 10 años | 18 días |
| Más de 10 años | 30 días |

> La antigüedad mínima para tener derecho se configura en **Ajustes → Nómina**. Si el empleado no la cumple, el sistema no permite cargar una solicitud.

---

## Saldo de vacaciones

Hay dos formas de consultar el saldo:

- **Por empleado:** abrir el perfil del empleado en **Empleados**, pestaña **Vacaciones** — muestra el historial de solicitudes y permite gestionarlas desde ahí mismo (solicitar, aprobar, rechazar, registrar pago).
- **Por año/sucursal (reporte agregado):** **Empleados → Vacaciones → Ver Balances** — tabla con el saldo de todos los empleados (con derecho, usados, pendientes, disponibles), filtrable por año, empresa y sucursal.

> Los balances anuales no se generan automáticamente: hay que crearlos con el botón **Generar Balances** en la pantalla de Balances (elige el año; genera el saldo solo para los empleados activos que todavía no lo tengan ese año). Al cargar la primera solicitud de un empleado el sistema también genera su balance del año en curso si no existe.

---

## Reporte de Vacaciones

A diferencia de los Balances (que muestran el saldo acumulado por empleado y año), el **Reporte de Vacaciones** lista **cada período de vacación tomado individualmente** — útil para ver el detalle de solicitudes en un rango de fechas.

Ir a **Reportes → Reporte de Vacaciones**.

**Columnas:** Empleado, CI, Sucursal, Inicio, Fin, Días hábiles, Monto, Forma de pago, Estado.

**Filtros:** Año, Mes, Empresa, Sucursal, Estado.

**Exportar:** PDF (con selector de columnas y orientación) o Excel (con selector de columnas), desde los botones del encabezado.

---

## Registrar una solicitud de vacaciones

1. Ir a **Empleados → Vacaciones** (o a la pestaña **Vacaciones** del empleado)
2. Clic en **Nueva Solicitud**
3. Seleccionar el **empleado** — el formulario muestra de inmediato su antigüedad, el derecho anual y los días disponibles; si no tiene antigüedad suficiente o no le quedan días disponibles, la sección de fechas ni siquiera se muestra
4. Elegir la **forma de pago**:
   - **Pago adelantado:** el empleado cobra la remuneración vacacional antes de salir
   - **Con próximo salario:** se incluye automáticamente en la nómina del período que contiene el inicio de las vacaciones
5. Ingresar **fecha de inicio** y **fecha de fin** — el sistema calcula automáticamente los días hábiles, la fecha de reintegro y una estimación del monto a cobrar, y avisa si el período incluye feriados o si los días quedan por debajo del fraccionamiento mínimo legal (6 días hábiles)
6. Opcionalmente, cargar un motivo u observación
7. Guardar

> La fecha de reintegro **no se ingresa manualmente** — el sistema la calcula a partir de la fecha de fin.

---

## Estados de una solicitud

| Estado | Descripción |
|--------|-------------|
| **Pendiente** | Creada, esperando aprobación. Los días quedan reservados como "pendientes" en el balance. |
| **Aprobado** | Autorizada — los días pasan de "pendientes" a "usados" en el balance. |
| **Rechazado** | No autorizada — los días reservados vuelven al saldo disponible. |

## Acciones disponibles según estado

**Desde Pendiente:**
- **Aprobar** — confirma el período y descuenta los días del balance.
- **Rechazar** — libera los días reservados.
- **Editar** — disponible mientras la solicitud no esté aprobada (pendiente o rechazada).
- **Eliminar** — disponible en los mismos casos que Editar (pendiente o rechazada); si la solicitud estaba pendiente, libera los días reservados antes de borrar.

**Desde Aprobado:**
- **Desaprobar** — revierte a Pendiente (solo si el pago vacacional todavía no fue registrado). Si las vacaciones ya comenzaron o ya pasaron, el sistema muestra una advertencia antes de confirmar.
- **Marcar como pagado** (solo si la forma de pago es "Pago adelantado") — registra la fecha de pago del monto vacacional.
- **Revertir Pago** — deshace el registro de pago (esto solo corrige el sistema; el dinero ya entregado al empleado debe gestionarse aparte).
- **Generar Documentos** — genera en PDF, a elección: Comunicación de Vacaciones, Solicitud de Usufructo y/o Recibo de Liquidación (Art. 220 C.L.). Si se eligen varios, se descargan juntos en un ZIP.
- **Editar** ya no está disponible una vez aprobada la solicitud.

**Con pago registrado ("Con próximo salario"):** el pago se marca automáticamente al procesar la nómina del período correspondiente — no requiere acción manual.
