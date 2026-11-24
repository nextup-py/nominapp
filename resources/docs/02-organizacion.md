# Organización

El módulo de Organización define la estructura legal y física de la empresa. Debe configurarse antes de registrar empleados.

## Empresas

Una empresa es la entidad legal empleadora. Puede tener múltiples sucursales.

![Listado de empresas](/docs-images/02-empresas.png)

**Listado:** las pestañas **Todas / Activas / Inactivas / Con datos pendientes** muestran cuántas empresas hay de cada tipo. La columna **Datos** indica si la empresa está completa o cuántas cosas le faltan (al pasar el cursor se ve el detalle): sin logo, sin sucursales, sin cuenta bancaria principal activa o con empleados activos sin contrato vigente. El menú de cada fila (⋮) permite abrir el **Organigrama**, descargarlo en **PDF** y **Activar** o **Desactivar** la empresa. El **Nro. Patronal** y las fechas de creación y actualización están ocultos por defecto (se activan desde el selector de columnas de la tabla). La búsqueda global del panel encuentra empresas por razón social, nombre comercial, RUC o número patronal.

**Formulario:** al editar, la primera sección es **Estado** (el interruptor **Activa**); le siguen **Identidad**, **Constitución Legal** y **Contacto**. Todos los datos son obligatorios salvo el logo, el nombre comercial y la fecha de constitución:
- **Identidad:** logo (se usa en los encabezados de los PDFs; conviene cargarlo), razón social, nombre comercial (si se deja vacío se usa la razón social), RUC con formato `XXXXXXXX-D` (ej: `80012345-6`) y número patronal IPS. El RUC y el número patronal no se pueden repetir entre empresas.
- **Constitución Legal:** tipo societario (SA, SRL, EU, Cooperativa, Fundación, etc.), fecha de constitución y representante legal (nombre y cédula, solo dígitos).
- **Contacto:** dirección, departamento y ciudad, teléfono y correo electrónico.
- **Ficha del empleado, Aviso de privacidad y Marcación desde el celular:** secciones opcionales; arrancan cerradas y se abren solas si la empresa ya tiene datos cargados.

**Cómo crear una empresa:**

1. Ir a **Organización → Empresas**
2. Clic en **Nueva empresa**
3. En **Identidad**, subir el logo (JPG, PNG, WEBP o SVG, máx. 5 MB; conviene PNG: el SVG se ve en el panel y en los PDFs, pero no en el terminal ni en el celular) y completar razón social, RUC y número patronal
4. En **Constitución Legal**, elegir el tipo societario y completar el representante legal
5. En **Contacto**, completar dirección y datos de contacto. Para la **ciudad**, elegir primero el **Departamento** (opcional, solo filtra la lista) y luego la **Ciudad**; el catálogo incluye los 17 departamentos y las ciudades de Paraguay
6. Guardar

![Departamento y ciudad en el formulario de la empresa](/docs-images/02-empresa-ciudad.png)

> El RUC debe tener el formato `número-dígito` (ej: `80012345-6`). El teléfono debe ingresarse con el 0 inicial, sin espacios (ej: `0981123456`).

**Vista de la empresa:** al abrir una empresa se ve un resumen con sucursales, departamentos, empleados, contratos (activos y por vencer), empleados activos **sin contrato**, terminales activos y períodos de nómina del año, además de sus datos legales, de contacto y la **cuenta bancaria principal**. Las pestañas inferiores, en este orden, son de solo lectura salvo Sucursales y Cuentas bancarias:

| Pestaña | Qué muestra |
|---------|-------------|
| **Sucursales** | Sucursales de la empresa (se pueden crear, editar y eliminar desde acá) |
| **Departamentos** | Cada departamento con su cantidad de cargos y de empleados activos; clic para abrirlo |
| **Empleados** | Empleados de la empresa, con filtros por estado, sucursal, **departamento** y **cargo** |
| **Terminales** | Terminales de marcación de todas sus sucursales, con su estado y conectividad; filtros por sucursal y estado |
| **Períodos de nómina** | Períodos de nómina de la empresa con su frecuencia, vigencia, cantidad de recibos y estado |
| **Aguinaldos** | Períodos de aguinaldo por año, con generados, pagados y estado |
| **Cuentas Bancarias** | Cuentas de la empresa; marcar principal, activar/desactivar, editar y eliminar |
| **Historial de cambios** | Quién modificó qué y cuándo |

![Pestañas de la empresa](/docs-images/02-empresa-pestanas.png)

![Detalle de una empresa](/docs-images/02-empresa-detalle.png)

**Aviso de privacidad:** la sección **Aviso de privacidad** del formulario (se abre sola si ya hay uno cargado) tiene el **texto del aviso** (máximo 3000 caracteres) y, opcionalmente, el **enlace a la política completa**. Lo redacta la empresa; el sistema solo lo muestra en el terminal de asistencia (ver el capítulo *Terminales de Marcación*). Cada vez que se cambia el texto o el enlace la **versión del aviso** sube sola; se ve en la vista de la empresa. Sin texto ni enlace, el terminal no muestra ningún aviso. Con un aviso cargado, además se pide la aceptación al empleado cuando registra su rostro por enlace o vincula su celular (ver *Consentimiento del aviso de privacidad* en el capítulo de Asistencias).

**Datos pendientes:** si a la empresa le falta algo que usan los PDFs o los pagos (logo, dirección o ciudad, representante legal, o una cuenta bancaria principal activa), la parte superior de la vista muestra un aviso con lo que falta. Desaparece solo al completarlo.

**Desactivar o eliminar:** para dejar de usar una empresa, usar la acción **Desactivar** (en el menú de la fila del listado o en el encabezado de la vista) o desmarcar **Activa** en la sección **Estado** de su edición (el interruptor aparece deshabilitado, con la explicación, mientras haya empleados activos). **No se puede desactivar una empresa que tenga empleados activos**: hay que desvincularlos o transferirlos a otra empresa primero, y el sistema avisa cuántos son. Los terminales de sus sucursales no se modifican al desactivarla. **Eliminar** solo aparece si la empresa está vacía: si tiene sucursales, departamentos, cuentas bancarias, períodos de nómina o aguinaldo, lotes de pago, plantillas de contrato, plantillas de turno o patrones de rotación, el botón no se muestra (queda **Desactivar**) y, si se intenta por otra vía, el sistema bloquea la eliminación y avisa cuántos hay de cada uno.

> Eliminar una empresa vacía no se puede deshacer. Si ya se usó alguna vez, desactivarla es la opción correcta.

**Cuentas bancarias:** la cuenta **principal** es la que usan los lotes de pago. No se puede eliminar la principal mientras haya otras cuentas activas: primero hay que marcar otra como principal. Si es la única cuenta se puede eliminar, y la empresa queda con el aviso de "Sin cuenta bancaria principal".

**Exportar:** el botón **Exportar** del listado descarga un Excel con las empresas que se están viendo (respeta la pestaña, los filtros y la búsqueda) y permite elegir qué columnas incluir, entre ellas los datos pendientes.

**Organigrama:** Desde la vista de una empresa puede abrir el organigrama o descargarlo en PDF con los botones **Organigrama** y **Organigrama en PDF**.

---

## Sucursales

Cada empresa puede tener una o varias sucursales. Los empleados se asignan a una sucursal específica.

**Formulario:** al editar, la primera sección es **Estado** (el interruptor **Activa**); le siguen **Información general** (empresa, nombre, teléfono y correo), **Ubicación** (dirección, ciudad y mapa), **Horario de atención (opcional)** y **Marcación desde el celular**. El campo **Empresa** solo aparece si hay más de una empresa activa; con una sola, la sucursal se crea en ella. El nombre no se puede repetir dentro de la empresa ni el correo entre sucursales. Las dos últimas secciones arrancan cerradas y se abren solas si ya tienen datos. La **ciudad** se elige del catálogo oficial: primero, opcionalmente, el **Departamento** (solo filtra las ciudades y no se guarda) y luego la **Ciudad**; el mapa completa la dirección, pero ya no la ciudad.

**Listado:** las pestañas **Todas / Activas / Inactivas / Con datos pendientes** muestran cuántas sucursales hay de cada tipo. La columna **Datos** indica si la sucursal está completa o cuántas cosas le faltan (al pasar el cursor se ve el detalle): sin ubicación en el mapa, sin ciudad, sin teléfono, con empleados activos pero sin terminal activo, o con empleados activos sin contrato vigente. La columna y el filtro **Empresa** solo aparecen si hay más de una empresa activa. El menú de cada fila (⋮) permite abrir la sucursal en el mapa y **Activar** o **Desactivar**.

**Horario de atención:** la sección **Horario de atención** define los días y las horas en que la sucursal trabaja (por ejemplo, lunes a sábado de 06:00 a 23:00). Los terminales de la sucursal que no tienen horario de vigilancia propio usan este horario: fuera de él no se avisa que están desconectados (**Fuera de horario**), con lo que se evitan, por ejemplo, las alertas de las 06:00 de un terminal apagado de noche. Ninguno marcado = todos los días; se completan **las dos** horas o ninguna. Si un día tiene otro horario (por ejemplo, el sábado hasta el mediodía), se agrega en **Horas distintas en ciertos días**: ese día usa sus propias horas y los demás las generales.

**Vista de la sucursal:** el detalle avisa en **Datos pendientes** lo que falta cargar y muestra empleados activos, activos sin contrato, terminales activos, el estado y el horario de atención.

**Cómo crear una sucursal:**

1. Ir a **Organización → Sucursales** (o desde la pestaña **Sucursales** dentro de la empresa)
2. Clic en **Nueva sucursal**
3. Completar el nombre y los datos de contacto (y la empresa, si hay más de una)
4. En **Ubicación**, marcar el punto en el mapa o ingresar la dirección para que el sistema geolocalice automáticamente, y elegir la ciudad
5. Guardar

> Las coordenadas GPS se usan para vincular marcaciones de asistencia a la ubicación de la sucursal.

**Desactivar una sucursal:** para dejar de usar una sucursal sin borrarla (conserva su historial), usar la acción **Desactivar** del listado o del detalle, o desmarcar **Activa** en la sección **Estado** de la edición (el interruptor aparece deshabilitado, con la explicación, mientras haya empleados activos). **No se puede desactivar una sucursal con empleados activos**: hay que reasignarlos a otra sucursal primero y el sistema avisa cuántos son. Una sucursal inactiva deja de ofrecerse al asignar empleados o terminales y de contarse en la tarjeta de asistencia del Escritorio, pero sus terminales no se modifican y las ya asignadas se siguen viendo en los registros existentes. Los reportes siguen mostrándola.

**Eliminar una sucursal:** solo es posible si no tiene empleados, terminales ni fallas de marcación asociadas. Si los tiene, el sistema bloquea la eliminación y avisa cuántos hay de cada uno; hay que reasignarlos primero.

**Eliminar varias a la vez:** en el listado, seleccionar las sucursales y usar **Eliminar seleccionadas**. Se borran solo las que están vacías; las que tienen empleados, terminales o fallas de marcación se omiten y la notificación indica cuáles y por qué.

**Historial de cambios:** la pestaña **Historial de cambios** de la sucursal muestra quién modificó la empresa, el nombre, el contacto, la dirección, la ciudad o las coordenadas, y cuándo, con el valor anterior y el nuevo.

**Exportar:** el botón **Exportar** del listado descarga a Excel solo las sucursales que se están viendo (según pestaña, filtros y búsqueda).

---

## Departamentos

Los departamentos agrupan cargos dentro de una empresa. Cada empresa define sus propios departamentos de forma independiente.

**Campos:** empresa (solo aparece si hay más de una empresa activa; con una sola, el departamento se crea en ella), nombre, **Responsable** (opcional: un empleado activo de la empresa que dirige el departamento; se busca por nombre o cédula y se vacía si se cambia la empresa), centro de costo (código opcional, ej: `RH-001`) y descripción (opcional). El listado muestra el **Responsable** y las **Vacantes** (plazas libres de los cargos del departamento que tienen plazas definidas).

**Cómo crear un departamento:**

1. Ir a **Organización → Departamentos**
2. Clic en **Nuevo departamento**
3. Ingresar el nombre (y elegir la empresa, si hay más de una)
4. Guardar

> El nombre y el centro de costo no se pueden repetir dentro de la misma empresa.

**Activar y desactivar:** para dejar de usar un departamento sin perder su historial, usar la acción **Desactivar** (menú de la fila del listado o encabezado de la vista) o desmarcar **Activo** en la edición. Un departamento inactivo deja de ofrecerse al asignar contratos y al dar de alta empleados, pero los contratos históricos y los reportes lo siguen mostrando. **No se puede desactivar un departamento con contratos vigentes**: hay que mover antes esos contratos a otro departamento, y el sistema avisa cuántos son. El listado tiene el filtro **Estado** y la columna **Estado**.

**Cambiar de empresa o eliminar:** un departamento que ya tiene cargos o contratos no puede cambiarse de empresa (el campo queda deshabilitado, con la explicación) ni eliminarse: el sistema avisa cuántos cargos y contratos tiene. Para dejar de usarlo, desactivarlo; para eliminarlo hay que mover antes sus cargos y contratos a otro departamento.

---

## Cargos

Los cargos (puestos de trabajo) pertenecen a un departamento. Pueden organizarse en jerarquía (un cargo puede tener un cargo superior).

**Campos:** nombre, departamento y **Reporta a** (cargo superior, opcional — define la jerarquía del organigrama). **Reporta a** solo ofrece cargos de la misma empresa que el departamento elegido, sin incluir al propio cargo ni a los que dependen de él: por eso conviene elegir primero el departamento. El nombre no se puede repetir dentro del departamento.

Además, el cargo puede llevar: **Descripción o funciones**, **Cargo de jefatura** (supervisa a otras personas), **Salario mínimo y máximo** (mensual, en Gs.; el máximo no puede ser menor que el mínimo) y **Plazas autorizadas** (cuántas personas pueden ocupar el cargo; vacío = sin límite), en la sección **Remuneración y plazas**, que arranca cerrada y se abre sola si ya tiene datos. El rango y las plazas **solo avisan**: al cargar un contrato, si el salario mensual queda fuera del rango o el cargo ya tiene todas sus plazas ocupadas, aparece un aviso junto al campo, pero el contrato se guarda igual. En la vista del cargo se ven las plazas ocupadas, las **vacantes** (plazas − personas con contrato vigente) y el rango. En **Marcación de asistencia** se suman **Horas extra** (Heredar / Sí / No; por ahora solo se registra, todavía no cambia el cálculo) y **Horario sugerido**: al usar **Asignar horario** desde Empleados, si no hay una sugerencia por marcaciones se preselecciona el horario del cargo (nunca se asigna solo).

**Cómo crear un cargo:**

1. Ir a **Organización → Cargos**
2. Clic en **Nuevo cargo**
3. Seleccionar el departamento e ingresar el nombre del cargo
4. Si corresponde, seleccionar en **Reporta a** el cargo superior para construir la jerarquía del organigrama
5. Guardar

**Marcación de asistencia por cargo:** la sección **Marcación de asistencia** del formulario del cargo tiene tres campos que se aplican a todos los que lo ocupan, incluidos los que se incorporen después:

- **Marcación desde el celular** (Heredar / Sí / No): ver **Quién puede marcar desde el celular** en el capítulo de Asistencias.
- **Marca descanso**: activado de fábrica. Se desactiva para los cargos que no marcan descanso (por ejemplo, repartidores en ruta): al terminal y al celular ya no les aparece **Inicio descanso**, aunque el horario del empleado tenga descanso, y las **horas netas** de los reportes descuentan el descanso del horario cuando ese día no marcó uno real. Las horas extra y la nómina no cambian.
- **Marcación en terminales**: **Solo en su sucursal** (de fábrica), **En su sucursal y en las que se elijan** (aparece el campo **Sucursales adicionales**) o **En cualquier sucursal**. Sirve, por ejemplo, para que los Supervisores marquen en el terminal de cualquier local. Ver **Marcar en el terminal de otra sucursal** en el capítulo de Asistencias.

**Activar y desactivar:** igual que en los departamentos: **Desactivar** (menú de la fila o encabezado de la vista) o desmarcar **Activo** en la edición. Un cargo inactivo deja de ofrecerse al asignar contratos, como superior en **Reporta a**, como destino de **Fusionar** y en el organigrama (si no tiene empleados); los reportes históricos lo siguen mostrando. **No se puede desactivar un cargo con contratos vigentes**: hay que moverlos antes a otro cargo con **Fusionar**.

**Cambiar de departamento o eliminar:** un cargo que ya tiene contratos no puede cambiarse de departamento (el campo queda deshabilitado, con la explicación) ni eliminarse: el sistema avisa cuántos contratos tiene. Para unirlo con otro cargo se usa **Fusionar en otro cargo**. En la eliminación masiva se borran solo los cargos sin contratos y la notificación indica cuáles se omitieron.

> Departamentos y cargos también pueden crearse directamente desde el formulario de contrato del empleado, sin salir de la pantalla.

---

**Fusionar cargos duplicados:** en el menú de cada fila del listado de cargos, **Fusionar en otro cargo** mueve los contratos, los cargos que reportaban a este y las sucursales de marcación a otro cargo **de la misma empresa**, y elimina el cargo sobrante. Antes de confirmar se ve cuántos contratos y empleados activos se mueven y qué configuraciones difieren (departamento, dónde puede marcar en terminal, permiso de celular, marca descanso): prevalece lo del cargo que se conserva. Hace falta poder editar y eliminar cargos. Es útil para unir, por ejemplo, «Supervisor De Sucursal» con «Supervisor de Sucursal».

## Organigrama

El organigrama se genera automáticamente a partir de la jerarquía de cargos de cada departamento. Para verlo o descargarlo en PDF:

1. Abrir la empresa en **Organización → Empresas**
2. Usar la acción **Organigrama** en el encabezado de la página (también disponible en el menú de cada fila del listado de empresas)

![Organigrama de una empresa con filtros y vacantes](/docs-images/02-organigrama.png)

**Qué muestra:**
- Cada cargo con los empleados activos que lo ocupan, con su **fecha de ingreso** ("Desde 03/2022") y, si la empresa tiene más de una sucursal, su **sucursal**.
- Los empleados con **contrato suspendido** llevan la insignia **Suspendido**. Los empleados inactivos no aparecen.
- Los cargos sin ningún ocupante aparecen atenuados, con borde punteado y la etiqueta **Vacante**. Un cargo ocupado solo en otra sucursal no es vacante.
- Los empleados activos sin contrato vigente (y por lo tanto sin cargo) se listan aparte, en **Empleados sin cargo asignado**.
- Los totales del encabezado (departamentos, cargos, empleados y vacantes) cuentan lo que se está viendo.

**Vista por personas:** el selector **Vista** del organigrama permite cambiar de **Por cargos** (la estructura de puestos, con vacantes) a **Por personas**, que arma el árbol con el campo **Reporta a** de cada empleado (ver *Superior directo* en el capítulo de Empleados). Las raíces son quienes tienen subordinados y no reportan a nadie, y quienes no tienen superior ni subordinados aparecen aparte en **Sin superior asignado**. La búsqueda, la sucursal y el departamento conservan la cadena de superiores de cada coincidencia. Esta vista no tiene PDF.

**Filtros:** sobre el organigrama hay un buscador (por nombre de empleado o de cargo) y selectores de **Sucursal** (solo si hay más de una) y **Departamento**, además del interruptor **Mostrar vacantes** (activado por defecto). Clic en **Aplicar** para filtrar y en **Limpiar** para volver a ver todo. Los filtros quedan en la dirección de la página, así que se pueden compartir como enlace.

**Modo oscuro:** el botón con el ícono de luna/sol de la cabecera cambia entre tema claro y oscuro; la página sigue por defecto la preferencia del sistema y recuerda la elección en ese navegador. En pantallas chicas el árbol se desplaza horizontalmente.

**PDF:** **Exportar PDF** descarga el organigrama con los mismos filtros activos y deja constancia de ellos bajo el título (por ejemplo, "Departamento: Ventas · Sin vacantes").

> Si un cargo tiene como superior a otro de un departamento distinto, se muestra como cargo principal dentro de su propio departamento. Revisar el campo **Reporta a** si la jerarquía no se ve como se espera.
