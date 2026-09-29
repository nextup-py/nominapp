# Lotes de Pagos Bancarios

El módulo de Lotes agrupa pagos por transferencia bancaria en un único archivo TXT en formato Itaú para enviar al banco, y registra el resultado de la acreditación. Evita procesar cada pago por separado y mantiene trazabilidad completa del ciclo bancario.

Un lote puede agrupar **cuatro tipos** de pago distintos — cada uno se crea desde su propio módulo, no desde un formulario único:

| Tipo | Se crea desde |
|------|----------------|
| **Adelantos de salario** | **Nóminas → Pagos Bancarios**, botón **Crear lote de pago** |
| **Planilla de salarios** | Vista de un período de nómina cerrado, acción **Enviar al Banco** |
| **Préstamos** | Listado de Préstamos, header action **Crear Lote Bancario** |
| **Aguinaldo** | Vista de un período de aguinaldo, acción **Enviar al Banco** |

---

![Listado de lotes de pagos bancarios](/docs-images/17-lotes-bancarios.png)

## Requisitos previos

Para poder generar y descargar el archivo TXT, deben estar configurados:

- La **empresa** con una cuenta bancaria principal activa
- La cuenta bancaria con el **ID de Empresa** configurado
- Los empleados incluidos con **cuentas bancarias primarias activas** — si falta alguna, el sistema advierte cuáles empleados no la tienen (no bloquea la creación del lote, pero esos registros quedan fuera del TXT)

---

## Estados de un lote

| Estado | Descripción |
|--------|-------------|
| **Pendiente** | Creado, aún no confirmado con el banco |
| **Confirmado** | Todos los ítems fueron aceptados por el banco |
| **Parcialmente confirmado** | El banco aceptó algunos y rechazó otros |
| **Cancelado** | Cancelado manualmente, o porque todos los ítems fueron rechazados |

---

## Crear un lote de Adelantos

1. Ir a **Nóminas → Pagos Bancarios**
2. Clic en **Crear lote de pago**
3. Seleccionar la **empresa** — el sistema carga automáticamente los adelantos disponibles (estado Aprobado, método de pago Transferencia, sin lote asignado)
4. Seleccionar los adelantos a incluir en este lote
5. Definir la **fecha de acreditación** (por defecto: hoy)
6. Agregar **notas** si aplica
7. Guardar

## Crear un lote de Planilla, Préstamos o Aguinaldo

Estos tres tipos **no** se crean desde el listado de Pagos Bancarios — se generan desde su propio módulo, y el sistema incluye automáticamente **todos** los registros elegibles de la empresa (no hay selección individual):

- **Planilla**: en la vista del período de nómina, acción **Enviar al Banco** → elegir empresa (si hay más de una con recibos elegibles) → el lote incluye todos los recibos **Aprobados**, por **Transferencia**, sin lote asignado, de ese período
- **Préstamos**: en el listado de Préstamos, header action **Crear Lote Bancario** → mismo criterio, sobre préstamos **Aprobados** por Transferencia
- **Aguinaldo**: en la vista del período de aguinaldo, acción **Enviar al Banco** → mismo criterio, sobre aguinaldos **Pendientes** por Transferencia

En los tres casos, antes de crear el lote el modal muestra un aviso con los empleados que no tienen cuenta bancaria activa registrada (no bloquea la creación). Al confirmar, el sistema redirige directamente al detalle del lote recién creado.

---

## Descargar el archivo TXT para el banco

1. Abrir el lote (estado **Pendiente**)
2. Clic en **Descargar TXT**
3. El modal muestra un resumen (cantidad de ítems y monto total) antes de generar el archivo
4. El archivo generado está en formato Itaú — enviarlo al banco por el canal habitual

> Si la empresa no tiene cuenta bancaria principal activa, o le falta el **ID de Empresa**, el sistema bloquea la descarga y avisa qué falta configurar.

---

## Confirmar el resultado del banco

Una vez que el banco procesa el archivo y devuelve el resultado:

1. Abrir el lote (debe estar en estado **Pendiente**)
2. Clic en **Confirmar Lote**
3. En el modal:
   - Adjuntar el **comprobante bancario** (PDF, JPG, PNG o WEBP, máx. 10 MB) — obligatorio
   - Marcar en la lista los ítems que el banco **rechazó** (si los hay)
4. Confirmar

Los ítems no marcados como rechazados pasan automáticamente según el tipo de lote:

| Tipo | Ítems aceptados pasan a | Ítems rechazados vuelven a |
|------|--------------------------|------------------------------|
| Adelantos | Entregado (Acreditado) | Aprobado, con motivo de rechazo registrado |
| Planilla | Desembolsado | Aprobado, con motivo de rechazo registrado |
| Préstamos | Desembolsado | Aprobado |
| Aguinaldo | Pagado | Pendiente |

El lote pasa a **Confirmado**, **Parcialmente confirmado** o **Cancelado** (si se rechazaron todos) según el resultado.

---

## Editar un lote

Mientras el lote esté **Pendiente**, la acción **Editar** permite modificar la **fecha de acreditación** y las **notas** — no los ítems incluidos.

---

## Cancelar un lote

Solo disponible desde estado **Pendiente**, acción **Cancelar Lote** (con confirmación).

Al cancelar, todos los ítems del lote quedan libres para incluirse en un lote nuevo: los adelantos y recibos de nómina vuelven a **Aprobado**, los préstamos vuelven a **Aprobado**, los aguinaldos vuelven a **Pendiente**.

---

## Acciones disponibles según estado

Estas acciones están en el encabezado de la vista de detalle del lote (no en el listado — la tabla solo tiene la acción **Ver**):

| Estado | Acciones disponibles |
|--------|---------------------|
| **Pendiente** | Editar, Descargar TXT, Confirmar Lote, Cancelar Lote |
| **Confirmado / Parcialmente confirmado / Cancelado** | Solo lectura — sin acciones de mutación |

En lotes de tipo Planilla o Aguinaldo también aparece un acceso directo (**Ver Planilla** / **Ver Período de Aguinaldo**) al período de origen.

---

## Detalle del lote

La vista de detalle muestra, además de los datos generales (empresa, tipo, fecha de acreditación, estado, cantidad de ítems y monto total):
- El **archivo TXT** generado y el **comprobante bancario** adjuntado (si existen), con enlace de descarga
- Pestañas con el listado de los ítems incluidos (adelantos, recibos, préstamos o aguinaldos según el tipo)
- Pestaña de **Auditoría** con el historial de cambios de estado

---

## Tabs del listado

El listado (**Nóminas → Pagos Bancarios**) filtra por **tipo de lote**, no por estado:

| Tab | Descripción |
|-----|-------------|
| **Todos** | Todos los lotes, de cualquier tipo |
| **Adelantos** | Lotes de adelantos de salario |
| **Planilla** | Lotes de recibos de nómina |
| **Préstamos** | Lotes de préstamos |
| **Aguinaldo** | Lotes de aguinaldo |

Para filtrar por estado (Pendiente, Confirmado, etc.) usar el filtro **Estado** de la tabla, junto con el filtro **Empresa**.

---

## Relación con los módulos de origen

Los adelantos, recibos, préstamos y aguinaldos incluidos en un lote muestran a qué lote pertenecen desde su propia vista de detalle. Al cancelar o rechazar un lote, el registro queda libre para asignarse a un nuevo lote.

Para gestionar cada tipo individualmente ver los capítulos **Adelantos**, **Nóminas**, **Préstamos** y **Aguinaldo**.
