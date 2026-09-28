# Reporte de Salarios

Ir a **Nóminas → Reporte de Salarios** para ver el desglose salarial completo de todos los empleados de una planilla. Muestra salario base, percepciones, deducciones segmentadas por tipo y neto a pagar, con opciones de exportación a PDF y Excel.

---

## Flujo de uso

1. Seleccionar la **empresa** (si hay más de una activa)
2. Seleccionar la **planilla** — obligatorio para ver datos
3. Aplicar filtros adicionales según necesidad
4. Revisar la tabla con los datos calculados
5. Exportar a PDF o Excel si corresponde

> Si no se selecciona una planilla, la tabla aparece vacía.

---

## Filtros disponibles

Los filtros funcionan en cascada: cambiar un filtro padre limpia automáticamente los filtros dependientes.

| Filtro | Descripción | Dependencia |
|--------|-------------|-------------|
| **Empresa** | Filtra planillas, sucursales, departamentos y empleados | — |
| **Planilla** | Selecciona el período a visualizar (obligatorio) | Empresa |
| **Sucursal** | Restringe a empleados de esa sucursal | Empresa |
| **Departamento** | Restringe a empleados del departamento (vía contrato activo) | Empresa |
| **Empleado** | Filtra un empleado específico | Empresa / Sucursal / Departamento |
| **Estado del recibo** | Borrador, Aprobado, Pagado, etc. | — |
| **Método de pago** | Transferencia, Efectivo, etc. | — |

---

## Columnas de la tabla

| Columna | Descripción |
|---------|-------------|
| **Empleado** | Apellido, Nombre |
| **CI** | Cédula de identidad (copiable) |
| **Sucursal** | Sucursal del empleado (toggleable) |
| **Cargo** | Puesto del contrato activo (toggleable) |
| **Salario Base** | Monto en Gs. |
| **+ Percepciones** | Total de percepciones del período (toggleable) |
| **IPS** | Monto descontado por aporte IPS |
| **Descuentos por Deuda** | Cuotas de préstamos, adelantos y retiros de mercadería |
| **Judiciales** | Embargos judiciales (toggleable) |
| **Voluntarias** | Deducciones voluntarias del empleado (toggleable) |
| **- Deducciones** | Total de deducciones del período |
| **Neto a Pagar** | Monto final que recibe el empleado |
| **Método** | Forma de pago (Transferencia / Efectivo) |
| **Estado** | Estado del recibo |

---

## Exportar a PDF

1. Clic en **Exportar PDF** en el encabezado
2. En el modal seleccionar:
   - **Columnas a incluir** — marcar solo las que se necesiten
   - **Sub-tablas opcionales:**
     - Desglose de Percepciones (detalle por concepto)
     - Desglose de Deducciones (detalle por concepto)
     - Resumen por Método de Pago
   - **Orientación:** Vertical u Horizontal
3. Clic en **Generar PDF**

El PDF incluye el encabezado de la empresa, el nombre del período, los filtros activos y los totales generales al pie.

---

## Exportar a Excel

1. Clic en **Exportar Excel** en el encabezado
2. En el modal seleccionar las **columnas a incluir**
3. Clic en **Exportar**

El archivo incluye una hoja con la tabla principal y una **fila de totales** al final (suma de las columnas monetarias seleccionadas: Salario Base, Percepciones, IPS, Descuentos por Deuda, Judiciales, Voluntarias, Deducciones y Neto). El nombre del archivo lleva la fecha y hora de generación.
