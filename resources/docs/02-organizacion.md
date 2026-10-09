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

**Datos pendientes:** si a la empresa le falta algo que usan los PDFs o los pagos (logo, dirección o ciudad, representante legal, o una cuenta bancaria principal activa), la parte superior de la vista muestra un aviso con lo que falta. Desaparece solo al completarlo.

**Desactivar o eliminar:** para dejar de usar una empresa, usar la acción **Desactivar** (en el menú de la fila del listado o en el encabezado de la vista) o desmarcar **Activa** en su edición (el interruptor aparece deshabilitado, con la explicación, mientras haya empleados activos). **No se puede desactivar una empresa que tenga empleados activos**: hay que desvincularlos o transferirlos a otra empresa primero, y el sistema avisa cuántos son. Los terminales de sus sucursales no se modifican al desactivarla. **Eliminar** solo es posible si la empresa está vacía: si tiene sucursales, departamentos, cuentas bancarias, períodos de nómina o aguinaldo, lotes de pago, plantillas de contrato, plantillas de turno o patrones de rotación, el sistema bloquea la eliminación y avisa cuántos hay de cada uno.

> Eliminar una empresa vacía no se puede deshacer. Si ya se usó alguna vez, desactivarla es la opción correcta.

**Cuentas bancarias:** la cuenta **principal** es la que usan los lotes de pago. No se puede eliminar la principal mientras haya otras cuentas activas: primero hay que marcar otra como principal. Si es la única cuenta se puede eliminar, y la empresa queda con el aviso de "Sin cuenta bancaria principal".

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

**Eliminar una sucursal:** solo es posible si no tiene empleados, terminales ni fallas de marcación asociadas. Si los tiene, el sistema bloquea la eliminación y avisa cuántos hay de cada uno; hay que reasignarlos primero.

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

**Filtros:** sobre el organigrama hay un buscador (por nombre de empleado o de cargo) y selectores de **Sucursal** (solo si hay más de una) y **Departamento**, además del interruptor **Mostrar vacantes** (activado por defecto). Clic en **Aplicar** para filtrar y en **Limpiar** para volver a ver todo. Los filtros quedan en la dirección de la página, así que se pueden compartir como enlace.

**Modo oscuro:** el botón con el ícono de luna/sol de la cabecera cambia entre tema claro y oscuro; la página sigue por defecto la preferencia del sistema y recuerda la elección en ese navegador. En pantallas chicas el árbol se desplaza horizontalmente.

**PDF:** **Exportar PDF** descarga el organigrama con los mismos filtros activos y deja constancia de ellos bajo el título (por ejemplo, "Departamento: Ventas · Sin vacantes").

> Si un cargo tiene como superior a otro de un departamento distinto, se muestra como cargo principal dentro de su propio departamento. Revisar el campo **Reporta a** si la jerarquía no se ve como se espera.
