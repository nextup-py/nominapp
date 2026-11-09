# Nominapp: visión del producto y hoja de ruta

Documento de producto, sin datos de clientes. Las horas son estimaciones con un margen de ~40 % y se ajustan al avanzar. Capacidad de referencia: ~20 h por semana.

## 1. Principios

| Tema | Decisión |
|---|---|
| Modelo | Una instalación por cliente, estandarizada. No se pasa a SaaS multi-cliente todavía; se revisa con 5 o 6 clientes |
| Automatización | **El sistema propone y una persona aprueba.** Nada se paga ni se liquida sin aprobación humana |
| Cumplimiento | Planilla del IPS y del Ministerio de Trabajo. IRP y SET: fuera por ahora |
| Reglas legales y fiscales | Se construyen solo después de que un contador las valide; el sistema nunca redacta contenido legal |
| Idioma | Solo español paraguayo |

## 2. Usuarios

| Usuario | Qué hace | Estado |
|---|---|---|
| RR.HH. y administración | Todo el panel | Existe |
| Empleado (portal propio) | Recibos, marcaciones, saldos, solicitudes de permisos, vacaciones y adelantos | Nuevo |
| Jefes y encargados | Aprobar solicitudes y ver su equipo, con alcance por sucursal y por equipo | Nuevo: hoy los permisos son por módulo |
| Gerentes y directores | Panel gerencial y reportes | Nuevo |
| Contador externo | Reportes, planillas y exportaciones sin tocar la operación | Nuevo |

## 3. Funciones por área

### Marcación
| Método | Decisión | Horas |
|---|---|---|
| Rostro | Se mantiene | — |
| Prueba de vida | Sí | 14 |
| Reporte de anomalías | Sí | 12 |
| PIN personal | Solo respaldo cuando el rostro falla, marcado «por PIN» y con revisión | 8 |
| QR desde el celular | Sí | 10 |
| Huella del celular (WebAuthn) | Acceso al portal y respaldo. No identifica al empleado: confirma que se desbloqueó el teléfono | 14 |

### Módulos de RR.HH. e informes
| Módulo | Horas |
|---|---|
| Legajo digital con documentos y vencimientos | 30 |
| Reclutamiento e incorporación | 40 |
| Evaluación de desempeño y capacitaciones | 45 |
| Panel gerencial (personal, rotación, ausentismo, costo laboral, horas extra) | 24 |
| Cumplimiento (vencimientos, vacaciones pendientes, alertas) | 12 |
| Reportes personalizados, guardados y programados | 30 |
| Planilla y aportes del IPS | 20 |
| Planillas del Ministerio de Trabajo | 20 |
| Bancos adicionales para pagos masivos (formato propio) | 10 c/u |
| Notificaciones push | 10 |

Los formatos de las planillas del Estado se verifican con el contador y fuentes oficiales antes de construir.

### Portal del empleado y alcance
| Pieza | Horas |
|---|---|
| Portal: acceso, recibos con confirmación de lectura, marcaciones, ausencias y saldos | 35 |
| Solicitudes de permisos, vacaciones y adelantos con aprobación del jefe | 25 |
| Alcance por sucursal y por equipo en todos los módulos | 30 |
| Acceso del contador externo | 8 |

El alcance por equipo requiere saber **a quién reporta cada persona** (dato nuevo). La jerarquía sale del organigrama de cargos, con excepción manual por persona.

## 4. Seguridad y aprobaciones

| Tema | Decisión | Horas |
|---|---|---|
| Acceso al portal | Correo y contraseña propios; la primera vez se prueba la identidad con cédula y fecha de nacimiento | incluido |
| Permisos y vacaciones | Dos pasos: jefe y luego RR.HH. | 8 |
| Adelantos y préstamos | Dos aprobadores distintos | 8 |
| Pagar la nómina | Quien la prepara no puede aprobarla (cuatro manos) | 6 |
| Verificación en dos pasos | Obligatoria para quien ve sueldos | 12 |
| Sesiones | Vencimiento por inactividad y cierre remoto | 6 |
| Registro de accesos | Quién consultó sueldos y recibos | 14 |

Las aprobaciones de dos pasos y la regla de cuatro manos **vienen apagadas** y cada empresa las activa, para no bloquear instalaciones con un solo administrador.

## 5. Vacaciones, datos, alta de clientes y soporte

| Tema | Decisión | Horas |
|---|---|---|
| Saldo de vacaciones | Visible para el empleado y el jefe | 10 |
| Calendario de equipo | Quién está de vacaciones y aviso de superposición | 12 |
| Fraccionamiento y acumulación | Reglas de la ley, validadas con el contador antes de construir | 10 |
| Retención de datos | Cada empresa define el plazo por tipo de dato, con purga automática; el sistema no fija plazos legales | 16 |
| Purga de biométricos y acción «Eliminar datos biométricos» | Descriptor facial, foto y miniatura al vencer el plazo; botón en la ficha con permiso propio | 10 |
| Alta de cliente: importación por Excel | Plantillas de empleados, contratos, hijos, cargos y saldos iniciales, con vista previa y errores por fila | 24 |
| Alta de cliente: asistente guiado | Empresa, sucursales, horarios, reglas de nómina | 16 |
| Alta de cliente: ensayo en paralelo | Nómina de prueba comparada contra el cálculo previo del cliente | 20 |
| Reporte de problemas | Botón con captura, usuario y contexto | 10 |
| Monitoreo de errores y salud | Alertas del programador, la cola y clientes que dejan de sincronizar | 14 |

## 6. Varias empresas, escala y personalización

| Tema | Decisión | Horas |
|---|---|---|
| Traslado entre empresas del mismo cliente | Conserva antigüedad, vacaciones y legajo. Validar con contador o abogado | 12 |
| Reportes consolidados del grupo | Costo laboral, personal y ausentismo con filtro por empresa | 10 |
| Usuarios con acceso a varias empresas | Una cuenta, varias empresas | 8 |
| Escala | Buen rendimiento hasta ~300 empleados por instalación | 12 |
| Logo, colores y datos de empresa | Panel y PDF | 8 |
| Plantillas de contratos y recibos editables | Las carga la empresa; los campos legales del recibo no se pueden quitar | 24 |
| Campos personalizados del empleado | Datos propios del cliente | 20 |
| Conceptos de nómina propios con reglas | Percepciones y deducciones con regla desde el panel | 24 |
| Respaldo y recuperación | Respaldo diario fuera del servidor y prueba de restauración periódica | 10 |

Las plantillas editables y los conceptos propios multiplican las combinaciones a probar: se construyen con vista previa y comparación contra el cálculo anterior antes de aplicarse a una nómina real.

## 7. Calidad, versiones y soporte

| Tema | Decisión | Horas |
|---|---|---|
| Regresión de nómina | Casos reales **anonimizados** con resultado esperado; cada cambio corre contra ellos. Solo con permiso del cliente | 20 |
| Versión y novedades en el panel | Pantalla «Novedades» clasificada: corrección, mejora o cambio que afecta funciones existentes | 12 |
| Ventanas de actualización | Día y hora acordados con cada cliente; aviso de mantenimiento en el panel | 6 |
| Aviso de cambios | Correcciones sin aviso previo; cambios que afectan funciones existentes con 15 días de aviso | incluido |
| Acceso de soporte | Usuario temporal habilitado por el administrador del cliente, con vencimiento y todo en el historial | 14 |
| Parámetros legales editables | Pantalla con fecha de vigencia e historial (vacaciones, preaviso, límites de jornada, IPS, aguinaldo) | 20 |
| Aviso de marcación lejana | Radio alrededor de la sucursal con aviso para revisar | 8 |

La regresión con casos reales se construye **antes** de mover parámetros legales al panel.

## 8. Fuera de la visión (solo si un cliente lo pide y paga)

Asientos contables de sueldos, firma electrónica, lectores biométricos externos, foto de cada marcación, WhatsApp y centro de ayuda con videos. Alternativas mientras tanto: exportación de costo laboral, firma en papel, rostro/PIN/QR, prueba de vida, correo/push/campanita y la guía de usuario.

Si aparecen disputas por marcaciones falsas que no se puedan resolver, la foto por marcación es lo primero que conviene reconsiderar.

## 9. Hoja de ruta

| Fase | Cuándo | Qué |
|---|---|---|
| 0 | Primera nómina oficial de un cliente nuevo | Lo necesario para esa nómina (ver especificación, sección de orden de implementación) |
| 1 | Aguinaldo | Importación de lo ya pagado, solo nóminas aprobadas, horas extra y lista previa |
| 2 | Trimestre siguiente | Perfiles de asistencia, alcance por sucursal y equipo, IPS y Ministerio, parámetros legales con vigencia, purga de biométricos |
| 3 | Siguiente | Portal del empleado, push, recibos por correo, PIN y QR |
| 4 | Siguiente | Legajo digital, panel gerencial, reportes de cumplimiento |
| 5 | A demanda | Reclutamiento, evaluación y bancos adicionales |

Esfuerzo total aproximado de la visión: ≈ 1.000 a 1.200 h (entre uno y dos años a 20 h por semana, con soporte y deploys).

## 10. Abiertos

- Validaciones de contador sobre reglas legales y fiscales.
- Conteos de uso real de tipos de empleado, contrato y horario por instalación para ordenar la lista de tipos nuevos.
- Proveedores externos si se retoman firma electrónica o WhatsApp.
- Textos de privacidad: los redacta cada empresa.
