# Contratos

El módulo de contratos gestiona el ciclo de vida laboral de cada empleado, desde la creación del contrato hasta su vencimiento, terminación o renovación.

---

## Estados de un contrato

| Estado | Descripción |
|--------|-------------|
| **Borrador** | Creado pero no activado. Puede editarse o eliminarse. No genera nómina. |
| **Vigente** | Contrato activo. El empleado participa en nómina normalmente. |
| **Suspendido** | Contrato pausado temporalmente. Percepciones y deducciones desactivadas. |
| **Vencido** | La fecha de fin fue alcanzada. El empleado queda inactivo automáticamente. |
| **Terminado** | Rescindido manualmente. El empleado queda inactivo automáticamente. |
| **Renovado** | Reemplazado por un nuevo contrato. Estado final sin acciones disponibles. |

> **"Por vencer" no es un estado real almacenado** — es una vista/filtro que muestra los contratos **Vigentes** cuya fecha de fin cae dentro de los días configurados en Ajustes Generales (`contract_alert_days`). El contrato sigue estando en estado Vigente hasta que vence de verdad.

---

## Crear un contrato

1. Ir a **Empleados → Contratos**
2. Clic en **Nuevo Contrato**
3. Completar los datos: empleado, tipo, fechas, salario, cargo, modalidad de trabajo
4. Elegir cómo guardar:
   - **Crear** — el contrato queda Vigente de inmediato
   - **Guardar borrador y previsualizar** — queda en Borrador para revisar el PDF antes de activar

> Solo se muestran empleados activos sin contrato vigente o en borrador. El sistema también valida esto al guardar: si el empleado ya tiene un contrato Vigente, bloquea la creación.

> **Art. 53 CLT — duración máxima:** los contratos de tipo **Por Plazo Determinado** y **De Aprendizaje** no pueden exceder 1 año entre la fecha de inicio y la de fin — el sistema lo valida y bloquea el guardado si se excede.

---

## Tipos de contrato

| Tipo | Descripción |
|------|-------------|
| **Por Tiempo Indefinido** | Sin fecha de fin. No tiene vencimiento automático. |
| **Por Plazo Determinado** | Con fecha de fin obligatoria, máximo 1 año (Art. 53 CLT). La segunda renovación se convierte automáticamente en Indefinido. |
| **Por Obra Determinada** | Vigente hasta completar la obra indicada. |
| **De Aprendizaje** | Para formación profesional. Máximo 1 año, igual que Plazo Determinado. |
| **Pasantía** | Para prácticas estudiantiles. |

---

## Ciclo de vida y transiciones

```
Borrador ──[Activar]──> Vigente
Vigente  ──[Suspender]──> Suspendido
Suspendido ──[Reactivar]──> Vigente
Vigente  ──[Renovar]──> Renovado (+ nuevo contrato Vigente)
Vigente  ──[Terminar]──> Terminado
Vigente  ──[Vence automáticamente a las 00:05]──> Vencido
Borrador ──[Eliminar]──> (eliminado)
```

Al pasar a **Vencido** o **Terminado**, el empleado queda **Inactivo** automáticamente — salvo que tenga otro contrato activo.

---

## Acciones disponibles

### Desde la tabla de contratos
Agrupadas en el menú **Más acciones** (⋮) de cada fila:
- **Suspender / Reactivar** — disponible por fila según estado
- **Renovar** — solo contratos Vigentes de tipo distinto a Indefinido
- **Terminar** — solo contratos Vigentes. Al confirmar, la notificación incluye un botón **Crear Liquidación** con acceso directo al formulario de liquidación del empleado
- **Generar PDF** — genera el PDF del contrato según la plantilla de la empresa
- **Subir / Reemplazar Firmado** — para adjuntar el PDF escaneado con firmas (el label cambia a "Reemplazar" si ya hay uno subido)
- **Descargar Firmado**

### Acciones masivas (bulk)
Seleccioná varios contratos con el checkbox y usá el menú de acciones masivas:
- **Activar seleccionados** — activa todos los que estén en Borrador
- **Suspender seleccionados** — suspende todos los Vigentes
- **Terminar seleccionados** — termina todos los Vigentes

Cada operación informa cuántos se procesaron y cuántos se ignoraron por no estar en el estado esperado.

---

## Renovación (Art. 53 CLT)

Al renovar un contrato a plazo fijo por segunda vez, el sistema convierte automáticamente el nuevo contrato en **Indefinido**, cumpliendo el Art. 53 del Código del Trabajo.

El modal de renovación avisa al usuario cuando esto ocurrirá antes de confirmar.

---

## Plantillas de contrato PDF

Cada empresa puede personalizar el PDF de cada tipo de contrato desde **Configuración → Plantillas de Contratos**. Se puede editar:

- **Párrafo introductorio** — texto de apertura con variables dinámicas
- **Cuerpo / Cláusulas** — cláusulas principales del contrato
- **Texto de cierre** — párrafo final antes de las firmas
- **Notas en firmas** — texto bajo las líneas de firma
- **Etiquetas de firma** — etiqueta del lado empleado y del lado empleador
- **Título del documento** — reemplaza "CONTRATO INDIVIDUAL DE TRABAJO"
- **Subtítulo** — reemplaza el tipo derivado automáticamente (ej: "Por Tiempo Indefinido")
- **Referencia al artículo legal** — el texto "(En cumplimiento del Art. 48...)" puede editarse o borrarse para ocultarlo
- **Encabezado de empresa** — mostrar u ocultar logo, nombre y datos de contacto
- **Pie de página** — mostrar u ocultar "Documento generado el..."

### Variables disponibles en plantillas

Las variables se reemplazan automáticamente con los datos del contrato y el empleado al generar el PDF:

| Variable | Valor |
|----------|-------|
| `{nombre_empleado}` | Nombre completo del empleado |
| `{ci_empleado}` | Cédula de identidad |
| `{cargo}` | Nombre del cargo |
| `{salario}` | Monto del salario formateado |
| `{salario_en_palabras}` | Salario escrito en palabras |
| `{nombre_empresa}` | Razón social de la empresa |
| `{representante_legal}` | Nombre del representante legal |
| `{ciudad}` | Ciudad de la empresa |
| `{dia}` / `{mes}` / `{año}` | Fecha de inicio del contrato |
| `{dias_prueba}` | Días del período de prueba |
| `{duracion_contrato}` | Duración en palabras (ej: "seis (6) meses") |
| `{tipo_jornada}` | DIURNA / NOCTURNA / MIXTA |
| `{horas_semanales}` | Horas semanales de trabajo |

---

## Cláusulas adicionales

Al crear o editar un contrato, se puede agregar una sección de **Cláusulas Adicionales** específica para ese empleado. Estas se muestran en el PDF después de las cláusulas de la plantilla.

---

## Alertas y notificaciones

- **Badge en el menú lateral** — muestra en naranja la cantidad de contratos por vencer según los días configurados en Ajustes Generales
- **Tab "Por vencer"** en la lista — filtra automáticamente los contratos próximos a vencer
- **Notificación diaria en campanita** — a las 08:00 el sistema notifica sobre contratos vencidos o próximos a vencer; cada contrato se notifica una sola vez hasta que la notificación sea leída
- **Alerta al generar nómina** — si hay contratos por vencer al momento de generar recibos, aparece un aviso con link al reporte

---

## Historial de cambios

Cada contrato registra automáticamente todos los cambios de estado, salario, cargo y fechas. El historial está disponible en el tab **Historial de cambios** dentro del detalle del contrato, mostrando quién hizo cada cambio y cuándo.

---

## Reporte de contratos

Ver **Empleados → Reporte de Contratos** para acceder a 7 vistas especializadas con filtros y exportación PDF/Excel. También accesible desde el botón **Ver Reporte** en el encabezado de la lista de contratos.

> Ver el capítulo **Reporte de Contratos** para el detalle completo de las 7 vistas disponibles.
