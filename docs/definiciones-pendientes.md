# Definiciones pendientes (nov. 2026)

Resultado de la sesión de análisis del 20/11/2026. Todavía no se tocó código.
Lo marcado como **Verificado** salió de consultas de solo lectura en el servidor de Macro.

## 1. Ausencias y marcación en Macro

### Verificado
- Hay 51 ausencias `pending`, todas de ayer y hoy (19 y 20/11). El histórico de ~6.100 ya no existe: se limpió.
- 40 de las 51 son de Laboratorio (22 personas), 9 de Casa Central y 2 de otras sucursales.
- Laboratorio no tenía terminal propio: marcaban en `Terminal Casa Central 1` y en un origen sin terminal registrado, hasta el 13/11.
- Del 14 al 18/11 Laboratorio casi no tiene jornadas (1 o 2 por día) ni ausencias generadas. El 19 aparecen 22 ausencias.
- `Terminal Laboratorio 1` se creó el 10/11, se vinculó el 20/11 a las 09:23 y está sin conexión desde las 09:54 (sin cargador; probable batería agotada).
- Las ausencias de Laboratorio del 10 al 13/11 están justificadas.
- Terminales sin Wake Lock: Guarambare, Capiata, Itauguá y Luque. Guarambare además corre en una pestaña del navegador. El «Celular» está desconectado desde el 17/11.

### Decidido
- No hay histórico que limpiar.
- Para las ausencias del 19/11 en Laboratorio no usar «Marcar injustificadas» (genera una deducción de sueldo por persona).
- Si RR.HH. confirma que trabajaron: `attendance:register-entries` con las horas del horario. Si no trabajaron: justificar con nota.
- Plan B de marcación: habilitar el celular a Laboratorio, empezando por quienes ya marcan con regularidad.

### Pendiente de confirmar con RR.HH. de Macro
1. Laboratorio: revisar el equipo del terminal (apagado o sin batería desde las 09:54 del 20/11).
2. Laboratorio, del 14 al 19/11: ¿quiénes trabajaron?
3. ¿Qué pasó con la marcación de Laboratorio antes del 20/11?
4. ¿María de la Paz Vargas sigue trabajando? No marca desde el 05/02.
5. ¿Norma Báez, Manuel Miranda y María de los Ángeles Cáceres siguen trabajando? Pararon el 05 o 06/11.
6. Guarambare, Capiata, Itauguá y Luque: configurar «nunca suspender» y abrir la app instalada.

### Dudas técnicas abiertas
- Por qué no se generaron ausencias del 16 al 18/11 en Laboratorio y sí el 19 (¿regla `branchCannotMark` de `attendance:check-missing`?).
- Si una marcación tardía del mismo día resuelve sola una ausencia `pending`.

## 2. Flujo del jefe y horas extra

### Decidido: flujo del jefe («Mi equipo»)
- Aprobación en cadena: jefe y luego RR.HH.
- **Configurable desde el panel, por empresa**, en una sección «Flujo del jefe» (solo Super Admin). Un interruptor general (apagado por defecto: sin cambios para quien no lo use) y uno por tipo de aprobación. Con el general encendido: licencias y horas extra activadas; vacaciones, tardanzas y ausencias apagadas (generan descuentos, las decide RR.HH.).
- **Quién es el jefe:** solo el superior directo («Reporta a»).
- **Jefe ausente** (vacaciones o licencia): aprueba el superior del jefe; si no tiene superior, RR.HH.
- **Demora:** a las 48 horas sin respuesta se avisa por campanita al jefe y a RR.HH., sin cambiar el estado del pedido.
- **RR.HH. puede aprobar siempre**, sin esperar al jefe; queda registrado quién aprobó.
- **Qué ve el jefe:** la foto de hoy más licencias próximas y pendientes de su equipo. Alertas de contrato y documentos del equipo quedan para después.

### Decidido: horas extra (etapas B, C y D)
- **B, permiso Heredar/Sí/No** por empleado, cargo, sucursal y empresa: el nivel más específico gana (mismo criterio que el permiso de marcar desde el celular). Si ningún nivel define, las horas extra están permitidas.
- **Extra sin autorización:** se registra con la marca «no autorizada» y RR.HH. decide caso por caso (solo se paga lo aprobado, como hoy).
- **Cargos gerenciales:** sin regla fija. Se configuran con el mismo permiso a nivel de cargo (poner «No» donde corresponda). Si deben quedar fuera por ley, lo confirma el asesor.
- **C, confirmación al salir:** el motivo es obligatorio y se elige de una lista (terminal y celular). Falta decidir si además admite nota libre.
- **D, pedido previo:** lo puede crear el jefe para su equipo o el propio empleado; vale para un día o para un rango de fechas; aprobación en cadena jefe y luego RR.HH.
- **Exceso sobre lo pedido:** lo pedido queda autorizado y el exceso cuenta como no autorizado.

### Pendiente
- Orden de construcción de las etapas B, C y D (hay dependencias: D necesita «Jefe directo» y el rol de Jefe).
- Si el motivo de la etapa C admite nota libre además de la lista, y quién administra esa lista.
- Si las licencias cortas las puede aprobar el jefe solo.
- Consultar al asesor si los cargos de dirección/confianza pueden quedar sin recargo por horas extra.

## 3. Otros pendientes sin definir

- Vacaciones: balances por aniversario (validar con el asesor).
- Feriado y domingo: confirmar con el asesor si el turno normal lleva recargo.
- Contratos: bajas programadas, alta con checklist, línea de tiempo.
- Documentos: obligatorios por cargo y mover al disco privado los PDF públicos antiguos.
- Plataforma: 2FA para Super Admin, correo configurado en Macro, PIN de administrador en el menú del terminal.
- Simulación de nómina: comparar contra el Excel del cliente.

## 4. Deploy a Macro
- Está pendiente desplegar los últimos commits (columna «Marca con» y aviso semanal de empleados silenciosos). Decisión: después de cerrar estas definiciones.
