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

### Verificado el sábado 21/11 (Casa Central y sucursales)
- Casa Central generó 37 ausencias pendientes en un sábado. De ellas, unas 19 personas trabajaron 0 de los últimos 4 sábados y otras tienen 1 a 3: el horario fijo les asigna el sábado a todos pero se trabaja por turnos. Lo mismo, en menor escala, en Colaboradores Externos (5), Julia Miranda y Itauguá. Sin horario ese día no se generó ausencia (25 casos, correcto).
- Casa Central, sábado: el horario dice 07:30–12:00 y 17 de 18 personas entraron entre las 08:58 y las 09:16 (90 minutos de atraso falso). Probable inicio real: 09:00.
- Al menos 7 personas de Casa Central vinieron y quedaron con ausencia porque su marcación (probablemente salida sin entrada) cayó en conflicto de sincronización entre las 11:56 y las 12:26, justo cuando `Terminal Casa Central 1` se reconectó (estuvo sin conexión toda la mañana). Hay además 5 jornadas «presentes» sin entrada registrada.
- Los terminales de sucursales se caen escalonados entre las 09:54 y las 11:49 y se recuperan casi juntos entre las 11:58 y las 12:55: posible causa común (servidor, red o límites). Sin confirmar.
- Reporte de RR.HH. de Macro (21/11): en la pantalla de marcación aparece la salida esperada a las 18:00 y muchos salen a las 18:30. Verificado: unas 10 personas de Casa Central (Ledezma, Verón, Riquelme, Salinas, Benítez, Cardozo, Ortigoza, Clara González, Perla Velázquez, Alcides Fernández) salen de lunes a viernes a las 18:30 casi exactas, y entran a su hora (07:30). Cada una acumula 30 min de extra pendiente por día, que supera la tolerancia de 15 min. Falta saber si el turno real termina a las 18:30 o si se quedan por su cuenta.
- Sueltos: Beatriz Ojeda (Julia Miranda) con turno hasta las 17:00 sale a las 17:41 todos los días (probable 17:30); Diego Franco Ortiz (Externos) entra 08:11 y sale 19:04 con turno 08:00–18:00; Nilda Florentín (Externos) sale a las 18:45.

### Horarios en Macro (verificado 21/11)
- 11 horarios, todos de tipo diurno: Casa Central (59 empleados activos), Laboratorio (22), Promotores y Vendedores (11), Mcal. Estigarribia (8), Itauguá (4), Luque (4), Capiata (3), Guarambare (3), Julia Miranda (3), Supervisores (1) y Horario Especial (1).
- 119 empleados activos tienen un horario cada uno; ninguno tiene dos vigentes.
- 80 asignaciones vigentes pertenecen a empleados inactivos (Casa Central 46, Mcal. Estigarribia 13, resto repartidas): nunca se cerraron. Impacto bajo, pero conviene un comando de limpieza que las cierre.
- Todo Casa Central comparte un único horario (sábado 07:30, salida 18:00): causa de fondo de las ausencias de sábado y de las extras falsas de las 18:30.

### Pendiente derivado
- Comando de limpieza: cerrar con fecha de fin las asignaciones de horario vigentes de empleados inactivos (80 en Macro).
- Decidir cómo se manejan los sábados rotativos: patrones de rotación o turnos puntuales en el Planificador, en vez de horario fijo con sábado para todos.
- Confirmar el horario real de los locales (¿18:00 o 18:30?) y el inicio real del sábado en Casa Central (¿09:00?).
- Resolver en «Fallos de marcación» los conflictos de Casa Central del 21/11 y justificar o cargar la asistencia de esas personas.

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
