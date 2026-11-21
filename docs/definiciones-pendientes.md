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

### Sábados de Casa Central (verificado, últimos 8 sábados)
No es una rotación semanal. Hay tres grupos estables entre los 59 activos:
- **A, sábado fijo (~23):** Alcides Fernández, Blanca González, Christian Verón, Clara González, Guillermo Ortigoza, Juan Salinas, Juan Ledezma, Leticia Zapattini, Pedro Martínez, Perla Velázquez, Santiago Sanabria, Silvia Romero, Yessica Robledo (8 de 8) y, con 1 o 2 faltas, Alcides Giménez, Aldo Benítez, Claudio Velázquez, Ever Cardozo, Óscar Cáceres, Osvaldo Chaparro, Samuel Riquelme, Iván Samaniego, Joel Fernández y Jorge Salinas. Casi los mismos que salen a las 18:30 de lunes a viernes.
- **B, nunca o casi nunca (~21):** generan ausencia falsa cada sábado.
- **C, intermitente (~14):** bloques de semanas o irregular.
- Totales por sábado (26/09 a 14/11): 28, 34, 34, 31, 29, 19, 26, 27.
- **Propuesta:** horario «Casa Central largo + sábado» para A (07:30–18:30, sábado desde las 09:00, a confirmar con Macro); horario «Casa Central sin sábado» para B; C con el horario sin sábado y los sábados que trabajen cargados semana a semana en el Planificador.

### Generación de ausencias: verificado (45 días, 996 ausencias)
- 0 ausencias en feriados, domingos, días de vacaciones, ni dentro de vacaciones o licencias aprobadas sin justificar; 0 en días sin turno (697 revisadas contra horario/rotación). La generación respeta todo eso.
- Estado: 945 justificadas (66 vinculadas a licencia, el resto con nota), 98 pendientes, 0 injustificadas y 0 con descuento: hoy ninguna falta se descuenta desde el sistema.
- Feriados cargados hasta el 25/12/2026; **falta cargar el calendario 2027** (el 1/1/2027 cae dentro de 41 días): desde Feriados, «Copiar al Próximo Año» (solo copia los fijos).
- Pendiente de preguntar a Macro: cómo se descuentan las faltas reales (¿por fuera del sistema?).

### Estado de los terminales (verificado 21/11)
- Todo bien en cámara, service worker, reloj, almacenamiento y app actualizada (salvo el «Celular»). La caché coincide con los enrolados; en Casa Central falta enrolar el rostro de 1 persona (61 de 62).
- 7 de 10 equipos funcionan con batería, sin cargador (casi todos celulares Android; solo Casa Central 1, Casa Central 2 y Mcal. Estigarribia 1 cargan). Disponibilidad de 7 días: 41% a 64% (Laboratorio 17%, «Celular» 3%); cortes largos de 700 a 816 minutos. Lo recomendable es 90% o más.
- Casa Central 1 y Luque: app oculta o minimizada. Guarambare: abierto en el navegador y Wake Lock rechazado. Julia Miranda: Wake Lock sin informar. Capiata: batería 29%, 2 GB de memoria.
- Laboratorio 1: caído desde el 20/11, 2 GB de memoria, 54% de identificación (7/13).
- Mcal. Estigarribia 1 (PC): 67% de identificación (31/46); revisar cámara e iluminación.
- «Celular» (Mcal. Estigarribia): desconectado desde el 17/11 y con app desactualizada; decidir si se retira de servicio.
- Orden recomendado: enchufar todos los equipos y activar «mantener la pantalla encendida mientras carga»; instalar Guarambare como app; dejar visibles las apps de Casa Central 1 y Luque; decidir sobre el «Celular» y Laboratorio 1; revisar cámara de Mcal. Estigarribia 1.

### Pendientes de aprobación y preparación del personal (verificado 21/11)
- Extras por aprobar (30 días): Casa Central 373 jornadas / 208,6 h, Externos 43 / 35,8 h, Capiata 38 / 19,6 h, Mcal. Estigarribia 28 / 18,6 h, Julia Miranda 17 / 6,5 h y 6 más: unas 505 jornadas y 291 horas. La mayor parte es la media hora de las 18:30 en Casa Central. Tardanzas por aprobar: 34 (Casa Central 18).
- Empleados activos: 124. Sin contrato vigente: 6. Sin rostro enrolado: 1 (Yeni Elizabeth Velázquez, Casa Central, que además no trabajó ninguno de los últimos 8 sábados). Sin horario: 3.
- Fallos de marcación: del 07 al 13/11 hubo cientos por día de rostro no reconocido o ambiguo (hasta 161); desde el 14/11 ya no se registran (cambio de versión del 16/11), así que solo queda como medida la tasa de identificación del terminal. Los conflictos de sincronización suben cada día: 1 (14/11), 2 (16/11), 3 (19/11), 6 (20/11), 13 (21/11).

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

## 3b. Salud del sistema y logs de Macro (verificado 21/11)
- Programador y cola OK; hoy 0 errores en el log. Correo no configurado (solo campanita). Disco 89% usado con 716 GB libres.
- `contracts:notify-expiring` falla todos los días (8 corridas): `Undefined property: stdClass::$data->contract_id`. El arreglo ya está en el repo (usa `$notification->data['contract_id']`); Macro corre una versión anterior. Mientras tanto no llegan avisos de contratos por vencer.
- Un intento de correr `leaves:justify-absences` falló con «no commands defined in the leaves namespace»: ese comando todavía no está desplegado en Macro.
- Errores aislados: `Trait Laravel\Sanctum\HasApiTokens not found` (2, durante un deploy), `Specified key was too long` (1, migración con motor MyISAM; verificar migraciones pendientes), `SQLSTATE[HY000] [2002]` (4, conexión a MySQL caída un instante), `Cardinality violation` en una consulta de empleados (2) y un error de vista Filament con registro nulo (1).
- Los errores aislados son del deploy del sábado 14/11 entre 06:29 y 08:15 (Sanctum, clave larga, vista, cardinalidad) y de una caída momentánea de MySQL el 16/11 a las 06:43. No hay migraciones pendientes. Desde el 16/11 no hay errores.
- Las oleadas de terminales sin conexión del 21/11 no dejaron ningún error en el log de Laravel. El log de acceso del servidor web de ese día: 1131 respuestas 200, 88 304, 9 302 y 2 404; ningún 429 ni 5xx, y todos los latidos de terminales respondieron 200. El servidor no rechazó ni falló: la causa está del lado de los equipos o de su red (suspensión, app oculta, sin cargador).

## 3c. Contratos, personal sin preparar y nómina (verificado 21/11)
- Contratos a plazo fijo que vencen en 45 días (9): Joel Fernández (**24/11**, Casa Central), Marta Noguera (05/12, Mcal. Estigarribia), Lourdes Mendieta (16/12), Richard Paiva (18/12), Yessica Robledo (28/12) y el 31/12 David Domínguez (Casa Central) y Cristian Ruiz, Alexis Jacquet y Maikol Enciso (Laboratorio). Hay que renovar respetando el Art. 53 (máximo un año; la segunda renovación pasa a indefinido).
- 6 empleados activos sin contrato vigente, y al menos 2 siguen trabajando: María Montserrat Tilleria (Mcal. Estigarribia) y Marianella Alonso (Julia Miranda) marcaron el 21/11; también Ninfa Acosta (Externos), Ramón Cáceres, Liliana Arias y Esteban Pereira (Casa Central). Probable plazo fijo vencido sin renovar (el vencimiento automático deja activo al empleado a propósito).
- 3 sin horario: Lourdes Mendieta (Casa Central), Claudia Ortiz de Trinidad (Luque, trabajó el 21/11) y Blanca Galeano (Externos).
- Nómina en el sistema: solo 3 períodos (mayo ×2 en procesamiento y agosto en borrador). Macro no usa la nómina del sistema, así que extras, tardanzas y ausencias hoy son informativas y no afectan ningún pago.

## 3d. Vacaciones, usuarios y avisos (verificado 21/11)
- Vacaciones pendientes de aprobar: 0. Licencias pendientes: 6. Vacaciones aprobadas en curso o próximas, todas sin pagar (pago por fuera): Juan Manuel Valenzuela (16 a 28/11), Maximiliano Malarino (20 a 27/11) y Jhonny Cabral (30/11 a 12/12).
- Usuarios del panel (5): Edgar Franco (Super Admin), Richar Sánchez (Super Admin; también figura como empleado de Casa Central), Rocío Ortiz (**sin rol**, no puede entrar), Lourdes Mendieta (Asistente de RRHH; también empleada, sin horario) y Yeni Velázquez (RRHH y Contador/Nómina a la vez, creada el 17/11; también empleada, sin rostro enrolado). Pendiente de confirmar: si Rocío Ortiz debe tener acceso o eliminarse, y si Yeni Velázquez debe tener ambos roles (RRHH y Contador/Nómina juntos permiten crear contratos y también aprobar y pagar nómina y créditos).
- Avisos sin leer en la campanita: unos 500, sobre todo salud de terminales (175), conflictos de sincronización (91) y alertas de contratos (62). Casi todo es ruido repetido. Conviene que cada usuario configure «Mis notificaciones» (resumen diario para salud de terminales y conflictos) y marque como leído lo viejo.

## 3e. Configuración, auditoría y retiros de mercadería (verificado 21/11)
- Configuración de asistencia en sus valores por defecto: tolerancia de atraso 5 min, de extras 15 min, reentrada 5 min, salida mínima 2 min, límite nocturno 16 h, umbral facial 0,50 (brecha 0,05), terminal confirma salida (15 s), alerta de contratos 30 días, retención de auditoría 24 meses. El cierre automático de jornadas sin salida está apagado; el aviso del mismo día, encendido. Macro tiene todas las propiedades de configuración consultadas.
- Auditoría de 7 días: el sistema recalcula jornadas (959 actualizaciones y 397 altas) y **borró 80 ausencias** solas (una marcación tardía las resuelve). Lourdes Mendieta (usuario 4) creó y editó 12 licencias, actualizó 25 ausencias y **eliminó 3 contratos activos** (16/11 un indefinido de 2025; 20/11 dos a plazo fijo de 6 meses con 90 días de prueba, sueldo Gs. 3.044.000). Borrar contratos pierde historial; lo correcto es terminarlos o renovarlos. Posible relación con los 6 empleados activos sin contrato vigente.
- Marcaciones por origen (14 días): terminal ~300 por día, celular 25 a 42, manuales 3 a 13 (bajando; ninguna el 20 y 21/11). Los terminales bajaron de ~335 a ~270–320 desde el 17/11.
- **Retiros de mercadería en uso intensivo:** 370 aprobados (Gs. 26.007.570), 26 pendientes (Gs. 3.412.826), 4 cancelados y 4 rechazados. Sin nómina generada en el sistema, las cuotas nunca se descuentan. Pendiente: cómo cobra Macro esas cuotas y qué hacer con las cuotas vencidas.

## 3f. Contratos eliminados y cuotas de mercadería (verificado 21/11)
- Los 3 contratos eliminados por Lourdes Mendieta no se pueden asignar a un empleado desde la auditoría (`Contract` no usa borrado lógico y `$auditInclude` no incluye `employee_id`). Para saber si fueron un error, se revisa el historial de contratos de los 6 empleados activos sin contrato vigente. Pendiente de decidir: guardar `employee_id` en la auditoría de contratos y evitar el borrado de contratos con historial (terminar o renovar en su lugar).
- **Cuotas de retiros de mercadería:** 370 pendientes por Gs. 26.007.570, de las cuales 319 ya vencidas, ninguna pagada y 4 canceladas. Cada retiro aprobado tiene una cuota y todas siguen pendientes porque la nómina no se genera en el sistema. Pendiente: confirmar cómo cobra Macro esas cuotas y decidir si el sistema debe permitir registrar un cobro por fuera (hoy las cuotas son solo de lectura).

## 4. Deploy a Macro
- Está pendiente desplegar los últimos commits (columna «Marca con» y aviso semanal de empleados silenciosos). Decisión: después de cerrar estas definiciones.
