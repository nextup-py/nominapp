# Aguinaldo (13.° salario)

El aguinaldo es el salario adicional obligatorio que corresponde a cada empleado al cierre del año, calculado sobre el total de ingresos percibidos durante el año.

---

## Fórmula de cálculo

```
Aguinaldo = Total de ingresos del año ÷ 12
```

El **total de ingresos** es la suma, mes a mes, de todas las nóminas generadas para el empleado dentro del año del período — de **salario base + percepciones que afectan IPS** (las salariales, como bonos o comisiones) **+ horas extra**. Las percepciones que no afectan IPS (viáticos, subsidios) **no** se incluyen en la base del aguinaldo.

> "Meses trabajados" es un dato informativo (la cantidad de nóminas generadas ese año para el empleado) — **no** multiplica el resultado. Un empleado que ingresó a mitad de año ya tiene un total de ingresos menor porque solo tuvo nóminas esos meses; la fórmula sigue siendo simplemente dividir ese total entre 12.

---

## Flujo del período de aguinaldo

```
Borrador → En Proceso → Cerrado
```

Todo el proceso se gestiona desde **Nóminas → Aguinaldo**.

---

## Paso 1 — Crear el período de aguinaldo

1. Ir a **Nóminas → Aguinaldo**
2. Clic en **Nuevo Período**
3. Seleccionar la **empresa** y el **año**
4. Guardar

> Solo puede existir un período de aguinaldo por empresa y año.

---

## Paso 2 — Generar los aguinaldos individuales

1. Abrir el período de aguinaldo
2. Clic en **Generar** (solo disponible en estado Borrador)
3. El sistema calcula un registro por cada empleado activo de la empresa que tenga al menos una nómina generada en el año, y pasa el período a **En Proceso**
4. Revisar los montos calculados en la pestaña de aguinaldos del período — el recibo en PDF de cada uno se genera automáticamente al crearse

**Ver Provisión** (disponible en cualquier estado del período): abre un reporte que muestra, mes a mes, cuánto lleva acumulado cada empleado en concepto de aguinaldo hasta el mes elegido — útil para contabilidad antes de cerrar el año. Tiene su propio botón **Exportar Excel**.

**Regenerar** un aguinaldo individual (solo con el período En Proceso): recalcula el monto de ese empleado si cambió alguna nómina del año, sin afectar al resto.

---

## Paso 3 — Gestionar el pago

Cada aguinaldo individual tiene método de pago (**Acreditación bancaria** o **Efectivo**), tomado del contrato del empleado al generarse — se puede cambiar con **Cambiar método de pago** mientras el aguinaldo esté Pendiente, sin lote bancario asignado, y el período siga En Proceso.

**Pago en efectivo:**
- **Marcar Pagado** en el aguinaldo individual, o **Pagar Todos** desde el período para marcar de una vez todos los pendientes.
- **Marcar Pendiente** revierte un aguinaldo ya pagado (mientras el período siga En Proceso).

**Pago por transferencia:**
- Desde el período, **Enviar al Banco** arma un lote con los aguinaldos pendientes por transferencia (bloquea si algún empleado no tiene cuenta bancaria activa registrada) y genera el archivo bancario — ver el capítulo **Lotes de Pagos Bancarios**. Al confirmarse la respuesta del banco, esos aguinaldos pasan automáticamente a Pagado.

**Recibo en PDF:** desde el aguinaldo individual, **Descargar PDF** (o **PDF** en el listado). También se puede acceder a todos los recibos desde el resumen **Nóminas → Recibos Aguinaldo**.

---

## Cerrar el período

**Cerrar** (disponible En Proceso) bloquea el período — no se podrán generar más aguinaldos. Si quedan aguinaldos pendientes de pago, el sistema lo advierte antes de confirmar: una vez cerrado, esos pendientes ya no podrán marcarse como pagados.

**Reabrir** (disponible con el período Cerrado) lo vuelve a En Proceso para poder seguir gestionando pagos.

**Eliminar** (En Proceso o Cerrado) borra el período completo junto con todos los aguinaldos e ítems generados — acción irreversible.

**Exportar** (En Proceso o Cerrado): descarga a Excel todos los aguinaldos del período.

---

## Estados del aguinaldo individual

| Estado | Descripción |
|--------|-------------|
| **Pendiente** | Calculado, aún sin pagar |
| **Pagado** | Pago registrado |

---

## Desglose mensual

Cada aguinaldo individual contiene un desglose mes a mes con:
- Salario base del mes
- Percepciones del mes (sin incluir horas extra, que se muestran aparte)
- Horas extra del mes
- Total del mes
