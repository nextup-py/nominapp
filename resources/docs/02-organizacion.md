# Organización

El módulo de Organización define la estructura legal y física de la empresa. Debe configurarse antes de registrar empleados.

## Empresas

Una empresa es la entidad legal empleadora. Puede tener múltiples sucursales.

![Listado de empresas](/docs-images/02-empresas.png)

**Campos principales:**
- **Razón social** y **nombre comercial** (nombre de fantasía)
- **Tipo societario:** SA, SRL, EU, Cooperativa, Fundación, etc.
- **RUC:** formato `XXXXXXXX-D` (ej: `80012345-6`)
- **Número patronal IPS**
- **Representante legal:** nombre y cédula
- **Logo:** se usa en encabezados de todos los PDFs generados
- **Dirección, teléfono, email, ciudad**
- **Activa:** las empresas inactivas no aparecen en los selectores del sistema

**Cómo crear una empresa:**

1. Ir a **Organización → Empresas**
2. Clic en **Nueva empresa**
3. Completar los datos legales: razón social, RUC, número patronal, representante legal
4. Subir el logo (JPG, PNG, WEBP o SVG, máx. 5 MB)
5. Completar dirección y contacto
6. Guardar

> El RUC debe tener el formato `número-dígito` (ej: `80012345-6`). El teléfono debe ingresarse con el 0 inicial, sin espacios (ej: `0981123456`).

**Organigrama:** Desde la vista de una empresa puede generar y descargar el organigrama completo en PDF con el botón correspondiente.

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
