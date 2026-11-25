# Configuración del Sistema

Esta sección cubre los ajustes que afectan el comportamiento global del sistema.

---

## Configuración General

Acceder desde **Configuración → Configuración General**.

> Esta pantalla **no** contiene los datos de la empresa (nombre, logo, RUC, dirección, etc.) — esos se cargan por separado, por cada empresa, en **Organización → Empresas**, y son los que aparecen en los encabezados de los PDFs. Configuración General solo tiene parámetros globales de comportamiento del sistema, listados abajo.

### Configuración Laboral

- **Zona horaria:** `America/Asunción` por defecto (también se puede elegir entre otros husos horarios de la región).
- **Tolerancia para ausencia:** minutos de gracia después de la hora de entrada antes de que el sistema marque al empleado como ausente (por defecto 30 minutos).
- **Mínimo entre salida y nueva entrada:** minutos que deben pasar después de marcar la salida para poder marcar otra entrada el mismo día, por ejemplo en un doble turno (por defecto 5 minutos; 0 = sin mínimo). Evita reentradas por error.
- **Mínimo entre entrada y salida:** minutos que deben pasar después de marcar la entrada para poder marcar la salida (por defecto 2 minutos; 0 = sin mínimo). Evita salidas por un toque equivocado; una salida que llega al sincronizar antes de ese mínimo queda como conflicto en **Fallos de Marcación**.
- **Límite de jornada nocturna abierta:** horas desde la última marcación de la jornada de ayer durante las cuales una salida nueva se le sigue asignando como turno nocturno en curso (por defecto 16 horas; 0 = sin límite). Pasado ese tiempo la jornada de ayer queda en **Sin salida registrada** para corregirla a mano y el terminal ofrece solo la entrada.
- **Tolerancia de tardanza** (por defecto 5 minutos): hasta ese margen de atraso en la entrada no se considera tardanza. Pasado el margen cuentan **todos** los minutos de atraso (con 5, llegar 7 minutos tarde son 7 minutos de tardanza). 0 = sin tolerancia.
- **Tolerancia de horas extra** (por defecto 15 minutos): hasta ese margen de tiempo trabajado por encima del horario no se considera hora extra (salir unos minutos tarde o llegar un poco antes). Pasado el margen cuenta todo el tiempo extra. 0 = sin tolerancia. Las dos tolerancias se aplican a las jornadas que se calculen desde que se activan; las ya calculadas no cambian salvo que se recalculen (**Recalcular** en Asistencias).
- **Cerrar solo las jornadas sin salida:** apagado por defecto. Al activarlo aparece **Hora del cierre automático** (por defecto 05:30): cada día, a esa hora, el sistema cierra las jornadas abiertas de los últimos 7 días con la salida del horario o, si el empleado marcó un descanso o un fin de descanso cerca de la hora de salida, con esa marcación real. No toca jornadas con nómina generada ni con decisiones de RR.HH. (aprobaciones, ajustes, exclusión), y deja un resumen en la campanita (**Jornadas cerradas automáticamente**). Las salidas agregadas quedan como marcaciones manuales y se pueden corregir desde **Corregir marcaciones**.
- **Avisar cada noche quién se fue sin marcar la salida:** activado por defecto, con **Hora del aviso** (por defecto 21:30). Envía a quienes ven asistencias la lista de los empleados cuyo turno terminó hace más de 1 hora y siguen sin salida (los que el **Monitor en vivo** muestra como **Sin salida**), para consultarles mientras se acuerdan. No se repite mientras el aviso anterior siga sin leer. Se puede apagar o ajustar desde **Mis notificaciones**.
- **Permitir marcar desde el celular por defecto:** valor para los empleados que no lo tienen definido en su ficha, su sucursal ni su empresa. Apagado de fábrica (solo terminal). Ver **Quién puede marcar desde el celular** en el capítulo de Asistencias.
- **Radio de marcación desde el celular:** distancia máxima (25 a 2000 m, 150 de fábrica) al punto de trabajo del empleado o de su sucursal para marcar desde el celular. Fuera de ella la marcación se acepta pero queda señalada para revisión. Ver **Control de ubicación del celular** en el capítulo de Asistencias.

### Configuración de Contratos

- **Días de anticipación para alertas:** cuántos días antes del vencimiento de un contrato a plazo el sistema muestra la alerta (por defecto 30).
- **Aviso de documentos por vencer:** cuántos días antes del vencimiento de un documento del legajo llega el aviso (por defecto 30; **0** lo apaga). Ver **Documentos del empleado**.
- **Aviso de fin de período de prueba:** cuántos días antes de que termine la prueba de un contrato (Art. 58 CLT) llega el resumen diario y aparece en el Escritorio (por defecto 7; **0** apaga el aviso).
- **Pasar a indefinido en la segunda renovación (Art. 53 CLT):** al renovar por segunda vez un contrato a plazo fijo, el contrato nuevo se crea indefinido (encendido por defecto). Apagado, el tipo no cambia solo. Aunque esté encendido, cada renovación puede mantener el plazo fijo indicando el motivo. Validar con el contador o un abogado antes de apagarlo.

### Reconocimiento Facial

- **Validez del enlace de registro:** horas que tiene el empleado para completar la captura facial desde que se genera el enlace (por defecto 48 horas).
- **Umbral de reconocimiento:** distancia máxima aceptada para dar por válido un match facial — cuanto menor, más estricto (por defecto 0.45; rango recomendado 0.35–0.60).
- **Gap mínimo de confianza:** diferencia mínima requerida entre el mejor candidato y el segundo, para evitar confundir rostros parecidos (por defecto 0.10).

> Estos dos últimos parámetros se ajustan revisando los fallos de marcación registrados en `Asistencia → Fallos de Marcación` (ver capítulo de Asistencias).

### Terminales de Marcación

- **Umbral de desconexión:** minutos sin un heartbeat (latido) exitoso antes de que el panel marque un terminal como **Desconectado** (por defecto 120 minutos; entre 5 y 4320). Un terminal sano reporta cada ~90 segundos, así que un valor de 15 a 20 minutos detecta los equipos dormidos a tiempo. No afecta a los estados *Sin vincular* ni *Nunca conectado*. Cada terminal puede definir su propio umbral en minutos. Ver el capítulo **Terminales de Marcación**.
- **Cola de marcaciones atascada:** minutos con marcaciones pendientes o en conflicto sin vaciarse (con el terminal en línea) antes de avisar por la campanita (por defecto 15; entre 5 y 1440).
- **Batería baja:** porcentaje por debajo del cual se avisa que un terminal sin cargador se está quedando sin batería (por defecto 20 %; entre 5 y 50). Solo aplica a navegadores que informan la batería.
- **Terminal sin cargador:** minutos sin cargador a partir de los cuales el terminal le recomienda a quien lo usa que lo enchufe y se avisa a quien gestiona terminales, antes de que la batería esté baja (por defecto 30; **0** lo apaga). Solo funciona si el navegador informa la batería.
- **Desfase de reloj tolerado:** segundos de diferencia entre el reloj del terminal y el del servidor a partir de los cuales se avisa (por defecto 120; entre 30 y 3600). Se avisa solo si el desfase se repite en tres reportes seguidos. Las marcaciones ya se corrigen con ese desfase; el aviso sirve para que alguien revise la fecha y hora del dispositivo.
- **Reencendido de la cámara apagada:** minutos que tarda en volver a encenderse sola la cámara de un terminal que alguien apagó a mano desde su menú **⋮** (por defecto 2; entre 0 y 240; **0** = queda apagada hasta encenderla a mano). Evita dejar el terminal inutilizado por un olvido.
- **Retención de la bitácora de terminales:** días que se conservan los eventos de la bitácora y los comandos remotos de cada terminal antes de eliminarse automáticamente (por defecto 90; **0** = conservar siempre).
- **Retención del historial de confiabilidad:** días que se conserva el resumen diario de disponibilidad, cortes e identificaciones de cada terminal (por defecto 90; **0** = conservar siempre).
- **Disponibilidad mínima semanal:** porcentaje por debajo del cual se avisa, cada lunes, de un terminal que estuvo poco en línea durante la semana anterior (por defecto 90; **0** = no avisar).

### Correo

Datos del servidor que envía los correos del sistema (avisos, pruebas). Solo lo ve y edita el **Super Admin**.

- **Enviar correos desde el panel:** apagado de fábrica, rige la configuración del servidor (`.env`). Al encenderlo, los datos de abajo reemplazan a esa configuración.
- **Servidor SMTP**, **Puerto** y **Cifrado** (TLS puerto 587 o SSL puerto 465): los datos que da el proveedor de correo (cPanel, Google, etc.).
- **Usuario** y **Clave:** la clave se guarda cifrada y **nunca se vuelve a mostrar**. Dejar el campo vacío al guardar conserva la clave guardada; escribir una nueva la reemplaza.
- **Correo remitente** y **Nombre del remitente:** obligatorios al encender el correo (junto con el servidor).
- **Enviar correo de prueba** (arriba a la derecha): manda un mensaje con los datos del formulario, **guardados o no**, y muestra el motivo si el servidor falla. Conviene probar antes de guardar.

Con el correo del panel activo, los avisos **Terminal sin conexión**, **Terminal sin vincular** y **Contratos por vencer o vencidos** llegan también por correo a quien no eligió otra cosa (ver **Mis notificaciones**). Si un correo no puede enviarse, el Super Admin recibe un aviso en la campanita con el motivo.

---

## Configuración de Nómina

Acceder desde **Configuración → Configuración de Nómina**.

![Configuración de Nómina](/docs-images/21-config-nomina.png)

### Horas de trabajo — Jornada Diurna

| Parámetro | Valor por defecto |
|-----------|-------------------|
| Horas mensuales | 240 |
| Horas por jornada | 8 |
| Días laborales/mes | 30 |

### Horas de trabajo — Jornada Nocturna

| Parámetro | Valor por defecto |
|-----------|-------------------|
| Horas mensuales | 210 |
| Horas por jornada | 7 |

### Horas de trabajo — Jornada Mixta

| Parámetro | Valor por defecto |
|-----------|-------------------|
| Horas mensuales | 225 |
| Horas por jornada | 7.5 |

### Multiplicadores de horas extra

| Tipo | Multiplicador por defecto |
|------|--------------------------|
| HE Diurnas | 1.5× (+50%) |
| HE Nocturnas (día regular) | 2.6× (1.30 × 2.0 sobre base diurna) |
| HE Diurnas Feriado/Domingo | 2.0× (+100%) |
| HE Nocturnas Feriado/Domingo | 2.6× (1.30 × 2.0 sobre base diurna) |

### Límites de horas extra

- **Máximo de horas extra por día:** por defecto 3 hrs (Art. 202 CLT).
- **Máximo de horas extra por semana:** por defecto 9 hrs (Art. 202 CLT).

### Liquidación / Finiquito

- **Aporte IPS Obrero (%):** por defecto 9%.
- **Código deducción IPS:** código de la deducción en el catálogo (por defecto `IPS001`).
- **Días de indemnización por año:** por defecto 15 días/año.

### Salarios Mínimos Legales

- **Salario mínimo mensual:** para trabajadores mensualizados.
- **Salario mínimo diario (jornaleros):** monto diario independiente, no es 1/30 del mensual.

> Actualizar estos dos montos ante cada resolución del Ministerio de Trabajo.

### Bonificación Familiar

- **Porcentaje por hijo:** % del salario mínimo mensual pagado por hijo a cargo (por defecto 5%). Aplica a empleados con hijos que ganen hasta 2 salarios mínimos (Arts. 253–262 CLT).

Un hijo genera bonificación solo si cumple **todo** lo siguiente:

| Condición | Detalle |
|---|---|
| Edad | Menor de 18 años **al fin del período de la nómina** (no con la fecha de hoy) |
| Respaldo | Tiene cargada la **cédula del menor** o el **certificado de nacimiento** (PDF) adjunto |

Los hijos se cargan en la pestaña **Hijos** del empleado. La columna **Bonificación** indica *Cobra*, *Falta respaldo* o *No aplica* (mayor de edad). No se pueden cargar dos hijos con el mismo nombre y fecha de nacimiento para un mismo empleado, ni una fecha futura o de hace más de 18 años.

### IRP — Impuesto a la Renta Personal

- **Umbral anual gravado:** renta anual a partir de la cual se retiene IRP (por defecto Gs. 80.000.000).
- **Tasa IRP:** porcentaje retenido sobre la renta gravada (por defecto 10%).

> La empresa actúa como agente de retención (Ley 2421/04).

### Préstamos

- **Monto máximo de préstamo:** por defecto Gs. 5.000.000
- **Tope de cuota (% salario):** por defecto 25% (Art. 245 CLT)
- **Máximo de cuotas:** por defecto 60
- **Tasa de interés máxima:** por defecto 100% anual
- **Días hasta primera cuota (default):** valor sugerido al crear un préstamo

### Adelantos de Salario

- **Porcentaje máximo por adelanto:** por defecto 50%
- **Máximo de adelantos por período:** 0 = sin límite

> El sistema valida que `cantidad máxima × porcentaje` no supere el 100% del salario. Ver el capítulo **Adelantos de Salario** para el detalle de uso.

### Retiros de Mercadería

- **Monto máximo por retiro:** por defecto Gs. 10.000.000
- **Máximo de cuotas:** por defecto 24
- **Días hasta primera cuota:** por defecto 30

### Vacaciones

- **Mínimo días consecutivos:** fraccionamiento mínimo por solicitud (por defecto 6).
- **Años mínimos de servicio:** antigüedad requerida para tener derecho a vacaciones (por defecto 1).
- **Días hábiles para vacaciones:** días de la semana que cuentan como hábiles al calcular el período — por defecto **Lunes a Sábado** (no lunes a viernes).
- **Contar los días de vacaciones según el horario de cada empleado:** apagado por defecto. Encendido, un día solo se descuenta si el empleado tenía turno ese día (quien trabaja de lunes a viernes no gasta los sábados, y se respetan los francos de las rotaciones); quien no tiene horario ni rotación usa los días hábiles de arriba. Los feriados nunca se descuentan. Solo afecta a las vacaciones que se carguen desde ahora; las ya aprobadas conservan sus días.

**Avisos de vacaciones** (sección dentro de Vacaciones): qué avisos se envían y cuándo. Los recibe quien puede editar vacaciones y cada usuario puede apagarlos o pasarlos a resumen en **Mis notificaciones**.

- **Solicitudes pendientes de aprobar:** días que una solicitud puede esperar sin decisión antes de avisar (por defecto 3; **0** apaga el aviso).
- **Cumple su primer año:** días de anticipación para avisar de quienes cumplen el primer año de antigüedad (por defecto 30; **0** lo apaga).
- **Aprobadas sin marcar como pagadas:** avisa de vacaciones aprobadas cuyo inicio fue hace más de 7 días y no figuran pagadas.
- **Derecho anual sin tomar (cada lunes):** avisa de los empleados que tienen sin usar todo el derecho de un año.

---

## Usuarios del Sistema

Acceder desde **Configuración → Usuarios**.

### Crear un usuario

1. Clic en **Nuevo usuario**
2. Ingresar nombre completo, correo electrónico y contraseña (con confirmación)
3. Opcional: vincular la ficha de empleado (**Ficha de empleado**), para que el perfil muestre cargo y sucursal
4. Guardar

La contraseña debe tener al menos 10 caracteres, con mayúsculas, minúsculas y números.

### Mi perfil

Cada usuario abre su perfil desde el menú de la esquina superior del panel. Ahí puede:

- Cambiar su **foto de perfil** y su **contraseña** (con la misma política de 10 caracteres).
- Ver su **nombre**, **correo**, **ficha de empleado** y **roles**, que son solo de lectura: los cambia el Super Admin desde **Configuración → Usuarios**.
- Revisar **Mis permisos** agrupados por módulo.
- Ver sus **Sesiones abiertas** y cerrar las de otros dispositivos con **Cerrar las demás sesiones**.
- Ver sus **Últimos ingresos** (fecha e IP). Si la cuenta ingresa desde una IP que nunca había usado, llega un aviso por la campanita.

> Un usuario recién creado **no puede entrar al panel** hasta que se le asigne al menos un rol. Ver el capítulo **Roles y Permisos** para asignar roles y entender qué puede hacer cada uno.

---

## Feriados

Acceder desde **Configuración → Feriados**.

![Listado de feriados](/docs-images/21-feriados.png)

Los feriados se usan para:
- Excluir días no laborales del cálculo de días hábiles en vacaciones
- Aplicar el multiplicador de horas extra en feriado
- Evitar generar ausencias en días que corresponden a feriado

### Tipos de feriado

Cada feriado tiene un **tipo**: **Nacional fijo** (misma fecha cada año), **Nacional móvil** (cambia cada año, como Jueves y Viernes Santo o los trasladados a lunes) y **Día no laborable o puente** (decretado por el gobierno). El listado muestra el tipo en una etiqueta y se puede filtrar por tipo.

### Cargar Feriados Nacionales

El botón **Cargar Feriados Nacionales** pide el **año** y agrega los feriados de fecha fija (no duplica los que ya existan). Conviene verificar esta lista contra el calendario oficial vigente:

| Fecha | Nombre |
|-------|--------|
| 01/01 | Año Nuevo |
| 01/03 | Día de los Héroes |
| 01/05 | Día del Trabajador |
| 14/05 y 15/05 | Independencia Nacional |
| 12/06 | Paz del Chaco |
| 15/08 | Fundación de Asunción |
| 29/09 | Victoria de Boquerón |
| 08/12 | Virgen de Caacupé |
| 25/12 | Navidad |

> No incluye los feriados móviles (Semana Santa, traslados a lunes) ni los días no laborables: se cargan a mano cada año con el tipo que corresponda.

### Otras acciones del listado

- **Eliminar Feriados Pasados** — borra todos los feriados anteriores al año actual (acción irreversible, útil para limpiar el historial).
- **Copiar al Próximo Año** — copia al año siguiente **solo los feriados de tipo «Nacional fijo»** (misma fecha, un año después), sin duplicar los que ya existan. Los móviles y los días no laborables no se copian porque cambian de fecha; la notificación lista cuáles hay que cargar a mano.
- **Calendario** — muestra los 12 meses del año actual y del siguiente, con los feriados resaltados por tipo y los domingos en gris.
- **Quién trabaja** (por fila) — lista a los empleados que marcaron ese día (fechas pasadas) o que tienen turno según su horario (fechas futuras), por sucursal. La columna **Trabajaron** del listado muestra la cantidad en las fechas ya pasadas.

### Aviso de feriados faltantes

Si el año en curso (o, desde noviembre, el siguiente) tiene menos de 3 feriados cargados, se avisa por la campanita a quienes pueden cargarlos (**Feriados faltantes**, configurable en **Mis notificaciones**). Mientras faltan, el formulario de **Vacaciones** también advierte que los días descontados pueden estar de más.

### Cargar un feriado manualmente

1. Clic en **Nuevo Feriado**
2. Ingresar fecha, tipo y nombre
3. Guardar

---

## Visor de Logs

Acceder desde **Sistema → Visor de Logs**.

Herramienta técnica de diagnóstico: muestra los registros de error y advertencia (`storage/logs/laravel.log`) directamente desde el panel, con columnas de **Nivel**, **Entorno**, **Archivo**, **Mensaje** y **Fecha**.

> Pensada para soporte técnico y desarrolladores al investigar un problema puntual (ej. un PDF que no se generó, un job que falló) — no forma parte del uso diario del sistema por parte de RR.HH. o Contaduría.

## Salud del sistema

Acceder desde **Sistema → Salud del sistema** (Super Admin y los roles con el permiso **Ver Salud del sistema y logs**, como Soporte TI). Resume en cinco tarjetas si el sistema está funcionando:

| Tarjeta | Qué mira |
|---------|----------|
| **Programador** | Que las tareas automáticas (cálculo de asistencia, avisos) estén corriendo. Pasa a **Crítico** si no hay actividad hace más de 5 minutos. |
| **Cola** | Trabajos fallidos y pendientes. |
| **Correo** | Si el correo está configurado y si hubo fallas de envío sin revisar. |
| **Disco** | Espacio usado del servidor (**Atención** desde el 85 %, **Crítico** desde el 95 %). |
| **Logs** | Tamaño de los archivos de registro. |

Debajo aparecen los **errores de los últimos 7 días** agrupados: un error que se repite 500 veces se muestra una sola vez, con su contador, la primera y la última aparición. Cuando el error es conocido se explica en palabras simples y se sugiere qué hacer. El botón **Abrir el visor de logs** lleva al detalle técnico.

Cuando aparece un error que no se había visto antes, el Super Admin recibe un aviso (**Errores nuevos en el sistema**, configurable en **Mis notificaciones**). Los logs viejos se limpian solos cada madrugada: los diarios se conservan 30 días y los demás archivos se recortan al pasar de 20 MB.
