# Préstamos

El módulo de préstamos permite otorgar créditos a empleados con descuento automático en cuotas mensuales a través de la nómina. Soporta préstamos sin interés y con interés con amortización francesa.

## Estados de un préstamo

| Estado | Descripción |
|--------|-------------|
| **Pendiente** | Creado, aún no revisado. Puede editarse, aprobarse, rechazarse o cancelarse. |
| **Aprobado** | Aprobado por el administrador. Pendiente de entrega del dinero al empleado. |
| **Desembolsado** | El dinero fue entregado al empleado. Las cuotas se descuentan en la próxima nómina. |
| **Pagado** | Todas las cuotas fueron saldadas. Estado final automático. |
| **Rechazado** | Rechazado por el administrador. Estado final. |
| **Cancelado** | Cancelado antes de entregarse. Estado final. |

## Ciclo de vida y acciones

```
Pendiente → Aprobado → Desembolsado → Pagado (automático)
          ↘ Rechazado
          ↘ Cancelado
Aprobado  → Cancelado
```

**Desde Pendiente:**
- **Aprobar** — valida los límites configurados y genera el plan de cuotas. El préstamo queda en espera de la entrega del dinero.
- **Rechazar** — rechaza la solicitud; se puede registrar un motivo opcional.
- **Cancelar** — cancela la solicitud sin efecto en la nómina.

**Desde Aprobado:**
- **Marcar Entregado** — confirma que el dinero llegó al empleado y pasa el préstamo a **Desembolsado**. A partir de este momento el sistema comienza a descontar las cuotas en cada nómina.
- **Cancelar** — cancela el préstamo antes de entregar el dinero; las cuotas generadas se anulan.

**Desde Desembolsado:**
- Sin acciones de mutación. El préstamo sigue su curso hasta ser saldado.

> El préstamo pasa a **Pagado** automáticamente al procesar en nómina la última cuota pendiente.

## Crear un préstamo

1. Ir a **Créditos → Préstamos**
2. Clic en **Nuevo Préstamo**
3. Completar los campos:

| Campo | Descripción |
|-------|-------------|
| **Empleado** | Empleado que solicita el préstamo. |
| **Monto total** | Capital del préstamo en Gs. |
| **Cantidad de cuotas** | Número de cuotas mensuales (máximo configurable en Ajustes). |
| **Tasa de interés anual** | Porcentaje anual. Dejar en 0 para un préstamo sin interés. |
| **Días hasta la primera cuota** | Ver nota abajo. |
| **Motivo** | Descripción de la razón del préstamo (opcional). |
| **Notas** | Observaciones internas (opcional). |

> **Cuota estimada:** el sistema calcula y muestra la cuota en tiempo real mientras se completan los campos, antes de guardar.

### Días hasta la primera cuota

Este campo define cuántos días después de la **aprobación** vence la primera cuota. Por ejemplo:

- Si el préstamo se aprueba el 1 de junio y los días son **30**, la primera cuota vence el 1 de julio.
- Las cuotas siguientes vencen cada 30 días a partir de la primera.

El valor por defecto se configura en **Ajustes → Nómina** (`Días hasta la primera cuota`). Se puede ajustar individualmente por préstamo al momento de crearlo.

## Cuotas y descuento en nómina

Las cuotas se generan automáticamente al **aprobar** el préstamo. Sin embargo, el descuento en nómina solo ocurre una vez que el préstamo pasa al estado **Desembolsado** — un préstamo aprobado pero no desembolsado **no** genera deducción.

Al generar la nómina de un período, el sistema incluye automáticamente las cuotas vencidas de cada empleado. No es necesario agregarlas manualmente.

Para ver el detalle de cuotas, abrir el préstamo y revisar la sección **Cuotas**. Cada cuota muestra:

| Campo | Descripción |
|-------|-------------|
| **N.° de cuota** | Ej: "Cuota 3/12" |
| **Monto total** | Capital + interés de esa cuota |
| **Capital** | Porción que reduce el saldo del préstamo |
| **Interés** | Porción correspondiente a la tasa pactada |
| **Fecha de vencimiento** | Fecha en que la cuota cae en la nómina |
| **Estado** | Pendiente, Pagada o Cancelada |

## Interés y amortización francesa

Cuando se ingresa una **tasa de interés anual** mayor a 0, el sistema usa el método de amortización francesa (cuota fija mensual): la cuota total es constante en todas las cuotas, pero la proporción entre capital e interés cambia — al inicio se paga más interés y al final más capital.

**Fórmula de cuota (PMT):**

```
r = tasa_anual / 100 / 12   (tasa mensual)
PMT = Capital × r × (1+r)^n / ((1+r)^n - 1)
```

Donde `n` es la cantidad de cuotas. Con tasa 0%, la cuota es simplemente `Capital / n`.

> La última cuota puede diferir levemente de las anteriores para absorber el redondeo acumulado.

## Contrato de préstamo en PDF

Desde la vista del préstamo, el botón **Descargar PDF** genera el contrato del préstamo listo para imprimir o archivar. Disponible en los estados **Aprobado**, **Desembolsado** y **Pagado**.

## Reporte de Préstamos

Ir a **Reportes → Reporte de Préstamos** para ver todos los préstamos de la empresa en una sola tabla, con saldo pendiente y cuota mensual calculados.

**Columnas:** Empleado, CI, Empresa, Sucursal, Monto, Cuotas, Cuota Mensual, Saldo Pendiente, Método de pago, Estado, Fecha de Otorgamiento, Aprobado por.

**Filtros:** Estado, Empresa, Sucursal, Empleado, Método de pago, Período (fecha de otorgamiento).

**Exportar:** solo a **Excel** (con selector de columnas) — este reporte no ofrece exportación a PDF; para el contrato en PDF de un préstamo individual usar **Descargar PDF** desde su vista de detalle.

## Límites y validaciones

### Límite de cuota por salario (Art. 245 CLT)

La cuota mensual no puede superar el porcentaje del salario definido en **Ajustes → Nómina** (`% máximo de cuota sobre salario`). El sistema lo valida al aprobar el préstamo y muestra un error si la cuota calculada supera ese límite.

> Por defecto el límite es del **25%** del salario mensual bruto, conforme al Art. 245 del Código Laboral.

### Límites configurables (Ajustes → Nómina)

| Parámetro | Descripción |
|-----------|-------------|
| **Monto máximo** | Importe máximo que puede tener un préstamo (Gs.) |
| **Cuotas máximas** | Cantidad máxima de cuotas permitidas |
| **Tasa de interés máxima** | Tasa anual máxima que se puede ingresar (%) |
| **% máximo de cuota sobre salario** | Límite de la cuota como porcentaje del salario |
| **Días hasta la primera cuota** | Valor por defecto al crear un préstamo |

### Un préstamo activo por empleado

Un empleado solo puede tener un préstamo en estado **Pendiente**, **Aprobado** o **Desembolsado** a la vez. El sistema bloquea la creación de un segundo préstamo si ya existe uno activo.
