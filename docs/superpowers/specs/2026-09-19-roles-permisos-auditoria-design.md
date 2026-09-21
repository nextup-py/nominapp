# Roles, permisos y auditoría completa — Diseño

**Fecha:** 2026-09-19
**Estado:** Aprobado, pendiente de plan de implementación

## Contexto

El panel Filament no tiene control de acceso: cualquier usuario autenticado
tiene acceso total a los 33 Resources (`User::canAccessPanel()` retorna
`true` siempre). No existe modelo de roles ni de permisos.

La auditoría de cambios (`owen-it/laravel-auditing` + `tapp/filament-auditing`)
ya está instalada como dependencia y la tabla `audits` existe, pero solo 15
de ~48 modelos implementan `Auditable`, no hay UI en el panel para revisarla,
y no cubre eventos de autenticación ni cambios de roles.

## Alcance

1. Roles y permisos CRUD + de acciones de negocio sobre los 33 Resources,
   vía `spatie/laravel-permission`.
2. Auditoría completa: extensión de `Auditable` a modelos de negocio/config
   restantes (excluyendo los de muy alto volumen), eventos de autenticación,
   cambios de roles, y UI de revisión en el panel.

**Fuera de alcance (explícitamente descartado en esta ronda):**
- Scoping de datos por sucursal/empresa (row-level) — los permisos son
  globales por rol, no filtrados por sucursal asignada al usuario.
- Purga/retención automática de `audits` — la tabla crece sin límite por
  ahora. **Pendiente**: agregar a `CLAUDE.md` como deuda técnica conocida.
- Auditoría de modelos de muy alto volumen (`AttendanceEvent`,
  `EmployeeScheduleAssignment`, `RotationAssignment`) — ya tienen su propio
  historial funcional (`AttendanceMarkFailure`, `EmployeeDevice`) y
  auditarlos duplicaría datos masivamente sin aportar valor de compliance.

## 1. Roles y permisos

### Paquete y modelo de datos

`spatie/laravel-permission`. `User` usa el trait `HasRoles`. Un usuario
puede tener **varios roles simultáneamente** (relación estándar
muchos-a-muchos del paquete).

`User::canAccessPanel()` pasa de `return true` a bloquear usuarios sin
ningún rol asignado (`$this->roles()->exists()`).

### Permisos CRUD — patrón `BasePolicy`

Para los 33 Resources se generan 5 permisos c/u siguiendo la convención de
nombres de Policy de Laravel: `view_any_{modelo}`, `view_{modelo}`,
`create_{modelo}`, `update_{modelo}`, `delete_{modelo}` (~165 permisos).

En vez de escribir 33 Policies casi idénticas a mano, se crea una
`App\Policies\BasePolicy` genérica que resuelve el nombre del permiso por
reflexión del modelo (`class_basename($model)` → snake_case), implementando
`viewAny`, `view`, `create`, `update`, `delete`. Cada Policy real es una
clase vacía: `class EmployeePolicy extends BasePolicy {}`. Filament detecta
la Policy por convención de nombres y la usa tanto para bloquear las
acciones del Resource como para ocultar el ítem de navegación si el usuario
no tiene `view_any_*`.

### Permisos de acciones de negocio

Cada acción crítica de estado (aprobar préstamo, rechazar adelanto, marcar
entregado, cerrar liquidación, cerrar planilla, exportar banco, etc.) tiene
su propio permiso, con convención `{verbo}_{entidad}` (ej. `approve_loan`,
`close_payroll_period`, `export_bank_payment`). El catálogo completo se
enumera durante la implementación revisando cada Resource — no se fija de
antemano en este documento.

Estas acciones ya tienen condiciones `->visible()`/de estado en el código
actual; se les agrega `&& auth()->user()->can('...')` a la condición
existente, sin restructurar el resto de la acción.

### Super Admin vía `Gate::before`

En vez de sincronizar manualmente los ~200 permisos al rol Super Admin (y
tener que re-sembrar cada vez que se agrega un permiso nuevo), se registra
`Gate::before(fn ($user) => $user->hasRole('Super Admin') ? true : null)`.
Cualquier chequeo de permiso para ese rol retorna `true` automáticamente.

### Roles iniciales

| Rol | Alcance |
|---|---|
| **Super Admin** | Todo (vía `Gate::before`), incluye gestión de usuarios/roles y auditoría. |
| **RRHH** | CRUD sobre Empleados, Contratos, Asistencia, Permisos/Licencias (incl. `approve_employee_leave`/`reject_employee_leave`), Ausencias (justificar/marcar injustificada), Amonestaciones, Vacaciones, Horarios/Rotaciones. Sin permisos de aprobación financiera. |
| **Contador/Nómina** | CRUD + aprobación sobre Nómina, Préstamos, Adelantos, Retiros de Mercadería, Liquidación, Aguinaldo, Lotes Bancarios. Solo lectura (`view`/`view_any`) sobre Empleados/Contratos. |
| **Solo Lectura** | `view`/`view_any` en todos los módulos. Cero `create`/`update`/`delete`/acciones de negocio. |

### UI de administración

- **`RoleResource`** nuevo (grupo Configuración) — CRUD de roles con un
  `CheckboxList` de permisos agrupado por módulo (Empleados, Nómina,
  Préstamos, ...) para que armar un rol custom sea manejable con ~200
  permisos.
- **`UserResource`** — se agrega un `Select` múltiple de roles al
  formulario existente.

### Migración de usuarios existentes

Un seeder (`RoleSeeder` o extensión de `ProductionSeeder`/`DemoSeeder`)
asigna el rol **Super Admin** a todos los usuarios que ya existen en la BD
al momento del deploy, para no romper el acceso de nadie. Roles más finos
se reasignan después desde el panel.

## 2. Auditoría completa

### Extensión de `Auditable`

Se agrega el trait (con `$auditInclude` explícito por modelo — nunca todos
los campos) a los modelos de negocio/config/PII que aún no lo tienen:
`Employee`, `User` (excluyendo `password`/`remember_token` del
`$auditInclude`), `Company`, `Branch`, `Department`, `Position`,
`Deduction`, `Perception`, `Schedule`, `ScheduleDay`, `ScheduleBreak`,
`ShiftTemplate`, `RotationPattern`, `Terminal`, `EmployeeDevice`, `Holiday`,
`CompanyBankAccount`, `Vacation`, `FaceEnrollment`, `Document`,
`EmployeeDeduction`, `EmployeePerception`.

Quedan explícitamente **excluidos** (alto volumen, sin valor de compliance
adicional sobre lo que ya registran): `AttendanceEvent`,
`EmployeeScheduleAssignment`, `RotationAssignment`.

### Eventos de autenticación

Tres listeners sobre `Illuminate\Auth\Events\Login`, `Logout` y `Failed`
que escriben un registro en la misma tabla `audits` (reutilizando
`OwenIt\Auditing\Models\Audit::create()`) con `event = 'login'` /
`'logout'` / `'failed_login'`, IP y user agent. Todo el log de auditoría
vive en una sola tabla — no se crea una tabla paralela para autenticación.

### Cambios de roles/permisos

Al asignar/quitar un rol a un usuario desde `UserResource`, se registra un
`Audit` manual (`event = 'roleAssigned'` / `'roleRemoved'`, `old_values`/
`new_values` con los nombres de los roles) para poder auditar el propio
sistema de permisos.

### UI de auditoría

- **`AuditResource` global** (nuevo, grupo Configuración, solo Super Admin)
  — tabla de solo lectura sobre todos los registros de `audits`, con
  filtros por usuario, modelo auditado, tipo de evento y rango de fechas,
  búsqueda, y vista de detalle con old/new values en formato legible.
- **Tab "Auditoría" por registro** vía la `AuditsRelationManager` que ya
  trae `tapp/filament-auditing`, agregado solo en los 9 Resources de mayor
  valor: `EmployeeResource`, `ContractResource`, `PayrollResource`,
  `LoanResource`, `AdvanceResource`, `MerchandiseWithdrawalResource`,
  `LiquidacionResource`, `UserResource`, `CompanyResource`.

### Retención

Sin política de purga en este alcance — la tabla crece sin límite. Se deja
anotado como deuda técnica conocida en `CLAUDE.md` (sección de notas
importantes), a resolver en una iteración futura si el volumen lo justifica.

## Testing

- Tests de policy: la `BasePolicy` resuelve correctamente el nombre de
  permiso por modelo; un usuario sin el permiso es bloqueado, con el
  permiso pasa.
- Tests de acceso al panel: usuario sin rol no puede acceder; usuario con
  rol sí.
- Tests de rol Super Admin: bypassa cualquier permiso vía `Gate::before`.
- Tests sobre una muestra representativa de acciones de negocio con
  permiso propio (ej. `approve_loan`, `close_payroll_period`).
- Tests de `RoleResource`/`UserResource`: asignar/quitar roles y permisos
  desde el panel.
- Tests de auditoría: cambios en un modelo recién auditado generan un
  `Audit`; login/logout/failed generan el registro correspondiente; cambio
  de rol genera `Audit` con `old_values`/`new_values`.
- Test del seeder de migración: usuarios existentes reciben Super Admin.

## Notas de implementación

- El catálogo completo de ~165 permisos CRUD + acciones de negocio se
  construye y siembra vía un `PermissionSeeder` durante la implementación,
  no se enumera exhaustivamente en este documento.
- La matriz de permisos por rol (RRHH, Contador/Nómina, Solo Lectura) se
  siembra vía un `RoleSeeder` que referencia los nombres de permiso ya
  generados por `PermissionSeeder`.
