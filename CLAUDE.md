# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Development (server + queue + vite)
composer run dev

# Run all tests
composer run test

# Run a single test
php artisan test --filter=TestName
php artisan pest tests/Feature/SomeTest.php

# Build frontend
npm run build

# Database
php artisan migrate
php artisan db:seed --class=ProductionSeeder   # new client setup (sets admin user)

# Artisan commands (also run via scheduler)
php artisan app:calculate-attendance          # calculate daily attendance totals (runs at 23:00)
php artisan attendance:check-missing          # flag missing clock-ins (every 15min, 6am–8pm Mon–Sat)
php artisan face:expire-enrollments           # expire stale face enrollments (hourly)
```

## Stack

- **Backend:** Laravel 12, PHP 8.2+
- **Admin panel:** Filament 3.3 (all CRUD at `/admin`)
- **Frontend:** Blade + Vite + Tailwind CSS v4 + Vue (only for attendance/facial pages)
- **Testing:** Pest
- **Queue/Cache/Session:** database driver
- **Locale:** Spanish (`es`), timezone `America/Asuncion`, currency Guaraní (Gs.)

## Architecture

### Hierarchy
Two separate axes that converge in `Contract`:
- **Physical/location:** `Company → Branch → Employee`
- **Organizational:** `Company → Department → Position` (`departments.company_id` FK — each company defines its own departments)

`Employee` has `branch_id` (NOT NULL — company is derived via `employee→branch→company`). Active salary, position, and start date live in the active `Contract` — not on `Employee` directly. `employee.position_id` and `employee.position` are virtual accessors that delegate to `activeContract.position_id` / `activeContract.position` — the column no longer exists on the `employees` table. Employee status flows through the contract lifecycle.

### Modules

| Module | Key classes |
|--------|------------|
| Payroll | `PayrollService`, `PayrollPeriod`, `Payroll`, `PayrollItem` |
| Perceptions | `PerceptionCalculator`, `EmployeePerception` |
| Deductions | `DeductionCalculator`, `EmployeeDeduction` |
| Extra hours | `ExtraHourCalculator` |
| Rest day | `RestDayCalculator` — pago del día de descanso semanal remunerado |
| Tardiness penalty | `AbsencePenaltyCalculator` — descuento por minutos de tardanza aprobados por RR.HH. (**no** procesa ausencias injustificadas — ver nota abajo) |
| Family bonus | `FamilyBonusCalculator` — bonificación familiar IPS |
| Loans | `Loan`, `LoanInstallment`, `LoanInstallmentCalculator` |
| Advances | `Advance`, `AdvanceCalculator` |
| Merchandise withdrawals | `MerchandiseWithdrawal`, `MerchandiseWithdrawalItem`, `MerchandiseWithdrawalInstallment`, `MerchandiseInstallmentCalculator` |
| Vacations | `VacationService`, `VacationBalance` |
| Aguinaldo (13th) | `AguinaldoService`, `AguinaldoPeriod`, `AguinaldoItem` |
| Liquidación | `LiquidacionService`, `LiquidacionItem` |
| Contracts | `ContractService` — renovación y terminación de contratos |
| Schedules | `ScheduleAssignmentService` — asignación de horarios fijos con vigencia por fechas |
| Rotations | `RotationService` — asignación de patrones rotativos y resolución de turno efectivo por fecha |
| Attendance | `AttendanceDay`, `AttendanceEvent`, observers auto-calculate daily totals |
| Attendance offline (terminal/dispositivo) | `Terminal`, `Employee` (Sanctum), `EmployeeDevice` (historial), `AttendanceMarkFailure` — ver "Módulo de Asistencia — Marcación Offline" más abajo |
| Face Recognition | TensorFlow.js (128-element descriptors), `FaceEnrollment`, `FaceCaptureApp.js` |
| Warnings | `Warning` — registro documental de amonestaciones laborales (sin impacto en nómina por ahora) |

### Pipeline de cálculo de nómina (`PayrollService`)

El orden de ejecución de calculadoras dentro de `generateForPeriod()` / `generateForEmployee()` es fijo y tiene dependencias:

```
1. perceptionCalculator           — percepciones (salario base, bonos configurados)
2. extraHourCalculator            — horas extras del período
3. restDayCalculator              — pago del día de descanso semanal
4. loanInstallmentCalculator      — cuotas de préstamos → genera EmployeeDeductions (PRE001)
5. advanceCalculator              — adelantos aprobados → genera EmployeeDeductions (ADE001)
6. merchandiseInstallmentCalculator — cuotas de retiros de mercadería → genera EmployeeDeductions (MER001)
7. deductionCalculator            — todas las deducciones (incluye las recién creadas por 4, 5 y 6)
8. absencePenaltyCalculator       — descuento por tardanzas aprobadas (minutos de atraso, no ausencias)
9. familyBonusCalculator          — bonificación familiar IPS
```

Los pasos 4, 5 y 6 deben correr **antes** que el paso 7 porque crean los `EmployeeDeduction` puntuales que `DeductionCalculator` luego procesa de forma uniforme.

**`AbsencePenaltyCalculator` — nombre engañoso, no penaliza ausencias:** pese al nombre, solo procesa días con `tardiness_deduction_approved = true` (tardanzas aprobadas por RR.HH.), para empleados de tiempo completo. Las **ausencias injustificadas** (día completo) no pasan por este calculator ni por el pipeline de nómina en ese paso — se generan directo como `EmployeeDeduction` (código `AUS-INJ`, creado on-demand la primera vez que se necesita) desde `Absence::markAsUnjustified()` cuando RR.HH. revisa la ausencia en `AbsenceResource`, y de ahí las recoge el `deductionCalculator` del paso 7 como cualquier otra deducción del empleado.

### Módulo de Deducciones (catálogo)

**Tipos reales (`Deduction.type`):** `legal` (default — IPS, IRP), `judicial` (embargos, alimentaria), `voluntary` (seguros, cooperativas), `loan` (cuotas de préstamo/adelanto/mercadería — `PRE001`, `ADE001`, `MER001`), `other`. Son 5, no 4 — no confundir "loan" con una categoría genérica de "deuda".

**`is_mandatory` no asigna retroactivamente:** marcar una deducción como obligatoria **no** la aplica a los empleados que ya existen. Solo se asigna automáticamente a **empleados nuevos** al crearlos (`EmployeeObserver` llama `Employee::assignMandatoryDeductions()`). Para aplicarla a un empleado existente hay que usar la acción manual "Asignar obligatorias" desde su pestaña de Deducciones (`EmployeeDeductionsRelationManager`).

### Módulo de Préstamos

**Ciclo de vida:** `pending` → `approve()` → `approved` → auto-`paid` (cuando todas las cuotas están pagadas). Desde `pending`: `reject()` → `rejected`. Desde `pending` / `approved`: `cancel()` → `cancelled`.

**Integración con nómina — pipeline de deducción:**
`LoanInstallmentCalculator.calculate()` se ejecuta **antes** que `DeductionCalculator` en `PayrollService`. Por cada cuota con `due_date` dentro del período y estado `pending`, crea un `EmployeeDeduction` puntual (`start_date = end_date = due_date`) usando el código `PRE001`. `DeductionCalculator` luego las procesa junto al resto de deducciones del empleado de forma uniforme.

**Dependencia crítica:** El registro `PRE001` debe existir en la tabla `deductions`. Sembrado por `ProductionSeeder` y `DeductionSeeder`. Sin él, las cuotas se omiten con un warning en el log.

**Idempotencia:** Llamar a `calculate()` dos veces en el mismo período no duplica `EmployeeDeduction`. Si la cuota ya tiene `employee_deduction_id`, actualiza el registro existente.

**Limpieza al eliminar nómina:** `Payroll::booted()` revierte las cuotas a `pending` y elimina los `EmployeeDeduction` asociados al período.

**UI — acciones disponibles según estado:**
- `pending`: Aprobar, Rechazar, Editar. Sin DeleteAction en ViewRecord.
- `approved`: Cancelar, Descargar PDF.
- `rejected` / `cancelled` / `paid`: sin acciones disponibles.

### Módulo de Adelantos

Retiro anticipado de salario del período en curso. A diferencia de los préstamos, no tiene cuotas — el descuento es único en la próxima nómina.

**Ciclo de vida:**
```
pending → approved → disbursed → paid
                   ↘ cancelled (solo desde approved)
       ↘ rejected (terminal)
pending/approved → cancelled
```

- `disbursed` = dinero entregado al empleado (acreditado en banco o entregado en efectivo), pendiente de descuento en nómina.
- `paid` = descontado de la nómina. Solo `AdvanceCalculator` setea este estado.
- `disbursement_batch_at`: fecha del lote de acreditación bancaria (campo `date nullable`). Se stampa al exportar TXT/Excel banco o al marcar manualmente como entregado.

**Integración con nómina — pipeline de deducción:**
`AdvanceCalculator.calculate()` se ejecuta antes que `DeductionCalculator` en `PayrollService`. Por cada adelanto con `status='disbursed'` y `payroll_id IS NULL`, crea un `EmployeeDeduction` puntual usando el código `ADE001`. Luego `markAdvancesAsPaid()` setea `status='paid'` y registra el `payroll_id`.

**Dependencia crítica:** El registro `ADE001` debe existir en la tabla `deductions`. Sembrado por `ProductionSeeder` y `DeductionSeeder`. Sin él, el adelanto se omite con un warning en el log.

**Idempotencia:** Si el adelanto ya tiene `employee_deduction_id`, actualiza el monto en lugar de crear uno nuevo.

**Limpieza al eliminar nómina:** `Payroll::booted()` revierte los adelantos a `disbursed` (no a `approved`) y elimina los `EmployeeDeduction` asociados.

**Validaciones en `Advance::approve()`** (en orden):
1. Estado debe ser `pending`.
2. Empleado debe tener contrato activo.
3. Nómina del período actual no debe estar generada para ese empleado.
4. Límite por período: si `advance_max_per_period > 0`, la cantidad de adelantos activos (`pending + approved + disbursed`) no puede igualar o superar ese límite.
5. Cap salarial (solo `salary_type = 'mensual'`): la suma de todos los adelantos activos + el monto del adelanto actual no puede superar el salario mensual bruto.

**Configuración en `PayrollSettings`:**
- `advance_max_percent`: % máximo del salario que puede representar cada adelanto individual.
- `advance_max_per_period`: cantidad máxima de adelantos activos simultáneos (0 = sin límite).
- Validación cruzada en el formulario de settings: `advance_max_per_period × advance_max_percent ≤ 100%`.

**Generación de adelantos — solo manual:**
No existe generación automática por scheduler. El usuario genera adelantos de dos formas:
- **Individual:** `CreateAdvance` desde el listado.
- **Masiva:** header action `Generar Adelantos` en `ListAdvances`, con filtros por empresa/sucursal, monto único, y selección de empleados. Valida tope por empleado y límite por período al ejecutar.

**Export banco (TXT / Excel):** header actions en `ListAdvances` → `BankPaymentExportService`. Stampan `disbursement_batch_at = fecha_credito` en los adelantos incluidos. No cambian el estado — el usuario marca manualmente como `disbursed` con la bulk action "Marcar como Entregados".

**UI — acciones disponibles según estado:**
- `pending`: Aprobar (`success`), Rechazar (`warning`), Cancelar (`danger`), Editar. Sin DeleteAction en ViewRecord.
- `approved`: Marcar como Entregado (`primary`), Cancelar (`danger`), Descargar PDF.
- `disbursed`: Revertir a Aprobado (`warning`, solo si `payroll_id IS NULL`), Descargar PDF.
- `paid`: Descargar PDF. Sin acciones de mutación.
- `rejected` / `cancelled`: sin acciones disponibles.

**Acciones en tabla (row actions):** `approve`, `reject`, `mark_disbursed` (approved → disbursed), `revert_to_approved` (disbursed → approved, si `payroll_id IS NULL`).

**Bulk actions:** `approveBulk` (`success`), `rejectBulk` (`warning`), `markDisbursedBulk` (`primary`), `revertBulk` (`warning`) — todas filtran internamente al estado esperado y reportan conteo de procesados/ignorados.

**Export Excel:** header action en `ListAdvances` → `AdvancesExport` (columnas: Empleado, CI, Monto, Estado, Método de pago, Notas, Aprobado el, Aprobado por, Fecha de Entrega, Creado, Editado).

### Módulo de Retiro de Mercaderías

Compra a crédito de productos del catálogo del empleador, con descuento automático en cuotas mensuales. Un empleado puede tener múltiples retiros activos simultáneamente. No tiene interés.

**Modelos:**
- `MerchandiseWithdrawal` — cabecera: empleado, total, cuotas, saldo pendiente, estado
- `MerchandiseWithdrawalItem` — ítems del retiro (código libre, nombre, descripción, precio, cantidad, subtotal)
- `MerchandiseWithdrawalInstallment` — cuotas generadas al aprobar el retiro

**Ciclo de vida:** `pending` → `approve()` → `approved` → auto-`paid` (cuando todas las cuotas están pagadas). Desde `pending`: `reject()` → `rejected`. Desde `approved`: `cancel()` → `cancelled` (solo si `paid_installments_count === 0`).

**Integración con nómina — pipeline de deducción:**
`MerchandiseInstallmentCalculator.calculate()` se ejecuta antes que `DeductionCalculator` en `PayrollService`. Por cada cuota con `due_date` dentro del período y estado `pending`, crea un `EmployeeDeduction` puntual usando el código `MER001`. `DeductionCalculator` luego las procesa de forma uniforme.

**Dependencia crítica:** El registro `MER001` debe existir en la tabla `deductions`. Sembrado por `ProductionSeeder` y `DeductionSeeder`. Sin él, las cuotas se omiten con un warning en el log.

**Idempotencia:** Llamar a `calculate()` dos veces en el mismo período no duplica `EmployeeDeduction`. Si la cuota ya tiene `employee_deduction_id`, actualiza el registro existente.

**Limpieza al eliminar nómina:** `Payroll::booted()` revierte las cuotas a `pending` y elimina los `EmployeeDeduction` asociados al período.

**Generación de cuotas:** Al aprobar (`approve()`), el modelo genera automáticamente las cuotas con `due_date` a partir de `approved_at` + 30 días (primera cuota), incrementando de a 30 días.

**Configuración en `PayrollSettings`:**
- `merchandise_max_amount`: monto máximo por retiro (Gs.)
- `merchandise_max_installments`: cantidad máxima de cuotas permitidas

**UI — acciones disponibles según estado:**
- `pending`: Aprobar, Rechazar, Editar.
- `approved`: Cancelar (solo si `paid_installments_count === 0`), Descargar PDF.
- `paid`: Descargar PDF. Sin acciones de mutación.
- `rejected` / `cancelled`: sin acciones disponibles.

**RelationManagers:**
- `ItemsRelationManager` — CRUD de productos; editable solo si el retiro está `pending`; recalcula `total_amount` tras cada cambio.
- `InstallmentsRelationManager` — solo lectura; exportable a Excel; filtrable por estado.

### Módulo de Pagos Bancarios Masivos (DisbursementBatch)

Agrupa adelantos, recibos de nómina, préstamos o aguinaldos para generar un único archivo TXT en formato Itaú y registrar el resultado del banco.

**Tipos (`type`):** `advances`, `payroll`, `loan`, `aguinaldo`

**Ciclo de vida:** `pending` → `confirmed` (todos aceptados) / `partially_confirmed` (algunos rechazados) / `cancelled` (cancelado o todos rechazados)

**Entry points para crear lotes:**
- `advances`: `CreateDisbursementBatch` (formulario con selección de adelantos aprobados por transferencia)
- `payroll`: header action "Enviar al Banco" en `ViewPayrollPeriod`
- `loan`: header action "Crear Lote Bancario" en `ListLoans`
- `aguinaldo`: header action "Enviar al Banco" en `ViewAguinaldoPeriod`

**Acciones principales en el modelo:**
- `cancel()`: solo desde `pending`; limpia `disbursement_batch_id` en todos los registros asociados según el tipo.
- `confirm(confirmedById, bankConfirmationPath, rejectedIds, rejectionReasons)`: según el tipo, marca los aceptados con su transición de estado (`disbursed` para advances/payroll/loans, `paid` para aguinaldos); los rechazados recuperan `disbursement_batch_id = null` y vuelven a su estado previo. Si todos se rechazan → `cancelled`; si algunos → `partially_confirmed`; si ninguno → `confirmed`.

**Integración por tipo:**
- `advances`: `Advance.disbursement_batch_id`. Aceptados → `disbursed`. Rechazados → vuelven a `approved` con `bank_rejection_reason`.
- `payroll`: `Payroll.disbursement_batch_id`. Aceptados → `disbursed`. Rechazados → vuelven a `approved` con `bank_rejection_reason`.
- `loan`: `Loan.disbursement_batch_id`. Aceptados → `disbursed` (vía `Loan::disburse()`). Rechazados → vuelven a `approved`.
- `aguinaldo`: `Aguinaldo.disbursement_batch_id`. Aceptados → `paid` (vía `Aguinaldo::markAsPaid()`). Rechazados → vuelven a `pending`.

**`payment_method` en Loan y Aguinaldo:** campo `string` con default `'cash'`. Solo los registros con `payment_method = 'transfer'` y `disbursement_batch_id IS NULL` son elegibles para incluirse en un lote. Se puede cambiar individualmente o en bulk desde sus respectivos listados mientras el registro esté en estado editable (pendiente/aprobado sin lote asignado).

### Módulo de Permisos y Licencias (EmployeeLeave)

Registro documental de permisos y licencias de empleados. **Sin integración con nómina** — no afecta el cálculo de salarios, pero sí justifica ausencias automáticamente al aprobar.

**Tipos disponibles:** `medical_leave`, `vacation`, `day_off`, `maternity_leave`, `paternity_leave`, `unpaid_leave`, `other`

**Ciclo de vida:** `pending` → `approved` / `rejected`

**Integración bidireccional con Ausencias (`Absence`):**
- `EmployeeLeave::approve(int $approvedById): array` — al aprobar, busca todas las `Absence` del empleado en el período con estado `pending` o `unjustified` y llama a `justify()` en cada una. Retorna `['justified_count' => N]`.
- `Absence::justify(int $reviewedById, ?string $reviewNotes, ?int $employeeLeaveId)` — al justificar una ausencia desde el modal, siempre se requiere vincular un `EmployeeLeave` aprobado que cubra esa fecha. La FK `employee_leave_id` queda almacenada en la ausencia.
- Una licencia no puede **crear** ausencias — solo justifica las que ya existen en el período.
- No se puede justificar una ausencia sin vincularla a un permiso aprobado. Si no existe ninguno, el modal muestra un aviso y deshabilita el campo.

**UI — acciones disponibles según estado:**
- `pending`: Aprobar (tabla + ViewRecord con descripción dinámica del nº de ausencias afectadas), Rechazar (tabla + ViewRecord), Editar.
- `approved` / `rejected`: sin acciones de mutación.

### Módulo de Amonestaciones

Registro documental de amonestaciones laborales emitidas a empleados. **Sin integración con nómina por ahora** — es un módulo puramente documental.

**Modelo:** `Warning` — campos: `employee_id`, `type` (verbal/written/severe), `reason` (categoría predefinida), `description`, `issued_at`, `issued_by_id`, `notes`, `document_path` (PDF firmado subido opcionalmente).

**Sin ciclo de vida:** una amonestación creada existe como registro permanente. Se edita si hay error, se elimina si fue incorrecta.

**Deuda técnica — integración futura con nómina:**
La opción acordada es **suspensión disciplinaria**: agregar `suspension_days int default 0` a `warnings`. Al guardar una amonestación con suspensión > 0, crear registros de `Absence` para esos días y marcarlos como injustificados (`Absence::markAsUnjustified()`), que generan su deducción `AUS-INJ` de la forma estándar — sin cambios en el pipeline de nómina. (Nota: no es `AbsencePenaltyCalculator` quien las procesaría — ese calculator solo maneja tardanzas, ver "Tardiness penalty" en Módulos arriba.)

**Formulario de creación:** `issued_at` e `issued_by_id` se inyectan automáticamente en `CreateWarning::mutateFormDataBeforeCreate()` — no aparecen en el form de create, sí en edit. La sección "Documento Firmado" también es `->visibleOn('edit')`.

**UI:**
- Resource: `WarningResource` en grupo `Empleados` — listado con tabs por tipo (Verbal/Escrita/Grave), filtros por tipo, motivo, empleado y rango de fechas
- RelationManager: `WarningsRelationManager` en `EmployeeResource`
- PDF: `WarningController@show` → `pdf.warning` → ruta `warnings.pdf`
- Export Excel: `WarningsExport` — header action en `ListWarnings`

**Helpers en el modelo:**
- `Warning::getTypeOptions/Label/Color/Icon()` — tipo de amonestación
- `Warning::getReasonOptions/Label()` — motivo predefinido

### Módulo de Liquidación

Liquidación de haberes por desvinculación del empleado. Se calcula manualmente desde `LiquidacionResource` (`LiquidacionService`).

**Ciclo de vida:** `draft` → `calculate()` → `calculated` → `close()` → `closed`. Crear la liquidación **no** calcula los montos — quedan en `draft` hasta ejecutar `calculate()` como acción separada. `calculate()` también puede volver a correrse sobre una liquidación ya `calculated` (acción "Recalcular"): borra los `LiquidacionItem` existentes (incluidas ediciones manuales) y recalcula todo desde cero.

- `calculate()`: calcula todos los ítems aplicables y persiste en `LiquidacionItem`. El empleado sigue activo.
- `close()`: marca la liquidación como cerrada, el contrato como `terminated` y el empleado como `inactive`. También cancela todos los préstamos pendientes.

**Período de prueba y preaviso ya otorgado — exclusiones:** si la antigüedad es menor o igual a `trial_days` del contrato (default 30), **no** corresponden Preaviso, Indemnización ni Vacaciones proporcionales (solo salario pendiente y aguinaldo proporcional). El campo `preaviso_otorgado` (toggle en el form) anula el pago de Preaviso aunque el tipo de terminación sea Despido Injustificado, si el empleador ya avisó con anticipación.

**Cálculos incluidos:**
- Preaviso (días según años de servicio, Art. CLT) — solo Despido Injustificado, fuera de período de prueba, sin preaviso ya otorgado
- Indemnización (proporcional a años y salario promedio de los últimos 6 meses, no el salario actual) — solo Despido Injustificado, fuera de período de prueba
- **Indemnización adicional por Estabilidad Laboral Propia (Art. 95 CLT):** si el empleado tiene 10+ años de antigüedad en un Despido Injustificado, se agrega una segunda línea por el mismo monto de la indemnización (indemnización doble)
- Vacaciones proporcionales al período trabajado (sobre el salario promedio de 6 meses)
- Aguinaldo proporcional al año en curso
- Salario pendiente (días trabajados en el último período sin nómina generada)
- Descuentos: ausencias injustificadas, IPS 9% (**solo si el empleado tiene la deducción de IPS activa en su perfil** — si no, se omite y se advierte al crear la liquidación), saldo de préstamos/adelantos activos (se cancelan al cerrar)

**Validaciones al crear (bloquean):** empleado ya con liquidación activa (`draft`/`calculated`), sin contrato activo, salario del contrato en Gs. 0. **Avisos no bloqueantes:** empleada bajo protección de maternidad (Ley 5508/15), empleado sin deducción IPS activa.

**Editar una liquidación calculada:** si se cambia `termination_type` o `preaviso_otorgado`, revierte automáticamente a `draft` (borra ítems y PDF) — debe recalcularse.

**Ítems editables manualmente:** desde `ItemsRelationManager` se pueden editar/eliminar ítems individuales (no crear nuevos) mientras no esté `closed`. Cualquier edición recalcula los totales pero invalida el PDF (`pdf_path = null`) — requiere regenerarlo.

### Módulo de Aguinaldo

Salario del mes 13, pagadero en diciembre. Se gestiona por `AguinaldoPeriod` (un período por año/empresa).

**Ciclo de vida del período:** `draft` → `processing` → `closed`

**Ciclo de vida de cada `Aguinaldo`:** `pending` → `paid` (vía `markAsPaid()`)

**Generación:** `AguinaldoService::generateForPeriod()` recorre los empleados activos o suspendidos de la empresa, suma los salarios de las nóminas pagadas en el año del período (`paid`) y calcula el proporcional. Se puede regenerar para un empleado individual con `regenerateForEmployee()`.

**Provisión mensual:** `AguinaldoService::provisionQuery()` retorna una query agregada para mostrar el monto acumulado hasta el mes indicado — útil para reportes contables.

### Módulo de Vacaciones

**Pago con nómina (`payment_method = 'with_payroll'`):** `PayrollService::resolveVacationPays()` paga la remuneración vacacional **completa como pago único** en el período de nómina que contiene el `start_date` de la vacación — **no se prorratea** aunque el `end_date` caiga en el período de nómina siguiente. Es una convención intencional (lump sum al inicio de la vacación), no un bug. `Vacation.payment_status` es un enum simple `unpaid`/`paid` — no rastrea montos parciales, así que implementar prorrateo real requeriría un cambio de modelo de datos, no solo de lógica.

### Módulo de Contratos

**Estados reales (`Contract.status`):** `draft` → `active` → `suspended` / `expired` / `terminated` / `renewed`. **"Por vencer" no es un estado almacenado** — es un filtro/vista sobre contratos `active` cuya `end_date` cae dentro de los días configurados en `GeneralSettings.contract_alert_days`; el contrato sigue en `active` hasta que vence de verdad (`contracts:expire`, corre a las 00:05).

**Tipos:** `indefinido`, `plazo_fijo` ("Por Plazo Determinado"), `obra_determinada`, `aprendizaje`, `pasantia`. `Contract::requiresEndDate()` exige `end_date` para todos salvo `indefinido`.

**Art. 53 CLT — duración máxima:** los contratos `plazo_fijo` y `aprendizaje` no pueden exceder 1 año entre `start_date` y `end_date` — validado y bloqueado en `CreateContract::mutateFormDataBeforeCreate()`.

**Art. 53 CLT — renovación forzada a indefinido:** `Contract::renew()` cuenta las renovaciones previas (`status = 'renewed'`) del mismo empleado y tipo `plazo_fijo`; en la **segunda renovación**, el nuevo contrato se crea como `indefinido` automáticamente (sin `end_date`). `wouldBecomeIndefiniteOnRenewal()` predice esto para mostrar el aviso en el modal antes de confirmar.

**Validación al crear:** no se puede crear un contrato si el empleado ya tiene uno `active` (el Select de empleados ya los excluye, más una validación de backend que bloquea igual).

**Terminar contrato → Liquidación:** `ContractService::terminate()` solo actualiza el contrato; la notificación posterior a terminar incluye un botón "Crear Liquidación" con link directo al formulario de `LiquidacionResource` para ese empleado (no se crea automáticamente).

**Cascada de estado del empleado:** al pasar a `expired` o `terminated`, `ContractObserver` marca al empleado `inactive` — salvo que tenga otro contrato `active` (ver convención "Observer para cascada de estados" más abajo).

### Módulo de Roles y Permisos

Fundación de control de acceso vía `spatie/laravel-permission`. Cubre permisos CRUD por modelo y administración de roles — **no** cubre permisos de acciones de negocio (aprobar, cerrar, exportar, etc.) ni auditoría completa, que quedan para planes posteriores.

**Patrón `BasePolicy`:** cada uno de los 33 modelos con Resource en Filament tiene una Policy real (`app/Policies/{Modelo}Policy.php`) que solo extiende `App\Policies\BasePolicy` — sin lógica propia. `BasePolicy` resuelve el nombre del permiso por convención a partir del nombre de la clase (`EmployeePolicy` → abilities `view_any_employee`, `view_employee`, `create_employee`, `update_employee`, `delete_employee`) y delega en `$user->can(...)`. Laravel descubre las Policies automáticamente por convención de nombres — no requieren registro manual.

**Catálogo de permisos (`PermissionSeeder`):** siembra `{ability}_{modelo}` para los 33 modelos × 5 abilities (`view_any`, `view`, `create`, `update`, `delete`). Las constantes `MODELS`, `ABILITIES`, `ABILITY_LABELS`, `MODEL_LABELS` y `GROUPS` (agrupación por módulo: Organización, Empleados, Asistencia, Nómina y Créditos, Configuración) son consumidas también por `RoleResource` para armar el formulario de permisos agrupados.

**4 roles iniciales (`RoleSeeder`):**
- **Super Admin** — bypassa cualquier chequeo de permiso vía `Gate::before` en `AppServiceProvider` (no se le sincronizan los ~165 permisos explícitamente).
- **RRHH** — CRUD sobre empleados, contratos, asistencia, permisos/licencias, amonestaciones, vacaciones, horarios, dispositivos, terminales y sucursales.
- **Contador/Nómina** — CRUD sobre nómina, préstamos, adelantos, retiros de mercadería, liquidación, aguinaldo, deducciones/percepciones y lotes bancarios; solo lectura sobre empleados y contratos.
- **Solo Lectura** — `view_any`/`view` sobre todos los 33 modelos, sin permisos de mutación.

`RoleSeeder` también asigna Super Admin a cualquier usuario existente sin rol (`User::doesntHave('roles')`), tanto en la primera siembra como en reejecuciones — útil para instalaciones que migran a este sistema.

**Bloqueo de panel sin rol:** `User::canAccessPanel()` requiere al menos un rol asignado — un usuario recién creado sin rol no puede entrar a `/admin` hasta que se le asigne uno.

**UI de administración:** `RoleResource` (grupo Configuración, solo visible/editable para Super Admin) permite crear/editar roles marcando permisos agrupados por módulo en checkboxes; `UserResource` incorpora un selector de roles (`Select` múltiple sobre la relación `roles`) visible únicamente para Super Admin.

**Seeders:** `ProductionSeeder` y `DemoSeeder` siembran `PermissionSeeder` + `RoleSeeder` justo después de crear el usuario admin — así el admin queda con Super Admin automáticamente vía el mecanismo de "usuarios sin rol" de arriba.

### Módulo de Asistencia — Marcación Offline (Terminal y Dispositivo Personal)

Documento completo con arquitectura, decisiones y deuda técnica: **`docs/marcacion-offline.md`**. Resumen:

Dos dispositivos marcan asistencia por reconocimiento facial (face-api.js, 100% client-side), ambos con soporte offline vía PWA:
- **Terminal** (`Terminal`) — compartido por sucursal, cachea el descriptor de *todos* los empleados activos de esa sucursal. Provisión por enlace de un solo uso generado por un admin.
- **Dispositivo personal** (`Employee`) — individual, cachea *únicamente* el propio descriptor. Vinculación self-service en `/vincular-dispositivo` con CI + fecha de nacimiento (no es un factor de auth completo — el match facial sigue siendo el control real). Un dispositivo vinculado a la vez: vincular uno nuevo revoca el anterior y notifica a los admins.

**Auth:** un único guard `auth:sanctum` sirve tokens de `Terminal` (`ability: terminal:sync`) y `Employee` (`ability: mobile:sync`) — Sanctum resuelve el `tokenable` de forma polimórfica sin config adicional en `config/auth.php`. Ambos modelos usan `HasApiTokens` + `Illuminate\Auth\Authenticatable` (este último solo necesario para que `Sanctum::actingAs()` funcione en tests).

**Cliente offline:** IndexedDB (`resources/js/attendances/terminal-offline/` y `mobile-offline/`) + cola de eventos con `client_event_id` idempotente + service worker (`public/sw.js`) cacheando modelos/assets/shell. Las piezas mecánicamente idénticas entre terminal y mobile viven extraídas en `resources/js/attendances/offline-shared/`: `matcher.js` (matching facial completo, idéntico en ambos modos), `generic-db.js` (CRUD genérico de IndexedDB — metadata, cola de eventos, caché de estado, sync log; recibe el handle `db` ya abierto, no conoce `DB_NAME`), y `submit-in-chunks.js` (loop de batching de `flushQueue()`, 200 eventos por lote). El modelo de dominio (N empleados cacheados en terminal vs. 1 empleado implícito en mobile) **no** está unificado — cada `db.js`/`queue.js` mantiene su propio schema/`getDb()` y delega solo lo genérico. `sync.js` tampoco está unificado (endpoints, `TerminalAuthError`/`MobileAuthError` y forma de datos son genuinamente distintos entre los dos modos).

**Gotcha de orden de carga — `faceapi` es un global, no un import:** `face-api.js` se carga vía `<script defer src="js/face-api.min.js">` en `terminal.blade.php`/`mark.blade.php`, **separado** del entrypoint de Vite (`@vite(...)`, que Laravel emite como `<script type="module">`). Ambos tipos de script son "deferred" y HTML los ejecuta **en el orden en que aparecen en el documento** — si el `<script>` de face-api.min.js queda DESPUÉS del `@vite(...)` del JS de la vista (como estaba en `terminal.blade.php`), cualquier código que use `faceapi` **a nivel de módulo** (fuera de una función, evaluado al importar) revienta con `ReferenceError: faceapi is not defined` apenas carga la página — y como eso pasa antes de que se registre el listener de `DOMContentLoaded`, toda la inicialización queda muerta sin ningún error visible en pantalla (bug real en producción, detectado recién con humo manual en un terminal real — Vitest no lo detecta porque `resources/js/test-setup.js` stubea `faceapi` globalmente antes de cualquier import). **Regla:** construir cualquier `new faceapi.X(...)` de forma perezosa (dentro de una función, en el primer uso real), nunca en una `const` a nivel de módulo — ver el patrón en `resources/js/attendances/terminal/camera.js`/`idle-detection.js` (`getTinyOptions()`/`getLightOptions()`).

**Conflictos de sync:** si un evento offline ya no es válido al sincronizar (otro origen registró algo mientras el dispositivo estaba desconectado), se rechaza puntualmente y se registra en `AttendanceMarkFailure` (`failure_type: sync_conflict`) — nunca se descarta silenciosamente. Un admin lo revisa en `AttendanceMarkFailureResource`: **aprobar** (reconstruye el evento, revalidando la secuencia contra el estado *actual*) o **descartar**.

**Gotcha al probar con empleados de test — fallback de "jornada nocturna abierta" (`AttendanceDay::resolveForEvent()`):** cuando la jornada de HOY de un empleado ya está completa (sin eventos permitidos), el resolver cae a revisar si la jornada de AYER quedó abierta (sin `check_out`) y, si la transición pedida es válida ahí, le cuelga el evento a ESA jornada vieja en vez de rechazarlo — pensado para turnos nocturnos reales que cruzan medianoche, funciona por diseño. **Efecto secundario al hacer pruebas repetidas con el mismo empleado de test:** si ese empleado tiene una jornada vieja sin cerrar de una prueba anterior (ej. un `check_in` de `/marcar` de hace días, nunca cerrado), una marcación de "Salida" que ya no aplica a hoy puede terminar attachada a esa jornada vieja — en el listado de `AttendanceEventResource` da la impresión de "dos Salidas el mismo día" cuando en realidad son de dos `attendance_day_id` distintos. No es un bug — hay que revisar `attendance_day_id` de cada evento (o el campo `date` de su `AttendanceDay`) para diagnosticar, no asumir duplicado. Cerrar/limpiar las jornadas de prueba viejas del empleado evita la confusión.

**Reconocimiento facial:** `face_threshold` (0.50) y `face_min_confidence_gap` (0.05) en `GeneralSettings`, ajustados con datos reales de `AttendanceMarkFailure`. Lógica de captura común en `resources/js/shared/face-capture-core.js`.

**Runbooks:** `docs/runbook-terminal-revocacion-reprovision.md`, `docs/runbook-dispositivo-vinculacion-revocacion.md`.

**Historial de dispositivos (`EmployeeDevice`):** cada vinculación/re-vinculación del celular personal crea un registro nuevo (no se sobrescribe el anterior) — `Employee::claimMobileToken()` cierra el `activeDevice` previo (`unlinked_at`) y crea uno nuevo vía `$this->devices()->create(...)`. Recurso Filament standalone (`EmployeeDeviceResource`, solo `index`/`view`/`edit`, sin `create` — es append-only) más un `DevicesRelationManager` de solo lectura en `EmployeeResource`. Acción **Revocar** disponible en ambos lados. Gotcha real ya corregido: `$employee->activeDevice` (relación `hasOne`) queda cacheado (stale) si se lee después de mutar vía `devices()->create()`/`update()` en la misma instancia — `claimMobileToken()`/`revokeMobileToken()` llaman `$this->setRelation('activeDevice', ...)` explícitamente para evitarlo.

**Detección best-effort de marca/modelo (`DeviceHintsParser`):** al vincular un celular o provisionar un terminal, se intenta prellenar `device_brand`/`device_model` (siempre editables) a partir de Client Hints del navegador (`navigator.userAgentData.getHighEntropyValues(['model'])` — solo Chromium + Android, manda `device_model_hint` en el POST de claim) con fallback a parseo del `User-Agent` en el servidor. **MAC address y número de serie nunca son detectables desde ningún navegador** — quedan 100% manuales por diseño, no es una limitación de esta implementación. `Terminal::claimSanctumToken()` nunca pisa una corrección manual previa (mismo registro se reutiliza entre reprovisiones); `Employee::claimMobileToken()` siempre aplica el guess (cada vinculación crea un `EmployeeDevice` nuevo).

**Migración de terminales de `/terminal` (legacy, sin código) a `/terminal/{code}`:** no requiere reprovisión — el token Sanctum vive en el IndexedDB del dispositivo, no depende de la URL que lo cargó. `terminal.js` (`checkLegacyTerminalMigration()`) detecta en `/terminal` si ya hay un `terminal_code` guardado localmente (provisión anterior) y muestra un banner con el link directo a `/terminal/{code}`, sin necesidad de ir a buscar el código a Filament. Ver sección "Migración..." en `docs/runbook-terminal-revocacion-reprovision.md`.

**Logo de empresa (`CompanyLogoThumbnailService`):** mismo patrón offline-safe que `EmployeePhotoThumbnailService` (thumbnail pre-generado en `Company.logo_thumbnail` vía `CompanyObserver`, viaja como data URI embebido — en `window.terminalData` para el terminal, en el payload de heartbeat para el celular) pero con dos diferencias por tratarse de un logo y no una foto de rostro: **ajusta preservando la proporción** (bounding box 240×80px, nunca recorta a cuadrado) y **exporta en PNG** (no JPEG, para conservar transparencia). SVG no se procesa — GD no puede rasterizarlo, degrada a sin logo.

**UI del modo móvil (`resources/js/attendances/mark.js`):**
- Header persistente con sucursal/empresa/logo (`#headerLocation`, `#headerLogo`), poblado por `refreshOwnEmployeeUi()` desde el empleado cacheado (heartbeat). Mismo patrón en `terminal.js` (`#terminalHeaderLocation`/`#terminalHeaderLogo`, poblado server-side vía `window.terminalData` ya que el terminal es fijo por sucursal).
- Splash personalizado: saludo con nombre + tarjeta de estado ("Hoy: Entrada · HH:MM"). **Gotcha corregido:** `returnToSplash()` (se ejecuta al cerrar el modal de éxito tras marcar) debe volver a llamar `refreshOwnEmployeeUi()` explícitamente — sin eso la tarjeta de estado queda con el valor de antes de marcar hasta recargar la página, porque `refreshOwnEmployeeUi` solo se disparaba en la carga inicial.
- Botón "Pausar cámara" junto a Sincronizar/Desvincular — permite usar la interfaz (ver "Mis marcaciones", sincronizar) sin activar la identificación facial por accidente. Auto-resume a los 2 minutos por seguridad.
- Modal "Mis marcaciones" — lista de eventos de hoy del propio empleado, consulta `getOwnStatus()` en el momento (no cachea una lista desactualizada offline; sin red muestra aviso en vez de una lista parcial).
- Toggle de tema oscuro/claro (`#btnThemeToggle`, `localStorage` key `mark-theme` en el celular vs. su propia key en el terminal — ambos mismo patrón, no comparten estado entre sí a propósito).
- Título de pestaña dinámico: `{empresa} — Marcación Facial` (celular) / `{sucursal} — {empresa}` (terminal) — se resuelve client-side en el celular porque la identidad del empleado recién se conoce vía IndexedDB/Sanctum, no server-side.

**Notificaciones por email:** `MobileDeviceLinkedNotification`/`MobileDeviceRelinkedNotification`/`TerminalProvisionedNotification` tienen canal `mail` además de `database` — llegan a todos los `User::all()`.

**`notify()` nunca debe invalidar una operación ya persistida:** `Terminal::claimSanctumToken()` y `Employee::claimMobileToken()` envuelven la llamada a `User::all()->each->notify(...)` en `try/catch` con `Log::warning()` en el catch. Un fallo del mailer (SMTP caído, credenciales vencidas, etc.) no debe hacer que el endpoint responda 500 y deje sin token a un dispositivo que ya fue provisionado en la base de datos — el dato crítico (token, vínculo) ya está guardado antes de notificar; la notificación es best-effort.

**Estado offline cruzado entre dispositivos (IndexedDB):** antes de confiar en el token cacheado localmente, `terminal.js`/`mark.js` comparan el `terminal_id`/`terminal_code` (o el id del empleado, en el celular) guardado en IndexedDB contra el actual (`window.terminalData` / dato server-side). Si no coinciden, se asume que el navegador tiene el estado de OTRO terminal/dispositivo (mismo navegador reutilizado para abrir dos enlaces de configuración distintos) y se limpia todo el estado local (`clearTerminalState()` en `db.js`: `terminal_meta`, `employees_cache`, `outbound_events`, `employee_status_cache`) antes de sincronizar — de lo contrario el segundo dispositivo terminaría marcando asistencia con el token del primero.

**Gotcha de throttle compartido (recurrente — revisar en cualquier ruta pública nueva):** `Illuminate\Routing\Middleware\ThrottleRequests::resolveRequestSignature()` genera la clave del rate limit solo con dominio+IP, **sin distinguir ruta ni parámetros del middleware** (`maxAttempts`/`decayMinutes`). Dos rutas públicas distintas con `throttle:X,Y` sin prefijo comparten el mismo bucket por IP — agotar el límite de una bloquea también a la otra. **Siempre** usar un tercer parámetro de prefijo único: `throttle:10,1,mi-prefijo-unico`. Ya corregido en `terminal.setup` (`terminal-setup`), `registro-facial` (`face-enrollment`) y `vincular-dispositivo` (`device-link-show`/`device-link-minute`/`device-link-day`) — todas las rutas públicas actuales del proyecto ya tienen prefijo explícito.

### Service Layer
Business logic lives in `app/Services/`. Each domain has a `*Service` for orchestration and a `*Calculator` for isolated math. PDF generation is handled by dedicated generator classes in the same directory.

### Observers
`app/Observers/` — `AttendanceDayObserver` recalculates daily totals on event changes; `AttendanceEventObserver` validates timestamps; `EmployeeObserver` handles lifecycle hooks.

### Admin Panel (Filament)
- Resources: `app/Filament/Resources/` — one Resource per Model
- Pages: `app/Filament/Pages/` — `ManageGeneralSettings`, `ManagePayrollSettings`
- Widgets: `app/Filament/Widgets/` — dashboard stats, attendance today, expiring contracts

#### Convenciones generales de RelationManagers

Las siguientes reglas aplican a **todos** los RelationManagers del proyecto, independientemente del recurso al que pertenezcan.

**`isReadOnly()`**
- Siempre declarar explícitamente `public function isReadOnly(): bool { return false; }` para que Filament no lo infiera del modo de la página padre (puede activarse en `ViewRecord`).

**`form()`**
- `->columns(1)` en el root form; los grupos de campos van en secciones o grids internos.
- Excepción aceptada: formularios con exactamente 2 campos sin agrupación lógica pueden usar `->columns(2)`.

**`table()` — layout mínimo**
- Siempre incluir `->emptyStateHeading()`, `->emptyStateDescription()` y `->emptyStateIcon()`.
- Siempre incluir `->defaultSort()` cuando hay un orden natural (ej. `'name'`, `'is_primary' desc`).
- Siempre incluir `->paginationPageOptions([10, 25, 50, 100])` — nunca la opción "Todos".

**`->bulkActions([])`**
- Por defecto los RMs no tienen bulk actions: usar `->bulkActions([])`.
- Solo agregar `DeleteBulkAction` si el modelo no tiene dependencias FK que lo hagan peligroso.
- Si se agrega `DeleteBulkAction`, validar con `->before()` o `->action()` override según la necesidad.

**Acciones de estado (domain actions)**
- Domain actions (`mark_primary`, `toggle_status`, `approve`, etc.) van en `->actions([])`.
- Siempre incluir `->requiresConfirmation()` + `->modalHeading()` + `->modalSubmitActionLabel('Sí, [verbo]')`.
- `EditAction` / `DeleteAction` en la misma lista de acciones, después de las domain actions.

**`placeholder('-')` → usar texto descriptivo**
- No usar `->placeholder('-')` — usar texto significativo: `->placeholder('Sin ID')`, `->placeholder('Sin teléfono')`, etc.

**RMs de solo lectura** (sin CRUD propio, el modelo tiene su propio Resource)
- `isReadOnly(): true`
- `->recordUrl()` apuntando al `ViewRecord` del Resource correspondiente
- `->searchable(query: ...)` override cuando la búsqueda involucra relaciones
- `->actions([])` y `->bulkActions([])` — ninguna acción de fila (el link al registro es suficiente)
- Export Excel como `headerAction` si hay volumen significativo

#### Convenciones de RelationManagers en EmployeeResource

`app/Filament/Resources/EmployeeResource/RelationManagers/` — cada RM sigue esta estructura:

**`form()`**
- Layout con `->columns(1)` en el root; cada grupo lógico es un `Section::make(...)->compact()->icon(...)->columns(N)`
- Secciones estándar en `ContractsRelationManager`:
  - *Contrato* (3 cols): `[type ── span 2 ──] [work_modality]` / `[start_date] [end_date*] [trial_days]`
  - *Remuneración* (2 cols): `[salary_type] [salary]` / `[payment_method] [payroll_type]` / `[advance_percent]` (solo visible si `salary_type === 'mensual'`)
  - *Cargo* (2 cols): `[department_id] [position_id]`
  - `Textarea notes` al root sin section, `->columnSpanFull()`
- Select encadenado `department_id → position_id`: department con `->live()->afterStateUpdated(fn(Set $set) => $set('position_id', null))`; position filtra opciones por `$get('department_id')`
- Ambos selects tienen `->createOptionForm()` + `->createOptionUsing()` para crear inline; el select de departamento en el form de posición va `->disabled()->dehydrated()`

**`table()`**
- Columnas: `type` (badge), `start_date` / `end_date` (toggleable hidden), `position.name`, `status` (badge)
- Acciones de fila:
  - `generate_pdf` → `->url(route(...))->openUrlInNewTab()` (nunca `->action()` para binarios)
  - `upload_signed` → acción con `->form([FileUpload...])` para subir PDF firmado
  - `download_signed` → `->action(fn() => response()->download(...))` — válido acá porque es un archivo ya en disco (no generado en tiempo real)
  - `ActionGroup` con `EditAction` (solo `status === active`) + `DeleteAction` (limpia `document_path` del disco antes)

**`headerActions()`**
- `CreateAction` con `->before()` para validar reglas de negocio (ej: no crear si ya hay contrato activo) — usar `$action->halt()` para cancelar con notificación de error
- `->mutateFormDataUsing()` para inyectar campos del sistema (`created_by_id`, limpiar `end_date` si indefinido)

#### Convenciones de Pages en Resources Filament

Cada Resource tiene sus Pages en `app/Filament/Resources/{Resource}Resource/Pages/`. Seguir estas convenciones:

**`ListRecords` (index)**
- `getHeaderActions()`: acción export Excel (con confirmación modal) **antes** de `CreateAction`
- El export usa `Action::make('export_excel')` con `->requiresConfirmation()`, dispara `Notification` de éxito y retorna `Excel::download(new FooExport(), 'foo_' . now()->format('Y_m_d_H_i_s') . '.xlsx')`
- Tabs opcionales (`getTabs()`) para filtrar por estado; calcular todos los counts en **una sola query GROUP BY** y cachear en propiedad `?array $fooCounts` — nunca hacer un `COUNT` separado por tab:
  ```php
  protected ?array $absenceCounts = null;

  protected function getAbsenceCounts(): array
  {
      if ($this->absenceCounts === null) {
          $counts = Absence::query()
              ->selectRaw('status, COUNT(*) as total')
              ->groupBy('status')
              ->pluck('total', 'status')
              ->toArray();

          $this->absenceCounts = [
              'all'         => array_sum($counts),
              'pending'     => $counts['pending'] ?? 0,
              'justified'   => $counts['justified'] ?? 0,
              'unjustified' => $counts['unjustified'] ?? 0,
          ];
      }
      return $this->absenceCounts;
  }
  ```
  `getTabs()` llama a `$this->getAbsenceCounts()` para leer los valores — 1 query por ciclo Livewire en lugar de N

**`CreateRecord`**
- `mutateFormDataBeforeCreate()`: capitalizar `name` con `preg_replace_callback('/(?:^|\s)\S/u', fn($m) => mb_strtoupper($m[0], 'UTF-8'), $data['name'])`
- `getCreatedNotification()`: retornar `Notification::make()->success()->title('...')->body('...')` — **NO llamar `->send()`**, Filament lo llama automáticamente

**`EditRecord`**
- `getHeaderActions()`: `[ViewAction::make()->icon('heroicon-o-eye')->color('primary'), DeleteAction::make()->label('Eliminar')->icon('heroicon-o-trash')->color('danger')->modalHeading(...)->modalDescription(...)->modalSubmitActionLabel('Sí, eliminar')->successNotificationTitle(...)->successRedirectUrl(index)]`
- `mutateFormDataBeforeSave()`: misma capitalización que en Create
- `getRedirectUrl()`: redirigir a `view` del record
- `getSavedNotification()`: retornar `Notification::make()->success()->title('...')->body('...')` — **NO llamar `->send()`**, Filament lo llama automáticamente

**`ViewRecord`**
- `getHeaderActions()`: solo `EditAction::make()->icon('heroicon-o-pencil-square')`
- **No agregar `DeleteAction` en `ViewRecord`** para módulos financieros (Préstamos, Adelantos) — la eliminación de registros financieros no debe hacerse desde la vista de detalle.

**Acciones en tabla (`->actions([])`)**
- No agregar `ViewAction` ni `EditAction` en las filas de la tabla: el clic sobre el registro ya navega a `ViewRecord` por defecto, y el usuario accede a edición desde el `EditAction` en el encabezado de `ViewRecord`
- Solo agregar acciones de fila para operaciones específicas del dominio (ej: `view_map`, `download`, `approve`)

**`->tooltip()` en acciones similares dentro de un `ActionGroup`**
Cuando un `ActionGroup` agrupa varias acciones con propósito parecido, usar `->tooltip()` para que el usuario entienda cuándo usar cada una sin tener que abrir el modal:

```php
ActionGroup::make([
    Action::make('register_attendance')
        ->tooltip('El empleado SÍ estuvo presente pero no marcó — crea marcaciones y justifica automáticamente'),
    Action::make('justify')
        ->tooltip('El empleado NO estuvo presente pero tiene razón válida — vincula un permiso aprobado, sin crear marcaciones'),
    Action::make('mark_unjustified')
        ->tooltip('El empleado faltó sin justificación válida — genera deducción salarial automática'),
])
```

### Public Routes (no auth)
Kiosk/terminal marking and face enrollment run on public routes, served by their own JS entry points:
- `resources/js/attendances/mark.js` — facial clock-in
- `resources/js/attendances/terminal.js` — shared kiosk terminal
- `resources/js/enrollments/capture-face.js` — employee self-enrollment

### Status Enums
- Employee: `active`, `inactive`, `draft`, `suspended`
- Contract: `draft` → `active` → `suspended` / `expired` / `terminated` / `renewed` (distinto del de Employee — no confundir; "por vencer" no es un estado, ver Módulo de Contratos)
- Payroll: `draft` → `processing` → `approved` → `paid`
- Loans: `pending` → `approved` → `paid` / `rejected` / `cancelled`
- Advances: `pending` → `approved` → `disbursed` → `paid` / `rejected` / `cancelled`
- DisbursementBatch: `pending` → `confirmed` / `partially_confirmed` / `cancelled`
- Merchandise withdrawals: `pending` → `approved` → `paid` / `cancelled`
- EmployeeLeave: `pending` → `approved` / `rejected`
- Liquidación: `draft` → `calculated` → `closed`
- Aguinaldo (item): `pending` → `paid`
- AguinaldoPeriod: `draft` → `processing` → `closed`
- Warnings: sin ciclo de vida (registro documental permanente)
- EmployeeDevice: sin columna de estado real — `status` es un accessor virtual (`active`/`unlinked`) derivado de `unlinked_at IS NULL`

### Key Config Files
- `config/payroll.php` — vacation tiers, payroll rules
- `config/attendance.php` — `ABSENCE_THRESHOLD_MINUTES`, face recognition settings
- `app/Settings/` — runtime settings via Filament Settings plugin (`GeneralSettings`, `PayrollSettings`)

### Convenciones de Validación de Campos

**Teléfonos Paraguay**
- Guardar **con `0` inicial**, sin prefijo `+595`, sin espacios ni guiones
- `->maxLength(10)->regex('/^0\d{8,9}$/')` — cubre móviles (`09XXXXXXXX`, 10 dígitos) y fijos (`021XXXXXX` / `0XXXXXXXX`, 9 dígitos)
- `->validationMessages(['regex' => 'Ingrese un número válido de Paraguay: móvil (09XXXXXXXX) o fijo (021XXXXXX / 0XXXXXXXX).'])`
- `->helperText('Número sin espacios ni guiones. Ej: 0981123456')`
- No usar `->prefix('+595')` ni `->minLength()`

**RUC**
- Formato: número base + guion + dígito verificador. Ej: `80012345-6` o `1234567-1`
- `->maxLength(20)->regex('/^\d{1,8}-\d$/')`

**CI (Cédula de Identidad)**
- Solo dígitos, sin puntos ni guiones, 1–8 dígitos
- `->integer()->minValue(1)->maxValue(99999999)`

**Número Patronal IPS**
- Solo dígitos, hasta 8 dígitos
- `->integer()->minValue(1)->maxValue(99999999)`

### Convenciones de Documentación

Todos los archivos del proyecto deben tener sus clases, métodos y propiedades documentados con **PHPDoc** (PHP) o **JSDoc** (JS). Esto aplica a:

- **PHP:** Resources, Pages, RelationManagers, Models, Services, Observers, Exports, Controllers, Rules, Settings, Providers, Commands
- **JS/Vue:** archivos en `resources/js/` (funciones, componentes, props, eventos)
- **Blade:** comentarios `{{-- Descripción de la sección --}}` en bloques relevantes
- **CSS:** comentarios de sección en archivos de estilos

**PHP — PHPDoc mínimo por tipo:**
```php
// Clase
/** Gestiona la exportación de sucursales a Excel. */
class BranchesExport { ... }

// Método (siempre con @param y @return)
/**
 * Retorna los encabezados de columna para el archivo Excel.
 *
 * @return array<int, string>
 */
public function headings(): array { ... }

/**
 * Capitaliza el nombre y limpia el teléfono antes de crear el registro.
 *
 * @param  array<string, mixed> $data
 * @return array<string, mixed>
 */
protected function mutateFormDataBeforeCreate(array $data): array { ... }

// Propiedad
/** @var int|null ID de la empresa para filtrar sucursales. */
protected ?int $companyId;
```

**JS — JSDoc mínimo:**
```js
/**
 * Inicializa el componente de captura facial.
 * @param {HTMLElement} container - Contenedor del video
 */
function initCapture(container) { ... }
```

No agregar comentarios redundantes que repitan lo que el nombre ya dice. El objetivo es explicar el **propósito** o comportamiento no obvio.

### Coordenadas GPS (sucursales)

- Almacenar como JSON `{lat, lng}` en columna `coordinates` con cast `'coordinates' => 'array'` en el modelo
- Usar el campo `Map` de `cheesegrits/filament-google-maps` (v4.0.2, compatible PHP 8.2)
- Configuración estándar del campo:
  ```php
  Map::make('coordinates')
      ->defaultLocation([-25.2867, -57.6478]) // Asunción, Paraguay
      ->draggable()->clickable()
      ->autocomplete('address')->autocompleteReverse(true)
      ->reverseGeocode(['address' => '%n %S', 'city' => '%L'])
      ->geolocate()->height('400px')
  ```
- El campo `city` debe ser `->readOnly()` — se completa automáticamente via `reverseGeocode`
- Para mostrar en Google Maps desde una action: `sprintf('https://www.google.com/maps?q=%s,%s', $record->coordinates['lat'], $record->coordinates['lng'])` con `->visible(fn($r) => isset($r->coordinates['lat'], $r->coordinates['lng']))`

### Colores semánticos en Filament

- Usar `'gray'` en lugar de `'secondary'` — `'secondary'` no es un color semántico válido en Filament 3
- Colores válidos: `'primary'`, `'success'`, `'warning'`, `'danger'`, `'info'`, `'gray'`
- También inválidos: `'pink'`, `'blue'`, `'red'`, `'green'` — solo los 6 de arriba

### Estados booleanos en tablas — badge en lugar de IconColumn

No usar `IconColumn::boolean()` para columnas de estado activo/inactivo — el ícono ✅/❌ es ambiguo sin texto. Usar `TextColumn` con badge y label explícito:

```php
// ❌ Ambiguo — ¿qué significa el ícono?
IconColumn::make('is_active')->boolean(),

// ✅ Autoexplicativo
TextColumn::make('is_active')
    ->label('Estado')
    ->formatStateUsing(fn ($state) => $state ? 'Activa' : 'Inactiva')
    ->badge()
    ->color(fn ($state) => $state ? 'success' : 'danger'),
```

Reservar `IconColumn::boolean()` para columnas donde el contexto hace el valor obvio (ej: columna "¿Tiene foto?" en una tabla de documentos).

### Evitar hardcoding de labels, colores y opciones

Los labels, colores y opciones de campos enum/select deben centralizarse en el modelo correspondiente, nunca hardcodearse en Resources, Pages o RelationManagers. Esto facilita el mantenimiento: si se agrega un nuevo valor, se toca un solo lugar.

**Patrón en el modelo:**
```php
public static function getShiftTypeOptions(): array  // para Select en formularios (puede incluir descripción)
{
    return ['diurno' => 'Diurno (06:00 - 20:00)', ...];
}

public static function getShiftTypeLabels(): array   // para badges/columnas (label corto)
{
    return ['diurno' => 'Diurno', 'nocturno' => 'Nocturno', 'mixto' => 'Mixto'];
}

public static function getShiftTypeColors(): array   // para colores de badges
{
    return ['diurno' => 'success', 'nocturno' => 'info', 'mixto' => 'warning'];
}
```

**Uso en Resource/Page:**
```php
->formatStateUsing(fn($state) => Schedule::getShiftTypeLabels()[$state] ?? $state)
->color(fn($state) => Schedule::getShiftTypeColors()[$state] ?? 'gray')
```

**Concordancia de género gramatical:** los labels deben concordar con el género que el resto del proyecto usa para esa entidad, no con el género "por defecto" de la palabra en español. Ej: `Terminal::getStatusOptions()` decía `'active' => 'Activa'` (femenino, como si "la terminal"), pero todo el código y la documentación del proyecto se refieren a "**el** terminal" (masculino, como el dispositivo/kiosko) — el label correcto es `'Activo'`. Revisar cómo se refiere el resto del código/UI a la entidad antes de fijar el género del label, no asumirlo por la palabra sola.

### Descargas de archivos desde acciones Filament

Las acciones Filament corren via Livewire (AJAX) y **no pueden retornar respuestas binarias** desde `->action()` — causa `Malformed UTF-8` al serializar a JSON.

**Patrón correcto para PDFs y descargas:**
1. Crear una ruta autenticada en `routes/web.php` dentro del grupo `auth`
2. Crear (o agregar a) un controller en `app/Http/Controllers/`
3. El generator devuelve `response($pdf->output(), 200, ['Content-Type' => 'application/pdf', ...])`
4. La action usa `->url(fn() => route('foo.bar', $record))->openUrlInNewTab()`

**PDFs on-demand vs almacenados:**
- On-demand (ej. legajo): `response()` directo, sin `Storage::put()` — no hay necesidad de persistirlo
- Almacenados (ej. recibo de nómina): `Storage::disk('public')->put($fileName, $pdf->output())` y se guarda la ruta en BD para re-descargar después

**`inline` vs `attachment` en Content-Disposition:**
- Usar `inline` cuando la action tiene `->openUrlInNewTab()` — el navegador renderiza el PDF en la pestaña
- Usar `attachment` solo cuando se quiere forzar la descarga al disco (sin abrir en el navegador)
- Regla general del proyecto: **los PDFs se abren en nueva pestaña** (`inline`)

**`response()->file()` y caché del navegador**
`response()->file()` no agrega headers no-cache por defecto. Si el archivo en disco puede cambiar (PDF regenerado), el navegador puede servir una versión cacheada. Siempre agregar estos headers cuando el archivo es mutable:

```php
return response()->file($path, [
    'Content-Type'  => 'application/pdf',
    'Cache-Control' => 'no-store, no-cache, must-revalidate',
    'Pragma'        => 'no-cache',
]);
```

**`$pdf->stream()` y caché del navegador**
`$pdf->stream()` tampoco agrega headers no-cache por defecto. El mismo principio aplica — capturar el response y agregar los headers antes de retornarlo:

```php
$response = $pdf->stream("nombre_archivo.pdf");
$response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
$response->headers->set('Pragma', 'no-cache');
return $response;
```

Aplica siempre que el PDF se genera en tiempo real a partir de datos que pueden cambiar (plantillas editables, recibos regenerados, etc.).

**`$this->js("window.open(url, '_blank')")` para URLs dinámicas post-modal**
Cuando la URL del PDF depende de datos del formulario del modal (ej. selección de modo), no se puede usar `->url()->openUrlInNewTab()` (que es estático). En su lugar:

```php
->action(function (array $data) {
    $url = route('foo.download', ['record' => $this->record, 'mode' => $data['mode']]);
    $this->js("window.open('{$url}', '_blank')");
})
```

Funciona tanto en Pages (`ViewRecord`) como en RelationManagers — ambos son componentes Livewire.

### Nombres de archivo en FileUpload

Los archivos subidos por el usuario deben tener nombres legibles e identificables, no el hash aleatorio que genera Filament por defecto. Usar siempre `->getUploadedFileNameForStorageUsing()` con el patrón:

```
{entidad}_{id}_{YYYY-MM-DD_HH-mm-ss}.{ext}
```

Ejemplos del proyecto:
- Comprobante de adelanto: `comprobante_adelanto_42_2026-05-14_10-30-00.jpg`
- Comprobante de lote bancario: `confirmacion_lote_7_2026-05-14_10-30-00.pdf`

**Implementación:**
```php
->getUploadedFileNameForStorageUsing(function ($file) use ($record): string {
    $ext = $file->getClientOriginalExtension();
    return 'comprobante_adelanto_'.$record->id.'_'.now()->format('Y-m-d_H-i-s').'.'.$ext;
})
```

En `ViewRecord`/`ViewAdvance` donde se accede vía `$this->record`, omitir el `use ($record)` y usar `$this->record->id` directamente.

El timestamp garantiza unicidad si el mismo registro es re-subido múltiples veces (ej. revertir y volver a marcar entregado). No incluir nombre del empleado ni texto libre — pueden tener caracteres especiales.

### Select dependiente (parent → child)

Patrón estándar para campos encadenados (ej. Departamento → Cargo):

```php
// 1. Parent primero, con ->live() y limpiar el hijo al cambiar
Select::make('department_id')
    ->live()
    ->afterStateUpdated(fn(Set $set) => $set('position_id', null))
    ...

// 2. Hijo filtra por el parent; si no hay parent seleccionado, muestra todos
Select::make('position_id')
    ->options(function (Get $get) {
        $deptId = $get('department_id');
        return $deptId
            ? Position::where('department_id', $deptId)->orderBy('name')->pluck('name', 'id')->toArray()
            : Position::getOptionsWithDepartment();
    })
    ...
```

### `createOptionForm` en Select

Permite crear registros inline desde un campo Select usando `->createOptionForm()` + `->createOptionUsing()`.

**Acceso al record padre en RelationManagers:** usar `$this->getOwnerRecord()` directamente en los closures (PHP enlaza `$this` automáticamente en closures de métodos de clase).

```php
->createOptionUsing(function (array $data) {
    return Department::create([
        'name'       => $data['name'],
        'company_id' => $this->getOwnerRecord()->branch?->company_id, // inyectado, no expuesto en el form
    ])->id;
})
```

**Regla `unique` cuando el campo de scope no está en el form** (ej. `company_id` no es un input visible):

```php
->unique(
    table: Department::class,
    column: 'name',
    modifyRuleUsing: fn($rule) => $rule->where('company_id', $this->getOwnerRecord()->branch?->company_id)
)
->validationMessages(['unique' => 'Ya existe un departamento con ese nombre en esta empresa.'])
```

**Mensajes de validación personalizados:** `->validationMessages(['rule' => 'mensaje'])` — la clave es el nombre de la regla Laravel (`unique`, `required`, `max`, `regex`, etc.).

### Campos virtuales en formularios de creación (`->dehydrated(false)`)

Para campos que no mapean a columnas del modelo pero disparan lógica post-creación (ej. contrato inicial, horario inicial), usar `->dehydrated(false)` para que Filament no intente guardarlos en la BD.

**Gotcha crítico:** `$this->form->getState()` **excluye** los campos con `->dehydrated(false)`. Para leerlos en `afterCreate()` o `afterSave()`, usar `$this->data` (propiedad Livewire con el estado raw completo del form):

```php
// ❌ No funciona para campos ->dehydrated(false)
$state = $this->form->getState();

// ✅ Correcto
$state = $this->data;
```

**Patrón completo para lógica post-creación con campos virtuales:**

```php
// En el form: prefijo para evitar colisiones con columnas reales
Select::make('initial_schedule_id')
    ->dehydrated(false)
    ->visibleOn('create')
    ...

// En afterCreate(): usar $this->data
protected function afterCreate(): void
{
    $state = $this->data;

    if (filled($state['initial_schedule_id'] ?? null)) {
        ScheduleAssignmentService::assign($this->record, Schedule::find($state['initial_schedule_id']), Carbon::today());
    }
}
```

**Convención de prefijos para campos virtuales:** usar `ic_` para "initial contract", `initial_` para otros — así es obvio que no son columnas reales y evitan colisiones con campos del modelo.

### Secciones opcionales en `CreateRecord`

Para secciones que solo tienen sentido en creación (ej. contrato inicial, horario inicial):
- `->visibleOn('create')` en la Section — desaparece en edit sin lógica extra
- `->collapsible()->collapsed()` — no abruma al usuario; solo la expande quien quiere usarla
- La lógica de creación va en `afterCreate()` con una condición mínima: si los campos clave están vacíos, no hacer nada (el usuario no expandió la sección)

```php
// Condición mínima — no crear si campos esenciales están vacíos
if (filled($state['ic_salary'] ?? null) && filled($state['ic_position_id'] ?? null)) {
    Contract::create([...]);
}
```

### Filament Forms — convenciones adicionales

**`modalContent()` se evalúa al ABRIR el modal, no al confirmar**
Si `->modalContent()` ejecuta un side-effect (ej. generar y persistir un nuevo token), ese efecto corre **cada vez que el modal se abre** — incluyendo si el usuario lo cierra sin confirmar, o si Livewire vuelve a renderizar el componente. No se puede combinar `->requiresConfirmation()` + `->modalContent()` con side-effects esperando que el efecto ocurra recién al enviar el formulario del modal — eso rompe la semántica de "confirmar antes de ejecutar". Si la acción necesita mostrar el resultado de un side-effect (ej. el QR de un token recién generado) **y** requerir confirmación cuando ya existe un estado previo, separar en dos `Action` distintas con visibilidad mutuamente excluyente: una sin confirmación que genera-y-muestra (caso "no hay nada aún"), otra con `->requiresConfirmation()` que genera en `->action()` (evaluado al confirmar) y notifica en vez de mostrar el contenido inline (caso "ya existe algo, reemplazar"). Ver `TerminalResource::view_setup_link` / `generate_setup_link` / `regenerate_setup_link`.

**`->live()` en lugar de `->reactive()`**
`->reactive()` está deprecado en Filament 3. Siempre usar `->live()`.

**`Section::columns()` no acepta Closure**
El método `->columns()` en `Section` (form e infolist) solo acepta `int|array|null`. Para layouts condicionales por operación (create vs edit), la única alternativa sin duplicar campos es aceptar un layout fijo o usar `Grid` interno.

**Grid en Infolists: namespace diferente al de Forms**
En un `infolist()`, usar `Filament\Infolists\Components\Grid` — no `Filament\Forms\Components\Grid`. Convención: importar como alias para evitar conflictos:
```php
use Filament\Infolists\Components\Grid as InfoGrid;
```

**`modalSubmitActionLabel` obligatorio en acciones con confirmación**
Toda acción con `->requiresConfirmation()` — tanto en filas como en `BulkAction` — debe tener `->modalSubmitActionLabel('Sí, [verbo]')`. El botón genérico "OK" de Filament no es suficiente.

**`->mountUsing()` para inicializar el estado del form antes de abrir un modal**
`->before()` corre **después** de que el formulario del modal es enviado — no sirve para prevenir que el modal abra. Para ejecutar lógica pre-modal (ej: pre-calcular datos disponibles), usar `->mountUsing()`:

```php
Action::make('add_items')
    ->mountUsing(function (\Filament\Forms\Form $form, Action $action) use ($record) {
        if (! Model::query()->where(...)->exists()) {
            Notification::make()->warning()->title('Sin registros disponibles')->send();
            $action->halt();
            return;
        }
        $form->fill(); // obligatorio al override de mountUsing
    })
    ->form([...])
    ->action(function (array $data) { ... })
```

Regla: siempre llamar `$form->fill()` en el path normal — Filament lo necesita para inicializar el formulario. Omitirlo resulta en un form vacío.

**Bug conocido: `$action->halt()` en `mountUsing` no es confiable en clicks repetidos**
En Livewire 3 + Filament 3, al llamar `$action->halt()` dentro de `mountUsing`, el primer click funciona correctamente (el modal no abre). Sin embargo, en el segundo click Livewire reutiliza el estado cacheado de la acción y el modal **abre igual**, ignorando el `mountUsing`.

**Solución**: no usar `halt()` en `mountUsing`. En cambio, siempre abrir el modal y mostrar contenido diferente según el estado. Usar `Hidden` para trasladar el resultado de la query (ejecutada una sola vez en `mountUsing`) a los closures de visibilidad de los campos:

```php
Action::make('justify')
    ->mountUsing(function (Form $form, Model $record) {
        $hasOptions = RelatedModel::where('...')->exists(); // 1 sola query
        $form->fill(['has_options' => $hasOptions]);
    })
    ->form([
        Hidden::make('has_options'),

        Placeholder::make('no_options_notice')
            ->label('Sin opciones disponibles')
            ->content('No hay registros disponibles. Cree uno primero.')
            ->visible(fn (Get $get) => ! $get('has_options')),

        Select::make('related_id')
            ->options(fn (Model $record) => RelatedModel::where('...')->pluck('name', 'id'))
            ->required(fn (Get $get) => (bool) $get('has_options'))
            ->visible(fn (Get $get) => (bool) $get('has_options')),
    ])
    ->action(function (Model $record, array $data, Action $action) {
        // Validación defensiva adicional
        if (empty($data['related_id'])) {
            Notification::make()->warning()->title('Sin selección')->send();
            $action->halt();
            return;
        }
        // ...
    })
```

El `Hidden::make('has_options')` es necesario para que `Get $get` pueda leer el valor establecido por `$form->fill()`. Sin el campo en el schema, el valor no es accesible desde los closures.

**Edición parcial de campos `datetime` en modales — solo hora**
Cuando el modal de edición debe permitir cambiar únicamente la hora (no la fecha completa), usar `Hidden` para pasar la fecha como dato oculto de solo lectura, `Placeholder` para mostrarla, y `TimePicker` para capturar la hora. `mutateRecordDataUsing` descompone el valor original; `mutateFormDataUsing` lo recompone:

```php
EditAction::make()
    ->form([
        Hidden::make('_date'),
        Placeholder::make('fecha')
            ->label('Fecha')
            ->content(fn (Get $get) => $get('_date')
                ? Carbon::parse($get('_date'))->translatedFormat('l d/m/Y')
                : '—'),
        TimePicker::make('time')
            ->label('Hora')
            ->seconds(false)
            ->native(false)
            ->required(),
    ])
    ->mutateRecordDataUsing(fn (array $data) => array_merge($data, [
        'time'  => Carbon::parse($data['recorded_at'])->format('H:i'),
        '_date' => Carbon::parse($data['recorded_at'])->format('Y-m-d'),
    ]))
    ->mutateFormDataUsing(fn (array $data) => [
        'event_type'  => $data['event_type'],
        'recorded_at' => Carbon::parse($data['_date'].' '.$data['time']),
    ])
```

El prefijo `_` en `_date` indica campo virtual (no mapea a columna del modelo). Este patrón evita que el admin mueva un evento a otro día de forma accidental al editar.

**Advertencias no-bloqueantes en modales de confirmación**
`->before()` con `$action->halt()` es para bloqueos hard (el usuario no puede continuar). Para casos donde el usuario *puede* continuar pero conviene informarle (ej. hay empleados sin recibo al cerrar la planilla), agregar la advertencia dentro de `->modalDescription()` con closure — no bloquear:

```php
->modalDescription(function () {
    $base = 'Se cerrará la planilla con X recibos aprobados.';
    $missingCount = /* consulta */;
    if ($missingCount > 0) {
        $base .= " Atención: {$missingCount} empleado(s) activos no tienen recibo.";
    }
    return $base;
})
// ->before() solo para bloqueo real (draft/approved pendientes, etc.)
->before(function (Action $action) {
    if ($this->record->payrolls()->whereIn('status', ['draft', 'approved'])->exists()) {
        Notification::make()->danger()->title('No se puede cerrar')->send();
        $action->halt();
    }
})
```

**`->paginationPageOptions()` — nunca exponer la opción "Todos"**
Tablas con volumen potencialmente alto no deben ofrecer la opción de cargar todos los registros de un golpe — es la forma más fácil de causar un timeout. Definir siempre opciones numéricas explícitas:
```php
->paginationPageOptions([10, 25, 50, 100])
```

**BulkActions de cambio de estado deben filtrar antes de actualizar**
Nunca hacer `$records->each->update([...])` sin verificar el estado esperado. Siempre filtrar:
```php
->action(fn($records) => $records->each(
    fn($record) => $record->status === 'pending' && $record->update(['status' => 'approved'])
))
```
Para lógica más compleja, iterar con `foreach` y contar procesados/omitidos para reportar en la notificación.

### Iconos semánticos por entidad

Usar siempre estos iconos para representar cada entidad en columnas, badges, infolists y acciones:

| Entidad | Icono |
|---------|-------|
| Empresa | `heroicon-o-building-office-2` |
| Sucursal | `heroicon-o-building-storefront` |
| Departamento | `heroicon-o-building-library` |
| Cargo | `heroicon-o-briefcase` |

### Convenciones de PDFs (DomPDF / Blade)

Todos los PDFs en `resources/views/pdf/` siguen estas reglas de estilo base:

```css
@page {
    size: A4;
    margin: 0;          /* SIEMPRE margin: 0 en @page */
}

body {
    font-family: Arial, sans-serif;
    font-size: 11px;
    line-height: 1.5;
    padding: 15mm 20mm; /* El margen real va en body padding */
}
```

**Regla crítica:** `@page { margin: 0 }` + `body { padding: 15mm 20mm }`. Nunca poner margen en `@page` y padding en `body` a la vez — DomPDF los suma y duplica el margen efectivo.

**Encabezado de empresa estándar** (centrado, mismo en todos los PDFs):
```css
.company-header { text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #000; }
.company-logo   { max-height: 40px; max-width: 120px; margin-bottom: 8px; }
.company-name   { font-size: 14px; font-weight: bold; text-transform: uppercase; margin-bottom: 3px; }
.company-info   { font-size: 9px; }
```

**Título del documento:**
```css
.title    { text-align: center; font-size: 13px; font-weight: bold; text-transform: uppercase; margin: 20px 0 5px 0; }
.subtitle { text-align: center; font-size: 10px; margin-bottom: 20px; }
```

**Sección:**
```css
.section-title { font-weight: bold; font-size: 10px; text-transform: uppercase; padding: 5px 0; margin-bottom: 8px; border-bottom: 1px solid #000; }
```

**Firmas:**
```css
.signature-section { margin-top: 50px; display: table; width: 100%; }
.signature-item    { display: table-cell; width: 50%; text-align: center; padding: 0 25px; }
.signature-line    { border-top: 1px solid #000; margin-bottom: 5px; padding-top: 5px; }
.signature-label   { font-size: 10px; font-weight: bold; }
.signature-sublabel{ font-size: 9px; }
```

**Footer:**
```css
.footer { margin-top: 40px; text-align: center; font-size: 8px; border-top: 1px solid #ccc; padding-top: 10px; }
```

**Otras reglas:**
- Sin colores de acento — documentos en blanco/negro/gris (`#000`, `#ccc`, `#f5f5f5`)
- Labels/opciones de enums (estado, tipo) siempre desde métodos del modelo (`Model::getStatusLabel()`) — nunca arrays hardcodeados en el blade
- Campos casteados como `datetime` ya son instancias Carbon — no usar `Carbon::parse()` sobre ellos
- Relaciones via `activeContract.position` — nunca `employee->position` (campo legacy)
- PDFs compactos (ej. asistencia diaria): pueden usar `padding: 12mm 15mm` y `font-size: 10px` como excepción justificada
- Pseudo-selector `:last-child` no funciona en DomPDF — usar clase explícita (`.metric-last`, etc.)

**Layout multicolumna en DomPDF (ej. 2 copias side-by-side)**
Usar tabla HTML con `width: 50%` en cada `<td>`. La línea de corte vertical va como `border-right: 1px dashed #888` en el primer `<td>`. **No agregar `height` a la tabla** — DomPDF hace overflow de una fracción de punto y genera una página en blanco extra:

```html
<table style="width: 100%; border-collapse: collapse;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding: 7mm 9mm; border-right: 1px dashed #888;">
            {{-- COPIA EMPLEADO --}}
        </td>
        <td style="width: 50%; vertical-align: top; padding: 7mm 9mm;">
            {{-- COPIA EMPRESA --}}
        </td>
    </tr>
</table>
```

**Partials PDF para contenido repetido (`_nombre.blade.php`)**
Cuando el mismo bloque se renderiza N veces en un PDF (ej. 2 copias en landscape), extraerlo a un partial `resources/views/pdf/_nombre.blade.php` e incluirlo con `@include('pdf._nombre')`. Las variables del scope padre están disponibles automáticamente en el partial:

```blade
{{-- payroll.blade.php --}}
@foreach (['COPIA EMPLEADO', 'COPIA EMPRESA'] as $copyLabel)
    <td ...>
        @include('pdf._payroll-copy')   {{-- recibe $copyLabel y todas las vars del padre --}}
    </td>
@endforeach
```

### Páginas de reporte con tabla agregada (custom Page + InteractsWithTable)

Para reportes que necesitan filtros prominentes + tabla con datos agregados (ej. `AttendanceReport`, `EmployeeReport`, `MerchandiseReport`):

**Estructura:**
```php
class AttendanceReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.attendance-report';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->columns([...])
            ->filters([...], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->persistFiltersInSession();
    }

    private function buildQuery(): Builder { ... }
}
```

Vista mínima (`resources/views/filament/pages/attendance-report.blade.php`):
```blade
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
```

**`FiltersLayout::AboveContent`** — renderiza los filtros visibles sobre la tabla (sin drawer). Ideal para reportes donde el período/agrupación es el foco principal.

**Acceder a filtros activos desde header actions** (ej. para pasarlos a un export):
```php
private function resolveActiveFilters(): array
{
    $f = $this->tableFilters ?? [];
    return [
        $f['period']['from_date'] ?? null,           // Filter con form
        $f['company_id']['value'] ?? null,            // SelectFilter
    ];
}
```

#### Filtros en cascada en reportes (`Filter::make` + `updated()`)

Cuando las opciones de un filtro dependen del valor de otro (ej. Sucursal filtra por Empresa), **no usar `SelectFilter`** — su callback `->query()` puede omitirse cuando el filtro está inactivo y sus opciones no son reactivas. Usar siempre `Filter::make()` con `FormSelect` + `->live()`:

```php
Filter::make('branch_id')
    ->form([
        FormSelect::make('value')
            ->label('Sucursal')
            ->options(function () {
                $companyId = $this->tableFilters['company_id']['value'] ?? null;
                return Branch::when($companyId, fn ($q) => $q->where('company_id', $companyId))
                    ->orderBy('name')->pluck('name', 'id')->toArray();
            })
            ->searchable()
            ->placeholder('Todas')
            ->live(),   // dispara updated() al cambiar
    ])
    ->query(fn (Builder $query, array $data) => filled($data['value'])
        ? $query->where('employees.branch_id', (int) $data['value'])
        : $query
    )
    ->indicateUsing(fn (array $data): ?string => filled($data['value'])
        ? 'Sucursal: '.Branch::find($data['value'])?->name
        : null
    );
```

**Limpiar filtros hijos con `updated()`** — el hook de Livewire recibe el nombre completo de la propiedad que cambió. Asignar `''` (no `null`) para resetear el valor del filtro:

```php
public function updated(string $name): void
{
    // Empresa → limpia todo lo que depende de ella
    if ($name === 'tableFilters.company_id.value') {
        $this->tableFilters['period_id']['value'] = '';
        $this->tableFilters['branch_id']['value'] = '';
        $this->tableFilters['department_id']['value'] = '';
        $this->tableFilters['employee_id']['value'] = '';
    }
    // Sucursal o Departamento → limpia solo Empleado (son ejes paralelos)
    if ($name === 'tableFilters.branch_id.value' || $name === 'tableFilters.department_id.value') {
        $this->tableFilters['employee_id']['value'] = '';
    }
}
```

**Regla:** solo los filtros que tienen hijos necesitan `->live()`. Los filtros hoja (sin dependientes) no lo necesitan.

**Filtrar por relaciones complejas (ej. Departamento vía contrato activo)** — cuando el modelo intermedio no está en el JOIN base de la query, usar `whereExists` correlacionado:

```php
->query(fn (Builder $query, array $data) => filled($data['value'])
    ? $query->whereExists(fn ($sub) => $sub
        ->select(DB::raw(1))
        ->from('contracts')
        ->join('positions', 'positions.id', '=', 'contracts.position_id')
        ->whereColumn('contracts.employee_id', 'employees.id')  // correlación
        ->where('contracts.status', 'active')
        ->where('positions.department_id', (int) $data['value'])
    )
    : $query
)
```

**Guardia en `buildQuery()` para filtros obligatorios** — si el reporte no tiene sentido sin un filtro clave (ej. Planilla), poner la guardia en `buildQuery()`, **no** en el callback del filtro — `SelectFilter` puede saltarse su callback cuando está inactivo:

```php
private function buildQuery(): Builder
{
    $periodId = isset($this->tableFilters['period_id']['value'])
        && $this->tableFilters['period_id']['value'] !== ''
        ? (int) $this->tableFilters['period_id']['value']
        : null;

    $query = Payroll::query()->select([...])->join(...);

    if (! $periodId) {
        $query->whereRaw('1=0');   // sin planilla → tabla vacía
    }

    return $query;
}
```

#### Columnas seleccionables en PDF y Excel (reportes)

Para reportes con columnas opcionales, centralizar el catálogo en la clase Export:

```php
// En FooReportExport
public static function availableColumns(): array
{
    return ['employee_name' => 'Empleado', 'ci' => 'CI', ...];
}

public static function defaultColumns(): array
{
    return array_keys(static::availableColumns());
}
```

En la action del modal usar `CheckboxList` con las opciones de `availableColumns()`. El `map()` y `headings()` filtran con `array_intersect_key($all, array_flip($this->columns))`.

**Selector de orientación adaptativo (PDF)**

Combinar `CheckboxList` con `Radio` de orientación usando `->live()` + `->afterStateUpdated()`. El Radio se auto-ajusta según la cantidad de columnas seleccionadas vs. un umbral:

```php
$orientationThreshold = 8; // ≤8 cols → portrait, >8 → landscape

CheckboxList::make('columns')
    ->live()
    ->afterStateUpdated(function (Get $get, Set $set) use ($orientationThreshold) {
        $count = count($get('columns') ?? []);
        $set('orientation', $count <= $orientationThreshold ? 'portrait' : 'landscape');
    }),

Radio::make('orientation')
    ->options(['portrait' => 'Vertical', 'landscape' => 'Horizontal'])
    ->default(count($columnDefaults) > $orientationThreshold ? 'landscape' : 'portrait')
    ->inline()
    ->required(),
```

En el controller: `->setPaper('a4', $orientation)`. En el blade PDF: `@page { size: A4 {{ $orientation }}; margin: 0; }`.

#### Columnas y filtros condicionales por empresa/sucursal activa

Ocultar la columna (y el checkbox del export) cuando solo hay una empresa/sucursal activa — el dato es redundante:

```php
// Al construir opciones de export en getHeaderActions()
if (Company::active()->count() <= 1) {
    unset($columnOptions['company_name']);
    $columnDefaults = array_values(array_diff($columnDefaults, ['company_name']));
}
if (Branch::whereHas('company', fn ($q) => $q->active())->count() <= 1) {
    unset($columnOptions['branch_name']);
    $columnDefaults = array_values(array_diff($columnDefaults, ['branch_name']));
}

// En la columna de la tabla
TextColumn::make('branch_name')
    ->visible(fn () => Branch::whereHas('company', fn ($q) => $q->active())->count() > 1),

TextColumn::make('company_name')
    ->visible(fn () => Company::active()->count() > 1),
```

El SelectFilter de empresa también se agrega condicionalmente:
```php
if (Company::active()->count() > 1) {
    $filters[] = SelectFilter::make('company_id')->...;
}
```

#### `SelectFilter` con default + `persistFiltersInSession()`

`->default('active')` en un `SelectFilter` solo aplica cuando no hay sesión almacenada. Si la sesión ya tiene un valor `null` para el filtro (sesión anterior o filtro nunca configurado), el default no se aplica.

**Fix:** agregar `mount()` en la Page para inicializar el valor nulo con el default deseado. En Livewire 3, los `mountX()` de traits se ejecutan **antes** del `mount()` del componente, por lo que `$this->tableFilters` ya está cargado desde sesión cuando `mount()` corre:

```php
public function mount(): void
{
    // ??= asigna solo si es null; preserva 'inactive', 'suspended', etc.
    $this->tableFilters['status']['value'] ??= 'active';
}
```

Esto significa que si el usuario limpia explícitamente el filtro (session guarda null), al recargar la página el filtro vuelve a 'active'. Para reportes con un default intencional, este comportamiento es aceptable.

### Migraciones DDL en Laravel 12 — sin Doctrine DBAL

Laravel 12 eliminó la dependencia de Doctrine DBAL. Métodos como `getDoctrineSchemaManager()`, `listTableIndexes()` y similares ya no existen. Las operaciones DDL idempotentes deben hacerse con `try/catch` y guards de Schema:

**Agregar columna (idempotente):**
```php
if (! Schema::hasColumn('tabla', 'columna')) {
    Schema::table('tabla', function (Blueprint $table) {
        $table->foreignId('columna')->nullable()->constrained('otras_tablas');
    });
}
```

**Eliminar / agregar índice único (idempotente):**
```php
// ❌ No existe en Laravel 12
$sm = Schema::getConnection()->getDoctrineSchemaManager();
$indexes = $sm->listTableIndexes('tabla');

// ✅ Correcto — try/catch para idempotencia
try {
    Schema::table('tabla', function (Blueprint $table) {
        $table->dropUnique(['columna']);
    });
} catch (\Throwable $e) {
    // El índice ya fue eliminado en un deploy anterior parcial — continuar
}

try {
    Schema::table('tabla', function (Blueprint $table) {
        $table->unique(['col1', 'col2']);
    });
} catch (\Throwable $e) {
    // El índice ya existe — continuar
}
```

El `try/catch` es necesario también para idempotencia en caso de deploys parciales (migración que falló a mitad y dejó la BD en estado intermedio).

### Diferencias de fechas con Carbon 3 (Laravel 12)

Laravel 12 usa Carbon 3, donde `diffInYears()`, `diffInMonths()` y `diffInDays()` **retornan `float`**, no `int`. Siempre castear explícitamente:

```php
// ❌ En Carbon 3 retorna float: "72.659721891016 meses"
$months = $hire->diffInMonths(now());

// ✅ Correcto
$years  = (int) $hire->diffInYears(now());
$months = (int) $hire->diffInMonths(now());
$days   = (int) $hire->diffInDays(now());
```

**Gotcha con LEFT JOIN a contrato activo:** cuando un empleado no tiene contrato activo, la columna calculada `TIMESTAMPDIFF(YEAR, contracts.start_date, CURDATE()) AS years_of_service` retorna `NULL`. `(int) null === 0`, lo que hace que el branch "años" sea falso y se entre incorrectamente al branch "meses". Calcular siempre desde `hire_date` en PHP, no desde el alias SQL:

```php
// ❌ (int) null = 0 → entra a la rama de meses incorrectamente
$years = (int) $record->years_of_service;

// ✅ Calcular desde hire_date directamente
if (! $record->hire_date || $record->status !== 'active') {
    return '—';
}
$hire  = \Carbon\Carbon::parse($record->hire_date);
$years = (int) $hire->diffInYears(now());
if ($years >= 1) { return $years.' año'.($years !== 1 ? 's' : ''); }
$months = (int) $hire->diffInMonths(now());
if ($months >= 1) { return $months.' mes'.($months !== 1 ? 'es' : ''); }
$days = (int) $hire->diffInDays(now());
return $days.' día'.($days !== 1 ? 's' : '');
```

Aplicar el mismo patrón en la Export class (`map()`) y en el blade PDF.

### `whereDate()` no usa índices — usar `whereBetween` con rango explícito

`DATE(columna) = '...'` aplica una función sobre la columna y obliga a MySQL a hacer full table scan, ignorando cualquier índice en esa columna. Reemplazar siempre con un rango explícito:

```php
// ❌ No usa índice — full table scan
->whereDate('created_at', now()->toDateString())

// ✅ Sargable — puede usar el índice en created_at
->whereBetween('created_at', [Carbon::today(), Carbon::today()->endOfDay()])
```

Aplica a cualquier columna `datetime`/`timestamp` filtrada por fecha exacta. Incluye `getNavigationBadge()` y cualquier scope que filtre "registros de hoy".

### GROUP BY con MySQL ONLY_FULL_GROUP_BY

MySQL en modo estricto exige que **todas las columnas no agregadas del SELECT estén en el GROUP BY**, incluso si son funcionalmente dependientes de la PK.

```php
// ❌ Falla con: 'employees.first_name' isn't in GROUP BY
->groupBy('employees.id');

// ✅ Correcto — incluir todas las columnas no agregadas del SELECT
->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.ci', 'employees.branch_id');
```

Las columnas calculadas por subquery en el SELECT (`DB::raw('(SELECT ...) AS alias')`) **no** necesitan ir en GROUP BY.

### Tokens de un solo uso — evitar race conditions con `lockForUpdate()`

Cualquier flujo de "reclamar/consumir" un token de un solo uso (setup links, invitaciones, etc.) debe leer y anular el token dentro de la **misma transacción con lock**, no en pasos separados — de lo contrario dos requests casi simultáneos (doble click, reintento de red) pueden pasar ambos la validación antes de que el primero anule el token, emitiendo dos credenciales para un solo enlace.

```php
$terminal = DB::transaction(function () use ($code, $setupToken) {
    $terminal = Terminal::where('code', $code)->lockForUpdate()->first();

    if (! $terminal || ! $terminal->isSetupTokenValid($setupToken)) {
        return null;
    }

    $terminal->forceFill(['setup_token' => null, 'setup_token_expires_at' => null])->save();

    return $terminal;
});

if (! $terminal) {
    // token inválido, ya usado, o expirado
}
```

El `lockForUpdate()` serializa las transacciones concurrentes sobre esa fila — la segunda espera a que la primera confirme el `forceFill` y entonces ve el token ya nulo. Ver `TerminalSetupController::claim()`.

### Deployment a producción — Cliente Macro (Sedacosmetica)

**Servidor propio del cliente Macro (Sedacosmetica):** `sedvouco@bh7104` — CentOS, cPanel, PHP 8.2 via `/opt/cpanel/ea-php82/root/usr/bin/php`, Node 16 (sin RAM suficiente para Vite build). Esta sección aplica únicamente a la instalación de Macro; ver la sección siguiente para Bar777/Arca (infraestructura de TechForge, mecanismo distinto).

**Limitación Node 16:** Vite 6 + Rollup requieren Node ≥18 y ~512MB RAM para compilar. El servidor falla con `RangeError: WebAssembly.instantiate(): Out of memory`. **Solución permanente: buildear en local y subir assets via rsync.**

**Deploy manual (sin automatización posible):** el hosting de Macro (cPanel, recursos limitados) no permite disparar el deploy automáticamente. El antiguo mecanismo vía webhook (`public/deploy.php`, disparado por GitHub Actions en push a `main`) fue removido del repo (commit `0b1cd93`). Hoy el deploy se hace a mano por SSH, ejecutando en orden los mismos pasos que antes corría el webhook:
1. `artisan down`
2. `git pull origin main`
3. `composer install --no-dev --optimize-autoloader`
4. `artisan migrate --force`
5. `artisan optimize:clear && optimize && filament:optimize`
6. `artisan queue:restart`
7. `artisan up`

Al ser manual, no hay log ni rollback automático — si un paso falla, hay que diagnosticarlo y ejecutar `artisan up` a mano para restaurar el sitio antes de reintentar.

**Antes de deployar manualmente a Macro verificar:**
- Si hay nuevas variables de entorno (`.env`), agregarlas al servidor antes de correr el deploy
- Si hay cambios en assets frontend (Blade, JS, CSS), buildear en local y subir via rsync antes del deploy:
```bash
npm run build
rsync -avz --delete public/build/ sedvouco@bh7104:/ruta/nominapp/public/build/
```

**Variables de entorno requeridas en producción:**
- `GOOGLE_MAPS_API_KEY` — requerida por `cheesegrits/filament-google-maps` para el mapa de sucursales

**Scheduler configurado** (`crontab -l`):
```
* * * * * cd /ruta/nominapp && /opt/cpanel/ea-php82/root/usr/bin/php artisan schedule:run >> storage/logs/cron.log 2>&1
```
Tareas activas: `app:calculate-attendance` (23:00 diario), `attendance:check-missing` (cada 15min, 6am-8pm, lun-sáb), `face:expire-enrollments` (cada hora), `contracts:expire` (00:05 diario), `contracts:notify-expiring` (08:00 diario).

### Deployment a Bar777 y Arca (VPS TechForge — CloudPanel)

Distinto del servidor cPanel de arriba. Bar777 y Arca corren en el mismo VPS de TechForge (`144.202.33.145`, CloudPanel), cada uno con su propio site user y checkout independiente del repo.

**Mecanismo:** GitHub Actions se conecta directo por SSH y ejecuta el deploy él mismo (no hay webhook ni script server-side como `deploy.php`). La lógica del pipeline vive en un **workflow reusable** compartido: `nextup-py/deploy-actions/.github/workflows/laravel-deploy.yml`. El `deploy.yml` de este repo (`nominapp`) es solo un caller con 2 jobs (`deploy-bar777`, `deploy-arca`), disparado manualmente vía `workflow_dispatch` eligiendo la instancia.

**Usuarios SSH dedicados:** `bar777-deploy` y `arca-deploy` (usuarios SSH separados del site user original de CloudPanel, creados en Sites → SSH/FTP → Add User). Cada uno tiene su propia clave SSH y está en el grupo Unix del site user correspondiente (acceso a archivos vía permisos de grupo, no por ser dueño).

**Deploy Key de solo lectura:** `git pull` en el servidor se autentica con una Deploy Key de solo lectura del repo (compartida entre `bar777-deploy` y `arca-deploy`, guardada en `~/.ssh/id_ed25519` de cada usuario). Es independiente de la clave SSH que usa GitHub Actions para conectarse al servidor.

**Pasos del deploy** (job `deploy`, corre después de que pasa el job `test` con Pest + MySQL de servicio):
1. `artisan down`
2. `git pull origin main`
3. `composer install --no-dev --optimize-autoloader`
4. `artisan storage:link` (idempotente)
5. `npm ci` + build de Vite con ruta absoluta (`node_modules/.bin/vite build`, no `npm run build`, por riesgo de PATH en shell no interactiva)
6. Backup de BD (`mysqldump` + rotación de 7)
7. `artisan migrate --force`
8. `livewire:publish --assets`, `filament:assets`, `optimize:clear`, `optimize`, `filament:optimize`, `queue:restart`
9. `artisan up`
10. Rollback automático (`trap ERR`) al SHA anterior si cualquier paso falla
11. Tag de release best-effort (`|| true` — la Deploy Key es de solo lectura, no puede pushear tags)
12. Health check HTTP post-deploy

**Secrets** (Repository secrets en `nominapp`, prefijados `BAR777_*` / `ARCA_*` — no Environment secrets, porque un job que llama a un reusable workflow no puede tener `environment:` seteado): `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_PRIVATE_KEY`, `DEPLOY_PATH`, `APP_URL`.

**Documentación completa** (arquitectura, gotchas encontrados, decisiones de diseño): ver `nominapp-pipeline-deploy-ssh-reusable-workflow.md` en Drive, carpeta `05-Infraestructura`.

### RelationManagers de asignación con vigencia por fechas (`EmployeePerception`, `EmployeeDeduction`)

Estas tablas tienen una constraint única compuesta: `(employee_id, entity_id, start_date)`. Esto impone reglas que van más allá de solo verificar si hay una asignación activa.

**Cuatro puntos de entrada que deben validar unicidad de `start_date`:**

```php
// 1. CreateAction — before()
$hasSameStartDate = Model::where('employee_id', ...)
    ->where('entity_id', ...)
    ->where('start_date', $data['start_date'])
    ->exists();

// 2. EditAction — before()
$hasSameStartDate = Model::where('employee_id', $record->employee_id)
    ->where('entity_id', $record->entity_id)
    ->where('id', '!=', $record->id)
    ->where('start_date', $data['start_date'])
    ->exists();

// 3. reactivate (action individual) — antes del update
$hasSameStartDate = Model::where(...)->where('id', '!=', $record->id)
    ->where('start_date', $startDate)->exists();

// 4. BulkAction reactivate — por registro dentro del foreach
if ($hasSameStartDate) { $skipped++; continue; }
```

**Por qué:** El chequeo de "asignación activa" (`start_date <= now AND end_date IS NULL OR >= now`) no detecta registros históricos inactivos que compartan la misma `start_date`. El INSERT/UPDATE falla con `UniqueConstraintViolationException` sin capturar.

**Acción deactivate — validar `end_date >= start_date` en el closure:**
```php
->action(function (Model $record, array $data) {
    $endDate = Carbon::parse($data['end_date']);
    if ($endDate->lt($record->start_date)) {
        Notification::make()->danger()->title('Fecha inválida')
            ->body("La fecha de fin no puede ser anterior a la fecha de inicio ({$record->start_date->format('d/m/Y')}).")
            ->send();
        return;
    }
    // ...
```

### `->searchable()` con columnas de relaciones en tablas Filament

Pasar un array de columnas relacionales a `->searchable()` genera SQL inválido:

```php
// ❌ Genera: WHERE employee.first_name LIKE ...
// MySQL no reconoce 'employee' (singular) como tabla en el subquery
->searchable(['employee.first_name', 'employee.last_name'])

// ✅ Correcto: usar query callback con whereHas
->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
    'employee',
    fn ($q) => $q->where('first_name', 'like', "%{$search}%")
                 ->orWhere('last_name', 'like', "%{$search}%")
))
```

La causa: Filament usa el nombre de la relación (singular) como alias de tabla en el subquery `EXISTS`, pero MySQL espera el nombre real de la tabla (plural). El `->searchable()` sin array en columnas simples de relación (`->searchable()` sobre `TextColumn::make('employee.ci')`) sí funciona porque Filament genera el subquery correcto en ese caso.

### `->searchable()` en Select con `->relationship()` — respeta el titleAttribute, no el label mostrado

Cuando un `Select::make(...)->relationship($rel, $titleAttribute, ...)` usa `->searchable()` **sin un array explícito de columnas**, Filament busca únicamente contra la columna `$titleAttribute` — no contra lo que `->getOptionLabelFromRecordUsing()` muestra en pantalla. Si el label override incluye más columnas (apellido, CI, trade_name, etc.) que el `$titleAttribute` no cubre, el campo se vuelve efectivamente imposible de buscar por esas columnas — visualmente parece normal, pero escribir texto en el buscador no encuentra nada.

```php
// ❌ El label muestra nombre + apellido + CI, pero solo busca por 'id'
Select::make('employee_id')
    ->relationship('employee', 'id', fn ($query) => $query->where('status', 'active'))
    ->searchable()
    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->first_name} {$record->last_name} — CI: {$record->ci}")

// ✅ El array de searchable() debe cubrir todas las columnas que aparecen en el label
Select::make('employee_id')
    ->relationship('employee', 'first_name', fn ($query) => $query->where('status', 'active'))
    ->searchable(['first_name', 'last_name', 'ci'])
    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->first_name} {$record->last_name} — CI: {$record->ci}")
```

**Caso límite más grave: `titleAttribute` omitido por completo** (`->relationship('perception', modifyQueryUsing: ...)` sin segundo argumento) — sin `searchable([...])` explícito, `getSearchColumns()` retorna `null` y el buscador queda **completamente inerte**: no filtra nada, ni siquiera devuelve cero resultados, simplemente ignora lo que el usuario escribe.

**Regla:** todo `Select::make(...)->relationship(...)->searchable()` que también tenga `->getOptionLabelFromRecordUsing()` debe pasarle a `searchable()` un array explícito con **todas** las columnas que aparecen en ese label override — nunca dejarlo sin argumentos cuando el label muestra más de una columna. Aplica igual a `SelectFilter::make(...)` en tablas (mismo mecanismo, ver `vendor/filament/tables/src/Filters/SelectFilter.php:260` `getFormField()`).

### `Select::relationship()` con `modifyQueryUsing` puede vaciar el valor ya seleccionado

`modifyQueryUsing` en `->relationship($name, $titleAttribute, $modifyQueryUsing)` no solo filtra las opciones listadas — también filtra la query que Filament usa internamente para resolver el label del valor **ya seleccionado** (`getSelectedRecordUsing()`, ver `vendor/filament/forms/src/Components/Select.php`). Si el closure excluye registros "inactivos" (ej. sucursales de empresas dadas de baja) y el registro editado tiene justo uno de esos como valor actual, el campo se muestra vacío al entrar a editar — aunque el dato siga guardado correctamente en la base.

**Correcto — OR-ear el id ya asignado del record actual** (Filament inyecta `$record` por utility injection en el closure):
```php
Select::make('branch_id')
    ->relationship('branch', 'name', modifyQueryUsing: fn (Builder $query, ?Terminal $record) => $query
        ->where(function (Builder $query) use ($record) {
            $query->whereHas('company', fn (Builder $query) => $query->active());

            if ($record?->branch_id) {
                $query->orWhere('id', $record->branch_id);
            }
        }))
```

Sin el `orWhere`, la sucursal actual desaparece de las opciones **y** del label mostrado en el edit — el usuario vería el select vacío y, si guarda sin darse cuenta, perdería la asignación.

### Nullsafe en propiedades de relaciones en closures de columnas Filament

Siempre usar `?->` al acceder a relaciones en closures de columnas — el registro relacionado puede ser null si fue eliminado:

```php
// ❌ Crash si employee es null
->defaultImageUrl(fn ($record) => $record->employee->avatar_url)

// ✅
->defaultImageUrl(fn ($record) => $record->employee?->avatar_url)

// ❌ Crash si deduction es null y custom_amount también es null
->getStateUsing(function ($record) {
    if ($record->custom_amount !== null) { return ...; }
    elseif ($record->deduction->isPercentage()) { ... }  // crash
})

// ✅ Guard explícito
->getStateUsing(function ($record) {
    if ($record->custom_amount !== null) { return ...; }
    if ($record->deduction === null) { return '-'; }
    if ($record->deduction->isPercentage()) { ... }
})
```

### Campos opcionales en Infolists y tablas (`TextEntry`, `TextColumn`, etc.)

Los campos opcionales deben mostrar un placeholder descriptivo cuando no tienen valor — no ocultarse. Un layout estable (todos los campos siempre presentes) es más fácil de escanear que uno donde los campos aparecen y desaparecen según los datos.

```php
// ✅ Infolist — campo siempre visible, con placeholder cuando es null
TextEntry::make('phone')
    ->placeholder('Sin teléfono'),

TextEntry::make('nationality')
    ->placeholder('No registrada'),

// ✅ Tabla — mismo principio, evitar ->default('—')
TextColumn::make('trade_name')
    ->placeholder('Sin nombre comercial'),

TextColumn::make('city')
    ->placeholder('Sin ciudad'),
```

> Usar `->placeholder()` en lugar de `->default('—')` — el placeholder muestra texto gris descriptivo; el default inserta un guión negro que parece un valor real.

Reservar `->hidden()` / `->visible()` para **bloques enteros** que son semánticamente irrelevantes en cierto estado — no para campos individuales vacíos:

```php
// ✅ Sección completa que no aplica si no está rechazado
InfoSection::make('Rechazo')
    ->visible(fn($record) => $record->isRejected()),

// ✅ Campo que no aplica si no hay contrato activo
TextEntry::make('activeContract.position.name')
    ->hidden(fn($record) => $record->activeContract === null),
```

### Infolists — convenciones de layout

**No anidar `InfoSection` dentro de `InfoSection`** — produce una sección con indentación visual rara. Para agrupar campos relacionados dentro de una sección, usar `InfoGrid` directamente:

```php
// ❌ Sub-sección anidada
InfoSection::make('Datos Personales')->schema([
    ...
    InfoSection::make('Contacto')->schema([...]),
])

// ✅ Grid plano dentro de la sección
InfoSection::make('Datos Personales')->schema([
    InfoGrid::make(4)->schema([...]),  // identidad
    InfoGrid::make(4)->schema([...]),  // contacto
])
```

**Secciones del infolist: siempre `->collapsible()` sin `->collapsed()`** — el usuario las ve expandidas por defecto y puede colapsarlas. Solo usar `->collapsed()` en secciones de formulario opcionales en creación (ej. "Contrato Inicial").

**Campos virtuales / calculados en infolist** — usar prefijo `_` en el nombre del campo para indicar que no mapea a ninguna columna del modelo. Evita confusión con atributos reales:

```php
TextEntry::make('_contract_notice')
    ->getStateUsing(fn () => 'Este empleado no tiene contrato activo.')
    ->hidden(fn ($record) => $record->activeContract !== null),
```

**Eager loading en `ViewRecord`** — cuando el infolist accede a relaciones anidadas (ej. `branch.company`, `activeContract.position.department`), sobrescribir `resolveRecord()` para cargarlas en una sola query:

```php
protected function resolveRecord(int|string $key): Model
{
    return Employee::with([
        'branch.company',
        'activeContract.position.department',
    ])->findOrFail($key);
}
```

### Acciones de transición de estado en tabla vs ViewRecord

Las transiciones de estado **irreversibles** (rechazar, cancelar) van solo en los header actions del `ViewRecord` — nunca como row actions de la tabla. El `ViewRecord` muestra toda la información del registro y pide confirmación; la tabla no tiene ese contexto.

Las row actions de la tabla se limitan a operaciones rápidas y de bajo riesgo: aprobar, descargar PDF.

```php
// ✅ En tabla: solo aprobar (acción de bajo riesgo)
->actions([
    Action::make('approve')->visible(fn($r) => $r->isPending()),
    Action::make('export_pdf')->visible(fn($r) => $r->isApproved()),
])

// ✅ En ViewRecord: approve + reject + cancel (acciones con contexto completo)
protected function getHeaderActions(): array
{
    return [
        Action::make('approve')->visible(fn() => $this->record->isPending()),
        Action::make('reject')->visible(fn() => $this->record->isPending()),
        Action::make('cancel')->visible(fn() => $this->record->isApproved()),
    ];
}
```

### Ciclo de vida de módulos con cuotas (Préstamos, Retiros de Mercadería)

Patrón estándar para módulos que generan cuotas descontadas en nómina:

- **`reject()`**: solo desde `pending` — la solicitud nunca fue aprobada, no hay cuotas generadas.
- **`cancel()`**: solo desde `approved`, y solo si `paid_installments_count === 0` — previene cancelar registros que ya tienen cuotas descontadas en nómina.

```php
public function cancel(): array
{
    if (! $this->isApproved()) {
        return ['success' => false, 'message' => 'Solo se pueden cancelar retiros aprobados.'];
    }
    if ($this->paid_installments_count > 0) {
        return ['success' => false, 'message' => "No se puede cancelar: {$this->paid_installments_count} cuota(s) ya descontadas."];
    }
    // ...
}
```

La modal de confirmación de cancelar debe advertir explícitamente si hay cuotas pendientes (aunque ninguna pagada), para que el usuario sepa cuántas cuotas se eliminarán.

### Carbon — mutación accidental de atributos de fecha del modelo

Carbon 3 (usado en Laravel 12) es **mutable por defecto**. Al llamar modificadores como `startOfWeek()`, `endOfWeek()`, `startOfMonth()` sobre un atributo Carbon del modelo, se muta el valor almacenado en la instancia — afectando cualquier acceso posterior al atributo en el mismo request.

Siempre usar `.copy()` antes de aplicar modificadores:

```php
// ❌ Muta $record->date — cualquier acceso posterior verá startOfWeek()
$weekStart = $record->date->startOfWeek()->toDateString();

// ✅ Seguro — el original no se toca
$weekStart = $record->date->copy()->startOfWeek()->toDateString();
$weekEnd   = $record->date->copy()->endOfWeek()->toDateString();
```

Aplica a cualquier campo con cast `'date'` o `'datetime'` en el modelo.

### Valores de configuración en labels de acciones estáticas

Cuando una acción (definida como método `static`) necesita valores de `PayrollSettings` u otro objeto de configuración para construir sus labels o descripciones, resolver el settings una sola vez al inicio del método factory:

```php
// ✅ Resolver settings al inicio del factory estático
public static function getAdjustExtraHoursTableAction(): TableAction
{
    $settings    = app(PayrollSettings::class);
    $pctDiurno   = (int) (($settings->overtime_multiplier_diurno - 1) * 100);
    $pctNocturno = (int) (($settings->overtime_multiplier_nocturno - 1) * 100);

    return TableAction::make('adjust_extra_hours')
        ->form([
            TextInput::make('extra_hours_diurnas')
                ->label("Horas extra diurnas (+{$pctDiurno}%)")
                // ...
        ]);
}
```

Nunca hardcodear multiplicadores, porcentajes o tasas directamente en labels — si cambian en `PayrollSettings`, el label quedaría desactualizado.

### Consolidar row actions en un único `ActionGroup`

Cuando un recurso tiene múltiples row actions que pueden aparecer condicionalmente, consolidarlas en un único `ActionGroup` para evitar una columna de acciones congestionada visualmente:

```php
->actions([
    TableActionGroup::make([
        self::getApproveOvertimeTableAction(),
        self::getApproveTardinessTableAction(),
        self::getAdjustExtraHoursTableAction(),
        self::getExportPdfTableAction(),
        self::getCalculateTableAction(),
    ]),
])
```

**Labels en dropdown:** dentro de un `ActionGroup` expandir labels abreviados a nombres descriptivos completos — el espacio no es limitado como en botones de fila:
- `"PDF"` → `"Exportar PDF"`
- `"Ajustar HE"` → `"Ajustar Horas Extra"`

Agregar `->tooltip()` a cada acción cuando varias tienen propósito similar y el label solo no basta para diferenciarlas.

**Testing:** una vez agrupadas dentro de un `ActionGroup` (tabla o header actions de página), `getCachedTableActions()`/`getCachedHeaderActions()` ya no devuelven esas acciones como items de nivel superior — `assertTableActionHidden()`/`assertActionHidden()` sobre el nombre de la acción puede dejar de encontrarla. Si el test necesita inspeccionar acciones dentro de un grupo, aplanarlas primero:
```php
$actions = collect($livewire->instance()->getCachedHeaderActions())
    ->flatMap(fn ($a) => $a instanceof \Filament\Actions\ActionGroup ? $a->getFlatActions() : [$a]);
```

### Filtrar solo empleados activos en selects de modales

Cualquier `Select` de empleado en formularios de acciones (modales) debe filtrar `where('status', 'active')` — nunca mostrar empleados desvinculados o inactivos como opción seleccionable:

```php
Select::make('employee_id')
    ->label('Empleado')
    ->options(fn () => Employee::where('status', 'active')
        ->orderBy('first_name')->orderBy('last_name')
        ->get()
        ->mapWithKeys(fn ($e) => [$e->id => "{$e->first_name} {$e->last_name} (CI: {$e->ci})"])
        ->toArray()
    )
    ->searchable()
    ->required()
```

Incluir la CI en el label del option para facilitar la identificación cuando hay homónimos.

### Advertencia reactiva de sobreescritura en patrones `firstOrNew`

Cuando una acción usa `firstOrNew()` o `updateOrCreate()` para crear-o-actualizar un registro, agregar un `Placeholder` reactivo que avise al usuario si el registro ya existe, mostrando su estado actual. Requiere `->live()` en los campos identificadores:

```php
Select::make('employee_id')->live(),
DatePicker::make('date')->live(),

Placeholder::make('existing_warning')
    ->label('')
    ->content(function (Get $get): ?string {
        $existing = AttendanceDay::where('employee_id', $get('employee_id'))
            ->where('date', $get('date'))->first();
        if (! $existing) {
            return null;
        }
        $statusLabel = AttendanceDay::getStatusLabel($existing->status);
        $msg = "Ya existe un registro para este empleado en esta fecha (estado: {$statusLabel}";
        if ((float) $existing->extra_hours > 0) {
            $msg .= ", {$existing->extra_hours} hrs registradas";
        }

        return $msg.'). Los valores serán reemplazados.';
    })
    ->visible(fn (Get $get): bool =>
        filled($get('employee_id')) && filled($get('date')) &&
        AttendanceDay::where('employee_id', $get('employee_id'))
            ->where('date', $get('date'))->exists()
    )
    ->columnSpanFull(),
```

El `Placeholder` debe mostrar datos relevantes del registro existente (estado, valores actuales) para que el usuario decida conscientemente si continuar.

### Workflow de borrador en `CreateRecord` (dos botones de guardado)

Cuando el usuario necesita guardar como borrador para previsualizar antes de activar, agregar una propiedad `$savingAsDraft` en la Page y sobreescribir `getFormActions()`:

```php
protected bool $savingAsDraft = false;

protected function getFormActions(): array
{
    return [
        Action::make('create')->label('Crear y activar')->action('create'),
        Action::make('save_draft')
            ->label('Guardar borrador')
            ->action(function () {
                $this->savingAsDraft = true;
                $this->create();
            }),
        Action::make('cancel')->label('Cancelar')->url($this->getResource()::getUrl('index')),
    ];
}

protected function mutateFormDataBeforeCreate(array $data): array
{
    $data['status'] = $this->savingAsDraft ? 'draft' : 'active';
    return $data;
}

protected function getRedirectUrl(): string
{
    return $this->savingAsDraft
        ? $this->getResource()::getUrl('view', ['record' => $this->getRecord()])
        : $this->getResource()::getUrl('index');
}
```

El botón "activar" usa el flow estándar de Filament; el de borrador setea la propiedad antes de llamar a `create()`. `mutateFormDataBeforeCreate()` lee la propiedad para decidir el status.

### Observer para cascada de estados entre modelos relacionados

Cuando el estado de un modelo debe propagar cambios a su modelo padre/relacionado, hacerlo en el observer con un guard que verifique si el relacionado tiene otro registro activo antes de cambiar su estado:

```php
public function updated(Contract $contract): void
{
    if (! $contract->isDirty('status')) {
        return;
    }

    if (in_array($contract->status, ['expired', 'terminated'])) {
        $hasOtherActive = $contract->employee->contracts()
            ->where('id', '!=', $contract->id)
            ->where('status', 'active')
            ->exists();

        if (! $hasOtherActive) {
            $contract->employee->update(['status' => 'inactive']);
        }
    }
}
```

**Regla:** siempre verificar si el modelo relacionado tiene otro registro que justifique mantener su estado antes de cambiarlo. Sin el guard, un empleado con dos contratos (uno que vence y otro activo) quedaría incorrectamente inactivo.

### Comando de expiración automática de registros por fecha

Para modelos con `end_date` o `expires_at` que deben cambiar de estado automáticamente, crear un comando diario en `app/Console/Commands/` y registrarlo en `routes/console.php` a las 00:05:

```php
// app/Console/Commands/ExpireFoo.php
public function handle(): int
{
    $count = Foo::query()
        ->where('status', 'active')
        ->whereNotNull('end_date')
        ->where('end_date', '<', now()->startOfDay())
        ->update(['status' => 'expired']);

    if ($count > 0) {
        Log::info("foo:expire: {$count} registros marcados como expirados.");
    }

    return self::SUCCESS;
}
```

```php
// routes/console.php
Schedule::command('foo:expire')->dailyAt('00:05')->withoutOverlapping();
```

Usar `now()->startOfDay()` (no `now()`) para evitar que registros que vencen hoy a medianoche se salteen por segundos de diferencia.

### Notificaciones de campanita — construir `toDatabase()` con `Filament\Notifications\Notification`

Una clase `Notification` de Laravel con canal `database` que retorna un **array plano** en `toDatabase()` persiste el registro en `notifications` y el mail (si tiene canal `mail`) llega con normalidad, pero la campanita del panel Filament **no la muestra** — Filament espera que `data` tenga el formato que genera su propio builder (incluye `data->format = 'filament'`, `title`, `body`, `icon`, `iconColor`, `actions`, etc.).

**Correcto:**
```php
public function toDatabase(object $notifiable): array
{
    return \Filament\Notifications\Notification::make()
        ->title('Terminal provisionado')
        ->body("El terminal \"{$this->terminal->name}\" reclamó su token de sincronización.")
        ->icon('heroicon-o-computer-desktop')
        ->success() // o ->warning()/->danger()
        ->actions([
            \Filament\Notifications\Actions\Action::make('view')
                ->label('Ver terminal')
                ->url(TerminalResource::getUrl('view', ['record' => $this->terminal])),
        ])
        ->getDatabaseMessage() + [
            'entity_id' => $this->terminal->id, // claves extra propias, ej. para deduplicación
        ];
}
```

Aplica a toda notificación de BD que deba aparecer en la campanita del panel — no solo a canal `mail`+`database` combinados.

### Deduplicación de notificaciones de campanita

Cuando un comando diario envía notificaciones (ej. contratos por vencer), evitar duplicados verificando las notificaciones no leídas existentes antes de enviar:

```php
private function getAlreadyNotifiedIds(User $anyUser): array
{
    return $anyUser->unreadNotifications()
        ->where('type', MyNotification::class)
        ->pluck('data->entity_id')
        ->map(fn ($id) => (int) $id)
        ->filter()
        ->unique()
        ->values()
        ->toArray();
}

// En handle():
$alreadyNotified = $this->getAlreadyNotifiedIds($users->first());
$pending = MyModel::whereNotIn('id', $alreadyNotified)->get();
```

La lógica: si al menos un usuario ya tiene la notificación no leída para ese registro, se asume que todos los usuarios ya fueron notificados. Al leer la notificación, el registro vuelve a ser elegible en la próxima corrida — comportamiento correcto si el estado aún persiste.

### Auditoría selectiva con `auditInclude`

Al implementar `Auditable` en un modelo, siempre definir `$auditInclude` para limitar qué campos se registran. Sin esto, la auditoría captura todos los campos incluyendo timestamps, FKs internas y datos que no aportan valor al historial:

```php
class Contract extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected array $auditInclude = [
        'status',
        'salary',
        'position_id',
        'start_date',
        'end_date',
        'notes',
        // Solo los campos que el usuario ve y que tienen significado de negocio
    ];
}
```

Acompañar siempre con `formatAuditFieldsForPresentation()` en el modelo para mostrar valores legibles (ej. `status: 'active'` → `'Vigente'`, `position_id: 5` → `'Gerente de Ventas'`) y el `RelationManager` correspondiente para mostrarlo en la UI.

### Important Notes
- Monetary values use `decimal:2` cast
- `Employee::getAdvanceReferenceSalary()` does **not** yet include the weekly paid rest day for jornaleros — pending automatic calculation
- Loan installment amount cannot exceed 25% of salary (Art. 245 CLT) — validated in `Loan::activate()`
- Advance salary cap validation (mensual only) is in `Advance::approve()` — compares sum of all active advances against gross monthly salary
- Mobile mode is for remote employees using their own device, **not** a shared kiosk
- Terminal/kiosk mode is a shared device per branch
- `attendance:check-missing` (deuda técnica conocida): corre cada 15 min entre 06:00-20:00 y solo evalúa la fecha de "hoy" contra "ahora" — nunca mira retroactivamente. Un empleado cuyo turno (horario fijo o rotación) arranca de noche (ej. 22:00) y falta esa noche **nunca** genera una ausencia automática: para cuando el comando vuelve a correr al día siguiente (06:00), ya está evaluando el horario/turno de ese nuevo día, no retrocede a revisar el turno de la noche anterior que nunca se marcó. Arreglarlo requiere que el comando también mire hacia atrás (el día previo), no solo "hoy" — pendiente, alcance mayor al soporte de rotación ya agregado (`AttendanceCalculator::resolveShiftDataFor()`).
- Auditoría (`audits` table): sin política de retención/purga por ahora — la tabla crece sin límite. Deuda técnica conocida, pendiente de definir si el volumen lo justifica.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to enhance the user's satisfaction building Laravel applications.

## Foundational Context
This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.6
- filament/filament (FILAMENT) - v3
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v3
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v3
- phpunit/phpunit (PHPUNIT) - v11
- vue (VUE) - v3
- tailwindcss (TAILWINDCSS) - v4

## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove it works. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling
- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Replies
- Be concise in your explanations - focus on what's important rather than explaining obvious details.

## Documentation Files
- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

## Laravel Boost
- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan
- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs
- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging
- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool
- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)
- Boost comes with a powerful `search-docs` tool you should use before any other approaches when dealing with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- The `search-docs` tool is perfect for all Laravel-related packages, including Laravel, Inertia, Livewire, Filament, Tailwind, Pest, Nova, Nightwatch, etc.
- You must use this tool to search for Laravel ecosystem documentation before falling back to other approaches.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries to start. For example: `['rate limiting', 'routing rate limiting', 'routing']`.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax
- You can and should pass multiple queries at once. The most relevant results will be returned first.

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

## PHP

- Always use curly braces for control structures, even if it has one line.

### Constructors
- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

### Type Declarations
- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Comments
- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless there is something very complex going on.

## PHPDoc Blocks
- Add useful array shape type definitions for arrays when appropriate.

## Enums
- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

=== tests rules ===

## Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

## Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Database
- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation
- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources
- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

### Controllers & Validation
- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

### Queues
- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

### Authentication & Authorization
- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

### URL Generation
- When generating links to other pages, prefer named routes and the `route()` function.

### Configuration
- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

### Testing
- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

### Vite Error
- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

## Laravel 12

- Use the `search-docs` tool to get version-specific documentation.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

### Laravel 12 Structure
- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

### Database
- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models
- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

## Livewire

- Use the `search-docs` tool to find exact version-specific documentation for how to write Livewire and Livewire tests.
- Use the `php artisan make:livewire [Posts\CreatePost]` Artisan command to create new components.
- State should live on the server, with the UI reflecting it.
- All Livewire requests hit the Laravel backend; they're like regular HTTP requests. Always validate form data and run authorization checks in Livewire actions.

## Livewire Best Practices
- Livewire components require a single root element.
- Use `wire:loading` and `wire:dirty` for delightful loading states.
- Add `wire:key` in loops:

    ```blade
    @foreach ($items as $item)
        <div wire:key="item-{{ $item->id }}">
            {{ $item->name }}
        </div>
    @endforeach
    ```

- Prefer lifecycle hooks like `mount()`, `updatedFoo()` for initialization and reactive side effects:

<code-snippet name="Lifecycle Hook Examples" lang="php">
    public function mount(User $user) { $this->user = $user; }
    public function updatedSearch() { $this->resetPage(); }
</code-snippet>

## Testing Livewire

<code-snippet name="Example Livewire Component Test" lang="php">
    Livewire::test(Counter::class)
        ->assertSet('count', 0)
        ->call('increment')
        ->assertSet('count', 1)
        ->assertSee(1)
        ->assertStatus(200);
</code-snippet>

<code-snippet name="Testing Livewire Component Exists on Page" lang="php">
    $this->get('/posts/create')
    ->assertSeeLivewire(CreatePost::class);
</code-snippet>

=== livewire/v3 rules ===

## Livewire 3

### Key Changes From Livewire 2
- These things changed in Livewire 3, but may not have been updated in this application. Verify this application's setup to ensure you conform with application conventions.
    - Use `wire:model.live` for real-time updates, `wire:model` is now deferred by default.
    - Components now use the `App\Livewire` namespace (not `App\Http\Livewire`).
    - Use `$this->dispatch()` to dispatch events (not `emit` or `dispatchBrowserEvent`).
    - Use the `components.layouts.app` view as the typical layout path (not `layouts.app`).

### New Directives
- `wire:show`, `wire:transition`, `wire:cloak`, `wire:offline`, `wire:target` are available for use. Use the documentation to find usage examples.

### Alpine
- Alpine is now included with Livewire; don't manually include Alpine.js.
- Plugins included with Alpine: persist, intersect, collapse, and focus.

### Lifecycle Hooks
- You can listen for `livewire:init` to hook into Livewire initialization, and `fail.status === 419` for the page expiring:

<code-snippet name="Livewire Init Hook Example" lang="js">
document.addEventListener('livewire:init', function () {
    Livewire.hook('request', ({ fail }) => {
        if (fail && fail.status === 419) {
            alert('Your session expired');
        }
    });

    Livewire.hook('message.failed', (message, component) => {
        console.error(message);
    });
});
</code-snippet>

=== pint/core rules ===

## Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.

=== pest/core rules ===

## Pest
### Testing
- If you need to verify a feature is working, write or update a Unit / Feature test.

### Pest Tests
- All tests must be written using Pest. Use `php artisan make:test --pest {name}`.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files - these are core to the application.
- Tests should test all of the happy paths, failure paths, and weird paths.
- Tests live in the `tests/Feature` and `tests/Unit` directories.
- Pest tests look and behave like this:
<code-snippet name="Basic Pest Test Example" lang="php">
it('is true', function () {
    expect(true)->toBeTrue();
});
</code-snippet>

### Running Tests
- Run the minimal number of tests using an appropriate filter before finalizing code edits.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).
- When the tests relating to your changes are passing, ask the user if they would like to run the entire test suite to ensure everything is still passing.

### Pest Assertions
- When asserting status codes on a response, use the specific method like `assertForbidden` and `assertNotFound` instead of using `assertStatus(403)` or similar, e.g.:
<code-snippet name="Pest Example Asserting postJson Response" lang="php">
it('returns all', function () {
    $response = $this->postJson('/api/docs', []);

    $response->assertSuccessful();
});
</code-snippet>

### Mocking
- Mocking can be very helpful when appropriate.
- When mocking, you can use the `Pest\Laravel\mock` Pest function, but always import it via `use function Pest\Laravel\mock;` before using it. Alternatively, you can use `$this->mock()` if existing tests do.
- You can also create partial mocks using the same import or self method.

### Datasets
- Use datasets in Pest to simplify tests that have a lot of duplicated data. This is often the case when testing validation rules, so consider this solution when writing tests for validation rules.

<code-snippet name="Pest Dataset Example" lang="php">
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
</code-snippet>

=== tailwindcss/core rules ===

## Tailwind CSS

- Use Tailwind CSS classes to style HTML; check and use existing Tailwind conventions within the project before writing your own.
- Offer to extract repeated patterns into components that match the project's conventions (i.e. Blade, JSX, Vue, etc.).
- Think through class placement, order, priority, and defaults. Remove redundant classes, add classes to parent or child carefully to limit repetition, and group elements logically.
- You can use the `search-docs` tool to get exact examples from the official documentation when needed.

### Spacing
- When listing items, use gap utilities for spacing; don't use margins.

<code-snippet name="Valid Flex Gap Spacing Example" lang="html">
    <div class="flex gap-8">
        <div>Superior</div>
        <div>Michigan</div>
        <div>Erie</div>
    </div>
</code-snippet>

### Dark Mode
- If existing pages and components support dark mode, new pages and components must support dark mode in a similar way, typically using `dark:`.

=== tailwindcss/v4 rules ===

## Tailwind CSS 4

- Always use Tailwind CSS v4; do not use the deprecated utilities.
- `corePlugins` is not supported in Tailwind v4.
- In Tailwind v4, configuration is CSS-first using the `@theme` directive — no separate `tailwind.config.js` file is needed.

<code-snippet name="Extending Theme in CSS" lang="css">
@theme {
  --color-brand: oklch(0.72 0.11 178);
}
</code-snippet>

- In Tailwind v4, you import Tailwind using a regular CSS `@import` statement, not using the `@tailwind` directives used in v3:

<code-snippet name="Tailwind v4 Import Tailwind Diff" lang="diff">
   - @tailwind base;
   - @tailwind components;
   - @tailwind utilities;
   + @import "tailwindcss";
</code-snippet>

### Replaced Utilities
- Tailwind v4 removed deprecated utilities. Do not use the deprecated option; use the replacement.
- Opacity values are still numeric.

| Deprecated |	Replacement |
|------------+--------------|
| bg-opacity-* | bg-black/* |
| text-opacity-* | text-black/* |
| border-opacity-* | border-black/* |
| divide-opacity-* | divide-black/* |
| ring-opacity-* | ring-black/* |
| placeholder-opacity-* | placeholder-black/* |
| flex-shrink-* | shrink-* |
| flex-grow-* | grow-* |
| overflow-ellipsis | text-ellipsis |
| decoration-slice | box-decoration-slice |
| decoration-clone | box-decoration-clone |
</laravel-boost-guidelines>

## Claude Code Plugins

### Flujo de trabajo con Superpowers

Antes de implementar cualquier feature o cambio significativo, usá el flujo de planificación:

1. `/superpowers:brainstorm` — para explorar el enfoque antes de codear (útil en features nuevas como suspensión disciplinaria en Warnings, o nuevos calculadores)
2. `/superpowers:write-plan` — para generar un plan de implementación paso a paso
3. `/superpowers:execute-plan` — para ejecutar el plan en batches controlados

**Cuándo usarlo en Nominapp:**
- Features nuevas que tocan el pipeline de nómina (`PayrollService`) — siempre planificar antes
- Nuevos módulos o Resources de Filament
- Cambios en ciclos de vida de modelos (nuevos estados, transiciones)
- Integraciones con calculadoras existentes

**Cuándo ir directo sin planificación:**
- Fixes puntuales (typos, labels, colores)
- Ajustes de UI en Filament (columnas, filtros, acciones)
- Migraciones simples sin lógica de negocio

### Code Review

Ejecutar `/code-review` antes de cada PR o deploy importante.

**Prioridades para este proyecto:**
- Verificar que los cambios al pipeline de nómina respetan el orden fijo de calculadoras (pasos 1–9 en `PayrollService`)
- Idempotencia en calculadoras (`LoanInstallmentCalculator`, `AdvanceCalculator`, `MerchandiseInstallmentCalculator`)
- Limpieza correcta en `Payroll::booted()` al eliminar nóminas
- Ciclos de vida de modelos — las transiciones de estado deben validar el estado previo

### Security Review

Ejecutar `/security-review` antes de deploys que toquen:
- `WarningController`, `ContractController` y cualquier controller con generación de PDF
- Endpoints de asistencia y reconocimiento facial
- Exports bancarios (`BankPaymentExportService`, `DisbursementBatch`)
- Cualquier acción masiva (bulk actions) en Filament

### Frontend Design

El skill de frontend-design se activa automáticamente al trabajar en vistas Blade y componentes Livewire.

**Stack de UI en este proyecto:**
- Filament 3.3 para el panel admin — respetar los componentes nativos de Filament antes de crear custom
- Tailwind CSS v4 con configuración CSS-first (`@theme` en lugar de `tailwind.config.js`)
- Dark mode obligatorio si la página o componente existente lo soporta
- Sin emojis en la UI
- Locale `es`, moneda Guaraní (Gs.), timezone `America/Asuncion`

### Memoria entre sesiones (Claude MEM)

Claude MEM captura contexto automáticamente. Para maximizar su utilidad:
- Al iniciar una sesión, mencioná el módulo en el que vas a trabajar
- Si retomás un trabajo interrumpido, describí brevemente el último estado
- Las decisiones de arquitectura importantes quedan registradas automáticamente