# Reporte de Contratos

Ir a **Empleados → Reporte de Contratos** para ver 7 vistas especializadas del estado de contratos de todos los empleados. Útil para anticipar vencimientos, monitorear períodos de prueba y auditar el estado contractual.

---

## Vistas disponibles (tabs)

### Por Vencer *(vista por defecto)*
Contratos activos con fecha de vencimiento definida, ordenados por urgencia (los más próximos primero).

La columna **Días Restantes** usa colores de alerta:

| Color | Significado |
|-------|-------------|
| Rojo | 15 días o menos |
| Naranja | Entre 16 y 30 días |
| Verde | Más de 30 días |

---

### Período de Prueba
Empleados actualmente dentro de su período de prueba, ordenados por días restantes de prueba.

Muestra la **fecha de fin de prueba** y los días restantes con los mismos colores de alerta que "Por Vencer".

---

### Sin Contrato
Empleados que **no tienen ningún contrato activo**. Puede deberse a que su contrato venció y no fue renovado, o a que el empleado fue creado sin asignarle uno.

---

### Por Antigüedad
Todos los contratos activos, ordenados desde el más antiguo. La columna **Antigüedad** muestra el tiempo de servicio con colores:

| Color | Rango |
|-------|-------|
| Gris | Menos de 1 año |
| Naranja | 1 a 5 años |
| Azul | 5 a 10 años |
| Verde | 10 años o más |

---

### Suspendidos
Contratos con estado **Suspendido**.

---

### Todos Activos
Todos los contratos vigentes, sin filtro adicional.

---

### Rescindidos
Contratos terminados, ordenados por fecha de rescisión más reciente. Muestra la columna **Rescindido el** en lugar de la fecha de vencimiento.

---

## Filtros disponibles

Los filtros cambian según el tab activo — no todos los tabs muestran los mismos:

| Filtro | Aplica a |
|--------|---------|
| **Empresa** (si hay >1 activa) | Todos los tabs |
| **Sucursal** | Todos los tabs |
| **Tipo de contrato** | Todos salvo "Sin Contrato" |
| **Tipo de salario** (Mensual / Jornal) | Todos salvo "Sin Contrato" |
| **Departamento** | Todos salvo "Sin Contrato" (en cascada con Empresa) |
| **Cargo** | Todos salvo "Sin Contrato" (en cascada con Departamento) |
| **Vencer en** (30 / 60 / 90 días) | Solo "Por Vencer" y "Período de Prueba" |
| **Rescindidos en los últimos** (3 / 6 / 12 meses) | Solo "Rescindidos" |
| **Período de inicio** (rango de fechas) | "Por Vencer", "Todos Activos", "Por Antigüedad", "Rescindidos" |
| **Período de vencimiento** (rango de fechas) | Solo "Por Vencer" |
| **Período de rescisión** (rango de fechas) | Solo "Rescindidos" |
| **Registrado en el sistema** (rango de fechas) | Solo "Sin Contrato" |

> Los filtros Empresa → Sucursal y Departamento → Cargo son en cascada: cambiar el filtro padre limpia el hijo.

---

## Exportar a PDF y Excel

Disponible en todos los tabs desde el encabezado:

1. Clic en **Exportar PDF** o **Exportar Excel**
2. Seleccionar las **columnas a incluir** — las opciones son dinámicas según el tab activo
3. En PDF, la **orientación** (Vertical/Horizontal) se ajusta automáticamente según la cantidad de columnas elegidas (Vertical hasta 6 columnas, Horizontal para más) — se puede cambiar manualmente
4. Confirmar

Las columnas disponibles cambian según el tab (por ejemplo, "Días Restantes" solo aparece en "Por Vencer" y "Período de Prueba"). Si solo hay una empresa activa, la columna/opción "Empresa" no se ofrece.
