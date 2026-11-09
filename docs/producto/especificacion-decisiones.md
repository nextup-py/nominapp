# Nominapp: especificación de decisiones de producto

Decisiones tomadas antes de tocar código. Las que tienen componente legal o fiscal son **provisionales hasta que un contador las valide**. No contiene datos de clientes.

## A. Salario mínimo y reajustes

| Tema | Decisión |
|---|---|
| Valores del mínimo | Historial con fecha de vigencia (mensual y jornal) en lugar de un valor único |
| Qué mínimo usa una nómina | El vigente al fin del período |
| Contrato bajo el mínimo | Sigue el bloqueo en el formulario; se agrega jornada parcial (horas semanales con mínimo proporcional) |
| Al cambiar el mínimo | Aviso con la lista de contratos que quedan debajo y acción masiva «Ajustar al mínimo» con vista previa |
| Reajuste de sueldo | Acción propia con historial (vigencia, monto anterior y nuevo, motivo, usuario) |
| Reajuste a mitad de mes | Prorrateo por días calendario sobre 30 |

## B. Cuotas de préstamos y mercadería

| Tema | Decisión |
|---|---|
| Arrastre | Las cuotas vencidas de períodos sin nómina se cobran en la siguiente, con tope; el resto se arrastra |
| Tope | Porcentaje configurable (25 % por defecto) que **bloquea** al superarse y aplica a la suma de préstamos, adelantos y mercadería; 0 = sin tope |
| Orden de cobro | La más antigua primero |
| Aviso | Línea en el recibo («cuotas pendientes por tope») y aviso en la lista previa |
| Cita legal | El artículo aplicable (240 o 245 del CLT) lo confirma el contador; mientras tanto se muestra «tope configurable» |
| Préstamos aprobados sin entregar | Aviso en el listado y en la lista previa; acción masiva «Marcar como entregados» |

## C. Autorización escrita del trabajador

Fundamento a validar: ciertos descuentos exigen acuerdo escrito. Préstamos y retiros nuevos: PDF de autorización firmada para aprobar (el sistema genera la plantilla; **el texto lo carga la empresa**). Los ya aprobados se marcan «sin autorización» y avisan sin bloquear. Interruptor «exigir autorización firmada» apagado por defecto.

## D. Aguinaldo

| Tema | Decisión |
|---|---|
| Método | Empezar limpio y cargar lo ya pagado: importación Excel por CI con el total devengado del año hasta el corte |
| Base | Todo lo remunerativo (salario, plus, horas extra, comisiones); sin viáticos |
| Estados que suman | Solo nóminas aprobadas y pagadas |
| A confirmar | Conceptos que entran, cálculo del año anterior, fecha de pago |

## E. IPS y percepciones

| Tema | Decisión |
|---|---|
| Aporte patronal | Costo del empleador y reporte mensual por empresa, sin tocar el recibo: tasa configurable (16,5 % por defecto), base con piso igual al mínimo |
| Plus de tipo «Otro» | Obligar a elegir si aportan a IPS (sin valor por defecto) y advertir el efecto. Los plus aportan; los viáticos no |
| A confirmar | Tasa y base, régimen MIPYME, IPS sobre plus y viáticos |

## F. Lista de verificación previa a generar la nómina

Solo avisa (advertencia o crítico) con enlace para resolver; **nunca bloquea**. Chequeos: empleados sin contrato; sueldos bajo el mínimo; sin deducción de IPS vigente; plus sin aporte a IPS; sin cuenta bancaria para pago por débito; ausencias y jornadas sin resolver; préstamos sin desembolsar; autorizaciones faltantes; adelantos de personas inactivas; vacaciones de pago inmediato sin marcar; contratos activos de personas inactivas; empresas con datos obligatorios incompletos.

## G. Horas extra

Configurable desde el panel (global; por empresa después): interruptor de llegada anticipada (por defecto no cuenta), mínimo de minutos por día (si no lo supera no hay extra; si lo supera se pagan todos los minutos), aprobación automática hasta un tope y redondeo (15/30 min). Sin cambios: topes legales (3 h diarias, 9 h semanales), multiplicadores y aprobación de RR.HH.

## H. Atrasos y ausencias

Los atrasos se **registran siempre** y se **descuentan solo si RR.HH. lo aprueba**. Día de ausencia = sueldo ÷ 30. El texto legal aplicable se confirma con contador o abogado.

## I. Limpieza de ausencias antiguas

Eliminar ausencias pendientes anteriores a una fecha de corte, con respaldo CSV automático, fecha de corte obligatoria, vista previa con conteo y confirmación escrita, permiso de negocio propio y constancia en la auditoría. Se eliminan también las jornadas «ausentes» sin marcaciones; las que tienen marcaciones no se tocan. Es irreversible: el CSV es la única recuperación.

## J. Baja de un empleado

Asistente que guía el cierre: terminar el contrato, crear la liquidación y cancelar préstamos y adelantos, mostrando lo pendiente y exigiendo confirmar cada paso. Un reporte lista los casos inconsistentes (contratos activos de personas inactivas, liquidaciones sin cerrar, adelantos pendientes de inactivos).

## K. Ciclo mensual

| Tema | Decisión |
|---|---|
| Períodos | Se crean automáticamente unos días antes de fin de mes (configurable), como borrador |
| Avisos de plazo | Antes del cierre (configurable), por campanita y correo según preferencias |
| «Cerrar el mes» | Panel de pasos en la vista del período: verificar, generar, aprobar, pagar, enviar recibos, cerrar |
| Feriados | Carga del año desde una lista base revisada por RR.HH. y aviso si faltan los del año siguiente |
| Simulación | Botón «Simular»: totales y diferencias con el período anterior, sin guardar |

## L. Recibos por correo

Se envían al marcar la nómina como pagada, con PDF adjunto, solo a empleados con correo; los demás quedan en un reporte «sin correo». Requiere el proceso de cola.

## M. «Registrar asistencia»

Motivo obligatorio, constancia en la jornada y reporte de uso por usuario y mes.

## N. Jornada parcial

Campo de horas semanales; mínimo proporcional = mínimo × horas semanales / 48 (48 configurable).

## O. Operación

Proceso de cola por cron (`queue:work --stop-when-empty`, cada minuto) documentado para instalaciones en cPanel.

## P. Orden de implementación (primer corte)

| Orden | Ítem | Horas |
|---|---|---|
| 1 | Cola en el servidor | 2 |
| 2 | Aviso y acción masiva de préstamos sin desembolsar | 3 |
| 3 | Aguinaldo: solo nóminas aprobadas y pagadas | 3 |
| 4 | Hijos a cargo y bonificación familiar (sección T) | ≈ 14 |
| 5 | Plus «Otro»: obligar a elegir | 4 |
| 6 | Arrastre de cuotas con tope conjunto y línea en el recibo | 12 |
| 7 | Aguinaldo: importación de lo ya pagado | 10 |
| 8 | Lista previa de verificación | 10 |
| 9 | Reglas de horas extra | 10 |
| 10–23 | Autorización escrita, mínimo con historial, jornada parcial, reajuste, perfiles de asistencia, limpieza de ausencias, baja guiada, períodos automáticos, recibos por correo, «Cerrar el mes», feriados, simulación, control de «Registrar asistencia», aporte patronal | ≈ 133 |

Backlog definido ≈ 201 h. Lo que cabe antes del aguinaldo son los ítems 1 a 9; el resto se escalona.

## Q. Decisiones de implementación

- Las reglas nuevas vienen **apagadas por defecto** y cada empresa las activa: mínimo de horas extra 0, sin tope de cuotas, autorización escrita opcional. Una instalación existente no cambia de comportamiento al actualizarse.
- La plantilla de la autorización de descuento y el aviso de privacidad los carga la empresa; el sistema trae la estructura vacía y nunca redacta contenido legal.
- La limpieza de datos al incorporar un cliente es un **script único supervisado**: respaldo previo, simulación (`--dry-run`) y confirmación escrita del cliente.

## R. Limpieza de datos al incorporar un cliente

Acciones típicas, cada una sujeta a confirmación del cliente: eliminar ausencias pendientes antiguas (con corte y respaldo CSV); pasar a terminados los contratos activos de personas dadas de baja; eliminar liquidaciones `draft`/`calculated` sin cerrar (las `closed` no se tocan); eliminar nóminas, recibos y aguinaldos de prueba; revisar adelantos pendientes de inactivos; marcar vacaciones de pago inmediato ya entregadas. Préstamos aprobados sin entregar no se tocan en el script: se marcan desde el panel.

Reglas: con el sitio en mantenimiento, respaldo inmediatamente antes, primero en simulación y recién después en modo real, tras revisarlo con el cliente.

## S. Hijos a cargo y bonificación familiar

**Problema corregido:** un comando creaba hijos de relleno con fecha de nacimiento ficticia, y la bonificación se pagaba por datos falsos.

| Tema | Decisión |
|---|---|
| Comando de relleno | Eliminado |
| Relleno ya cargado | Una migración borra de forma estricta solo las filas de relleno (nombre genérico, fecha ficticia, sin cédula ni certificado) |
| `children_count` | Se elimina la columna; antes se guarda un CSV de respaldo para armar la plantilla de carga |
| Para cobrar | Fecha de nacimiento real **y** cédula del menor o certificado PDF adjunto. Bloquea en todas las instalaciones |
| Edad | Se evalúa al fin del período de la nómina, no con la fecha de hoy |
| Duplicados | No se permiten dos hijos con mismo nombre y nacimiento para un empleado |
| Fechas imposibles | Se bloquea nacimiento futuro o anterior a 18 años |
| Pago doble | Aviso (sin bloquear) si dos empleados activos cargan al mismo hijo |
| Carga masiva | Importación por Excel con vista previa y errores por fila |

Antes de desplegar a una instalación existente: consulta de solo lectura de cuántos hijos elegibles dejarían de cobrar por falta de respaldo.

## T. Reinicio de nóminas de una instalación

Cuando un cliente confirma por escrito que las nóminas generadas hasta ahora fueron de prueba, se eliminan períodos de nómina, recibos, aguinaldos de prueba y liquidaciones sin cerrar. **No se borran** empleados, contratos, marcaciones, ausencias, préstamos, adelantos, retiros de mercadería ni vacaciones.

`Payroll::booted()` impide borrar nóminas `approved`/`disbursed`/`paid` y, al borrar una `draft`, revierte cuotas, adelantos y deducciones. Por eso el script pasa cada nómina a `draft` y la borra **por Eloquent**; un `DELETE` directo dejaría cuotas «pagadas» de una nómina inexistente. Se ensaya antes sobre una copia de la base.

Consecuencias a decidir con el cliente:
1. Las cuotas de meses pasados vuelven a `pending` y los calculadores solo toman las del período: decidir si se cobran (arrastre con tope) o se dan por saldadas.
2. Los adelantos descontados vuelven a `disbursed` y se descontarían en la primera nómina oficial: revisar caso por caso.
3. Sin nóminas no hay base de aguinaldo: hace falta la importación de lo realmente pagado.
4. Préstamos sin entregar: marcarlos como entregados antes de la primera nómina oficial, o no se descuentan.
5. El mínimo vigente se aplica desde la primera nómina oficial, sin retroactivo dentro del sistema.

## U. Personal sin lugar fijo de trabajo

**Principio:** máxima flexibilidad para todos los tipos de empleado.

**V1. Perfiles con interruptores.** Cada empleado tiene un perfil y cuatro interruptores (registra asistencia, genera ausencias, descuenta tardanzas, paga horas extra), heredados del cargo y sobrescribibles por persona.

| Perfil | Asistencia | Ausencias | Tardanzas | Horas extra |
|---|---|---|---|---|
| Estándar | Sí | Sí | Sí | Sí |
| Flexible (por resultados) | Sí | Sí | No | No |
| Solo presencia | Sí | No | No | No |
| Sin registro | No | No | No | No |

El sueldo mensual no cambia por estos interruptores. Un jornalero no puede ser «Sin registro» ni «Solo presencia».

**V2. Zonas permitidas.** Lista de zonas por persona (radio alrededor de la sucursal, ciudad del catálogo o punto libre con radio). Una marcación fuera de todas las zonas **no se bloquea**: queda para revisar en «Por resolver». Sin zonas no hay aviso.

**V3. Visitas y cobros.** Tipo de marcación «Visita» con ubicación y nota, desde el celular y sin conexión. No entra en la secuencia entrada/salida; las máquinas de estado de `mobile-offline/queue.js` y `AttendanceEvent::allowedNextEventTypes()` deben mantenerse en sincronía.

**V4. Reporte «Recorrido del día».** Marcaciones y visitas por persona y día, en orden y sobre el mapa.

**Privacidad:** la ubicación se toma solo al marcar o registrar una visita, nunca en seguimiento continuo.

Esfuerzo: V1 12 h, V2 14 h, V3 16 h, V4 6 h (≈ 48 h).

## V. Otros tipos de empleado, contrato y horario

El sistema ya soporta contratos indefinido, plazo determinado, obra determinada, aprendizaje y pasantía; remuneración mensualizada o jornal; pago mensual, quincenal o semanal; horario fijo, rotaciones y turnos puntuales. Cada tipo con efecto legal o fiscal exige validar la regla con el contador antes de construirlo.

| Tipo | Qué hace falta | Horas |
|---|---|---|
| Pago por hora | Forma de pago calculada desde marcaciones | 14 |
| Sueldo fijo más comisión | Concepto variable por período; tratamiento en IPS y aguinaldo | 12 |
| Honorarios profesionales | Vínculo sin dependencia, sin IPS ni aguinaldo | 16 |
| Turno partido | Más de un tramo por día | 12 |
| Turnos largos (12x36, 24x48) | Horas extra y descanso adecuados | 14 |
| Banco de horas / flexible | Total semanal o mensual sin hora fija | 16 |
| Guardia pasiva | Compensación por período sin marcar | 10 |
| Personal doméstico | Régimen especial | 10 |
| Zafrales y eventuales | Altas y bajas en lote, liquidación rápida | 8 |
| Cargos de confianza | Marca sin control de jornada ni horas extra | 5 |
| **Total** | | **≈ 117** |

**Método:** antes de construir, contar de solo lectura qué tipos usan hoy las instalaciones para ordenar esta lista.

## W. Reglas permanentes de trabajo

- PR únicamente cuando se pide; deploy solo cuando se pide y sin nómina en curso.
- La guía de usuario y `CLAUDE.md` se actualizan en el mismo PR de cada ítem.
- Nada de datos de clientes (nombres, cédulas, montos reales, hosts) en el repositorio.
