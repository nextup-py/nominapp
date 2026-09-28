# Empleados y Contratos

## Empleados

El módulo de Empleados gestiona todos los datos personales y laborales del personal.

### Campos del empleado

- **Nombre y apellido**, foto
- **Cédula de identidad (CI):** solo dígitos, sin puntos ni guiones (ej: `4567890`)
- **Fecha de nacimiento**, sexo (`Masculino` / `Femenino`)
- **Teléfono:** con 0 inicial, sin espacios (ej: `0981123456`)
- **Email**
- **Sucursal** a la que pertenece
- **Estado:** Activo o Inactivo

### Crear un empleado

1. Ir a **Empleados → Empleados**
2. Clic en **Nuevo empleado**
3. Completar los datos personales
4. Opcionalmente, expandir la sección **Contrato inicial** para crear el primer contrato en el mismo paso
5. Guardar

> Los nombres se capitalizan automáticamente al guardar. La CI debe ser única en el sistema.

### Estados del empleado

| Estado | Descripción |
|--------|-------------|
| **Activo** | Empleado vigente en nómina |
| **Inactivo** | Relación laboral terminada (se marca automáticamente al vencer o terminar su contrato, o al cerrar una liquidación) |

> El estado se puede cambiar manualmente con la acción **Cambiar estado** desde el perfil del empleado, entre Activo e Inactivo.

---

## Contratos

El contrato activo define el **salario, cargo y fecha de ingreso** del empleado. Un empleado puede tener historial de contratos.

Desde el perfil del empleado, pestaña **Contratos**, se puede crear el primer contrato o consultar el historial completo. Departamentos y cargos pueden crearse directamente desde el selector, sin salir del formulario.

> Para el detalle completo de tipos de contrato, estados, ciclo de vida, plantillas de PDF y alertas de vencimiento, ver el capítulo **Contratos**.

---

## Percepciones y Deducciones del empleado

Desde las pestañas **Percepciones** y **Deducciones** del perfil del empleado puede asignar conceptos que se incluirán automáticamente en cada nómina:

- **Percepciones:** ingresos adicionales (ej: bono de transporte, antigüedad)
- **Deducciones:** descuentos recurrentes (ej: IPS, seguro médico)

Cada asignación tiene fecha de inicio, fecha de fin opcional y monto personalizado (si difiere del monto global del concepto).

---

## Amonestaciones del empleado

Desde la pestaña **Amonestaciones** en el perfil del empleado se pueden ver y registrar todas las amonestaciones emitidas a ese empleado. Ver el capítulo **Amonestaciones** para el detalle completo del módulo.

---

## Legajo del empleado

El legajo es un resumen completo del empleado en PDF. Para generarlo:

1. Abrir el perfil del empleado
2. Clic en el botón **Legajo** en el encabezado de la página

El documento incluye datos personales, contrato activo e historial relevante.
