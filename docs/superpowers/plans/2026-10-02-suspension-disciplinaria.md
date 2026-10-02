# Plan: Suspensión disciplinaria en Amonestaciones

Fecha: 2026-10-02 · Módulo: Warnings · Estado: pendiente de aprobación

## Objetivo

Permitir que una amonestación lleve una suspensión sin goce de sueldo (máx. 8 días) que se
descuente en nómina sin pasar por `Absence` ni por el código `AUS-INJ`, sin afectar antigüedad,
vacaciones ni cobertura IPS.

## Decisiones acordadas

| Tema | Decisión |
|------|----------|
| Modelo | Deducción propia `SUS-DIS`, un `EmployeeDeduction` por día laborable suspendido. Sin `Absence`. |
| Días | `suspension_start_date` + `suspension_days` = días **laborables** según rotación/horario fijo (se saltan francos y feriados). |
| Tope | `suspension_days` entre 0 y 8, bloqueo duro. |
| Sumario | Con 4 a 8 días es obligatorio marcar `suspension_summary_done`. |
| Edición/eliminación | Se revierte (borra deducciones y días) solo si no hay nómina de esas fechas; si la hay, se bloquea con aviso. |
| Monto por día | `Employee::getAbsenceDeductionAmount()` (jornal, o salario base / 30), misma convención que `AUS-INJ`. |
| Liquidación | Fuera de alcance salvo evitar el doble conteo; la revisión de `calculateAbsenceDeductions()` es una tarea aparte. |

## Hallazgos del código que condicionan el diseño

- `DeductionCalculator` (paso 7) ya procesa cualquier `EmployeeDeduction` con `start_date`/`end_date` dentro del período: **no se toca el pipeline**.
- `AttendanceCalculator::resolveShiftDataFor()` ya resuelve rotación > horario fijo y devuelve `check_in = null` en francos: se reutiliza para saber qué días son laborables.
- `CheckMissingAttendance` crea `AttendanceDay` en `absent` y luego corre `AttendanceCalculator::apply()`, que deja el día en `on_leave` si hay vacaciones o permiso. Sin un paso equivalente para la suspensión, el día quedaría `absent` y `LiquidacionService` lo descontaría de nuevo.
- `Warning` es documental puro hoy; `auditInclude` y `formatAuditFieldsForPresentation()` deben ampliarse.
- `Payroll` no tiene fechas propias: la guarda de nómina generada se hace por solapamiento con `payroll_periods` (`start_date`/`end_date`) del empleado.

## Cambios

### 1. Migraciones
1. `add_suspension_to_warnings_table`: `suspension_start_date` (date, null), `suspension_days` (unsignedTinyInteger, default 0), `suspension_summary_done` (boolean, default false). Idempotente con `Schema::hasColumn`.
2. `create_warning_suspension_days_table`: `id`, `warning_id` (FK cascade), `employee_id` (FK cascade), `date`, `employee_deduction_id` (FK nullOnDelete, null), timestamps. `unique(warning_id, date)` e índice `(employee_id, date)`.

### 2. Modelos
- `Warning`: nuevos `fillable`/`casts`; relación `suspensionDays()`; `hasSuspension()`; ampliar `auditInclude` y labels de auditoría.
- Nuevo `WarningSuspensionDay` (con `warning()`, `employee()`, `employeeDeduction()`).
- `Deduction`: constante `CODE_DISCIPLINARY_SUSPENSION = 'SUS-DIS'`.

### 3. `app/Services/SuspensionService.php`
- `resolveDates(Employee, Carbon $start, int $days): Collection`: recorre días calendario desde el inicio y acumula los laborables (`resolveShiftDataFor()['check_in'] !== null` y no feriado) hasta completar `$days`; tope de iteraciones para evitar bucles con empleados sin horario.
- `validate(Employee, Carbon $start, int $days, bool $summaryDone, ?Warning $current): void`: lanza `ValidationException` si `days > 8`, falta sumario con 4 a 8 días, el empleado no está activo, no hay horario/rotación que resuelva días, existe nómina del empleado cuyo período solapa alguna fecha (incluidas las ya aplicadas por `$current`), o alguna fecha ya tiene una `Absence` con deducción (evita doble descuento con `AUS-INJ`).
- `apply(Warning)`: en transacción; llama a `revert()` primero, crea `SUS-DIS` con `firstOrCreate` (`type = other`, `calculation = fixed`, `is_mandatory = false`, `affects_irp = false`), crea un `EmployeeDeduction` por fecha (`start_date = end_date = fecha`, `custom_amount`) y su `WarningSuspensionDay`, y recalcula con `AttendanceCalculator::apply()` los `AttendanceDay` que ya existan en esas fechas.
- `revert(Warning)`: valida nómina, borra `EmployeeDeduction` y `WarningSuspensionDay`, y recalcula los `AttendanceDay` afectados.

### 4. Integración con asistencia
- `AttendanceCalculator::apply()`: nuevo `checkSuspensionStatus($day)` (existe un `WarningSuspensionDay` para empleado y fecha); si es verdadero el día queda `on_leave` junto a vacaciones y permisos. Así `CheckMissingAttendance` no genera ausencia y `Liquidación` no lo cuenta como `absent`.

### 5. Hooks del modelo y Filament
- `WarningObserver` (`saved` si cambiaron los campos de suspensión → `apply()`; `deleting` → `revert()`), registrado como los demás observers.
- `WarningResource::form()`: sección "Suspensión disciplinaria" (colapsada) con `suspension_days` (0 a 8), `suspension_start_date` (requerido si días > 0, `->live()`), `suspension_summary_done` (visible y requerido si días ≥ 4), helper text con la fecha de reintegro calculada. Visible en create y edit.
- `CreateWarning::beforeCreate()` y `EditWarning::beforeSave()`: llaman a `SuspensionService::validate()` y, si falla, notifican y hacen `$this->halt()`.
- `DeleteAction` de `EditWarning`: `->before()` con la misma guarda de nómina.
- Infolist: bloque de suspensión (días, inicio, fechas efectivas, sumario). Tabla: columna/badge "Suspensión" y export `WarningsExport` con las columnas nuevas. PDF `pdf.warning`: línea con el período suspendido y fecha de reintegro.
- Seeders: `SUS-DIS` en `ProductionSeeder` y `DeductionSeeder`.

### 6. Documentación
- `CLAUDE.md`: reemplazar la sección de deuda técnica de Warnings por el diseño implementado, y corregir el comentario de `AUS-INJ` que sugería usarlo para suspensiones.

## Tests (Pest)

`tests/Feature/SuspensionServiceTest.php`:
- `resolveDates` salta francos de rotación, días inactivos del horario fijo y feriados, y cuenta solo laborables.
- `apply` crea N `EmployeeDeduction` `SUS-DIS` con el monto correcto (mensual y jornalero) y N `WarningSuspensionDay`.
- Es idempotente: reaplicar no duplica; cambiar los días reemplaza.
- `validate` bloquea más de 8 días, 4 a 8 sin sumario, empleado inactivo, nómina solapada y fecha con `Absence` ya deducida.
- `revert`/eliminar la amonestación borra todo; con nómina generada bloquea y no borra nada.
- `DeductionCalculator` incluye las deducciones `SUS-DIS` del período en el total.
- `AttendanceCalculator::apply()` deja `on_leave` un día suspendido; `CheckMissingAttendance` no crea ausencia ese día.
- No altera vacaciones ni aguinaldo (regresión mínima).

`tests/Feature/WarningResourceSuspensionTest.php` (Livewire): crear con suspensión, campos condicionales del sumario, bloqueo con aviso y edición que revierte.

## Orden de ejecución

1. Migraciones + modelos + constante + seeders.
2. `SuspensionService` con sus tests (TDD).
3. `AttendanceCalculator::checkSuspensionStatus` + tests.
4. `WarningObserver` y registro.
5. Form, páginas, infolist, tabla, export y PDF.
6. Tests Livewire, `vendor/bin/pint --dirty`, suite de Warnings, Deductions y Attendance.
7. Actualizar `CLAUDE.md`.

## Riesgos y puntos a confirmar

- **Suspensión retroactiva con asistencia ya calculada:** al cambiar el `AttendanceDay` a `on_leave` se pierden marcaciones reales si el empleado sí trabajó esos días. Propuesta: bloquear fechas que ya tengan eventos de asistencia.
- **`AUS-INJ` creado con `is_mandatory = true`** (`Absence::markAsUnjustified()`): `assignMandatoryDeductions()` se lo asignaría a empleados nuevos. Ajeno a este plan; conviene revisarlo aparte.
- **Reporte REOP:** el requisito legal de reportar la suspensión queda fuera; el sistema solo deja el registro y el sumario.
