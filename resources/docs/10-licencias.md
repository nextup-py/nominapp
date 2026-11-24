# Permisos y Licencias

El módulo de Licencias registra ausencias planificadas y autorizadas de los empleados: reposo médico, maternidad, permisos parciales por horas, etc. Al aprobar un permiso de día completo, el sistema justifica automáticamente las ausencias del período sin intervención adicional.

---

## Modalidades de permiso

El sistema distingue dos modalidades que coexisten en el mismo módulo:

| Modalidad | Cuándo usarla |
|-----------|---------------|
| **Por días completos** | El empleado se ausenta uno o más días enteros |
| **Parcial por horas** | El empleado se ausenta solo una parte del día (ej: 2 horas para una consulta médica) |

---

## Tipos de permiso disponibles

| Tipo | Descripción |
|------|-------------|
| **Reposo Médico** | Licencia por enfermedad o accidente |
| **Vacaciones** | Días de descanso remunerado |
| **Día Libre** | Día de descanso eventual |
| **Licencia de Maternidad** | Ley 5508/15 |
| **Licencia de Paternidad** | Por nacimiento de hijo |
| **Sin Goce de Sueldo** | Permiso sin retribución |
| **Otro** | Cualquier otro tipo de permiso |

---

## Registrar un permiso por días completos

![Formulario de nueva licencia](/docs-images/10-licencia-nueva.png)

1. Ir a **Licencias y vacaciones → Licencias**
2. Clic en **Nueva Licencia**
3. Completar:
   - **Empleado** (solo aparecen los activos; se busca por nombre, apellido o CI) y **Tipo de licencia**
   - **Fecha de inicio** y **Fecha de fin**
   - **Motivo** (opcional)
   - **Documento de soporte** (obligatorio para Reposo Médico)
   - **Descuento de sueldo** (opcional): activar **Genera descuento en la próxima nómina** si los días no se pagan. Con el tipo **Sin Goce de Sueldo** viene activado
4. Guardar — el permiso queda en estado **Pendiente**

### Descuento por días de licencia sin goce de sueldo

Con el interruptor **Genera descuento en la próxima nómina** activado en una licencia de días completos, al **aprobar** se genera una deducción (código **LIC001**, que el sistema crea sola si no existe) **por cada día laborable del período**: se omiten los feriados y los días en que el empleado no tenía turno. El monto de cada día es el salario diario del empleado (salario ÷ 30, o la tarifa diaria de los jornaleros), igual que en las suspensiones. El modal de **Aprobar** muestra de antemano cuántos días y cuánto se descontará.

Si el empleado **ya tiene nómina generada** para esas fechas, la licencia se aprueba igual pero **no** se genera el descuento (no se cobraría): el aviso lo dice y hay que cargarlo a mano como deducción puntual.

---

## Registrar un permiso parcial por horas

1. Ir a **Licencias y vacaciones → Licencias**
2. Clic en **Nueva Licencia**
3. Completar los datos generales (empleado, tipo y fecha)
4. Expandir la sección **Permiso por horas (opcional)**
5. Ingresar la **Hora de inicio** y la **Hora de fin** — el sistema calcula la duración en tiempo real
6. Si el permiso debe descontar del salario, activar el toggle **Genera descuento en la próxima nómina**
7. Guardar

> 💡 La sección de horas está colapsada por defecto. Expandirla solo si el permiso no abarca el día completo.

### Cálculo del descuento por permiso parcial

Si se activa la opción de deducción, el monto se calcula automáticamente al aprobar según el tipo de contrato:

| Tipo de contrato | Fórmula |
|------------------|---------|
| **Mensual** | Salario ÷ 240 horas (30 días × 8 hs) × horas del permiso |
| **Jornalero** | Tarifa diaria ÷ 8 × horas del permiso |

El descuento se registra como una deducción **fechada el día del permiso** y se aplica en la nómina de ese período.

---

## Estados del permiso

| Estado | Descripción |
|--------|-------------|
| **Pendiente** | Creado, esperando aprobación |
| **Aprobado** | Autorizado |
| **Rechazado** | No autorizado |
| **Anulada** | Estaba aprobada y se anuló: se deshicieron sus efectos |

> **El estado solo cambia con Aprobar o Rechazar.** En el formulario de edición el campo **Estado** se muestra (al crear no aparece: siempre nace Pendiente) pero no se puede modificar: antes se podía cambiar a «Aprobado» desde ahí, y eso no justificaba las ausencias del período (una licencia de maternidad quedó aprobada con 82 ausencias pendientes en **Por resolver**).

---

## Aprobar o rechazar un permiso

Los permisos se pueden gestionar desde tres lugares:

**Desde el listado global:**
1. Ir a **Licencias y vacaciones → Licencias**
2. En la fila del permiso → menú de acciones → **Aprobar** o **Rechazar**

**Desde el detalle del permiso:**
1. Clic sobre el permiso en la tabla para abrir el detalle
2. En el encabezado usar los botones **Aprobar** o **Rechazar**

**Desde el perfil del empleado:**
1. Abrir el empleado en **Empleados**
2. Ir a la pestaña **Licencias**
3. En la fila del permiso → **Aprobar** o **Rechazar**

### Información que muestra el modal de confirmación

El modal de aprobación muestra información contextual según el tipo de permiso:

| Tipo de permiso | Información mostrada |
|-----------------|----------------------|
| **Parcial por horas** | Rango horario y monto del descuento a generar (si aplica) |
| **Por días completos** | Cantidad de ausencias del período que se justificarán automáticamente |

---

## Anular una licencia aprobada

Una licencia **aprobada** que se cargó por error se puede **anular** desde el detalle (**Anular**, permiso *Anular licencias*). El modal pide el **motivo** y avisa de antemano qué se deshace:

- Las ausencias que la licencia había justificado vuelven a **Por resolver** (estado **Pendiente**): RR.HH. decide de nuevo. **No** se genera ningún descuento por esas ausencias.
- Los descuentos que había generado (LIC001) se **eliminan**.

Se bloquea si la licencia tiene descuentos y el empleado ya tiene **nómina generada** para esas fechas: primero hay que eliminar o regenerar esa nómina. Una licencia sin descuentos se puede anular aunque haya nómina. En el detalle, la sección **Seguimiento** muestra quién aprobó (o anuló) y cuándo.

---

## Justificación automática de ausencias

Al aprobar un permiso **por días completos**, el sistema busca todas las ausencias del empleado en ese período con estado **Pendiente** o **Injustificada** y las justifica sin intervención adicional.

> ⚠️ Los permisos **parciales por horas** **no justifican ausencias**. El empleado estuvo presente ese día — solo se ausentó unas horas. La justificación automática aplica únicamente a permisos de día completo.

Para más información sobre ausencias, ver el capítulo **Ausencias**.

---

## Documento de soporte

Al registrar el permiso se puede adjuntar un documento (certificado médico, solicitud escrita, etc.).

| Campo | Detalle |
|-------|---------|
| **Formatos aceptados** | PDF o cualquier imagen (JPG, PNG, GIF, WEBP, etc.) |
| **Tamaño máximo** | 5 MB |
| **Obligatorio en** | Reposo Médico |

El documento se puede descargar desde el detalle del permiso o desde la pestaña **Licencias** del perfil del empleado.

---

## Ver licencias desde el perfil del empleado

Además del listado global, cada empleado tiene una pestaña **Licencias** en su perfil:

1. Ir a **Empleados**
2. Clic sobre el empleado
3. Pestaña **Licencias**

Desde ahí se puede registrar, editar, aprobar, rechazar y descargar documentos de los permisos del empleado sin salir de su perfil.
