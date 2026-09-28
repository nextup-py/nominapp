# Adelantos de Salario

Un adelanto es un anticipo parcial del salario del mes en curso. A diferencia de los préstamos, no tiene cuotas: el monto se descuenta íntegro en la próxima liquidación de nómina.

## Estados de un adelanto

| Estado | Descripción |
|--------|-------------|
| **Pendiente** | Creado, esperando aprobación. Puede editarse, rechazarse o cancelarse. |
| **Aprobado** | Aprobado, listo para entregar al empleado. Puede cancelarse o desaprobarse. |
| **Entregado** | El dinero fue acreditado o entregado al empleado. Será descontado en la próxima nómina. |
| **Descontado** | Deducido de la nómina. Estado final. |
| **Rechazado** | Rechazado por el administrador. Estado final. |
| **Cancelado** | Cancelado antes de ser procesado. Estado final. |

## Crear un adelanto individual

1. Ir a **Créditos → Adelantos**
2. Clic en **Nuevo Adelanto**
3. Seleccionar el empleado (solo se muestran empleados activos con salario definido) — el formulario muestra de inmediato la **Cuota disponible**: el monto que todavía puede adelantarse y la cantidad de adelantos activos frente al límite por período
4. Ingresar el **monto** — no puede superar el máximo permitido según el salario del empleado (se muestra debajo del campo)
5. Revisar el **método de pago** — se autocompleta según el método de pago del contrato del empleado, pero puede cambiarse
6. Agregar notas u observaciones (opcional)
7. Guardar

> Una vez creado el adelanto, ni el empleado ni el monto pueden modificarse — solo se puede editar antes de aprobarlo desde el estado Pendiente.

## Ciclo de vida y acciones

```
Pendiente → Aprobado → Entregado → Descontado (automático)
          ↘ Rechazado
          ↘ Cancelado
Aprobado  → Cancelado
Aprobado  → Pendiente (desaprobar, solo si no está en un lote bancario)
Entregado → Aprobado (revertir, solo si aún no fue descontado en nómina)
```

**Desde Pendiente:**
- **Aprobar** — pide confirmar el **método de pago** (precargado con el valor del adelanto) y habilita la entrega al empleado.
- **Rechazar** — rechaza el adelanto; se puede registrar un motivo.
- **Cancelar** — cancela el adelanto.

**Desde Aprobado:**
- **Marcar Entregado** — registra fecha y hora de entrega. El **comprobante** (PDF/imagen) es obligatorio si el método de pago es transferencia, y opcional si es efectivo.
- **Desaprobar** — vuelve a estado Pendiente (solo disponible si el adelanto todavía no forma parte de un lote bancario).
- **Cancelar** — cancela el adelanto.

**Desde Entregado:**
- **Revertir** — vuelve a estado Aprobado, solo disponible si el adelanto aún no fue incluido en ninguna nómina (`payroll_id` vacío).

**Desde Aprobado, Entregado o Descontado:**
- **PDF** — genera el comprobante del adelanto.

> El adelanto pasa a **Descontado** automáticamente al procesar la nómina que incluye el descuento.

## Adelantos por transferencia: acreditación bancaria

Los adelantos con método de pago **transferencia** no se marcan como Entregados manualmente uno por uno ni con la acción masiva de efectivo — se gestionan agrupados en un lote bancario desde **Nóminas → Pagos Bancarios**, creando un lote y seleccionando los adelantos Aprobados por transferencia. El lote genera el archivo bancario (formato Itaú) y, al confirmarse la respuesta del banco, pasa automáticamente los adelantos aceptados a **Entregado**. Ver el capítulo **Lotes de Pagos Bancarios** para el detalle completo del flujo.

Los adelantos en **efectivo**, en cambio, se marcan como Entregados directamente (individualmente o en bloque) desde este mismo listado.

## Aprobación, rechazo y entrega masivos

Desde el listado se pueden seleccionar varios adelantos y ejecutar acciones en bloque:

- **Aprobar** — aprueba (pidiendo el método de pago a aplicar a todos) los seleccionados que estén en estado Pendiente; el resto se ignora.
- **Rechazar** — rechaza los seleccionados que estén en estado Pendiente; el resto se ignora.
- **Marcar como Entregados (Efectivo)** — marca como Entregados solo los seleccionados que estén Aprobados **y** con método de pago Efectivo. Los de transferencia deben gestionarse desde el lote bancario.
- **Revertir a Aprobado** — revierte a Aprobado los seleccionados que estén Entregados y aún no descontados.
- **Descargar PDF** — descarga los comprobantes de los adelantos seleccionados.

La notificación de resultado indica cuántos fueron procesados y cuántos fueron ignorados por no estar en el estado esperado.

## Generación y carga masiva de adelantos

El menú **Creación masiva** (encabezado del listado) agrupa tres formas de crear varios adelantos a la vez:

### Generar Adelantos

Crea adelantos con el **mismo monto** para varios empleados:

1. Clic en **Creación masiva → Generar Adelantos**
2. Filtrar opcionalmente por **empresa** y **sucursal**
3. Ingresar el **monto** y el **método de pago** a aplicar a todos
4. Seleccionar empleados puntuales, o dejar vacío para aplicar a todos los del filtro
5. Agregar notas (opcional) y confirmar con **Generar**

> El sistema omite automáticamente los empleados que ya alcanzaron el límite de adelantos activos por período o cuyo monto supere su tope máximo individual. La notificación de resultado indica cuántos se crearon y cuántos se omitieron, con el motivo.

### Descargar Plantilla / Importar Adelantos

Para cargar **montos distintos** por empleado:

1. **Descargar Plantilla** — genera un Excel con los empleados activos (de la empresa/sucursal elegida) pre-cargados; hay que completar el monto y ajustar el método de pago de cada fila
2. **Importar Adelantos** — subir el Excel completado; crea un adelanto en estado Pendiente por cada fila válida y reporta las filas con error (motivo incluido)

> Las columnas CI, Nombre y Sucursal de la plantilla son solo de referencia — no se usan para modificar datos del empleado.

## Descuento automático en nómina

Al generar la nómina, el sistema descuenta automáticamente todos los adelantos en estado **Entregado** del empleado que aún no hayan sido procesados. No es necesario agregar la deducción manualmente.

## Reporte de Adelantos

El botón **Ver Reporte** (encabezado del listado) abre una pantalla de reporte con filtros por período, empresa, sucursal, estado, empleado y método de pago. Desde ahí:

- **Exportar PDF** — genera un PDF con selector de columnas a incluir y orientación de página.
- **Exportar Excel** — genera un Excel con selector de columnas a incluir.

> No existe un botón de exportación directa desde el listado principal de Adelantos — toda exportación a Excel/PDF de varios registros se hace desde este reporte (o, para comprobantes en PDF, con la acción bulk **Descargar PDF** sobre los seleccionados).

## Límites y configuración

Los límites se configuran en **Configuración → Configuración de Nómina**, sección **Adelantos de Salario**:

| Parámetro | Descripción |
|-----------|-------------|
| **Porcentaje máximo por adelanto** | % del salario que puede adelantarse por solicitud individual |
| **Máximo de adelantos por período** | Cantidad máxima de adelantos activos simultáneos (0 = sin límite) |

> El sistema valida que `cantidad máxima × porcentaje` no supere el 100% del salario al guardar la configuración.

**Validaciones al aprobar un adelanto:**
- La cantidad de adelantos activos del empleado (Pendientes + Aprobados + Entregados) no puede igualar o superar el límite configurado.
- Para empleados mensuales: la suma de todos los adelantos activos más el monto del nuevo adelanto no puede superar el salario mensual bruto.
- No se puede aprobar si la nómina del período actual ya fue generada para ese empleado.
