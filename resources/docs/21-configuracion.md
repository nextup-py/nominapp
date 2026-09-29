# Configuración del Sistema

Esta sección cubre los ajustes que afectan el comportamiento global del sistema.

---

## Configuración General

Acceder desde **Configuración → Configuración General**.

> Esta pantalla **no** contiene los datos de la empresa (nombre, logo, RUC, dirección, etc.) — esos se cargan por separado, por cada empresa, en **Organización → Empresas**, y son los que aparecen en los encabezados de los PDFs. Configuración General solo tiene parámetros globales de comportamiento del sistema, listados abajo.

### Configuración Laboral

- **Zona horaria:** `America/Asunción` por defecto (también se puede elegir entre otros husos horarios de la región).
- **Tolerancia para ausencia:** minutos de gracia después de la hora de entrada antes de que el sistema marque al empleado como ausente (por defecto 30 minutos).

### Configuración de Contratos

- **Días de anticipación para alertas:** cuántos días antes del vencimiento de un contrato a plazo el sistema muestra la alerta (por defecto 30).

### Reconocimiento Facial

- **Validez del enlace de registro:** horas que tiene el empleado para completar la captura facial desde que se genera el enlace (por defecto 48 horas).
- **Umbral de reconocimiento:** distancia máxima aceptada para dar por válido un match facial — cuanto menor, más estricto (por defecto 0.45; rango recomendado 0.35–0.60).
- **Gap mínimo de confianza:** diferencia mínima requerida entre el mejor candidato y el segundo, para evitar confundir rostros parecidos (por defecto 0.10).

> Estos dos últimos parámetros se ajustan revisando los fallos de marcación registrados en `Asistencia → Fallos de Marcación` (ver capítulo de Asistencias).

### Terminales de Marcación

- **Umbral de desconexión:** horas sin un heartbeat exitoso antes de que el panel marque un terminal como desconectado (por defecto 2 horas).

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

---

## Usuarios del Sistema

Acceder desde **Configuración → Usuarios**.

### Crear un usuario

1. Clic en **Nuevo usuario**
2. Ingresar nombre completo, correo electrónico y contraseña (con confirmación)
3. Guardar

Cada usuario puede gestionar su propio perfil (nombre, email, contraseña, foto) desde el menú de perfil en la esquina del panel.

> Un usuario recién creado **no puede entrar al panel** hasta que se le asigne al menos un rol. Ver el capítulo **Roles y Permisos** para asignar roles y entender qué puede hacer cada uno.

---

## Feriados

Acceder desde **Configuración → Feriados**.

![Listado de feriados](/docs-images/21-feriados.png)

Los feriados se usan para:
- Excluir días no laborales del cálculo de días hábiles en vacaciones
- Aplicar el multiplicador de horas extra en feriado
- Evitar generar ausencias en días que corresponden a feriado

### Cargar Feriados Nacionales (un clic)

El botón **Cargar Feriados Nacionales** agrega automáticamente, para el año actual, los feriados nacionales de Paraguay precargados en el sistema (no duplica los que ya existan):

| Fecha | Nombre |
|-------|--------|
| 01/01 | Año Nuevo |
| 01/03 | Día de los Héroes |
| 01/05 | Día del Trabajador |
| 15/05 | Día de la Independencia Nacional |
| 12/06 | Paz del Chaco |
| 15/08 | Fundación de Asunción |
| 29/09 | Día de la Victoria de Boquerón |
| 08/12 | Día de la Virgen de Caacupé |
| 25/12 | Navidad |

> Esta lista fija no incluye feriados de fecha variable (ej. Semana Santa) — esos deben cargarse manualmente cada año.

### Otras acciones del listado

- **Eliminar Feriados Pasados** — borra todos los feriados anteriores al año actual (acción irreversible, útil para limpiar el historial).
- **Copiar al Próximo Año** — duplica todos los feriados del año actual al año siguiente (respetando la misma fecha, un año después), sin duplicar los que ya existan.

### Cargar un feriado manualmente

1. Clic en **Nuevo Feriado**
2. Ingresar fecha y nombre
3. Guardar

> Se recomienda usar **Cargar Feriados Nacionales** al inicio de cada año y completar manualmente los feriados de fecha variable (Semana Santa) y cualquier feriado local o extraordinario.

---

## Visor de Logs

Acceder desde **Configuración → Visor de Logs**.

Herramienta técnica de diagnóstico: muestra los registros de error y advertencia (`storage/logs/laravel.log`) directamente desde el panel, con columnas de **Nivel**, **Entorno**, **Archivo**, **Mensaje** y **Fecha**.

> Pensada para soporte técnico y desarrolladores al investigar un problema puntual (ej. un PDF que no se generó, un job que falló) — no forma parte del uso diario del sistema por parte de RR.HH. o Contaduría.
