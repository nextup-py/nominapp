# Liquidaciones (Finiquito)

La liquidación es el pago final al empleado cuando termina la relación laboral. El monto y los conceptos incluidos dependen del **tipo de terminación**, si hubo **período de prueba** y si el empleador ya **otorgó preaviso**.

---

## Tipos de terminación y conceptos incluidos

| Tipo | Preaviso | Indemnización |
|------|----------|---------------|
| **Despido Injustificado** | Sí | Sí |
| **Despido Justificado** | No | No |
| **Renuncia Voluntaria** | No | No |
| **Mutuo Acuerdo** | No | No |
| **Fin de Contrato** | No | No |

Todos los tipos incluyen (salvo período de prueba, ver abajo): salario pendiente proporcional, vacaciones proporcionales y aguinaldo proporcional al año.

**Período de prueba:** si la antigüedad (días entre fecha de ingreso y fecha de desvinculación) es menor o igual a los días de prueba del contrato del empleado (`trial_days`, por defecto 30), **no** corresponde Preaviso, Indemnización ni Vacaciones proporcionales — solo salario pendiente y aguinaldo proporcional.

**Preaviso ya otorgado:** al crear o editar la liquidación hay un interruptor **"¿Se otorgó preaviso al empleado?"** — si el empleador ya avisó con anticipación, no se paga el concepto de Preaviso aunque el tipo de terminación sea Despido Injustificado.

**Estabilidad Laboral Propia (Art. 95 CLT):** si el empleado tiene **10 años o más** de antigüedad y la terminación es un Despido Injustificado, se agrega un concepto adicional de **indemnización doble** (mismo monto que la indemnización normal, como línea separada). El formulario muestra una advertencia destacada al seleccionar un empleado con esa antigüedad.

---

## Estados de la liquidación

| Estado | Descripción |
|--------|-------------|
| **Borrador** | Creada, sin calcular todavía — puede editarse libremente |
| **Calculada** | Montos confirmados, pendiente de cierre — puede recalcularse o editarse (una edición que cambie el tipo de terminación o el preaviso otorgado la revierte a Borrador) |
| **Cerrada** | Procesada y definitiva — el empleado queda inactivo, ya no se puede editar ni eliminar |

El listado tiene pestañas para filtrar rápido: **Todas**, **Borradores**, **Calculadas**, **Cerradas**.

---

## Crear una liquidación

1. Ir a **Nóminas → Liquidaciones**
2. Clic en **Nueva Liquidación**
3. Completar:
   - **Empleado** (solo empleados activos con contrato activo)
   - **Fecha de Desvinculación**
   - **Tipo de Desvinculación**
   - **¿Se otorgó preaviso al empleado?** (solo visible si el tipo incluye preaviso y no está en período de prueba)
   - **Motivo de Desvinculación** (opcional)
4. Guardar — la liquidación se crea en estado **Borrador**, sin conceptos calculados todavía

> Guardar **no** calcula los montos automáticamente. Después de crearla hay que ejecutar la acción **Calcular**.

**Validaciones al guardar:**
- No se puede crear si el empleado ya tiene otra liquidación activa (Borrador o Calculada) — hay que cerrarla o eliminarla primero.
- No se puede crear si el empleado no tiene contrato activo, o si el salario del contrato es Gs. 0.
- Si la empleada está bajo **protección de maternidad** (Ley 5508/15), se muestra una advertencia (no bloquea la creación).
- Si el empleado no tiene la deducción de **IPS** activa en su perfil, se advierte que el aporte del 9% no se descontará en la liquidación.

---

## Calcular la liquidación

Desde la vista de la liquidación (o como acción de fila en el listado), ejecutar **Calcular Liquidación** (solo disponible en estado Borrador). El sistema genera automáticamente todos los conceptos aplicables y un PDF del recibo.

Si ya está calculada y hace falta rehacerla (ej. se corrigió un dato del contrato), usar **Recalcular** — elimina todos los conceptos actuales (incluidos los editados manualmente) y los vuelve a generar desde cero.

### Conceptos calculados automáticamente

**Haberes (ingresos):**
- **Preaviso** — según años de servicio (tabla configurable), solo si el tipo lo incluye, no está en período de prueba y no fue otorgado por el empleador
- **Indemnización** — proporcional a años/meses de servicio y al **salario promedio de los últimos 6 meses** (no el salario actual), solo si el tipo lo incluye y no está en período de prueba
- **Indemnización adicional — Estabilidad Laboral Propia** — solo si hay 10+ años de antigüedad en un Despido Injustificado
- **Vacaciones proporcionales** — calculadas sobre el salario promedio de los últimos 6 meses, descontando los días ya usados en el año
- **Salario pendiente** — días trabajados del último mes sin nómina generada que cubra hasta la fecha de desvinculación
- **Aguinaldo proporcional** — al año en curso; si ya se pagó el aguinaldo del año, no se incluye

**Deducciones:**
- **Ausencias injustificadas** — días ausentes sin justificar entre la fecha de ingreso y la de desvinculación (no aplica a jornaleros)
- **Aporte IPS Obrero** (9% por defecto) — solo si el empleado tiene la deducción de IPS activa en su perfil; se calcula sobre salario pendiente + vacaciones (preaviso, indemnización y aguinaldo están exentos)
- **Saldo de préstamos/adelantos pendientes** — deuda activa total del empleado

---

## Ajustar conceptos manualmente

En la pestaña **Desglose de la Liquidación** de la vista de detalle se pueden **editar o eliminar** ítems individuales (monto, descripción, tipo, categoría) mientras la liquidación no esté cerrada — útil para correcciones puntuales sin recalcular todo. No se pueden agregar ítems nuevos manualmente, solo editar o quitar los generados por el cálculo.

> Cualquier edición o eliminación de un ítem recalcula los totales (Haberes, Descuentos, Neto) automáticamente, pero **invalida el PDF generado** — hay que regenerarlo con la acción **Regenerar PDF** antes de descargarlo.

---

## Cerrar la liquidación

Al ejecutar la acción **Cerrar y Desactivar Empleado** (solo disponible en estado Calculada), el sistema automáticamente:
- Marca el contrato activo del empleado como **Terminado**
- Actualiza el estado del empleado a **Inactivo**
- Cancela todos los préstamos activos pendientes (y sus cuotas)

> No es necesario hacer estos cambios manualmente. El cierre es **irreversible**.

---

## Documentos de la liquidación

| Acción | Disponible cuando | Qué hace |
|--------|--------------------|----------|
| **Descargar PDF** | Hay un PDF generado (`pdf_path` no vacío) | Abre el recibo de finiquito en una pestaña nueva |
| **Regenerar PDF** | No hay PDF generado (ej. se invalidó tras editar un ítem) y la liquidación no está en Borrador | Genera un PDF nuevo con los datos actuales |

---

## Editar y eliminar

- **Editar**: disponible mientras la liquidación no esté Cerrada. Si está Calculada y se cambia el **tipo de terminación** o el **preaviso otorgado**, la liquidación vuelve a **Borrador** (se eliminan los conceptos y el PDF) y debe recalcularse.
- **Eliminar**: disponible mientras la liquidación no esté Cerrada — individualmente desde **Editar** o en bloque desde el listado (la eliminación masiva ignora las que ya estén Cerradas).

---

## Exportar

Desde el listado, header action **Exportar a Excel** (respeta los filtros y la pestaña activa) — incluye todos los conceptos, montos, fechas y estado de cada liquidación.

---

## Parámetros de cálculo

Los parámetros de indemnización (días por año de servicio) y las tasas de IPS se configuran en **Configuración → Configuración de Nómina**, sección **Liquidación / Finiquito**:
- **Aporte IPS Obrero** (%, default 9%)
- **Código deducción IPS** (código del catálogo de deducciones que identifica el aporte, default `IPS001`)
- **Días indemnización por año** (default 15 días/año)

Los tramos de días de preaviso según antigüedad no son configurables desde el panel — están definidos en la configuración del sistema.
