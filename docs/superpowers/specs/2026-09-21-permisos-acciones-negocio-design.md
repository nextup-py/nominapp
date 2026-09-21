# Permisos de Acciones de Negocio — Diseño (Plan 2)

## Contexto

Plan 1 (mergeado en PR #170, `main`) instaló `spatie/laravel-permission`, agregó permisos CRUD (`view_any`/`view`/`create`/`update`/`delete`) para los 33 modelos con Resource en Filament vía `BasePolicy` + `PermissionSeeder`, sembró 4 roles iniciales (Super Admin, RRHH, Contador/Nómina, Solo Lectura) y dio de alta `RoleResource`/selector de roles en `UserResource`.

Ese plan explícitamente dejó fuera los **permisos de acciones de negocio** — botones de transición de estado (aprobar, rechazar, cerrar, desembolsar, etc.) que hoy están condicionados solo por el estado del registro (`->visible(fn ($record) => $record->isPending())`), sin ningún chequeo de rol. Este documento diseña esa capa.

Ver el spec original: `docs/superpowers/specs/2026-09-19-roles-permisos-auditoria-design.md`, sección "Permisos de acciones de negocio" (genérica a propósito — decía que el catálogo se define revisando cada Resource durante la implementación).

## Alcance

**Incluye:**
- Catálogo de permisos de negocio para los 12 módulos con acciones de transición de estado reales: Loan, Advance, MerchandiseWithdrawal, DisbursementBatch, Payroll, PayrollPeriod, Liquidación, Aguinaldo, AguinaldoPeriod, EmployeeLeave, Absence, Contract.
- Extensión de `RoleSeeder` (RRHH y Contador/Nómina) con los permisos nuevos correspondientes.
- Extensión de `RoleResource` para mostrar los permisos de negocio junto a los CRUD de cada modelo, agrupados por módulo.
- Gate de `->visible()` en cada acción de fila, header de página y bulk action.
- Tests por permiso nuevo.

**No incluye (fuera de alcance de este plan):**
- Auditoría completa (Plan 3, spec original sección 2).
- Warning: no tiene lifecycle/estado, solo CRUD + export documental — no entra en este catálogo.
- Exports Excel de los 21 módulos restantes sin workflow (Employee, Branch, Department, etc.) — siguen cubiertos por `view_any_{modelo}` de Plan 1, sin permiso nuevo.
- Descarga de PDF de un registro individual ya generado (recibo, contrato firmado) — cubierta por `view_{modelo}`, no es una acción de negocio nueva.
- Nuevos roles — se extienden los 4 existentes.

## Catálogo de permisos

Un permiso por recurso+verbo (no por cada ocurrencia de botón en fila/header/bulk — las variantes de UI que representan la misma transición comparten permiso).

| Módulo | Permiso | Cubre (acciones actuales en el código) |
|---|---|---|
| Loan | `approve_loan` | `activate` (row + view header, label "Aprobar") |
| Loan | `reject_loan` | `reject` (row + view header) |
| Loan | `disburse_loan` | `mark_disbursed` (row + view header) |
| Loan | `cancel_loan` | `cancel` (view header) |
| Loan | `export_loan` | `export_pdf` masivo, `export` PDF de listado (list header) |
| Advance | `approve_advance` | `approve` (row + view header), `approveBulk` |
| Advance | `reject_advance` | `reject` (row + view header), `rejectBulk` |
| Advance | `disburse_advance` | `mark_disbursed` (row + view header), `markDisbursedBulk` |
| Advance | `revert_advance` | `revert_to_pending`, `revert_to_approved` (row + view header), `revertBulk` |
| Advance | `cancel_advance` | `cancel` (view header) |
| Advance | `export_advance` | `pdf_masivo` (bulk), `export` (list header) |
| MerchandiseWithdrawal | `approve_merchandise_withdrawal` | `approve` (row + view header) |
| MerchandiseWithdrawal | `reject_merchandise_withdrawal` | `reject` (row + view header) |
| MerchandiseWithdrawal | `cancel_merchandise_withdrawal` | `cancel` (view header) |
| DisbursementBatch | `confirm_disbursement_batch` | `confirm_batch` (view header) |
| DisbursementBatch | `cancel_disbursement_batch` | `cancel_batch` (view header) |
| Payroll | `approve_payroll` | `approve` (row + view header), `approve_selected` (bulk); también cubre `approve_all_payrolls` de PayrollPeriod |
| Payroll | `disburse_payroll` | `mark_disbursed` (row + view header), `mark_disbursed_selected` (bulk) |
| Payroll | `mark_paid_payroll` | `mark_paid` (row + view header), `mark_paid_selected` (bulk); también cubre `mark_cash_paid` de PayrollPeriod |
| Payroll | `revert_payroll` | `revert_paid`, `revert_to_approved`, `unapprove` (row + view header), `revert_paid_selected`/`unapprove_selected` (bulk) |
| Payroll | `regenerate_payroll` | `regenerate` (row + view header) |
| Payroll | `export_payroll` | `download_pdfs` (bulk), `ExportBulkAction` Excel (bulk) |
| PayrollPeriod | `generate_payrolls_period` | `generate_payrolls`, `regenerate_payrolls` (view + edit header) |
| PayrollPeriod | `close_payroll_period` | `close_period` (view + edit header) |
| PayrollPeriod | `reopen_payroll_period` | `reopen_period` (view + edit header) |
| Liquidación | `calculate_liquidacion` | `calculate`, `recalculate` (row + view header) |
| Liquidación | `close_liquidacion` | `close` (row + view header) |
| Liquidación | `export_liquidacion` | `export_excel` (list header) |
| Aguinaldo | `mark_paid_aguinaldo` | `mark_paid`, `unmark_paid` (row + view header); también cubre `mark_all_paid` de AguinaldoPeriod |
| Aguinaldo | `export_aguinaldo` | `export_excel` (list header) |
| AguinaldoPeriod | `generate_aguinaldos_period` | `generate_aguinaldos` (row + view header) |
| AguinaldoPeriod | `close_aguinaldo_period` | `close_period` (view header) |
| AguinaldoPeriod | `reopen_aguinaldo_period` | `reopen_period` (view header) |
| EmployeeLeave | `approve_employee_leave` | `approve` (row + view header) |
| EmployeeLeave | `reject_employee_leave` | `reject` (row + view header) |
| Absence | `justify_absence` | `justify` (view + edit header), `register_attendance` (view + edit header), `bulk_justify` |
| Absence | `mark_unjustified_absence` | `mark_unjustified` (view + edit header), `bulk_unjustify` |
| Absence | `export_absence` | `export_excel` (list header) |
| Contract | `activate_contract` | `activate` (row + view header), `bulk_activate` |
| Contract | `renew_contract` | `renew` (row + view header) |
| Contract | `suspend_contract` | `suspend` (row + view header), `bulk_suspend` |
| Contract | `reactivate_contract` | `reactivate` (row + view header) |
| Contract | `terminate_contract` | `terminate` (row + view header), `bulk_terminate` |

Total: **43 permisos nuevos** (33 para Contador/Nómina: Loan 5 + Advance 6 + MerchandiseWithdrawal 3 + DisbursementBatch 2 + Payroll 6 + PayrollPeriod 3 + Liquidación 3 + Aguinaldo 2 + AguinaldoPeriod 3; 10 para RRHH: EmployeeLeave 2 + Absence 3 + Contract 5).

**Reutilización de permisos de Plan 1** (sin permiso nuevo):
- "Enviar al Banco" / "Crear Lote Bancario" en Loan/Advance/Payroll/Aguinaldo → requieren `create_disbursement_batch` (ya sembrado en Plan 1).
- `change_payment_method` (Loan), `edit_batch`/`download_txt` (DisbursementBatch), `edit_draft`/`revert_to_draft` (Payroll/PayrollPeriod) → cubiertos por `update_{modelo}` existente.
- Descarga de PDF individual en cualquier módulo → cubierta por `view_{modelo}` existente.

**Puntos a verificar durante la implementación** (no bloquean el diseño, se confirman al tocar el código):
- `AguinaldoPeriod::force_delete` — verificar si tiene guard de estado propio o si ya está cubierto por `delete_aguinaldo_period`.
- `Absence::register_attendance` — confirmar que efectivamente comparte flujo con `justify` antes de unificar el permiso.
- `Contract::generate_pdf`/`upload_signed` — confirmar que no requieren permiso propio más allá de `view_contract`/`update_contract`.

## Matriz de roles

| Rol | Permisos de negocio nuevos |
|---|---|
| Super Admin | Ninguno (bypass vía `Gate::before`, ya implementado) |
| RRHH | `approve_employee_leave`, `reject_employee_leave`, `justify_absence`, `mark_unjustified_absence`, `export_absence`, `activate_contract`, `renew_contract`, `suspend_contract`, `reactivate_contract`, `terminate_contract` |
| Contador/Nómina | Los 33 permisos restantes: todos los de Loan, Advance, MerchandiseWithdrawal, DisbursementBatch, Payroll, PayrollPeriod, Liquidación, Aguinaldo, AguinaldoPeriod |
| Solo Lectura | Ninguno |

Coincide con la matriz ya documentada en el spec original de Plan 1 — este documento solo la concreta con nombres de permiso reales.

## Patrón de implementación

### `BusinessActionPermissionSeeder`

Nuevo seeder junto a `PermissionSeeder`, con su propia constante de catálogo (forma distinta a la de CRUD — no son 5 abilities uniformes por modelo, sino un set de acciones específico por módulo):

```php
class BusinessActionPermissionSeeder extends Seeder
{
    /** @var array<string, array<string, string>> modelo => [accion => label] */
    public const ACTIONS = [
        'loan' => [
            'approve' => 'Aprobar',
            'reject' => 'Rechazar',
            'disburse' => 'Desembolsar',
            'cancel' => 'Cancelar',
            'export' => 'Exportar',
        ],
        'advance' => [
            'approve' => 'Aprobar',
            'reject' => 'Rechazar',
            'disburse' => 'Desembolsar',
            'revert' => 'Revertir',
            'cancel' => 'Cancelar',
            'export' => 'Exportar',
        ],
        // ... resto de los 12 módulos, según el catálogo de arriba
    ];

    public function run(): void
    {
        foreach (self::ACTIONS as $model => $actions) {
            foreach (array_keys($actions) as $action) {
                Permission::firstOrCreate([
                    'name' => "{$action}_{$model}",
                    'guard_name' => 'web',
                ]);
            }
        }
    }
}
```

Se corre después de `PermissionSeeder` en `ProductionSeeder`/`DemoSeeder`:

```php
$this->call([
    PermissionSeeder::class,
    BusinessActionPermissionSeeder::class,
    RoleSeeder::class,
]);
```

### `->visible()` en acciones existentes

Se agrega el chequeo de permiso a la condición de estado ya existente, sin restructurar el resto:

```php
// Antes
Action::make('approve')
    ->visible(fn (Loan $record) => $record->isPending())

// Después
Action::make('approve')
    ->visible(fn (Loan $record) => $record->isPending() && auth()->user()->can('approve_loan'))
```

### Bulk actions

Hoy no tienen `->visible()` (filtran registros elegibles dentro del closure de `->action()`). Se les agrega:

```php
BulkAction::make('approveBulk')
    ->visible(fn () => auth()->user()->can('approve_loan'))
    ->action(function (Collection $records) { /* ya filtra isPending() adentro, sin cambios */ })
```

### `RoleResource` — permisos de negocio dentro del grupo del modelo

`PermissionSeeder::GROUPS` ya agrupa modelos por módulo (Organización, Empleados, Asistencia, Nómina y Créditos, Configuración). En vez de una sección nueva "Acciones de Negocio", el formulario de `RoleResource` se extiende para que, al armar las opciones del `CheckboxList` de cada modelo, además de las 5 abilities CRUD agregue las de `BusinessActionPermissionSeeder::ACTIONS[$model] ?? []` — así el admin ve todo junto: "Préstamo: Ver listado, Ver detalle, Crear, Editar, Eliminar, Aprobar, Rechazar, Desembolsar, Cancelar, Exportar".

```php
foreach (PermissionSeeder::GROUPS as $groupName => $models) {
    $options = [];
    foreach ($models as $model) {
        foreach (PermissionSeeder::ABILITIES as $ability) {
            $options["{$ability}_{$model}"] = PermissionSeeder::ABILITY_LABELS[$ability].' — '.PermissionSeeder::MODEL_LABELS[$model];
        }
        foreach (BusinessActionPermissionSeeder::ACTIONS[$model] ?? [] as $action => $label) {
            $options["{$action}_{$model}"] = $label.' — '.PermissionSeeder::MODEL_LABELS[$model];
        }
    }
    // ... resto igual
}
```

`RoleResource::collectPermissions()` no cambia — ya reconstruye la lista completa a partir de los campos `group_*`, sin importar si el permiso es CRUD o de negocio.

### `RoleSeeder`

Se extiende `syncPermissions()` de RRHH y Contador/Nómina con `array_merge()` sobre los arrays ya existentes, agregando los permisos de negocio de la matriz de arriba.

## Testing

Un test por cada uno de los 43 permisos nuevos (no por cada ocurrencia de botón), verificando dos casos con `Livewire::test(...)->assertActionHidden()` / `assertTableActionHidden()`:
1. Usuario con el rol pero **sin** el permiso → acción oculta, aunque el registro esté en el estado correcto.
2. Usuario con el permiso → acción visible cuando el estado también es correcto.

Se agregan estas aserciones a los archivos de test de Resource ya existentes de cada módulo (no se crean archivos nuevos por módulo), siguiendo el patrón ya usado en `SuperAdminBypassTest`/`RoleResourceTest` de Plan 1: crear un `Role` de prueba con `syncPermissions([...])` puntual en vez de depender de los roles sembrados, para aislar el permiso bajo prueba.

Al finalizar: `php artisan test --compact` completo, sin regresiones (mismo procedimiento que cerró Plan 1 — reconstruir la DB de testing con `migrate:fresh --env=testing` antes de la corrida final si hace falta, y no correr tests en paralelo contra la misma base).

## Notas de implementación

- El orden de ejecución dentro de `PayrollService` (pipeline de calculadoras) no se toca — este plan es puramente de autorización UI, no de lógica de cálculo.
- Ningún estado nuevo se introduce en ningún modelo — las transiciones siguen siendo las mismas, solo se les agrega un chequeo de permiso adicional a la condición de visibilidad ya existente.
- Actualizar la sección "Módulo de Roles y Permisos" de `CLAUDE.md` (agregada en Plan 1) para mencionar que las acciones de negocio ya tienen permisos propios, quitando la frase "no cubre permisos de acciones de negocio" que quedaría desactualizada.
