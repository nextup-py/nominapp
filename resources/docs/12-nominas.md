# Nóminas

El módulo de Nóminas gestiona la liquidación salarial de los empleados por período. Soporta frecuencias mensual, quincenal y semanal, y tanto empleados mensuales como jornaleros.

## Conceptos clave

- **Planilla (período de nómina):** rango de fechas al que corresponde la liquidación
- **Recibo de salario:** liquidación generada para un empleado dentro de una planilla
- **Ítem de nómina:** cada línea de percepción o deducción dentro de un recibo
- **Percepción:** ingreso adicional al salario base (bono, horas extra, viáticos, etc.)
- **Deducción:** descuento aplicado al salario (IPS, préstamo, ausencia, etc.)

---

## Planillas (Períodos de Nómina)

Las planillas definen el rango de fechas de cada liquidación. Todo el proceso de nómina se gestiona desde **Nóminas → Planillas**.

### Crear una planilla

1. Ir a **Nóminas → Planillas**
2. Clic en **Nueva Planilla**
3. Seleccionar la **empresa** y la **frecuencia:** Mensual, Quincenal o Semanal — al elegirla, el sistema propone automáticamente las fechas de inicio y fin del período actual (se pueden ajustar)
4. El **nombre** se genera automáticamente:
   - Mensual: "Enero 2026"
   - Quincenal: "Quincena 01/01/2026 - 15/01/2026"
   - Semanal: "Semana del 05/01/2026 al 11/01/2026"
5. Guardar

> No se puede crear dos planillas con la misma empresa, frecuencia y rango de fechas exacto.

### Estados de la planilla

| Estado | Descripción |
|--------|-------------|
| **Borrador** | Planilla creada, sin recibos generados |
| **En Proceso** | Con recibos en curso |
| **Cerrado** | Finalizada — los recibos no pueden modificarse |

### Generar recibos de la planilla

Desde la vista de la planilla (clic en la planilla para abrirla):

1. Clic en **Generar Recibos** — procesa a todos los empleados activos cuyo contrato tenga la **misma frecuencia de pago** que la planilla y un salario configurado, y que todavía no tengan recibo en ella
2. El sistema calcula automáticamente para cada empleado:
   - Salario base (proporcional si el período es parcial)
   - Percepciones activas en el período
   - Horas extra desde las asistencias registradas
   - Día de descanso semanal remunerado (para jornaleros)
   - Deducciones activas (IPS, préstamos, adelantos, retiros de mercadería, ausencias injustificadas)
   - Bonificación familiar IPS

Si algún empleado no pudo procesarse, el sistema lo informa con el motivo en una notificación aparte (no bloquea la generación del resto). También avisa si, tras generar, quedan empleados con contrato por vencer dentro de los próximos días configurados.

Para agregar manualmente un empleado que no fue incluido: clic en **Agregar Recibo** y seleccionarlo.

**Regenerar recibos:** para recalcular todos los recibos de la planilla de una sola vez (por ejemplo, tras corregir una asistencia o un contrato), usar **Regenerar Recibos** en el menú de acciones — revierte a Borrador cualquier recibo ya aprobado y vuelve a calcular percepciones, deducciones, horas extra, ausencias y cuotas. También se puede regenerar un recibo individual desde su propia fila (ver más abajo).

### Cerrar una planilla

El botón **Cerrar Planilla** solo está habilitado cuando **no** quedan recibos en estado Borrador o Aprobado (el botón muestra un tooltip explicando qué falta si está deshabilitado). Al cerrar, los recibos quedan bloqueados y no se pueden generar más.

> Si hay empleados activos sin recibo al momento de cerrar, el sistema lo informa como advertencia en el modal de confirmación, pero permite continuar.

**Reabrir una planilla:** una planilla cerrada puede reabrirse con **Reabrir Planilla**, que la vuelve a estado "En Proceso" y permite modificarla de nuevo.

**Revertir a Borrador:** desde "En Proceso", **Revertir a Borrador** elimina todos los recibos que todavía estén en Borrador y devuelve la planilla a ese estado — solo funciona si no queda ningún recibo Aprobado o Pagado.

---

## Recibos de Salario

Cada recibo corresponde a la liquidación de un empleado en una planilla.

### Flujo de estados

El flujo varía según el método de pago del recibo:

**Acreditación bancaria (transferencia):**
```
Borrador → Aprobado → Acreditado → Pagado
```

**Efectivo:**
```
Borrador → Aprobado → Pagado
```

| Estado | Descripción |
|--------|-------------|
| **Borrador** | Generado, pendiente de revisión. Se puede regenerar o editar. |
| **Aprobado** | Revisado y aprobado. Para transferencias, pasa a Acreditado al confirmarse el depósito. Para efectivo, pasa directo a Pagado. |
| **Acreditado** | El banco confirmó el depósito (solo transferencias) — ver el capítulo **Lotes de Pagos Bancarios**. |
| **Pagado** | Pago registrado y completado. |

### Acciones disponibles por estado

Desde la vista del recibo (la mayoría también como acción de fila en la tabla de recibos de la planilla):

| Estado | Acciones disponibles |
|--------|---------------------|
| Borrador | Aprobar, Regenerar, Editar (método de pago y notas), Agregar ajuste HE (horas extra manuales), Eliminar |
| Aprobado | Marcar Acreditado (transferencia), Marcar Pagado (efectivo), Desaprobar, Descargar PDF |
| Acreditado | Marcar Pagado, Revertir a Aprobado (si no está en un lote bancario), Descargar PDF |
| Pagado | Revertir Pago, Descargar PDF |

> **Editar** solo permite cambiar el método de pago y las notas del recibo — no los montos calculados.

### Acciones sobre toda la planilla

Desde la vista de la planilla:

- **Aprobar Todos** — aprueba en un solo paso todos los recibos en estado Borrador.
- **Marcar Efectivo como Pagado** — marca como Pagados únicamente los recibos **en efectivo** que estén Aprobados. Los recibos por transferencia pasan a Pagado a través de la confirmación del lote bancario (ver **Lotes de Pagos Bancarios**), no desde este botón.
- **Enviar al Banco** — arma un lote con los recibos aprobados por transferencia para generar el archivo bancario (ver **Lotes de Pagos Bancarios**).
- **Reporte de Salarios** — abre el reporte de salarios filtrado por esta planilla.

---

## Descarga del recibo en PDF

Desde la vista de un recibo o desde la tabla de la planilla (botón **PDF** en la fila), se abre un modal para elegir el formato:

| Formato | Descripción |
|---------|-------------|
| **Para imprimir** | Hoja A4 horizontal con dos copias: *COPIA EMPLEADO* y *COPIA EMPRESA*, separadas por una línea punteada de corte |
| **Para empleado** | Hoja A4 vertical con una sola copia, ideal para enviar por correo electrónico |

También es posible descargar los PDFs de varios recibos a la vez seleccionándolos en la tabla y usando la acción bulk **Descargar PDFs**. Si se selecciona un solo recibo se descarga el PDF directamente; si son varios, se genera un archivo ZIP.

---

## Percepciones y Deducciones

Las percepciones son ingresos adicionales al salario base (bonos, viáticos); las deducciones son descuentos (IPS, préstamos, embargos). Ambas se administran desde **Nóminas → Percepciones** / **Nóminas → Deducciones** y se asignan a cada empleado desde su perfil, pestañas **Percepciones** / **Deducciones**.

> Ver el capítulo **Percepciones y Deducciones** para el detalle completo: tipos, creación, asignación individual y masiva, y la lista de códigos reservados que usa el sistema internamente (`IPS001`, `PRE001`, `ADE001`, `MER001`, `LIC001`, `AUS-INJ`).

---

## Horas extra en nómina

Las horas extra calculadas automáticamente desde las asistencias se incluyen como percepciones en la nómina. También se pueden cargar horas extra manuales sobre un recibo en Borrador con el botón **Agregar ajuste HE**.

Los multiplicadores de recargo se configuran en **Ajustes → Nómina** (valores por defecto):

| Tipo | Recargo | Multiplicador |
|------|---------|----------------|
| Diurnas | +50% | 1.5× |
| Nocturnas | +160% | 2.6× |
| Feriado / Domingo | +100% | 2.0× |
| Feriado Nocturno | +160% | 2.6× |

---

## Exportar nómina

No hay un botón para exportar todos los recibos de la planilla de una vez. Para exportar a Excel, seleccionar los recibos deseados en la tabla (o todos, con el checkbox del encabezado) y usar la acción bulk **Export** (aparece sin traducir).
