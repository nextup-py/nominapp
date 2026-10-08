# Organización

El módulo de Organización define la estructura legal y física de la empresa. Debe configurarse antes de registrar empleados.

## Empresas

Una empresa es la entidad legal empleadora. Puede tener múltiples sucursales.

![Listado de empresas](/docs-images/02-empresas.png)

**Listado:** las pestañas **Todas / Activas / Inactivas / Con datos pendientes** muestran cuántas empresas hay de cada tipo. La columna **Datos** indica si la empresa está completa o cuántas cosas le faltan (al pasar el cursor se ve el detalle): sin logo, sin sucursales, sin cuenta bancaria principal activa o con empleados activos sin contrato vigente. El menú de cada fila (⋮) permite abrir el **Organigrama**, descargarlo en **PDF** y **Activar** o **Desactivar** la empresa. El **Nro. Patronal** y las fechas de creación y actualización están ocultos por defecto (se activan desde el selector de columnas de la tabla). La búsqueda global del panel encuentra empresas por razón social, nombre comercial, RUC o número patronal.

**Campos principales** (todos obligatorios salvo el nombre comercial, la fecha de constitución y el logo):
- **Razón social** y **nombre comercial** (nombre de fantasía, opcional)
- **Tipo societario:** SA, SRL, EU, Cooperativa, Fundación, etc.
- **RUC:** formato `XXXXXXXX-D` (ej: `80012345-6`)
- **Número patronal IPS**
- **Representante legal:** nombre y cédula
- **Logo:** se usa en encabezados de todos los PDFs generados (opcional, pero conviene cargarlo)
- **Dirección, ciudad, teléfono y correo electrónico**
- **Activa:** las empresas inactivas no aparecen en los selectores del sistema

**Cómo crear una empresa:**

1. Ir a **Organización → Empresas**
2. Clic en **Nueva empresa**
3. Completar los datos legales: razón social, RUC, número patronal, tipo societario, representante legal
4. Completar dirección y contacto. Para la **ciudad**, elegir primero el **Departamento** (opcional, solo filtra la lista) y luego la **Ciudad**; el catálogo incluye los 17 departamentos y las ciudades de Paraguay
5. Subir el logo (JPG, PNG, WEBP o SVG, máx. 5 MB). Conviene PNG: el SVG se ve en el panel y en los PDFs, pero no en el terminal ni en el celular
6. Guardar

![Departamento y ciudad en el formulario de la empresa](/docs-images/02-empresa-ciudad.png)

> El RUC debe tener el formato `número-dígito` (ej: `80012345-6`). El teléfono debe ingresarse con el 0 inicial, sin espacios (ej: `0981123456`).

**Vista de la empresa:** al abrir una empresa se ve un resumen con sucursales, departamentos, empleados, contratos (activos y por vencer), empleados activos **sin contrato**, terminales activos y períodos de nómina del año, además de sus datos legales, de contacto y la **cuenta bancaria principal**. Las pestañas inferiores listan sus sucursales, empleados, cuentas bancarias y el **historial de cambios** (quién modificó qué y cuándo).

![Detalle de una empresa](/docs-images/02-empresa-detalle.png)

**Datos pendientes:** si a la empresa le falta algo que usan los PDFs o los pagos (logo, dirección o ciudad, representante legal, o una cuenta bancaria principal activa), la parte superior de la vista muestra un aviso con lo que falta. Desaparece solo al completarlo.

**Desactivar o eliminar:** para dejar de usar una empresa, usar la acción **Desactivar** (en el menú de la fila del listado o en el encabezado de la vista) o desmarcar **Activa** en su edición (el interruptor aparece deshabilitado, con la explicación, mientras haya empleados activos). **No se puede desactivar una empresa que tenga empleados activos**: hay que desvincularlos o transferirlos a otra empresa primero, y el sistema avisa cuántos son. Los terminales de sus sucursales no se modifican al desactivarla. **Eliminar** solo es posible si la empresa está vacía: si tiene sucursales, departamentos, cuentas bancarias, períodos de nómina o aguinaldo, lotes de pago, plantillas de contrato, plantillas de turno o patrones de rotación, el sistema bloquea la eliminación y avisa cuántos hay de cada uno.

> Eliminar una empresa vacía no se puede deshacer. Si ya se usó alguna vez, desactivarla es la opción correcta.

**Exportar:** el botón **Exportar** del listado descarga un Excel con las empresas que se están viendo (respeta la pestaña, los filtros y la búsqueda) y permite elegir qué columnas incluir, entre ellas los datos pendientes.

**Organigrama:** Desde la vista de una empresa puede abrir el organigrama o descargarlo en PDF con los botones **Organigrama** y **Organigrama en PDF**.

---

## Sucursales

Cada empresa puede tener una o varias sucursales. Los empleados se asignan a una sucursal específica.

**Campos:** nombre, dirección, ciudad, teléfono, email, y coordenadas GPS (mapa interactivo).

**Cómo crear una sucursal:**

1. Ir a **Organización → Sucursales** (o desde la pestaña **Sucursales** dentro de la empresa)
2. Clic en **Nueva sucursal**
3. Completar los datos de contacto
4. Opcionalmente, marcar la ubicación en el mapa o ingresar la dirección para que el sistema geolocalice automáticamente
5. Guardar

> Las coordenadas GPS se usan para vincular marcaciones de asistencia a la ubicación de la sucursal.

---

## Departamentos

Los departamentos agrupan cargos dentro de una empresa. Cada empresa define sus propios departamentos de forma independiente.

**Campos:** nombre, centro de costo (código opcional, ej: `RH-001`), descripción.

**Cómo crear un departamento:**

1. Ir a **Organización → Departamentos**
2. Clic en **Nuevo departamento**
3. Seleccionar la empresa e ingresar el nombre
4. Guardar

> El nombre del departamento debe ser único dentro de la misma empresa.

---

## Cargos

Los cargos (puestos de trabajo) pertenecen a un departamento. Pueden organizarse en jerarquía (un cargo puede tener un cargo superior).

**Campos:** nombre, departamento, **Reporta a** (cargo superior, opcional — define la jerarquía del organigrama).

**Cómo crear un cargo:**

1. Ir a **Organización → Cargos**
2. Clic en **Nuevo cargo**
3. Seleccionar el departamento e ingresar el nombre del cargo
4. Si corresponde, seleccionar en **Reporta a** el cargo superior para construir la jerarquía del organigrama
5. Guardar

> Departamentos y cargos también pueden crearse directamente desde el formulario de contrato del empleado, sin salir de la pantalla.

---

## Organigrama

El organigrama se genera automáticamente a partir de la jerarquía de cargos. Para verlo o descargarlo en PDF:

1. Abrir la empresa en **Organización → Empresas**
2. Usar la acción **Organigrama** en el encabezado de la página (también disponible como acción de fila desde el listado de empresas)
