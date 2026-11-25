# Empleados

## Datos del empleado

El módulo de Empleados gestiona todos los datos personales y laborales del personal.

### Campos del empleado

- **Nombre y apellido**, foto
- **Cédula de identidad (CI):** solo dígitos, sin puntos ni guiones (ej: `4567890`)
- **Fecha de nacimiento**, género (`Masculino` / `Femenino`) y **nacionalidad** (lista con Paraguaya por defecto; un valor cargado antes que no esté en la lista se conserva). El empleado debe ser mayor de 18 años: el calendario abre en una fecha de ejemplo (hace 30 años), y el año se puede cambiar escribiéndolo en la cabecera del calendario
- **Teléfono:** con 0 inicial, sin espacios (ej: `0981123456`). Al salir del campo se acomoda solo: quita espacios, guiones y paréntesis, el prefijo `+595` y agrega el 0 inicial si falta. Si el número queda incompleto, se avisa para corregirlo. Lo mismo rige en Empresas, Sucursales, el responsable del terminal y la importación
- **Email**
- **Empresa y sucursal** a las que pertenece (el campo **Empresa** solo se muestra si hay más de una empresa activa; sirve para filtrar las sucursales)
- **Estado:** Activo o Inactivo
- **Marcación desde el celular:** Heredar, Sí o No (por defecto se marca solo desde el terminal). También se puede habilitar con el botón **Habilitar celular** de la ficha del empleado o con la acción **Habilitar celular** del menú de cada fila del listado; si el cargo ya lo habilita, queda en «Heredar». Ver **Quién puede marcar desde el celular** en el capítulo de Asistencias, donde también se explica el **Estado del celular** (detalle de la columna **Marca con** y filtro del listado).
- **Lugar de trabajo propio (opcional):** sección colapsada (se abre sola si ya tiene datos) con latitud, longitud y **Radio de marcación**; solo para quien trabaja en un lugar fijo propio; la marcación desde el celular se compara con ese punto. Vacíos = usa el de su sucursal (si controla la ubicación) o es «de campo». Ver **Control de ubicación del celular** en Asistencias.

![Asistente de alta de empleado, paso Datos básicos](/docs-images/04-empleado-nuevo.png)

### Crear un empleado

El alta es un asistente de tres pasos (**Siguiente** / **Anterior** para moverse entre ellos):

1. Ir a **Empleados → Empleados** y hacer clic en **Nuevo empleado**.
2. **Datos básicos:** cédula, nombre(s), apellido(s), fecha de nacimiento y género (obligatorios); estado civil y nacionalidad son opcionales.
3. **Ubicación y horario:** empresa y sucursal (obligatorias; si solo hay una, ya vienen elegidas) y, opcionalmente, el horario inicial, que rige desde hoy.
4. **Contrato** (opcional; solo aparece con permiso para crear contratos): si se completan el salario y el cargo, el contrato se crea junto con el empleado. Si se omite, al terminar aparece un aviso de que el empleado quedó sin contrato; se lo crea después desde la pestaña **Contratos** de su ficha.
5. Clic en **Crear**.

La foto, el teléfono, el correo, el domicilio, el superior directo y la protección de maternidad no se piden en el alta: se completan desde la ficha con **Editar** (el formulario de edición muestra todos los campos) o desde sus pestañas. Las deducciones marcadas como obligatorias se asignan solas al nuevo empleado; el resto se agrega desde la pestaña **Deducciones**.

> Los nombres se capitalizan automáticamente al guardar. La CI debe ser única en el sistema.

### Editar un empleado

El título de la pantalla lleva el nombre del empleado («Editar: María González»). El formulario se organiza en **Datos Personales**, **Asignación Laboral** (sucursal, superior directo, estado y marcación desde el celular; con varias empresas, también la empresa), **Lugar de trabajo propio (opcional)** y, solo para mujeres, **Protección de Maternidad** (se abre sola si ya tiene fecha). Arriba están **Ver** y el menú **Más acciones** (**Enrolar** o **Re-enrolar** y **Generar enlace de enrolamiento**). Al cambiar la sucursal de alguien con contrato vigente, el campo avisa que marcará en los terminales de la nueva sucursal.

Si el empleado está inactivo, la ayuda del campo **Estado** indica que, para reactivarlo con un contrato nuevo, se usa **Reingresar** desde la ficha. La CI y el correo repetidos se rechazan con un mensaje propio.

### Superior directo («Reporta a»)

El campo **Reporta a** de la ficha indica el superior jerárquico directo del empleado. Se busca escribiendo nombre, apellido o CI, y solo ofrece empleados **activos de la misma empresa** que no sean él ni alguien que ya le reporte, directa o indirectamente (el sistema también lo valida al guardar para evitar ciclos). En el listado se puede mostrar la columna **Reporta a** y filtrar por **Sin superior directo**.

Para cargarlo en muchos empleados a la vez:

- **Asignar superior directo** (acción masiva): seleccionar empleados, elegir el superior y confirmar. Los que no se pueden asignar (el propio superior, los de otra empresa o los que crearían un ciclo) se omiten y la notificación indica cuántos.
- **Asignar superiores por regla** (botón del encabezado): permite, por ejemplo, que **todos los cajeros reporten a la Encargada de su sucursal**. Se elige la sucursal y los cargos alcanzados, y el superior: *el encargado de su sucursal* (quien tiene cierto cargo en la misma sucursal, siempre que haya uno solo) o *una persona en particular*. La **vista previa** muestra a quién se asignaría cada empleado y por qué se omiten los demás (ya tienen superior, no hay nadie con ese cargo en su sucursal o hay más de uno). Por defecto respeta a quienes ya tienen superior.

### Ficha completa o incompleta

La ficha (detalle) del empleado lleva su nombre como título. Si no tiene foto, se muestra un círculo con sus iniciales. Se organiza en secciones: **Ficha incompleta** (solo si falta algo), **Datos Personales** (la **Protección de maternidad** solo aparece cuando está cargada), **Ubicación en la organización** (empresa, sucursal, cargo, departamento, a quién reporta y subordinados directos), **Contrato** (antigüedad, tipo de empleo, salario, nómina y método de pago), **Marcación: rostro y celular** y **Registro** (fecha de alta y última actualización). Arriba están **Reingresar** (solo en empleados inactivos con contratos anteriores), el menú **Más acciones** (**Crear usuario**, **Descargar Legajo**, **Habilitar celular** y, en rojo, **Dar de baja**) y **Editar**.

El listado de empleados muestra por defecto **Foto**, **Nombre** (con la CI), **Cargo** (con la sucursal debajo), **Ficha**, **Estado**, **Contacto** y **Marca con**. Las demás columnas (**Sucursal** aparte, **Reporta a**, **Nómina**, **Marca desde el celular**, **Aviso de privacidad**, datos del dispositivo y fechas) se activan desde el botón de columnas de la tabla.

**Marca con.** Indica con qué canal marcó realmente cada empleado en los **últimos 30 días**: **Terminal**, **Celular**, **Ambos** o **Ninguno**. Las marcaciones cargadas a mano por RR.HH. no cuentan. Quien tiene el contrato vigente desde hace menos de 30 días y todavía no marcó figura como **Nuevo**. Debajo del canal se ve el estado del celular (por ejemplo *Celular: Habilitado, sin vincular*), y el filtro **Marca con** permite quedarse con un solo canal, por ejemplo **Ninguno** para encontrar a quien no está marcando. En la ficha, la sección **Marcación: rostro y celular** suma el canal, la cantidad de marcaciones por canal, la última marcación en terminal y con celular (con el terminal o la sucursal) y, si marcó con el celular pero hoy no lo tiene habilitado, un aviso que sugiere usar **Habilitar celular**.

El listado de empleados tiene la columna **Ficha**: el porcentaje de datos requeridos que ya están cargados (verde al 100 %, amarillo desde 70 %, rojo por debajo). Al pasar el cursor se ve qué falta. En la ficha de un empleado incompleto aparece la sección **Ficha incompleta** con la lista, y el filtro **Ficha: falta** deja solo a quienes no tienen un dato puntual (por ejemplo, domicilio), útil para completarlos de a uno.

Los datos requeridos de fábrica son teléfono, fecha de nacimiento, domicilio, foto, rostro enrolado y cuenta bancaria (esta última solo si el contrato paga por transferencia). También se pueden exigir correo y contrato firmado (PDF cargado). Cada empresa elige los suyos en **Organización → Empresas → Editar → Ficha del empleado**.

### Estados del empleado

| Estado | Descripción |
|--------|-------------|
| **Activo** | Empleado vigente en nómina |
| **Inactivo** | Relación laboral terminada (se marca automáticamente al vencer o terminar su contrato, o al cerrar una liquidación) |

> Con un contrato vigente, el empleado **no se puede pasar a Inactivo** ni desde el campo **Estado** del formulario ni con **Cambiar estado**: la baja se registra con **Dar de baja**. Sin contrato vigente, **Cambiar estado** sigue disponible para desactivar.

> **Quién ve y modifica los datos de la ficha.** Las pestañas **Domicilios**, **Hijos**, **Cuentas bancarias**, **Deducciones** y **Percepciones** siguen los permisos de Empleados: se ven con **Ver Empleado** y se agregan, editan o eliminan solo con **Editar Empleado**. En **Licencias** y **Vacaciones**, aprobar y rechazar piden sus permisos (**Aprobar** y **Rechazar** de Permiso/Licencia; **Editar Vacación**); en **Contratos**, **Nuevo Contrato**, **Subir Firmado**, **Suspender** y **Reactivar** piden los de Contrato. **El salario** (sección «Contrato» de la ficha, columna «Salario» del Reporte de Empleados y su Excel/PDF, y el legajo en PDF) solo aparece con el permiso **Ver Contratos**; sin él el resto de los datos se muestra igual. En el alta, la sección «Contrato Inicial» solo aparece con **Crear Contrato**.

> **Activar** (con **Cambiar estado**, el campo **Estado** o la acción masiva **Activar seleccionados**) solo se permite si el empleado tiene un contrato vigente; sin él quedaría «activo sin contrato». Para quien ya tuvo contratos se usa **Reingresar**. La acción masiva **Desactivar seleccionados** cierra, igual que la baja individual, el horario y las percepciones y deducciones vigentes, y omite a quienes tienen contrato vigente. Las acciones de estado, el enlace de enrolamiento facial, **Revocar sesión móvil**, la configuración masiva de adelanto automático y la importación exigen el permiso correspondiente (**Editar Empleado**, **Editar Dispositivo de Empleado**, **Editar Contrato** y **Crear Empleado**); sin él no aparecen.

### Dar de baja

Desde la ficha del empleado (o el menú de acciones del listado), **Dar de baja** pide la **fecha de baja** (hoy o anterior, nunca anterior al inicio del contrato), el **tipo de baja** (renuncia, despido, mutuo acuerdo o fin de contrato) y el **motivo**. Al confirmar, el contrato queda **Terminado** con esa fecha, tipo y motivo, y el empleado pasa a **Inactivo** (se cierran su horario, percepciones y deducciones). Si tiene préstamos aprobados o adelantos sin descontar, el modal lo avisa: la baja no los cancela. Requiere el permiso **Terminar contrato**.

### Reingresar

Para un empleado **Inactivo** con contratos anteriores, **Reingresar** (ficha o menú de acciones) lo reactiva y abre un **contrato nuevo** prellenado con el cargo, la modalidad y el pago del último. Se piden el tipo de contrato, la fecha de reingreso, el salario, los días de prueba y, si el tipo lo exige, la fecha de finalización (plazo fijo y aprendizaje: máximo 1 año, Art. 53 CLT). El interruptor **Acumular la antigüedad anterior** lo decide RR.HH. en cada reingreso: apagado, la antigüedad cuenta desde el nuevo contrato; encendido, desde el primer ingreso (si corresponde acumular es un criterio legal que confirma el asesor). Las percepciones y deducciones que se cerraron con la baja **no** se restauran: hay que asignarlas de nuevo. Requiere el permiso **Crear contrato**.

Si la liquidación se calcula en el sistema, activar **Calcular la liquidación en el sistema**: no da de baja todavía, sino que abre el formulario de **Liquidación** con los datos cargados, y el contrato se termina al **Cerrar** la liquidación (crear una liquidación exige un contrato vigente). Si se liquida por fuera, dejarlo apagado.

### Empleado sin contrato o sin horario

Un empleado **activo** puede marcar asistencia aunque todavía no tenga contrato ni horario: solo necesita tener la sucursal asignada y el rostro registrado (no necesita una cuenta de usuario; los usuarios son solo para entrar al panel).

- Puede marcar **entrada y salida** (y descanso). Sus horas trabajadas quedan guardadas.
- Sin horario no se calculan tardanza ni horas extra, y no se generan ausencias. Sus jornadas aparecen como **Sin horario ese día** en **Requieren atención**, y RR.HH. recibe el aviso diario de empleados sin horario.
- **No entra en la nómina**: solo se generan recibos para empleados con contrato activo, así que no tiene recibo ni reporte de salario hasta que se le cargue el contrato.
- Aparece en los reportes de asistencia y de empleados, y en las alertas de **empleados activos sin contrato** de su empresa y sucursal.

Para que cobre: crear el contrato (salario y cargo) y asignar un horario. Las jornadas ya marcadas se pueden volver a calcular con **Volver a leer el horario vigente**, salvo las de períodos con nómina ya generada.

---

## Contratos

El contrato activo define el **salario, cargo y fecha de ingreso** del empleado. Un empleado puede tener historial de contratos.

Desde el perfil del empleado, pestaña **Contratos**, se puede crear el primer contrato o consultar el historial completo. Departamentos y cargos pueden crearse directamente desde el selector, sin salir del formulario.

> Para el detalle completo de tipos de contrato, estados, ciclo de vida, plantillas de PDF y alertas de vencimiento, ver el capítulo **Contratos**.

---

## Percepciones y Deducciones del empleado

Desde las pestañas **Percepciones** y **Deducciones** del perfil del empleado puede asignar conceptos que se incluirán automáticamente en cada nómina:

- **Percepciones:** ingresos adicionales (ej: bono de transporte, antigüedad)
- **Deducciones:** descuentos recurrentes (ej: IPS, seguro médico)

Cada asignación tiene fecha de inicio, fecha de fin opcional y monto personalizado (si difiere del monto global del concepto).

---

## Amonestaciones del empleado

Desde la pestaña **Amonestaciones** en el perfil del empleado se pueden ver y registrar todas las amonestaciones emitidas a ese empleado. Ver el capítulo **Amonestaciones** para el detalle completo del módulo.

---

## Documentos del empleado

La pestaña **Documentos** del perfil guarda los archivos del legajo: cédula, carné de salud, certificados, contratos firmados, etc.

- **Subir documento:** se elige el **Tipo de documento** (opcional), el **nombre**, el **Vencimiento** y el archivo (PDF, imágenes u Office, hasta 10 MB). Al elegir un tipo se completa el nombre. Si el tipo tiene vencimiento, la fecha es obligatoria.
- **Tipos de documento:** los define cada empresa en **Catálogos → Tipos de documento**, y marca cuáles vencen. Un tipo inactivo no se ofrece al subir, pero los documentos ya cargados lo conservan.
- **Vencimiento:** la columna **Vencimiento** muestra la fecha y el estado (**Vigente**, **Por vencer**, **Vencido**) y el filtro **Vencimiento** lista cada grupo. Un resumen avisa de los que vencen en los próximos días (se configura en **Configuración General → Contratos**, «Aviso de documentos por vencer», 30 días por defecto; 0 lo apaga) y de los que ya vencieron; cada documento se avisa una vez por estado y vuelve a avisarse si se cambia su fecha.
- **Privacidad:** los documentos nuevos son privados: no tienen una dirección pública y se descargan con el botón **Descargar**, que exige el permiso **Ver Documento del Empleado**. Los subidos antes de esta versión siguen donde estaban.
- **Permisos:** subir, editar y eliminar piden **Crear**, **Editar** y **Eliminar Documento del Empleado**. RR.HH. los tiene; Contador/Nómina y Soporte TI no ven la pestaña.

---

## Legajo del empleado

El legajo es un resumen completo del empleado en PDF. Para generarlo:

1. Abrir el perfil del empleado
2. Clic en el botón **Legajo** en el encabezado de la página

El documento incluye datos personales, contrato activo e historial relevante.

---

## Reporte de Empleados

Ir a **Reportes → Reporte de Empleados** para ver una nómina completa de todos los empleados con antigüedad, salario, cumpleaños y datos de contrato — útil para RR.HH. y para auditorías generales del personal. Los datos de contrato (salario, cargo, departamento, fecha de ingreso) provienen siempre del **contrato activo**.

Por defecto la tabla muestra solo empleados **Activos** — cambiar el filtro **Estado** para ver también inactivos.

![Reporte de Empleados](/docs-images/04-reporte-empleados.png)

### Columnas disponibles

Empleado, CI, Género, Edad, Cumpleaños, Fecha de ingreso, Antigüedad, Salario, Tipo de contrato, Método de pago, Cargo, Departamento, Sucursal, Empresa, Estado, Teléfono, Fecha de registro, Fecha de baja.

### Filtros disponibles

**Empresa**, **Sucursal**, **Departamento** (en cascada), **Tipo de contrato**, **Método de pago**, **Género**, **Mes de cumpleaños** (útil para planificar saludos o beneficios por cumpleaños), **Rango de fecha de registro** **Aviso de privacidad** (si aceptaron la versión vigente, una anterior o ninguna; ver el capítulo de Asistencias) y **Sin horario asignado** (empleados sin horario fijo ni rotación vigente; desde ahí se les puede asignar uno con **Asignar horario**, ver el capítulo de Horarios). También **Contrato vencido sin renovar** (empleados con el contrato vencido y ningún otro vigente; ver el capítulo de Contratos).

El filtro **Antigüedad desde el ingreso original** hace que **Fecha de ingreso** y **Antigüedad** conserven el inicio de la relación laboral aunque el contrato vigente sea una renovación: toma el primer contrato posterior a la última terminación del empleado (las renovaciones y los contratos que vencieron sin baja forman parte de la misma relación; una terminación la corta). Si el contrato tiene cargada una **antigüedad acumulada** (reingreso), prevalece esa fecha. Sin el filtro, el reporte muestra el inicio del contrato vigente. El filtro también se aplica a los archivos de **Exportar PDF** y **Exportar Excel**.

### Exportar

- **Exportar PDF** — con selector de columnas y orientación de página (Vertical/Horizontal)
- **Exportar Excel** — con selector de columnas
