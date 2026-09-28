# Retiros de Mercadería

Un retiro de mercadería es una compra a crédito de productos del catálogo del empleador. El monto total se descuenta en cuotas mensuales iguales directamente en la nómina, sin interés.

A diferencia de los préstamos, un empleado puede tener varios retiros activos al mismo tiempo.

## Estados de un retiro

| Estado | Descripción |
|--------|-------------|
| **Pendiente** | Creado, con productos cargados. Puede editarse, aprobarse o rechazarse. |
| **Aprobado** | Aprobado, con cuotas generadas. Los descuentos se aplican automáticamente en nómina. |
| **Pagado** | Todas las cuotas fueron descontadas. Estado final. |
| **Rechazado** | Rechazado por el administrador. Estado final. |
| **Cancelado** | Cancelado luego de aprobado, antes de completarse (solo si no hay cuotas ya descontadas). Estado final. |

> **Cancelar solo está disponible desde Aprobado**, no desde Pendiente — un retiro pendiente se gestiona con Aprobar o Rechazar. Editar y Eliminar sí están disponibles mientras está Pendiente.

## Crear un retiro

1. Ir a **Créditos → Retiros de Mercadería**
2. Clic en **Nuevo Retiro**
3. Completar:
   - **Empleado** (solo empleados activos)
   - **Cantidad de Cuotas**
   - **Días hasta primera cuota** — cuántos días después de la aprobación vence la primera cuota (viene precargado con el valor configurado en Ajustes, pero se puede ajustar por retiro)
   - **Notas** (opcional)
4. Guardar

> El empleado, la cantidad de cuotas y los días hasta la primera cuota **no se pueden modificar** una vez creado el retiro.

## Agregar productos

Después de crear el retiro, ir a la pestaña de productos para cargar los ítems:

1. Clic en **Agregar Producto**
2. Ingresar:
   - **Código** — código interno libre (puede ser el código del catálogo)
   - **Nombre del Producto**
   - **Descripción** (opcional)
   - **Precio Unitario**
   - **Cantidad**
3. El **subtotal** se calcula automáticamente (precio × cantidad)
4. El **total del retiro** se actualiza automáticamente al guardar cada producto

> Los productos solo pueden editarse mientras el retiro esté en estado **Pendiente**.

## Ciclo de vida y acciones

**Desde Pendiente:**
- **Aprobar** — genera las cuotas y habilita el descuento en nómina. El monto de cada cuota es `total ÷ cantidad de cuotas`.
- **Rechazar** — rechaza el retiro; se puede registrar un motivo.
- **Editar** — disponible mientras el retiro sigue pendiente.

**Desde Aprobado:**
- **Cancelar** — cancela el retiro y anula las cuotas pendientes; pide un motivo. Solo disponible si ninguna cuota fue descontada aún en nómina.
- **Descargar PDF** — genera el documento del retiro con los productos y el plan de cuotas.

> El retiro pasa a **Pagado** automáticamente al procesar en nómina la última cuota pendiente.

## Cuotas y descuento en nómina

Al generar la nómina de un período, el sistema incluye automáticamente las cuotas vencidas del período como deducción. No es necesario agregarlas manualmente.

Para ver el detalle de cuotas, abrir el retiro y revisar la pestaña de cuotas (solo lectura). Cada cuota muestra:
- Número de cuota (ej: "Cuota 3/12")
- Monto
- Fecha de vencimiento y, si corresponde, fecha de pago
- Estado: Pendiente, Pagada o Cancelada

La lista de cuotas también se puede exportar con el botón **Exportar a Excel**.

## Documento PDF

Desde la vista del retiro aprobado o pagado, el botón **Descargar PDF** genera el documento con:
- Datos del empleado y la empresa
- Listado detallado de productos (código, nombre, precio, cantidad, subtotal)
- Resumen del pago (total, cuotas, monto por cuota, saldo pendiente)
- Plan de cuotas completo con fechas de vencimiento
- Sección de firmas

## Listado y reporte

El listado principal tiene pestañas para filtrar rápido por estado (Todos, Pendientes, Aprobados, Pagados, Cancelados, Rechazados), con la cantidad de cada uno en el badge.

El botón **Ver Reporte** abre una pantalla de reporte aparte, con sus propios filtros y exportación, para análisis agregados de los retiros.

## Límites y configuración

Los límites se configuran en **Ajustes → Nómina**, sección **Retiros de Mercadería**:

| Parámetro | Descripción |
|-----------|-------------|
| **Monto máximo por retiro** | Límite en Guaraníes por cada retiro individual |
| **Máximo de cuotas** | Cantidad máxima de cuotas permitidas por retiro |
| **Días hasta primera cuota** | Valor por defecto que se precarga al crear un retiro (se puede ajustar por retiro) |
