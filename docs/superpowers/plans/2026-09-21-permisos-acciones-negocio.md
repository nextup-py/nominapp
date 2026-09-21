# Permisos de Acciones de Negocio Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar permisos de negocio (aprobar, rechazar, cerrar, desembolsar, exportar, etc.) a las acciones de transición de estado de los 12 módulos con workflow real, que hoy solo están condicionadas por el estado del registro sin ningún chequeo de rol.

**Architecture:** Un nuevo `BusinessActionPermissionSeeder` siembra `{accion}_{modelo}` para cada acción de negocio catalogada. Cada `->visible()` existente en las acciones de Filament (fila, header de página, bulk) recibe un `&& auth()->user()->can('{permiso}')` adicional a su condición de estado, sin restructurar el resto. `RoleResource` y `RoleSeeder` se extienden para mostrar/asignar estos permisos junto a los CRUD de Plan 1.

**Tech Stack:** Laravel 12, Filament 3.3, `spatie/laravel-permission` (ya instalado en Plan 1), Pest v3.

**Spec:** `docs/superpowers/specs/2026-09-21-permisos-acciones-negocio-design.md`

## Global Constraints

- Convención de nombres: `{accion}_{modelo}`, ej. `approve_loan`, `close_payroll_period` — copiada literal del spec.
- Un permiso por recurso+verbo, no por cada ocurrencia de botón (fila/header/bulk comparten el mismo permiso).
- Solo los 12 módulos con workflow real: Loan, Advance, MerchandiseWithdrawal, DisbursementBatch, Payroll, PayrollPeriod, Liquidación (clase `Liquidacion`), Aguinaldo, AguinaldoPeriod, EmployeeLeave, Absence, Contract. Warning queda explícitamente fuera (sin lifecycle).
- No se crean roles nuevos — se extienden los 4 existentes (Super Admin, RRHH, Contador/Nómina, Solo Lectura).
- No se toca el pipeline de cálculo de nómina (`PayrollService`) ni se introduce ningún estado nuevo en ningún modelo — este plan es puramente de autorización UI.
- **No existen factories** para Loan/Advance/MerchandiseWithdrawal/DisbursementBatch/Payroll/PayrollPeriod/Liquidacion/Aguinaldo/AguinaldoPeriod/EmployeeLeave/Absence/Contract (solo `CompanyBankAccountFactory`, `EmployeeBankAccountFactory`, `EmployeeFactory`, `UserFactory` existen en `database/factories/`). Todos los registros de test se crean manualmente vía `Model::create([...])`, siguiendo el patrón ya usado en `tests/Feature/LoanActivateTest.php` (helpers `makeActivateEmployee()`/`makePendingLoan()`).
- `vendor/bin/pint --dirty` antes de cada commit, sin excepciones.
- Cada test nuevo sigue el patrón ya usado en `SuperAdminBypassTest`/`RoleResourceTest` de Plan 1: `Role::create(['name' => 'Test Role', 'guard_name' => 'web'])->syncPermissions([...])` para aislar el permiso bajo prueba, en vez de depender de los roles sembrados por `RoleSeeder`.

---

## Task 1: `BusinessActionPermissionSeeder` — catálogo de permisos de negocio

**Files:**
- Create: `database/seeders/BusinessActionPermissionSeeder.php`
- Test: `tests/Feature/Seeders/BusinessActionPermissionSeederTest.php`

**Interfaces:**
- Consumes: nada (seeder de base).
- Produces: 43 permisos `{accion}_{modelo}` sembrados en la tabla `permissions`, consumidos por Task 2 (`RoleSeeder`), Task 3 (`RoleResource`) y Tasks 4-15 (gates de `->visible()`).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use Database\Seeders\BusinessActionPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('seeds all 43 business-action permissions', function () {
    (new BusinessActionPermissionSeeder())->run();

    expect(Permission::count())->toBe(43);

    expect(Permission::where('name', 'approve_loan')->exists())->toBeTrue();
    expect(Permission::where('name', 'reject_loan')->exists())->toBeTrue();
    expect(Permission::where('name', 'disburse_loan')->exists())->toBeTrue();
    expect(Permission::where('name', 'cancel_loan')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_loan')->exists())->toBeTrue();

    expect(Permission::where('name', 'approve_advance')->exists())->toBeTrue();
    expect(Permission::where('name', 'reject_advance')->exists())->toBeTrue();
    expect(Permission::where('name', 'disburse_advance')->exists())->toBeTrue();
    expect(Permission::where('name', 'revert_advance')->exists())->toBeTrue();
    expect(Permission::where('name', 'cancel_advance')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_advance')->exists())->toBeTrue();

    expect(Permission::where('name', 'approve_merchandise_withdrawal')->exists())->toBeTrue();
    expect(Permission::where('name', 'reject_merchandise_withdrawal')->exists())->toBeTrue();
    expect(Permission::where('name', 'cancel_merchandise_withdrawal')->exists())->toBeTrue();

    expect(Permission::where('name', 'confirm_disbursement_batch')->exists())->toBeTrue();
    expect(Permission::where('name', 'cancel_disbursement_batch')->exists())->toBeTrue();

    expect(Permission::where('name', 'approve_payroll')->exists())->toBeTrue();
    expect(Permission::where('name', 'disburse_payroll')->exists())->toBeTrue();
    expect(Permission::where('name', 'mark_paid_payroll')->exists())->toBeTrue();
    expect(Permission::where('name', 'revert_payroll')->exists())->toBeTrue();
    expect(Permission::where('name', 'regenerate_payroll')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_payroll')->exists())->toBeTrue();

    expect(Permission::where('name', 'generate_payrolls_period')->exists())->toBeTrue();
    expect(Permission::where('name', 'close_payroll_period')->exists())->toBeTrue();
    expect(Permission::where('name', 'reopen_payroll_period')->exists())->toBeTrue();

    expect(Permission::where('name', 'calculate_liquidacion')->exists())->toBeTrue();
    expect(Permission::where('name', 'close_liquidacion')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_liquidacion')->exists())->toBeTrue();

    expect(Permission::where('name', 'mark_paid_aguinaldo')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_aguinaldo')->exists())->toBeTrue();

    expect(Permission::where('name', 'generate_aguinaldos_period')->exists())->toBeTrue();
    expect(Permission::where('name', 'close_aguinaldo_period')->exists())->toBeTrue();
    expect(Permission::where('name', 'reopen_aguinaldo_period')->exists())->toBeTrue();

    expect(Permission::where('name', 'approve_employee_leave')->exists())->toBeTrue();
    expect(Permission::where('name', 'reject_employee_leave')->exists())->toBeTrue();

    expect(Permission::where('name', 'justify_absence')->exists())->toBeTrue();
    expect(Permission::where('name', 'mark_unjustified_absence')->exists())->toBeTrue();
    expect(Permission::where('name', 'export_absence')->exists())->toBeTrue();

    expect(Permission::where('name', 'activate_contract')->exists())->toBeTrue();
    expect(Permission::where('name', 'renew_contract')->exists())->toBeTrue();
    expect(Permission::where('name', 'suspend_contract')->exists())->toBeTrue();
    expect(Permission::where('name', 'reactivate_contract')->exists())->toBeTrue();
    expect(Permission::where('name', 'terminate_contract')->exists())->toBeTrue();
});

it('is idempotent — running twice does not duplicate permissions', function () {
    (new BusinessActionPermissionSeeder())->run();
    (new BusinessActionPermissionSeeder())->run();

    expect(Permission::count())->toBe(43);
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Seeders/BusinessActionPermissionSeederTest.php`
Expected: FAIL — la clase `Database\Seeders\BusinessActionPermissionSeeder` no existe.

- [ ] **Step 3: Implementar**

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/**
 * Siembra el catálogo de permisos de acciones de negocio (transiciones de
 * estado: aprobar, rechazar, cerrar, desembolsar, exportar, etc.) para los
 * 12 módulos con workflow real. No cubre CRUD (ver PermissionSeeder) ni
 * Warning (sin lifecycle).
 */
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
        'merchandise_withdrawal' => [
            'approve' => 'Aprobar',
            'reject' => 'Rechazar',
            'cancel' => 'Cancelar',
        ],
        'disbursement_batch' => [
            'confirm' => 'Confirmar',
            'cancel' => 'Cancelar',
        ],
        'payroll' => [
            'approve' => 'Aprobar',
            'disburse' => 'Desembolsar',
            'mark_paid' => 'Marcar como Pagada',
            'revert' => 'Revertir',
            'regenerate' => 'Regenerar',
            'export' => 'Exportar',
        ],
        'payroll_period' => [
            'generate_payrolls' => 'Generar Nóminas',
            'close' => 'Cerrar Período',
            'reopen' => 'Reabrir Período',
        ],
        'liquidacion' => [
            'calculate' => 'Calcular',
            'close' => 'Cerrar',
            'export' => 'Exportar',
        ],
        'aguinaldo' => [
            'mark_paid' => 'Marcar como Pagado',
            'export' => 'Exportar',
        ],
        'aguinaldo_period' => [
            'generate_aguinaldos' => 'Generar Aguinaldos',
            'close' => 'Cerrar Período',
            'reopen' => 'Reabrir Período',
        ],
        'employee_leave' => [
            'approve' => 'Aprobar',
            'reject' => 'Rechazar',
        ],
        'absence' => [
            'justify' => 'Justificar',
            'mark_unjustified' => 'Marcar como Injustificada',
            'export' => 'Exportar',
        ],
        'contract' => [
            'activate' => 'Activar',
            'renew' => 'Renovar',
            'suspend' => 'Suspender',
            'reactivate' => 'Reactivar',
            'terminate' => 'Terminar',
        ],
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

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Seeders/BusinessActionPermissionSeederTest.php`
Expected: PASS (2 tests, incluyendo el de idempotencia)

- [ ] **Step 5: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add database/seeders/BusinessActionPermissionSeeder.php tests/Feature/Seeders/BusinessActionPermissionSeederTest.php
git commit -m "feat: agregar BusinessActionPermissionSeeder con catálogo de 43 permisos de negocio"
```

---

## Task 2: Extender `RoleSeeder` con los permisos de negocio de RRHH y Contador/Nómina

**Files:**
- Modify: `database/seeders/RoleSeeder.php`
- Test: `tests/Feature/Seeders/RoleSeederTest.php` (agregar casos nuevos al archivo existente de Plan 1)

**Interfaces:**
- Consumes: los 43 permisos sembrados por `BusinessActionPermissionSeeder` (Task 1) — deben existir en la tabla `permissions` antes de que `syncPermissions()` los pueda asignar (los tests de este task siembran ambos seeders en `beforeEach`, igual que ya lo hacía `RoleSeederTest` de Plan 1).
- Produces: RRHH con 10 permisos de negocio nuevos; Contador/Nómina con 33 permisos de negocio nuevos.

- [ ] **Step 1: Escribir el test que falla**

Agregar estos dos tests a `tests/Feature/Seeders/RoleSeederTest.php` (el archivo ya existe de Plan 1 y ya tiene `beforeEach` que corre `PermissionSeeder` — hay que agregar `BusinessActionPermissionSeeder` a ese `beforeEach` también):

```php
// En el beforeEach() existente, agregar la línea que siembra el nuevo catálogo:
// (new BusinessActionPermissionSeeder())->run();
// junto al (new PermissionSeeder())->run(); que ya está ahí.

it('gives RRHH the business-action permissions for employee leave, absences and contracts', function () {
    (new RoleSeeder())->run();

    $rrhh = Role::findByName('RRHH');

    expect($rrhh->hasPermissionTo('approve_employee_leave'))->toBeTrue();
    expect($rrhh->hasPermissionTo('reject_employee_leave'))->toBeTrue();
    expect($rrhh->hasPermissionTo('justify_absence'))->toBeTrue();
    expect($rrhh->hasPermissionTo('mark_unjustified_absence'))->toBeTrue();
    expect($rrhh->hasPermissionTo('export_absence'))->toBeTrue();
    expect($rrhh->hasPermissionTo('activate_contract'))->toBeTrue();
    expect($rrhh->hasPermissionTo('renew_contract'))->toBeTrue();
    expect($rrhh->hasPermissionTo('suspend_contract'))->toBeTrue();
    expect($rrhh->hasPermissionTo('reactivate_contract'))->toBeTrue();
    expect($rrhh->hasPermissionTo('terminate_contract'))->toBeTrue();

    // RRHH no gestiona finanzas — no debe tener permisos de negocio de esos módulos
    expect($rrhh->hasPermissionTo('approve_loan'))->toBeFalse();
    expect($rrhh->hasPermissionTo('approve_payroll'))->toBeFalse();
});

it('gives Contador/Nómina the business-action permissions for financial modules', function () {
    (new RoleSeeder())->run();

    $contador = Role::findByName('Contador/Nómina');

    expect($contador->hasPermissionTo('approve_loan'))->toBeTrue();
    expect($contador->hasPermissionTo('reject_loan'))->toBeTrue();
    expect($contador->hasPermissionTo('disburse_loan'))->toBeTrue();
    expect($contador->hasPermissionTo('cancel_loan'))->toBeTrue();
    expect($contador->hasPermissionTo('export_loan'))->toBeTrue();

    expect($contador->hasPermissionTo('approve_advance'))->toBeTrue();
    expect($contador->hasPermissionTo('reject_advance'))->toBeTrue();
    expect($contador->hasPermissionTo('disburse_advance'))->toBeTrue();
    expect($contador->hasPermissionTo('revert_advance'))->toBeTrue();
    expect($contador->hasPermissionTo('cancel_advance'))->toBeTrue();
    expect($contador->hasPermissionTo('export_advance'))->toBeTrue();

    expect($contador->hasPermissionTo('approve_merchandise_withdrawal'))->toBeTrue();
    expect($contador->hasPermissionTo('reject_merchandise_withdrawal'))->toBeTrue();
    expect($contador->hasPermissionTo('cancel_merchandise_withdrawal'))->toBeTrue();

    expect($contador->hasPermissionTo('confirm_disbursement_batch'))->toBeTrue();
    expect($contador->hasPermissionTo('cancel_disbursement_batch'))->toBeTrue();

    expect($contador->hasPermissionTo('approve_payroll'))->toBeTrue();
    expect($contador->hasPermissionTo('disburse_payroll'))->toBeTrue();
    expect($contador->hasPermissionTo('mark_paid_payroll'))->toBeTrue();
    expect($contador->hasPermissionTo('revert_payroll'))->toBeTrue();
    expect($contador->hasPermissionTo('regenerate_payroll'))->toBeTrue();
    expect($contador->hasPermissionTo('export_payroll'))->toBeTrue();

    expect($contador->hasPermissionTo('generate_payrolls_period'))->toBeTrue();
    expect($contador->hasPermissionTo('close_payroll_period'))->toBeTrue();
    expect($contador->hasPermissionTo('reopen_payroll_period'))->toBeTrue();

    expect($contador->hasPermissionTo('calculate_liquidacion'))->toBeTrue();
    expect($contador->hasPermissionTo('close_liquidacion'))->toBeTrue();
    expect($contador->hasPermissionTo('export_liquidacion'))->toBeTrue();

    expect($contador->hasPermissionTo('mark_paid_aguinaldo'))->toBeTrue();
    expect($contador->hasPermissionTo('export_aguinaldo'))->toBeTrue();

    expect($contador->hasPermissionTo('generate_aguinaldos_period'))->toBeTrue();
    expect($contador->hasPermissionTo('close_aguinaldo_period'))->toBeTrue();
    expect($contador->hasPermissionTo('reopen_aguinaldo_period'))->toBeTrue();

    // Contador/Nómina no gestiona RRHH — no debe tener estos permisos
    expect($contador->hasPermissionTo('approve_employee_leave'))->toBeFalse();
    expect($contador->hasPermissionTo('activate_contract'))->toBeFalse();
});

it('gives Solo Lectura none of the new business-action permissions', function () {
    (new RoleSeeder())->run();

    $readOnly = Role::findByName('Solo Lectura');

    foreach (BusinessActionPermissionSeeder::ACTIONS as $model => $actions) {
        foreach (array_keys($actions) as $action) {
            expect($readOnly->hasPermissionTo("{$action}_{$model}"))->toBeFalse();
        }
    }
});
```

Agregar el `use Database\Seeders\BusinessActionPermissionSeeder;` al inicio del archivo de test si no está.

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Seeders/RoleSeederTest.php`
Expected: FAIL — RRHH y Contador/Nómina todavía no tienen estos permisos asignados (no existen en la tabla `permissions` porque el `beforeEach` no los siembra, y aunque se agregaran, `RoleSeeder::run()` no los sincroniza).

- [ ] **Step 3: Implementar**

Editar `database/seeders/RoleSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $rrhh = Role::firstOrCreate(['name' => 'RRHH', 'guard_name' => 'web']);
        $rrhh->syncPermissions(array_merge(
            $this->crudPermissionsFor([
                'employee', 'contract', 'contract_template', 'department', 'position',
                'attendance_day', 'attendance_event', 'attendance_mark_failure',
                'employee_leave', 'warning', 'vacation', 'schedule', 'shift_template',
                'rotation_pattern', 'holiday', 'face_enrollment', 'employee_device',
                'terminal', 'branch', 'absence',
            ]),
            [
                'approve_employee_leave', 'reject_employee_leave',
                'justify_absence', 'mark_unjustified_absence', 'export_absence',
                'activate_contract', 'renew_contract', 'suspend_contract',
                'reactivate_contract', 'terminate_contract',
            ],
        ));

        $contador = Role::firstOrCreate(['name' => 'Contador/Nómina', 'guard_name' => 'web']);
        $contador->syncPermissions(array_merge(
            $this->crudPermissionsFor([
                'payroll', 'payroll_period', 'loan', 'advance',
                'merchandise_withdrawal', 'liquidacion', 'aguinaldo',
                'aguinaldo_period', 'disbursement_batch', 'deduction', 'perception',
            ]),
            $this->viewOnlyPermissionsFor(['employee', 'contract']),
            [
                'approve_loan', 'reject_loan', 'disburse_loan', 'cancel_loan', 'export_loan',
                'approve_advance', 'reject_advance', 'disburse_advance', 'revert_advance', 'cancel_advance', 'export_advance',
                'approve_merchandise_withdrawal', 'reject_merchandise_withdrawal', 'cancel_merchandise_withdrawal',
                'confirm_disbursement_batch', 'cancel_disbursement_batch',
                'approve_payroll', 'disburse_payroll', 'mark_paid_payroll', 'revert_payroll', 'regenerate_payroll', 'export_payroll',
                'generate_payrolls_period', 'close_payroll_period', 'reopen_payroll_period',
                'calculate_liquidacion', 'close_liquidacion', 'export_liquidacion',
                'mark_paid_aguinaldo', 'export_aguinaldo',
                'generate_aguinaldos_period', 'close_aguinaldo_period', 'reopen_aguinaldo_period',
            ],
        ));

        $readOnly = Role::firstOrCreate(['name' => 'Solo Lectura', 'guard_name' => 'web']);
        $readOnly->syncPermissions($this->viewOnlyPermissionsFor(PermissionSeeder::MODELS));

        $this->assignSuperAdminToUsersWithoutRoles($superAdmin);
    }

    /**
     * @param  array<int, string>  $models
     * @return array<int, string>
     */
    private function crudPermissionsFor(array $models): array
    {
        $names = [];
        foreach ($models as $model) {
            foreach (PermissionSeeder::ABILITIES as $ability) {
                $names[] = "{$ability}_{$model}";
            }
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $models
     * @return array<int, string>
     */
    private function viewOnlyPermissionsFor(array $models): array
    {
        $names = [];
        foreach ($models as $model) {
            $names[] = "view_any_{$model}";
            $names[] = "view_{$model}";
        }

        return $names;
    }

    private function assignSuperAdminToUsersWithoutRoles(Role $superAdmin): void
    {
        User::doesntHave('roles')->get()->each(
            fn (User $user) => $user->assignRole($superAdmin)
        );
    }
}
```

También editar el `beforeEach()` en `tests/Feature/Seeders/RoleSeederTest.php` para sembrar el nuevo catálogo:

```php
beforeEach(function () {
    (new PermissionSeeder())->run();
    (new BusinessActionPermissionSeeder())->run();
});
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Seeders/RoleSeederTest.php`
Expected: PASS

- [ ] **Step 5: Correr toda la suite de seeders para descartar regresiones**

Run: `php artisan test --compact tests/Feature/Seeders`
Expected: PASS

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add database/seeders/RoleSeeder.php tests/Feature/Seeders/RoleSeederTest.php
git commit -m "feat: asignar permisos de negocio a RRHH y Contador/Nómina en RoleSeeder"
```

---

## Task 3: Mostrar permisos de negocio en `RoleResource`

**Files:**
- Modify: `app/Filament/Resources/RoleResource.php:72-89` (método `form()`)
- Modify: `app/Filament/Resources/RoleResource/Pages/EditRole.php:31-49` (método `mutateFormDataBeforeFill()`)
- Test: `tests/Feature/Filament/RoleResourceTest.php` (agregar casos al archivo existente de Plan 1)

**Interfaces:**
- Consumes: `BusinessActionPermissionSeeder::ACTIONS` (Task 1), `RoleResource::groupFieldKey()` (ya existe de Plan 1).
- Produces: nada — tarea hoja de UI.

- [ ] **Step 1: Escribir el test que falla**

Agregar a `tests/Feature/Filament/RoleResourceTest.php` (ya existe de Plan 1, ya tiene `beforeEach` con `PermissionSeeder`+`RoleSeeder` — agregar `BusinessActionPermissionSeeder` ahí también):

```php
// En el beforeEach() existente, agregar:
// (new BusinessActionPermissionSeeder())->run();

it('creates a role with a business-action permission selected alongside CRUD permissions', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateRole::class)
        ->fillForm([
            'name' => 'Aprobador de Préstamos',
            'group_nomina_y_creditos' => ['view_any_loan', 'view_loan', 'approve_loan', 'reject_loan'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::findByName('Aprobador de Préstamos');
    expect($role->hasPermissionTo('view_any_loan'))->toBeTrue();
    expect($role->hasPermissionTo('approve_loan'))->toBeTrue();
    expect($role->hasPermissionTo('reject_loan'))->toBeTrue();
    expect($role->hasPermissionTo('disburse_loan'))->toBeFalse();
});

it('preloads business-action permissions when editing a role that has them', function () {
    $this->actingAs($this->admin);

    $role = Role::findByName('Contador/Nómina');

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->assertFormSet(['group_nomina_y_creditos' => fn ($state) => in_array('approve_loan', $state, true)
            && in_array('close_payroll_period', $state, true)]);
});

it('keeps business-action permissions after saving an edit that only touches CRUD selections', function () {
    $this->actingAs($this->admin);

    $role = Role::findByName('Contador/Nómina');
    expect($role->hasPermissionTo('approve_loan'))->toBeTrue();

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->fresh()->hasPermissionTo('approve_loan'))->toBeTrue();
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/RoleResourceTest.php`
Expected: FAIL — el `CheckboxList` del grupo "Nómina y Créditos" todavía no incluye `approve_loan`/`reject_loan`/etc. como opciones, así que `fillForm` con esos valores no los persiste; y el tercer test falla porque `EditRole::mutateFormDataBeforeFill` no incluye los permisos de negocio en `group_nomina_y_creditos`, entonces al guardar sin cambios `collectPermissions()` los pierde (el `syncPermissions()` de `afterSave()` los borra).

- [ ] **Step 3: Implementar**

Editar `app/Filament/Resources/RoleResource.php` — agregar el import y extender el loop de construcción de opciones:

```php
// Antes (línea 6, imports)
use Database\Seeders\PermissionSeeder;

// Después
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
```

```php
// Antes (líneas 72-78, dentro de form())
foreach (PermissionSeeder::GROUPS as $groupName => $models) {
    $options = [];
    foreach ($models as $model) {
        foreach (PermissionSeeder::ABILITIES as $ability) {
            $options["{$ability}_{$model}"] = PermissionSeeder::ABILITY_LABELS[$ability].' — '.PermissionSeeder::MODEL_LABELS[$model];
        }
    }

// Después
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
```

Editar `app/Filament/Resources/RoleResource/Pages/EditRole.php`:

```php
// Antes (línea 6, imports)
use Database\Seeders\PermissionSeeder;

// Después
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
```

```php
// Antes (líneas 35-41, dentro de mutateFormDataBeforeFill())
foreach (PermissionSeeder::GROUPS as $groupName => $models) {
    $groupPermissionNames = [];
    foreach ($models as $model) {
        foreach (PermissionSeeder::ABILITIES as $ability) {
            $groupPermissionNames[] = "{$ability}_{$model}";
        }
    }

// Después
foreach (PermissionSeeder::GROUPS as $groupName => $models) {
    $groupPermissionNames = [];
    foreach ($models as $model) {
        foreach (PermissionSeeder::ABILITIES as $ability) {
            $groupPermissionNames[] = "{$ability}_{$model}";
        }
        foreach (array_keys(BusinessActionPermissionSeeder::ACTIONS[$model] ?? []) as $action) {
            $groupPermissionNames[] = "{$action}_{$model}";
        }
    }
```

El resto de ambos métodos queda igual — `collectPermissions()` en `RoleResource.php` no necesita cambios, ya reconstruye la lista completa a partir de `group_*` sin importar el tipo de permiso.

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/RoleResourceTest.php`
Expected: PASS

- [ ] **Step 5: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/RoleResource.php app/Filament/Resources/RoleResource/Pages/EditRole.php tests/Feature/Filament/RoleResourceTest.php
git commit -m "feat: mostrar permisos de negocio junto a los CRUD en RoleResource"
```

---

## Task 4: Permisos de negocio — Loan

**Files:**
- Modify: `app/Filament/Resources/LoanResource.php:518-591` (acciones de fila `activate`, `reject`, `mark_disbursed`), `:623-705` (bulk `activateBulk`, `rejectBulk`), `:707-715` (`ExportBulkAction`)
- Modify: `app/Filament/Resources/LoanResource/Pages/ViewLoan.php:23-157` (header actions `activate`, `reject`, `mark_disbursed`, `cancel`)
- Modify: `app/Filament/Resources/LoanResource/Pages/ListLoans.php:240-259` (header action `export`)
- Test: `tests/Feature/Filament/LoanBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `approve_loan`, `reject_loan`, `disburse_loan`, `cancel_loan`, `export_loan` (ya sembrados por `BusinessActionPermissionSeeder` en Task 1)
- Produces: nada — tarea hoja

**Nota de reconciliación:** el catálogo del spec dice que `export_loan` cubre "`export_pdf` masivo, `export` PDF de listado" pero no existe ningún bulk `export_pdf` en el código actual de `LoanResource.php` (solo hay un `ExportBulkAction` de Excel en la línea 707 y un header action `export` de Excel en `ListLoans.php:240`). Este task aplica `export_loan` a esos dos, que son los exports reales que existen hoy. El `export_pdf` de fila/header individual (línea 585 y `ViewLoan.php:159`) **no** se toca — sigue cubierto por `view_loan` según la regla general del spec ("descarga de PDF de un registro individual ya generado").

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Resources\LoanResource\Pages\ListLoans;
use App\Filament\Resources\LoanResource\Pages\ViewLoan;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crea un empleado con contrato activo para tests de permisos de negocio de Loan.
 */
function makeLoanPermEmployee(): Employee
{
    static $ci = 9100000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpLoanPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucLoanPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepLoanPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosLoanPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'LoanPerm',
        'ci' => (string) $n,
        'email' => "loanperm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'payroll_type' => 'monthly',
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/**
 * Crea un préstamo en el estado dado para el empleado.
 */
function makeLoanPermLoan(Employee $employee, string $status = 'pending'): Loan
{
    return Loan::create([
        'employee_id' => $employee->id,
        'amount' => 1_000_000,
        'interest_rate' => 0,
        'installments_count' => 4,
        'installment_amount' => 250_000,
        'status' => $status,
        'reason' => 'personal',
    ]);
}

/**
 * Autentica un usuario con un rol de prueba que tiene los permisos CRUD de Loan
 * (necesarios para acceder al recurso) más los permisos de negocio indicados.
 *
 * @param  array<int, string>  $businessPermissions
 */
function actingAsLoanPermUser(array $businessPermissions): User
{
    foreach (array_merge(['view_any_loan', 'view_loan'], $businessPermissions) as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions(array_merge(['view_any_loan', 'view_loan'], $businessPermissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

// ─── approve_loan ──────────────────────────────────────────────────────────

it('oculta la acción de aprobar préstamo sin el permiso approve_loan', function () {
    actingAsLoanPermUser([]);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'pending');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionHidden('activate');
});

it('muestra la acción de aprobar préstamo con el permiso approve_loan', function () {
    actingAsLoanPermUser(['approve_loan']);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'pending');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionVisible('activate');
});

// ─── reject_loan ───────────────────────────────────────────────────────────

it('oculta la acción de rechazar préstamo sin el permiso reject_loan', function () {
    actingAsLoanPermUser([]);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'pending');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionHidden('reject');
});

it('muestra la acción de rechazar préstamo con el permiso reject_loan', function () {
    actingAsLoanPermUser(['reject_loan']);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'pending');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionVisible('reject');
});

// ─── disburse_loan ─────────────────────────────────────────────────────────

it('oculta la acción de desembolsar préstamo sin el permiso disburse_loan', function () {
    actingAsLoanPermUser([]);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'approved');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionHidden('mark_disbursed');
});

it('muestra la acción de desembolsar préstamo con el permiso disburse_loan', function () {
    actingAsLoanPermUser(['disburse_loan']);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'approved');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionVisible('mark_disbursed');
});

// ─── cancel_loan ───────────────────────────────────────────────────────────

it('oculta la acción de cancelar préstamo sin el permiso cancel_loan', function () {
    actingAsLoanPermUser([]);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'approved');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionHidden('cancel');
});

it('muestra la acción de cancelar préstamo con el permiso cancel_loan', function () {
    actingAsLoanPermUser(['cancel_loan']);
    $loan = makeLoanPermLoan(makeLoanPermEmployee(), 'approved');

    Livewire::test(ViewLoan::class, ['record' => $loan->getRouteKey()])
        ->assertActionVisible('cancel');
});

// ─── export_loan ───────────────────────────────────────────────────────────

it('oculta la acción de exportar préstamos sin el permiso export_loan', function () {
    actingAsLoanPermUser([]);

    Livewire::test(ListLoans::class)
        ->assertActionHidden('export');
});

it('muestra la acción de exportar préstamos con el permiso export_loan', function () {
    actingAsLoanPermUser(['export_loan']);

    Livewire::test(ListLoans::class)
        ->assertActionVisible('export');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/LoanBusinessPermissionsTest.php`
Expected: FAIL — las acciones son visibles hoy sin necesidad de ningún permiso, así que los tests `oculta...sin el permiso` fallan (la acción está visible cuando no debería).

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/LoanResource.php`:

```php
// Antes (línea 522)
Action::make('activate')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->visible(fn (Loan $record) => $record->isPending())

// Después
Action::make('activate')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->visible(fn (Loan $record) => $record->isPending() && auth()->user()->can('approve_loan'))
```

```php
// Antes (línea 545)
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->visible(fn (Loan $record) => $record->isPending())

// Después
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->visible(fn (Loan $record) => $record->isPending() && auth()->user()->can('reject_loan'))
```

```php
// Antes (línea 570)
Action::make('mark_disbursed')
    ->label('Marcar Entregado')
    ->icon('heroicon-o-banknotes')
    ->color('primary')
    ->visible(fn (Loan $record) => $record->isApproved())

// Después
Action::make('mark_disbursed')
    ->label('Marcar Entregado')
    ->icon('heroicon-o-banknotes')
    ->color('primary')
    ->visible(fn (Loan $record) => $record->isApproved() && auth()->user()->can('disburse_loan'))
```

```php
// Antes (línea 623, sin ->visible())
BulkAction::make('activateBulk')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->requiresConfirmation()

// Después
BulkAction::make('activateBulk')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->visible(fn () => auth()->user()->can('approve_loan'))
    ->requiresConfirmation()
```

```php
// Antes (línea 664, sin ->visible())
BulkAction::make('rejectBulk')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->requiresConfirmation()

// Después
BulkAction::make('rejectBulk')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->visible(fn () => auth()->user()->can('reject_loan'))
    ->requiresConfirmation()
```

```php
// Antes (línea 707, sin ->visible())
ExportBulkAction::make()
    ->exports([
        ExcelExport::make()
            ->fromTable()
            ->withFilename('prestamos_'.now()->format('Y_m_d_H_i_s').'.xlsx'),
    ])
    ->label('Exportar a Excel')
    ->color('info')
    ->icon('heroicon-o-arrow-down-tray'),

// Después
ExportBulkAction::make()
    ->exports([
        ExcelExport::make()
            ->fromTable()
            ->withFilename('prestamos_'.now()->format('Y_m_d_H_i_s').'.xlsx'),
    ])
    ->label('Exportar a Excel')
    ->color('info')
    ->icon('heroicon-o-arrow-down-tray')
    ->visible(fn () => auth()->user()->can('export_loan')),
```

Editar `app/Filament/Resources/LoanResource/Pages/ViewLoan.php`:

```php
// Antes (línea 27)
Action::make('activate')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->visible(fn () => $this->record->isPending())

// Después
Action::make('activate')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->visible(fn () => $this->record->isPending() && auth()->user()->can('approve_loan'))
```

```php
// Antes (línea 61)
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->visible(fn () => $this->record->isPending())

// Después
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->visible(fn () => $this->record->isPending() && auth()->user()->can('reject_loan'))
```

```php
// Antes (línea 96)
Action::make('mark_disbursed')
    ->label('Marcar como Entregado')
    ->icon('heroicon-o-banknotes')
    ->color('primary')
    ->visible(fn () => $this->record->isApproved())

// Después
Action::make('mark_disbursed')
    ->label('Marcar como Entregado')
    ->icon('heroicon-o-banknotes')
    ->color('primary')
    ->visible(fn () => $this->record->isApproved() && auth()->user()->can('disburse_loan'))
```

```php
// Antes (línea 125)
Action::make('cancel')
    ->label('Cancelar')
    ->icon('heroicon-o-minus-circle')
    ->color('danger')
    ->visible(fn () => $this->record->isPending() || $this->record->isApproved())

// Después
Action::make('cancel')
    ->label('Cancelar')
    ->icon('heroicon-o-minus-circle')
    ->color('danger')
    ->visible(fn () => ($this->record->isPending() || $this->record->isApproved()) && auth()->user()->can('cancel_loan'))
```

Editar `app/Filament/Resources/LoanResource/Pages/ListLoans.php`:

```php
// Antes (línea 240, sin ->visible())
Action::make('export')
    ->label('Exportar Excel')
    ->icon('heroicon-o-arrow-down-tray')
    ->color('info')
    ->requiresConfirmation()

// Después
Action::make('export')
    ->label('Exportar Excel')
    ->icon('heroicon-o-arrow-down-tray')
    ->color('info')
    ->visible(fn () => auth()->user()->can('export_loan'))
    ->requiresConfirmation()
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/LoanBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/LoanActivateTest.php tests/Feature/LoanInstallmentCalculatorTest.php tests/Feature/LoanResourceFilterTest.php`
Expected: PASS (estos tests operan directamente sobre el modelo o usan Super Admin, que hace bypass de todo permiso vía `Gate::before`, así que no deberían verse afectados)

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/LoanResource.php app/Filament/Resources/LoanResource/Pages/ViewLoan.php app/Filament/Resources/LoanResource/Pages/ListLoans.php tests/Feature/Filament/LoanBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a préstamos"
```

---

## Task 5: Permisos de negocio — Advance

**Files:**
- Modify: `app/Filament/Resources/AdvanceResource.php:520-661` (acciones de fila `approve`, `reject`, `revert_to_pending`, `mark_disbursed`, `revert_to_approved`), `:667-861` (bulk `approveBulk`, `rejectBulk`, `markDisbursedBulk`, `revertBulk`, `pdf_masivo`)
- Modify: `app/Filament/Resources/AdvanceResource/Pages/ViewAdvance.php:29-247` (header actions `approve`, `reject`, `cancel`, `revert_to_pending`, `mark_disbursed`, `revert_to_approved`)
- Modify: `app/Filament/Pages/AdvanceReport.php:137-165` (header action `export`, Excel) — ver nota de reconciliación abajo
- Test: `tests/Feature/Filament/AdvanceBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `approve_advance`, `reject_advance`, `disburse_advance`, `revert_advance`, `cancel_advance`, `export_advance` (ya sembrados por `BusinessActionPermissionSeeder` en Task 1)
- Produces: nada — tarea hoja

**Nota de reconciliación:** el spec ubica el header action `export` (Excel) en el "list header" de `ListAdvances`, pero en el código actual `ListAdvances.php` **no tiene** ningún header action de exportación Excel — la única acción `export` (Excel, con selector de columnas) vive en `app/Filament/Pages/AdvanceReport.php:137`, una `Page` de Filament separada, alcanzable desde el botón "Ver Reporte" del header de `ListAdvances`. Este task aplica `export_advance` ahí en vez de en `ListAdvances.php`. `AdvanceReport` es una `Page` sin política CRUD propia (no requiere `view_any_advance`/`view_advance` para acceder), así que el test de `export_advance` no necesita esos permisos previos. El `export_pdf` de esa misma página (línea 92) no se toca — no está en el catálogo del spec.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Pages\AdvanceReport;
use App\Filament\Resources\AdvanceResource\Pages\ViewAdvance;
use App\Models\Advance;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crea un empleado con contrato activo para tests de permisos de negocio de Advance.
 */
function makeAdvancePermEmployee(): Employee
{
    static $ci = 9200000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpAdvPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucAdvPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepAdvPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosAdvPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'AdvPerm',
        'ci' => (string) $n,
        'email' => "advperm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'payroll_type' => 'monthly',
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/**
 * Crea un adelanto en el estado dado para el empleado.
 */
function makeAdvancePermAdvance(Employee $employee, string $status = 'pending'): Advance
{
    return Advance::create([
        'employee_id' => $employee->id,
        'amount' => 500_000,
        'status' => $status,
        'payment_method' => 'transfer',
    ]);
}

/**
 * Autentica un usuario con un rol de prueba que tiene los permisos CRUD de Advance
 * (necesarios para acceder al recurso) más los permisos de negocio indicados.
 *
 * @param  array<int, string>  $businessPermissions
 */
function actingAsAdvancePermUser(array $businessPermissions, bool $withCrud = true): User
{
    $crud = $withCrud ? ['view_any_advance', 'view_advance'] : [];

    foreach (array_merge($crud, $businessPermissions) as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions(array_merge($crud, $businessPermissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

// ─── approve_advance ───────────────────────────────────────────────────────

it('oculta la acción de aprobar adelanto sin el permiso approve_advance', function () {
    actingAsAdvancePermUser([]);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionHidden('approve');
});

it('muestra la acción de aprobar adelanto con el permiso approve_advance', function () {
    actingAsAdvancePermUser(['approve_advance']);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionVisible('approve');
});

// ─── reject_advance ────────────────────────────────────────────────────────

it('oculta la acción de rechazar adelanto sin el permiso reject_advance', function () {
    actingAsAdvancePermUser([]);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionHidden('reject');
});

it('muestra la acción de rechazar adelanto con el permiso reject_advance', function () {
    actingAsAdvancePermUser(['reject_advance']);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionVisible('reject');
});

// ─── disburse_advance ──────────────────────────────────────────────────────

it('oculta la acción de desembolsar adelanto sin el permiso disburse_advance', function () {
    actingAsAdvancePermUser([]);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'approved');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionHidden('mark_disbursed');
});

it('muestra la acción de desembolsar adelanto con el permiso disburse_advance', function () {
    actingAsAdvancePermUser(['disburse_advance']);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'approved');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionVisible('mark_disbursed');
});

// ─── revert_advance ────────────────────────────────────────────────────────

it('oculta la acción de revertir adelanto sin el permiso revert_advance', function () {
    actingAsAdvancePermUser([]);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'approved');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionHidden('revert_to_pending');
});

it('muestra la acción de revertir adelanto con el permiso revert_advance', function () {
    actingAsAdvancePermUser(['revert_advance']);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'approved');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionVisible('revert_to_pending');
});

// ─── cancel_advance ────────────────────────────────────────────────────────

it('oculta la acción de cancelar adelanto sin el permiso cancel_advance', function () {
    actingAsAdvancePermUser([]);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionHidden('cancel');
});

it('muestra la acción de cancelar adelanto con el permiso cancel_advance', function () {
    actingAsAdvancePermUser(['cancel_advance']);
    $advance = makeAdvancePermAdvance(makeAdvancePermEmployee(), 'pending');

    Livewire::test(ViewAdvance::class, ['record' => $advance->getRouteKey()])
        ->assertActionVisible('cancel');
});

// ─── export_advance ────────────────────────────────────────────────────────

it('oculta la acción de exportar adelantos sin el permiso export_advance', function () {
    actingAsAdvancePermUser([], withCrud: false);

    Livewire::test(AdvanceReport::class)
        ->assertActionHidden('export');
});

it('muestra la acción de exportar adelantos con el permiso export_advance', function () {
    actingAsAdvancePermUser(['export_advance'], withCrud: false);

    Livewire::test(AdvanceReport::class)
        ->assertActionVisible('export');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/AdvanceBusinessPermissionsTest.php`
Expected: FAIL — los tests `oculta...sin el permiso` fallan porque hoy nada las oculta salvo el estado del registro.

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/AdvanceResource.php`:

```php
// Antes (línea 524)
Action::make('approve')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->visible(fn (Advance $record) => $record->isPending())

// Después
Action::make('approve')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->visible(fn (Advance $record) => $record->isPending() && auth()->user()->can('approve_advance'))
```

```php
// Antes (línea 550)
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->visible(fn (Advance $record) => $record->isPending())

// Después
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->visible(fn (Advance $record) => $record->isPending() && auth()->user()->can('reject_advance'))
```

```php
// Antes (línea 575)
Action::make('revert_to_pending')
    ->label('Desaprobar')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->visible(fn (Advance $record) => $record->isApproved() && $record->disbursement_batch_id === null)

// Después
Action::make('revert_to_pending')
    ->label('Desaprobar')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->visible(fn (Advance $record) => $record->isApproved() && $record->disbursement_batch_id === null && auth()->user()->can('revert_advance'))
```

```php
// Antes (línea 594)
Action::make('mark_disbursed')
    ->label('Marcar Entregado')
    ->icon('heroicon-o-banknotes')
    ->color('primary')
    ->visible(fn (Advance $record) => $record->isApproved())

// Después
Action::make('mark_disbursed')
    ->label('Marcar Entregado')
    ->icon('heroicon-o-banknotes')
    ->color('primary')
    ->visible(fn (Advance $record) => $record->isApproved() && auth()->user()->can('disburse_advance'))
```

```php
// Antes (línea 640)
Action::make('revert_to_approved')
    ->label('Revertir')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->visible(fn (Advance $record) => $record->isDisbursed() && $record->payroll_id === null)

// Después
Action::make('revert_to_approved')
    ->label('Revertir')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->visible(fn (Advance $record) => $record->isDisbursed() && $record->payroll_id === null && auth()->user()->can('revert_advance'))
```

```php
// Antes (línea 667, sin ->visible())
BulkAction::make('approveBulk')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->modalHeading('Aprobar Adelantos')

// Después
BulkAction::make('approveBulk')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->visible(fn () => auth()->user()->can('approve_advance'))
    ->modalHeading('Aprobar Adelantos')
```

```php
// Antes (línea 716, sin ->visible())
BulkAction::make('rejectBulk')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->requiresConfirmation()

// Después
BulkAction::make('rejectBulk')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->visible(fn () => auth()->user()->can('reject_advance'))
    ->requiresConfirmation()
```

```php
// Antes (línea 759, sin ->visible())
BulkAction::make('markDisbursedBulk')
    ->label('Marcar como Entregados (Efectivo)')
    ->icon('heroicon-o-banknotes')
    ->color('primary')
    ->modalHeading('Marcar Adelantos en Efectivo como Entregados')

// Después
BulkAction::make('markDisbursedBulk')
    ->label('Marcar como Entregados (Efectivo)')
    ->icon('heroicon-o-banknotes')
    ->color('primary')
    ->visible(fn () => auth()->user()->can('disburse_advance'))
    ->modalHeading('Marcar Adelantos en Efectivo como Entregados')
```

```php
// Antes (línea 813, sin ->visible())
BulkAction::make('revertBulk')
    ->label('Revertir a Aprobado')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->requiresConfirmation()

// Después
BulkAction::make('revertBulk')
    ->label('Revertir a Aprobado')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->visible(fn () => auth()->user()->can('revert_advance'))
    ->requiresConfirmation()
```

```php
// Antes (línea 852, sin ->visible())
BulkAction::make('pdf_masivo')
    ->label('Descargar PDF')
    ->icon('heroicon-o-arrow-down-tray')
    ->color('gray')
    ->action(function (Collection $records, Component $livewire) {

// Después
BulkAction::make('pdf_masivo')
    ->label('Descargar PDF')
    ->icon('heroicon-o-arrow-down-tray')
    ->color('gray')
    ->visible(fn () => auth()->user()->can('export_advance'))
    ->action(function (Collection $records, Component $livewire) {
```

Editar `app/Filament/Resources/AdvanceResource/Pages/ViewAdvance.php`:

```php
// Antes (línea 33)
Action::make('approve')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->visible(fn () => $this->record->isPending())

// Después
Action::make('approve')
    ->label('Aprobar')
    ->icon('heroicon-o-check')
    ->color('success')
    ->visible(fn () => $this->record->isPending() && auth()->user()->can('approve_advance'))
```

```php
// Antes (línea 69)
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->visible(fn () => $this->record->isPending())

// Después
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->visible(fn () => $this->record->isPending() && auth()->user()->can('reject_advance'))
```

```php
// Antes (línea 104)
Action::make('cancel')
    ->label('Cancelar')
    ->icon('heroicon-o-minus-circle')
    ->color('danger')
    ->visible(fn () => $this->record->isPending() || $this->record->isApproved())

// Después
Action::make('cancel')
    ->label('Cancelar')
    ->icon('heroicon-o-minus-circle')
    ->color('danger')
    ->visible(fn () => ($this->record->isPending() || $this->record->isApproved()) && auth()->user()->can('cancel_advance'))
```

```php
// Antes (línea 138)
Action::make('revert_to_pending')
    ->label('Desaprobar')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->visible(fn () => $this->record->isApproved() && $this->record->disbursement_batch_id === null)

// Después
Action::make('revert_to_pending')
    ->label('Desaprobar')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->visible(fn () => $this->record->isApproved() && $this->record->disbursement_batch_id === null && auth()->user()->can('revert_advance'))
```

```php
// Antes (línea 167)
Action::make('mark_disbursed')
    ->label('Marcar como Entregado')
    ->icon('heroicon-o-banknotes')
    ->color('primary')
    ->visible(fn () => $this->record->isApproved())

// Después
Action::make('mark_disbursed')
    ->label('Marcar como Entregado')
    ->icon('heroicon-o-banknotes')
    ->color('primary')
    ->visible(fn () => $this->record->isApproved() && auth()->user()->can('disburse_advance'))
```

```php
// Antes (línea 224)
Action::make('revert_to_approved')
    ->label('Revertir a Aprobado')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->visible(fn () => $this->record->isDisbursed() && $this->record->payroll_id === null)

// Después
Action::make('revert_to_approved')
    ->label('Revertir a Aprobado')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->visible(fn () => $this->record->isDisbursed() && $this->record->payroll_id === null && auth()->user()->can('revert_advance'))
```

Editar `app/Filament/Pages/AdvanceReport.php`:

```php
// Antes (línea 137)
Action::make('export')
    ->label('Exportar Excel')
    ->icon('heroicon-o-table-cells')
    ->color('gray')
    ->modalHeading('Exportar reporte de adelantos')

// Después
Action::make('export')
    ->label('Exportar Excel')
    ->icon('heroicon-o-table-cells')
    ->color('gray')
    ->visible(fn () => auth()->user()->can('export_advance'))
    ->modalHeading('Exportar reporte de adelantos')
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/AdvanceBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/AdvanceTest.php tests/Feature/AdvanceCalculatorTest.php tests/Feature/AdvanceResourceFilterTest.php`
Expected: PASS

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/AdvanceResource.php app/Filament/Resources/AdvanceResource/Pages/ViewAdvance.php app/Filament/Pages/AdvanceReport.php tests/Feature/Filament/AdvanceBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a adelantos"
```

---

## Task 6: Permisos de negocio — MerchandiseWithdrawal

**Files:**
- Modify: `app/Filament/Resources/MerchandiseWithdrawalResource.php:340-376` (acciones de fila `approve`, `reject`)
- Modify: `app/Filament/Resources/MerchandiseWithdrawalResource/Pages/ViewMerchandiseWithdrawal.php:26-138` (header actions `approve`, `reject`, `cancel`)
- Test: `tests/Feature/Filament/MerchandiseWithdrawalBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `approve_merchandise_withdrawal`, `reject_merchandise_withdrawal`, `cancel_merchandise_withdrawal` (ya sembrados por `BusinessActionPermissionSeeder` en Task 1)
- Produces: nada — tarea hoja

**Nota:** `ListMerchandiseWithdrawals.php` no tiene ninguna acción de negocio en el catálogo del spec (solo `go_to_report` y `CreateAction`, cubiertos por `create_merchandise_withdrawal` de Plan 1) — no requiere cambios. Los `bulkActions` del recurso están vacíos (`BulkActionGroup::make([])` en `MerchandiseWithdrawalResource.php:387`), así que no hay bulk actions que tocar.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Resources\MerchandiseWithdrawalResource\Pages\ViewMerchandiseWithdrawal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\MerchandiseWithdrawal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crea un empleado activo para tests de permisos de negocio de MerchandiseWithdrawal.
 */
function makeMerchPermEmployee(): Employee
{
    static $ci = 9400000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpMerchPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucMerchPerm {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Test',
        'last_name' => 'MerchPerm',
        'ci' => (string) $n,
        'email' => "merchperm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
}

/**
 * Crea un retiro de mercadería en el estado dado para el empleado.
 */
function makeMerchPermWithdrawal(Employee $employee, string $status = 'pending'): MerchandiseWithdrawal
{
    return MerchandiseWithdrawal::create([
        'employee_id' => $employee->id,
        'total_amount' => 500_000,
        'installments_count' => 2,
        'installment_amount' => 250_000,
        'outstanding_balance' => 500_000,
        'status' => $status,
    ]);
}

/**
 * Autentica un usuario con un rol de prueba que tiene los permisos CRUD de
 * MerchandiseWithdrawal (necesarios para acceder al recurso) más los permisos
 * de negocio indicados.
 *
 * @param  array<int, string>  $businessPermissions
 */
function actingAsMerchPermUser(array $businessPermissions): User
{
    foreach (array_merge(['view_any_merchandise_withdrawal', 'view_merchandise_withdrawal'], $businessPermissions) as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions(array_merge(['view_any_merchandise_withdrawal', 'view_merchandise_withdrawal'], $businessPermissions));

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

// ─── approve_merchandise_withdrawal ────────────────────────────────────────

it('oculta la acción de aprobar retiro sin el permiso approve_merchandise_withdrawal', function () {
    actingAsMerchPermUser([]);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'pending');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionHidden('approve');
});

it('muestra la acción de aprobar retiro con el permiso approve_merchandise_withdrawal', function () {
    actingAsMerchPermUser(['approve_merchandise_withdrawal']);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'pending');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionVisible('approve');
});

// ─── reject_merchandise_withdrawal ─────────────────────────────────────────

it('oculta la acción de rechazar retiro sin el permiso reject_merchandise_withdrawal', function () {
    actingAsMerchPermUser([]);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'pending');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionHidden('reject');
});

it('muestra la acción de rechazar retiro con el permiso reject_merchandise_withdrawal', function () {
    actingAsMerchPermUser(['reject_merchandise_withdrawal']);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'pending');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionVisible('reject');
});

// ─── cancel_merchandise_withdrawal ──────────────────────────────────────────

it('oculta la acción de cancelar retiro sin el permiso cancel_merchandise_withdrawal', function () {
    actingAsMerchPermUser([]);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'approved');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionHidden('cancel');
});

it('muestra la acción de cancelar retiro con el permiso cancel_merchandise_withdrawal', function () {
    actingAsMerchPermUser(['cancel_merchandise_withdrawal']);
    $withdrawal = makeMerchPermWithdrawal(makeMerchPermEmployee(), 'approved');

    Livewire::test(ViewMerchandiseWithdrawal::class, ['record' => $withdrawal->getRouteKey()])
        ->assertActionVisible('cancel');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/MerchandiseWithdrawalBusinessPermissionsTest.php`
Expected: FAIL — los tests `oculta...sin el permiso` fallan porque hoy las acciones son visibles solo con la condición de estado, sin ningún chequeo de permiso.

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/MerchandiseWithdrawalResource.php`:

```php
// Antes (línea 344)
Action::make('approve')
    ->label('Aprobar')
    ->icon('heroicon-o-play')
    ->color('success')
    ->visible(fn (MerchandiseWithdrawal $record) => $record->isPending())

// Después
Action::make('approve')
    ->label('Aprobar')
    ->icon('heroicon-o-play')
    ->color('success')
    ->visible(fn (MerchandiseWithdrawal $record) => $record->isPending() && auth()->user()->can('approve_merchandise_withdrawal'))
```

```php
// Antes (línea 363)
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('danger')
    ->visible(fn (MerchandiseWithdrawal $record) => $record->isPending())

// Después
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('danger')
    ->visible(fn (MerchandiseWithdrawal $record) => $record->isPending() && auth()->user()->can('reject_merchandise_withdrawal'))
```

Editar `app/Filament/Resources/MerchandiseWithdrawalResource/Pages/ViewMerchandiseWithdrawal.php`:

```php
// Antes (línea 30)
Action::make('approve')
    ->label('Aprobar')
    ->icon('heroicon-o-play')
    ->color('success')
    ->visible(fn () => $this->record->isPending())

// Después
Action::make('approve')
    ->label('Aprobar')
    ->icon('heroicon-o-play')
    ->color('success')
    ->visible(fn () => $this->record->isPending() && auth()->user()->can('approve_merchandise_withdrawal'))
```

```php
// Antes (línea 65)
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('danger')
    ->visible(fn () => $this->record->isPending())

// Después
Action::make('reject')
    ->label('Rechazar')
    ->icon('heroicon-o-x-circle')
    ->color('danger')
    ->visible(fn () => $this->record->isPending() && auth()->user()->can('reject_merchandise_withdrawal'))
```

```php
// Antes (línea 100)
Action::make('cancel')
    ->label('Cancelar')
    ->icon('heroicon-o-minus-circle')
    ->color('warning')
    ->visible(fn () => $this->record->isApproved() && $this->record->paid_installments_count === 0)

// Después
Action::make('cancel')
    ->label('Cancelar')
    ->icon('heroicon-o-minus-circle')
    ->color('warning')
    ->visible(fn () => $this->record->isApproved() && $this->record->paid_installments_count === 0 && auth()->user()->can('cancel_merchandise_withdrawal'))
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/MerchandiseWithdrawalBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/MerchandiseWithdrawalResourceFilterTest.php`
Expected: PASS

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/MerchandiseWithdrawalResource.php app/Filament/Resources/MerchandiseWithdrawalResource/Pages/ViewMerchandiseWithdrawal.php tests/Feature/Filament/MerchandiseWithdrawalBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a retiros de mercadería"
```

---

## Task 7: Permisos de negocio — DisbursementBatch

**Files:**
- Modify: `app/Filament/Resources/DisbursementBatchResource/Pages/ViewDisbursementBatch.php:225-229` (`confirm_batch`), `:333-337` (`cancel_batch`)
- Test: `tests/Feature/Filament/DisbursementBatchBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `confirm_disbursement_batch`, `cancel_disbursement_batch` (ya sembrados por `BusinessActionPermissionSeeder` en Task 1)
- Produces: nada — tarea hoja

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Resources\DisbursementBatchResource\Pages\ViewDisbursementBatch;
use App\Models\Company;
use App\Models\DisbursementBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Crea un lote de pago pendiente, listo para confirmar/cancelar. */
function makeBizBatch(string $status = 'pending'): DisbursementBatch
{
    static $ci = 7000000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpBiz {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);

    $admin = User::firstOrCreate(
        ['email' => 'batchperm_admin@test.com'],
        ['name' => 'Batch Perm Admin', 'password' => bcrypt('password')],
    );

    return DisbursementBatch::create([
        'type' => 'loan',
        'company_id' => $company->id,
        'fecha_credito' => today()->addDays(3),
        'status' => $status,
        'created_by_id' => $admin->id,
    ]);
}

/** Crea un usuario con un Role de prueba que solo tiene los permisos indicados. */
function actingAsBizUser(array $permissions): User
{
    static $roleN = 0;
    $roleN++;

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => "Test Role Batch {$roleN}", 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('respeta el permiso confirm_disbursement_batch en la acción Confirmar Lote', function () {
    $batch = makeBizBatch('pending');

    $this->actingAs(actingAsBizUser([]));
    Livewire::test(ViewDisbursementBatch::class, ['record' => $batch->getRouteKey()])
        ->assertActionHidden('confirm_batch');

    $this->actingAs(actingAsBizUser(['confirm_disbursement_batch']));
    Livewire::test(ViewDisbursementBatch::class, ['record' => $batch->getRouteKey()])
        ->assertActionVisible('confirm_batch');
});

it('respeta el permiso cancel_disbursement_batch en la acción Cancelar Lote', function () {
    $batch = makeBizBatch('pending');

    $this->actingAs(actingAsBizUser([]));
    Livewire::test(ViewDisbursementBatch::class, ['record' => $batch->getRouteKey()])
        ->assertActionHidden('cancel_batch');

    $this->actingAs(actingAsBizUser(['cancel_disbursement_batch']));
    Livewire::test(ViewDisbursementBatch::class, ['record' => $batch->getRouteKey()])
        ->assertActionVisible('cancel_batch');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/DisbursementBatchBusinessPermissionsTest.php`
Expected: FAIL — ambas acciones son visibles solo por el estado `pending`, sin chequeo de permiso todavía, así que `assertActionHidden` falla para el usuario sin permiso.

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/DisbursementBatchResource/Pages/ViewDisbursementBatch.php` (línea 229, dentro de `confirm_batch`):

```php
// Antes
->visible(fn () => $this->record->isPending())

// Después
->visible(fn () => $this->record->isPending() && auth()->user()->can('confirm_disbursement_batch'))
```

Editar la misma clase (línea 337, dentro de `cancel_batch`):

```php
// Antes
->visible(fn () => $this->record->isPending())

// Después
->visible(fn () => $this->record->isPending() && auth()->user()->can('cancel_disbursement_batch'))
```

(Nota: `edit_batch` en línea 45 y `download_txt` en línea 85 usan la misma condición `$this->record->isPending()` pero NO se tocan en este task — quedan cubiertas por `update_disbursement_batch` de Plan 1, según el catálogo aprobado.)

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/DisbursementBatchBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/DisbursementBatchTest.php`
Expected: PASS — este archivo cubre el ciclo de vida de `DisbursementBatch` a nivel de modelo, no debería verse afectado por el cambio de `->visible()`, pero se corre para confirmar que ningún helper compartido se vio impactado.

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/DisbursementBatchResource/Pages/ViewDisbursementBatch.php tests/Feature/Filament/DisbursementBatchBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a lotes bancarios"
```

---

## Task 8: Permisos de negocio — Payroll

**Files:**
- Modify: `app/Filament/Resources/PayrollResource.php:229` (`approve`), `:248` (`mark_disbursed`), `:266` (`mark_paid`), `:285` (`revert_paid`), `:304` (`revert_to_approved`), `:327` (`unapprove`), `:354` (`regenerate`), `:361-387` (`approve_selected` bulk, sin `->visible()`), `:389-411` (`mark_disbursed_selected` bulk, sin `->visible()`), `:413-435` (`mark_paid_selected` bulk, sin `->visible()`), `:437-459` (`revert_paid_selected` bulk, sin `->visible()`), `:461-487` (`unapprove_selected` bulk, sin `->visible()`), `:489-548` (`download_pdfs` bulk, sin `->visible()`), `:550-556` (`ExportBulkAction` Excel, sin `->visible()`)
- Modify: `app/Filament/Resources/PayrollResource/Pages/ViewPayroll.php:67` (`approve`), `:88` (`mark_disbursed`), `:108` (`mark_paid`), `:129` (`revert_paid`), `:150` (`revert_to_approved`), `:175` (`unapprove`), `:212` (`regenerate`)
- Test: `tests/Feature/Filament/PayrollBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `approve_payroll`, `disburse_payroll`, `mark_paid_payroll`, `revert_payroll`, `regenerate_payroll`, `export_payroll` (ya sembrados por `BusinessActionPermissionSeeder` en Task 1)
- Produces: nada — tarea hoja (los reusos de `approve_payroll`/`mark_paid_payroll` en `PayrollPeriod::approve_all_payrolls`/`mark_cash_paid` se cubren en Task 9, no acá)

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Resources\PayrollResource\Pages\ViewPayroll;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Crea un empleado con contrato activo, listo para tener recibos de nómina. */
function makePayrollPermEmployee(): Employee
{
    static $ci = 7500000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'Perm',
        'ci' => (string) $n,
        'email' => "perm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'payroll_type' => 'monthly',
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
        'payment_method' => 'transfer',
    ]);

    return $employee->fresh();
}

/** Crea un período de nómina mínimo. */
function makePayrollPermPeriod(): PayrollPeriod
{
    $start = Carbon::now()->startOfMonth();

    return PayrollPeriod::create([
        'name' => $start->format('F Y'),
        'start_date' => $start->toDateString(),
        'end_date' => $start->copy()->endOfMonth()->toDateString(),
        'frequency' => 'monthly',
        'status' => 'processing',
    ]);
}

/** Crea un recibo de nómina en el estado indicado. */
function makePayrollPermRecord(string $status = 'draft'): Payroll
{
    $employee = makePayrollPermEmployee();
    $period = makePayrollPermPeriod();

    return Payroll::create([
        'employee_id' => $employee->id,
        'payroll_period_id' => $period->id,
        'status' => $status,
        'base_salary' => 2_550_000,
        'gross_salary' => 2_550_000,
        'net_salary' => 2_550_000,
        'total_deductions' => 0,
        'total_perceptions' => 0,
    ]);
}

/** Crea un usuario con un Role de prueba que solo tiene los permisos indicados. */
function actingAsPayrollPermUser(array $permissions): User
{
    static $roleN = 0;
    $roleN++;

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => "Test Role Payroll {$roleN}", 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('respeta el permiso approve_payroll en la acción Aprobar', function () {
    $payroll = makePayrollPermRecord('draft');

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionHidden('approve');

    $this->actingAs(actingAsPayrollPermUser(['approve_payroll']));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionVisible('approve');
});

it('respeta el permiso disburse_payroll en la acción Marcar Acreditado', function () {
    $payroll = makePayrollPermRecord('approved');

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionHidden('mark_disbursed');

    $this->actingAs(actingAsPayrollPermUser(['disburse_payroll']));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionVisible('mark_disbursed');
});

it('respeta el permiso mark_paid_payroll en la acción Marcar Pagado', function () {
    $payroll = makePayrollPermRecord('disbursed');

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionHidden('mark_paid');

    $this->actingAs(actingAsPayrollPermUser(['mark_paid_payroll']));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionVisible('mark_paid');
});

it('respeta el permiso revert_payroll en las acciones revert_paid, revert_to_approved y unapprove', function () {
    $paid = makePayrollPermRecord('paid');
    $disbursed = makePayrollPermRecord('disbursed'); // disbursement_batch_id es null por defecto
    $approved = makePayrollPermRecord('approved');

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(ViewPayroll::class, ['record' => $paid->getRouteKey()])
        ->assertActionHidden('revert_paid');
    Livewire::test(ViewPayroll::class, ['record' => $disbursed->getRouteKey()])
        ->assertActionHidden('revert_to_approved');
    Livewire::test(ViewPayroll::class, ['record' => $approved->getRouteKey()])
        ->assertActionHidden('unapprove');

    $this->actingAs(actingAsPayrollPermUser(['revert_payroll']));
    Livewire::test(ViewPayroll::class, ['record' => $paid->getRouteKey()])
        ->assertActionVisible('revert_paid');
    Livewire::test(ViewPayroll::class, ['record' => $disbursed->getRouteKey()])
        ->assertActionVisible('revert_to_approved');
    Livewire::test(ViewPayroll::class, ['record' => $approved->getRouteKey()])
        ->assertActionVisible('unapprove');
});

it('respeta el permiso regenerate_payroll en la acción Regenerar', function () {
    $payroll = makePayrollPermRecord('draft');

    $this->actingAs(actingAsPayrollPermUser([]));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionHidden('regenerate');

    $this->actingAs(actingAsPayrollPermUser(['regenerate_payroll']));
    Livewire::test(ViewPayroll::class, ['record' => $payroll->getRouteKey()])
        ->assertActionVisible('regenerate');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/PayrollBusinessPermissionsTest.php`
Expected: FAIL — las 5 acciones son visibles solo por el estado del recibo, sin chequeo de permiso todavía, así que `assertActionHidden` falla para el usuario sin permiso en cada `it()`.

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/PayrollResource/Pages/ViewPayroll.php`:

```php
// Antes (línea 67)
->visible(fn () => $this->record->status === 'draft'),
// (cierre de 'approve')

// Después
->visible(fn () => $this->record->status === 'draft' && auth()->user()->can('approve_payroll')),
```

```php
// Antes (línea 88)
->visible(fn () => $this->record->isApproved()),
// (cierre de 'mark_disbursed')

// Después
->visible(fn () => $this->record->isApproved() && auth()->user()->can('disburse_payroll')),
```

```php
// Antes (línea 108)
->visible(fn () => $this->record->isDisbursed()),
// (cierre de 'mark_paid')

// Después
->visible(fn () => $this->record->isDisbursed() && auth()->user()->can('mark_paid_payroll')),
```

```php
// Antes (línea 129)
->visible(fn () => $this->record->isPaid()),
// (cierre de 'revert_paid')

// Después
->visible(fn () => $this->record->isPaid() && auth()->user()->can('revert_payroll')),
```

```php
// Antes (línea 150)
->visible(fn () => $this->record->isDisbursed() && $this->record->disbursement_batch_id === null),
// (cierre de 'revert_to_approved')

// Después
->visible(fn () => $this->record->isDisbursed() && $this->record->disbursement_batch_id === null && auth()->user()->can('revert_payroll')),
```

```php
// Antes (línea 175)
->visible(fn () => $this->record->isApproved()),
// (cierre de 'unapprove')

// Después
->visible(fn () => $this->record->isApproved() && auth()->user()->can('revert_payroll')),
```

```php
// Antes (línea 212)
->visible(fn () => $this->record->status === 'draft'),
// (cierre de 'regenerate')

// Después
->visible(fn () => $this->record->status === 'draft' && auth()->user()->can('regenerate_payroll')),
```

(`edit_draft`, línea 249, no se toca — cubierto por `update_payroll` de Plan 1.)

Editar `app/Filament/Resources/PayrollResource.php` con los mismos 7 cambios (mismas condiciones de estado, mismos permisos) para las row actions homólogas en líneas 229 (`approve`), 248 (`mark_disbursed`), 266 (`mark_paid`), 285 (`revert_paid`), 304 (`revert_to_approved`), 327 (`unapprove`), 354 (`regenerate`):

```php
// Antes (línea 229)
->visible(fn (Payroll $record) => $record->status === 'draft'),

// Después
->visible(fn (Payroll $record) => $record->status === 'draft' && auth()->user()->can('approve_payroll')),
```

```php
// Antes (línea 248)
->visible(fn (Payroll $record) => $record->isApproved()),

// Después
->visible(fn (Payroll $record) => $record->isApproved() && auth()->user()->can('disburse_payroll')),
```

```php
// Antes (línea 266)
->visible(fn (Payroll $record) => $record->isDisbursed()),

// Después
->visible(fn (Payroll $record) => $record->isDisbursed() && auth()->user()->can('mark_paid_payroll')),
```

```php
// Antes (línea 285)
->visible(fn (Payroll $record) => $record->isPaid()),

// Después
->visible(fn (Payroll $record) => $record->isPaid() && auth()->user()->can('revert_payroll')),
```

```php
// Antes (línea 304)
->visible(fn (Payroll $record) => $record->isDisbursed() && $record->disbursement_batch_id === null),

// Después
->visible(fn (Payroll $record) => $record->isDisbursed() && $record->disbursement_batch_id === null && auth()->user()->can('revert_payroll')),
```

```php
// Antes (línea 327)
->visible(fn (Payroll $record) => $record->status === 'approved'),

// Después
->visible(fn (Payroll $record) => $record->status === 'approved' && auth()->user()->can('revert_payroll')),
```

```php
// Antes (línea 354)
->visible(fn (Payroll $record) => $record->status === 'draft'),
// (cierre de 'regenerate'; la línea 357 tiene la misma condición pero pertenece a DeleteAction — NO tocar, cubierta por delete_payroll de Plan 1)

// Después
->visible(fn (Payroll $record) => $record->status === 'draft' && auth()->user()->can('regenerate_payroll')),
```

Agregar `->visible()` a las bulk actions que hoy no lo tienen (mismo archivo, `app/Filament/Resources/PayrollResource.php`):

```php
// approve_selected (línea 361) — agregar antes de ->action(...)
BulkAction::make('approve_selected')
    ->label('Aprobar Seleccionados')
    ->icon('heroicon-o-check-circle')
    ->color('success')
    ->visible(fn () => auth()->user()->can('approve_payroll'))
    ->requiresConfirmation()
    ->modalHeading('Aprobar Recibos Seleccionados')
    // ... resto sin cambios
```

```php
// mark_disbursed_selected (línea 389)
BulkAction::make('mark_disbursed_selected')
    ->label('Marcar Acreditados')
    ->icon('heroicon-o-building-library')
    ->color('info')
    ->visible(fn () => auth()->user()->can('disburse_payroll'))
    ->requiresConfirmation()
    // ... resto sin cambios
```

```php
// mark_paid_selected (línea 413)
BulkAction::make('mark_paid_selected')
    ->label('Marcar Pagados')
    ->icon('heroicon-o-banknotes')
    ->color('success')
    ->visible(fn () => auth()->user()->can('mark_paid_payroll'))
    ->requiresConfirmation()
    // ... resto sin cambios
```

```php
// revert_paid_selected (línea 437)
BulkAction::make('revert_paid_selected')
    ->label('Revertir Pagos')
    ->icon('heroicon-o-arrow-uturn-left')
    ->color('warning')
    ->visible(fn () => auth()->user()->can('revert_payroll'))
    ->requiresConfirmation()
    // ... resto sin cambios
```

```php
// unapprove_selected (línea 461)
BulkAction::make('unapprove_selected')
    ->label('Desaprobar Seleccionados')
    ->icon('heroicon-o-x-circle')
    ->color('warning')
    ->visible(fn () => auth()->user()->can('revert_payroll'))
    ->requiresConfirmation()
    // ... resto sin cambios
```

```php
// download_pdfs (línea 489)
BulkAction::make('download_pdfs')
    ->label('Descargar PDFs')
    ->icon('heroicon-o-arrow-down-tray')
    ->color('gray')
    ->visible(fn () => auth()->user()->can('export_payroll'))
    ->action(function (Collection $records, Component $livewire) {
    // ... resto sin cambios
```

```php
// ExportBulkAction (línea 550)
ExportBulkAction::make()
    ->visible(fn () => auth()->user()->can('export_payroll'))
    ->exports([
        ExcelExport::make()
            ->fromTable()
            ->withFilename(fn () => 'recibos_seleccionados_'.now()->format('d_m_Y_H_i_s'))
            ->withWriterType(Excel::XLSX),
    ]),
```

(`DeleteBulkAction`, línea 558, no se toca — cubierta por `delete_payroll` de Plan 1.)

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/PayrollBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/PayrollResourceFilterTest.php tests/Feature/PayrollServiceTest.php`
Expected: PASS — `PayrollResourceFilterTest` usa Super Admin (bypass de permisos vía `Gate::before`) así que no debería verse afectado; `PayrollServiceTest` no toca Filament, se corre por ser el test más cercano al módulo.

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/PayrollResource.php app/Filament/Resources/PayrollResource/Pages/ViewPayroll.php tests/Feature/Filament/PayrollBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a recibos de nómina"
```

---

## Task 9: Permisos de negocio — PayrollPeriod

**Files:**
- Modify: `app/Filament/Resources/PayrollPeriodResource/Pages/ViewPayrollPeriod.php:169` (`generate_payrolls`), `:202-203` (`approve_all_payrolls`), `:287-288` (`close_period`), `:314` (`reopen_period`), `:317` (`create_payroll_batch`, reutiliza `create_disbursement_batch` de Plan 1), `:502-503` (`mark_cash_paid`)
- Modify: `app/Filament/Resources/PayrollPeriodResource/Pages/EditPayrollPeriod.php:81` (`generate_payrolls`), `:145` (`regenerate_payrolls`), `:252-254` (`close_period`), `:284` (`reopen_period`)
- Test: `tests/Feature/Filament/PayrollPeriodBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `generate_payrolls_period`, `close_payroll_period`, `reopen_payroll_period` (nuevos, ya sembrados por `BusinessActionPermissionSeeder` en Task 1); `approve_payroll`, `mark_paid_payroll` (ya existentes desde Task 8, reutilizados acá para `approve_all_payrolls`/`mark_cash_paid`); `create_disbursement_batch` (ya existente desde Plan 1, reutilizado para `create_payroll_batch`)
- Produces: nada — tarea hoja

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Resources\PayrollPeriodResource\Pages\EditPayrollPeriod;
use App\Filament\Resources\PayrollPeriodResource\Pages\ViewPayrollPeriod;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Crea un empleado con contrato activo, listo para tener recibos de nómina. */
function makePeriodPermEmployee(): Employee
{
    static $ci = 7800000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpPeriodPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucPeriodPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepPeriodPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosPeriodPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'PeriodPerm',
        'ci' => (string) $n,
        'email' => "periodperm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYear(),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'payroll_type' => 'monthly',
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
        'payment_method' => 'transfer',
    ]);

    return $employee->fresh();
}

/** Crea un período de nómina en el estado indicado. */
function makePeriodPermPeriod(string $status = 'processing'): PayrollPeriod
{
    static $offset = 0;
    $offset++;
    $start = Carbon::now()->startOfMonth()->addMonths($offset);

    return PayrollPeriod::create([
        'name' => $start->format('F Y'),
        'start_date' => $start->toDateString(),
        'end_date' => $start->copy()->endOfMonth()->toDateString(),
        'frequency' => 'monthly',
        'status' => $status,
    ]);
}

/** Crea un recibo de nómina dentro de un período, en el estado indicado. */
function makePeriodPermPayroll(PayrollPeriod $period, string $status = 'draft'): Payroll
{
    $employee = makePeriodPermEmployee();

    return Payroll::create([
        'employee_id' => $employee->id,
        'payroll_period_id' => $period->id,
        'status' => $status,
        'base_salary' => 2_550_000,
        'gross_salary' => 2_550_000,
        'net_salary' => 2_550_000,
        'total_deductions' => 0,
        'total_perceptions' => 0,
        'payment_method' => $status === 'approved' ? 'cash' : 'transfer',
    ]);
}

/** Crea un usuario con un Role de prueba que solo tiene los permisos indicados. */
function actingAsPeriodPermUser(array $permissions): User
{
    static $roleN = 0;
    $roleN++;

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => "Test Role Period {$roleN}", 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('respeta el permiso generate_payrolls_period en generate_payrolls y regenerate_payrolls (Ver y Editar)', function () {
    $period = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($period, 'approved'); // hace visible a regenerate_payrolls

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('generate_payrolls');
    Livewire::test(EditPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('generate_payrolls')
        ->assertActionHidden('regenerate_payrolls');

    $this->actingAs(actingAsPeriodPermUser(['generate_payrolls_period']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('generate_payrolls');
    Livewire::test(EditPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('generate_payrolls')
        ->assertActionVisible('regenerate_payrolls');
});

it('respeta el permiso close_payroll_period en close_period (Ver y Editar)', function () {
    $viewPeriod = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($viewPeriod, 'paid'); // hace visible a close_period en ViewPayrollPeriod (payrolls()->exists())

    $editPeriod = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($editPeriod, 'paid'); // hace visible a close_period en EditPayrollPeriod (todos pagados)

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $viewPeriod->getRouteKey()])
        ->assertActionHidden('close_period');
    Livewire::test(EditPayrollPeriod::class, ['record' => $editPeriod->getRouteKey()])
        ->assertActionHidden('close_period');

    $this->actingAs(actingAsPeriodPermUser(['close_payroll_period']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $viewPeriod->getRouteKey()])
        ->assertActionVisible('close_period');
    Livewire::test(EditPayrollPeriod::class, ['record' => $editPeriod->getRouteKey()])
        ->assertActionVisible('close_period');
});

it('respeta el permiso reopen_payroll_period en reopen_period (Ver y Editar)', function () {
    $period = makePeriodPermPeriod('closed');

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('reopen_period');
    Livewire::test(EditPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('reopen_period');

    $this->actingAs(actingAsPeriodPermUser(['reopen_payroll_period']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('reopen_period');
    Livewire::test(EditPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('reopen_period');
});

it('respeta el permiso approve_payroll (reutilizado) en approve_all_payrolls', function () {
    $period = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($period, 'draft');

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('approve_all_payrolls');

    $this->actingAs(actingAsPeriodPermUser(['approve_payroll']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('approve_all_payrolls');
});

it('respeta el permiso mark_paid_payroll (reutilizado) en mark_cash_paid', function () {
    $period = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($period, 'approved'); // payment_method 'cash' por el helper

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('mark_cash_paid');

    $this->actingAs(actingAsPeriodPermUser(['mark_paid_payroll']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('mark_cash_paid');
});

it('respeta el permiso create_disbursement_batch (reutilizado) en create_payroll_batch', function () {
    $period = makePeriodPermPeriod('processing');
    makePeriodPermPayroll($period, 'approved'); // payment_method 'cash' por el helper — no hace falta transfer para este chequeo de visibilidad

    $this->actingAs(actingAsPeriodPermUser([]));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('create_payroll_batch');

    $this->actingAs(actingAsPeriodPermUser(['create_disbursement_batch']));
    Livewire::test(ViewPayrollPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('create_payroll_batch');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/PayrollPeriodBusinessPermissionsTest.php`
Expected: FAIL — todas las acciones son visibles solo por el estado del período/recibos, sin chequeo de permiso todavía.

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/PayrollPeriodResource/Pages/ViewPayrollPeriod.php`:

```php
// Antes (línea 169) — cierre de 'generate_payrolls'
->visible(fn () => in_array($this->record->status, ['draft', 'processing'])),

// Después
->visible(fn () => in_array($this->record->status, ['draft', 'processing']) && auth()->user()->can('generate_payrolls_period')),
```

```php
// Antes (líneas 202-203) — cierre de 'approve_all_payrolls'
->visible(fn () => $this->record->status === 'processing'
    && $this->record->payrolls()->where('status', 'draft')->exists()),

// Después
->visible(fn () => $this->record->status === 'processing'
    && $this->record->payrolls()->where('status', 'draft')->exists()
    && auth()->user()->can('approve_payroll')),
```

```php
// Antes (líneas 287-288) — cierre de 'close_period'
->visible(fn () => $this->record->status === 'processing'
    && $this->record->payrolls()->exists()),

// Después
->visible(fn () => $this->record->status === 'processing'
    && $this->record->payrolls()->exists()
    && auth()->user()->can('close_payroll_period')),
```

```php
// Antes (línea 314) — cierre de 'reopen_period'
->visible(fn () => $this->record->status === 'closed'),

// Después
->visible(fn () => $this->record->status === 'closed' && auth()->user()->can('reopen_payroll_period')),
```

Editar `create_payroll_batch` (dentro del `ActionGroup::make([...])` que arranca en la línea 316; el `Action::make('create_payroll_batch')` está en la línea 317, con su `->visible()` de cierre en la línea 503):

```php
// Antes (línea 503) — cierre de 'create_payroll_batch'
->visible(fn () => $this->record->status === 'processing'),

// Después
->visible(fn () => $this->record->status === 'processing' && auth()->user()->can('create_disbursement_batch')),
```

```php
// Antes (líneas 550-551) — cierre de 'mark_cash_paid'
->visible(fn () => in_array($this->record->status, ['processing', 'closed'])
    && $this->record->payrolls()->where('payment_method', 'cash')->where('status', 'approved')->exists()),

// Después
->visible(fn () => in_array($this->record->status, ['processing', 'closed'])
    && $this->record->payrolls()->where('payment_method', 'cash')->where('status', 'approved')->exists()
    && auth()->user()->can('mark_paid_payroll')),
```

(`salary_report`, línea 559, no se toca — es un link de navegación sin permiso propio, según el alcance aprobado; `regenerate_payrolls` en esta página, línea 623, y `revert_to_draft`, línea 662, tampoco se tocan en `ViewPayrollPeriod` — el catálogo solo pide `generate_payrolls_period` en `EditPayrollPeriod` para `regenerate_payrolls`, y `revert_to_draft` está fuera de alcance.)

Editar `app/Filament/Resources/PayrollPeriodResource/Pages/EditPayrollPeriod.php`:

```php
// Antes (línea 81) — cierre de 'generate_payrolls'
->visible(fn () => in_array($this->record->status, ['draft', 'processing'])),

// Después
->visible(fn () => in_array($this->record->status, ['draft', 'processing']) && auth()->user()->can('generate_payrolls_period')),
```

```php
// Antes (línea 145) — cierre de 'regenerate_payrolls'
->visible(fn () => $this->record->status === 'processing' && $this->record->payrolls()->whereIn('status', ['draft', 'approved'])->exists()),

// Después
->visible(fn () => $this->record->status === 'processing' && $this->record->payrolls()->whereIn('status', ['draft', 'approved'])->exists() && auth()->user()->can('generate_payrolls_period')),
```

```php
// Antes (líneas 252-254) — cierre de 'close_period'
->visible(fn () => $this->record->status === 'processing'
    && $this->record->payrolls()->exists()
    && $this->record->payrolls()->whereNot('status', 'paid')->doesntExist()),

// Después
->visible(fn () => $this->record->status === 'processing'
    && $this->record->payrolls()->exists()
    && $this->record->payrolls()->whereNot('status', 'paid')->doesntExist()
    && auth()->user()->can('close_payroll_period')),
```

```php
// Antes (línea 284) — cierre de 'reopen_period'
->visible(fn () => $this->record->status === 'closed'),

// Después
->visible(fn () => $this->record->status === 'closed' && auth()->user()->can('reopen_payroll_period')),
```

(`revert_to_draft`, línea 184, no se toca — fuera de alcance según el catálogo aprobado; `DeleteAction`, línea 290, no se toca — cubierta por `delete_payroll_period` de Plan 1.)

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/PayrollPeriodBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/Filament/PayrollBusinessPermissionsTest.php tests/Feature/PayrollServiceTest.php`
Expected: PASS — se corre junto con Task 8 porque `PayrollPeriod` y `Payroll` comparten flujo (`generateForPeriod`), para confirmar que ningún cambio de permiso en una página rompe el flujo de la otra.

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/PayrollPeriodResource/Pages/ViewPayrollPeriod.php app/Filament/Resources/PayrollPeriodResource/Pages/EditPayrollPeriod.php tests/Feature/Filament/PayrollPeriodBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a planillas de nómina"
```

---

## Task 10: Permisos de negocio — Liquidación

**Files:**
- Modify: `app/Filament/Resources/LiquidacionResource.php:378-420` (row actions `calculate`, `recalculate`, `close`), `app/Filament/Resources/LiquidacionResource.php:714-720` (`getExcelExportAction()`)
- Modify: `app/Filament/Resources/LiquidacionResource/Pages/ViewLiquidacion.php:21-68` (header actions `calculate`, `recalculate`, `close`)
- Test: `tests/Feature/Filament/LiquidacionBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `calculate_liquidacion`, `close_liquidacion`, `export_liquidacion` (ya sembrados por `BusinessActionPermissionSeeder` en Task 1)
- Produces: nada — tarea hoja

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Resources\LiquidacionResource\Pages\ListLiquidaciones;
use App\Filament\Resources\LiquidacionResource\Pages\ViewLiquidacion;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Liquidacion;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crea un empleado con contrato activo, aislado por CI incremental,
 * para pruebas de permisos de negocio de Liquidación.
 */
function makeLiquidacionPermTestEmployee(): Employee
{
    static $ci = 9500000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpLiqPerm {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucLiqPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepLiqPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosLiqPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'LiqPerm',
        'ci' => (string) $n,
        'email' => "liqperm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYears(3),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/**
 * Crea una Liquidación en el estado indicado, siguiendo los campos
 * requeridos reales (ver tests/Feature/LiquidacionServiceTest.php::makeLiquidacion).
 */
function makeLiquidacionPermTest(string $status): Liquidacion
{
    $employee = makeLiquidacionPermTestEmployee();

    return Liquidacion::create([
        'employee_id' => $employee->id,
        'termination_date' => Carbon::create(2026, 3, 15)->toDateString(),
        'termination_type' => 'unjustified_dismissal',
        'preaviso_otorgado' => false,
        'hire_date' => Carbon::now()->subYears(3)->toDateString(),
        'base_salary' => 2_550_000,
        'daily_salary' => round(2_550_000 / 30, 2),
        'salary_type' => 'mensual',
        'status' => $status,
    ]);
}

/**
 * Crea un usuario autenticado con un rol de prueba que tiene exactamente
 * los permisos indicados (aislado, no depende de los roles sembrados).
 */
function actingAsWithLiquidacionPerms(array $permissions): User
{
    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

it('oculta calcular en ViewLiquidacion sin el permiso calculate_liquidacion', function () {
    actingAsWithLiquidacionPerms([]);
    $liquidacion = makeLiquidacionPermTest('draft');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionHidden('calculate');
});

it('muestra calcular en ViewLiquidacion con el permiso calculate_liquidacion y estado draft', function () {
    actingAsWithLiquidacionPerms(['calculate_liquidacion']);
    $liquidacion = makeLiquidacionPermTest('draft');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionVisible('calculate');
});

it('oculta recalcular en ViewLiquidacion sin el permiso calculate_liquidacion', function () {
    actingAsWithLiquidacionPerms([]);
    $liquidacion = makeLiquidacionPermTest('calculated');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionHidden('recalculate');
});

it('muestra recalcular en ViewLiquidacion con el permiso calculate_liquidacion y estado calculated', function () {
    actingAsWithLiquidacionPerms(['calculate_liquidacion']);
    $liquidacion = makeLiquidacionPermTest('calculated');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionVisible('recalculate');
});

it('oculta cerrar en ViewLiquidacion sin el permiso close_liquidacion', function () {
    actingAsWithLiquidacionPerms([]);
    $liquidacion = makeLiquidacionPermTest('calculated');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionHidden('close');
});

it('muestra cerrar en ViewLiquidacion con el permiso close_liquidacion y estado calculated', function () {
    actingAsWithLiquidacionPerms(['close_liquidacion']);
    $liquidacion = makeLiquidacionPermTest('calculated');

    Livewire::test(ViewLiquidacion::class, ['record' => $liquidacion->getRouteKey()])
        ->assertActionVisible('close');
});

it('oculta exportar en ListLiquidaciones sin el permiso export_liquidacion', function () {
    actingAsWithLiquidacionPerms([]);

    Livewire::test(ListLiquidaciones::class)
        ->assertActionHidden('export_excel');
});

it('muestra exportar en ListLiquidaciones con el permiso export_liquidacion', function () {
    actingAsWithLiquidacionPerms(['export_liquidacion']);

    Livewire::test(ListLiquidaciones::class)
        ->assertActionVisible('export_excel');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/LiquidacionBusinessPermissionsTest.php`
Expected: FAIL — las aserciones `assertActionHidden` fallan porque hoy `calculate`, `recalculate`, `close` y `export_excel` son visibles solo con la condición de estado, sin chequeo de permiso (un usuario sin ningún permiso igual las ve).

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/LiquidacionResource.php` (líneas 378-420, row actions):

```php
// Antes
                Action::make('calculate')
                    ->label('Calcular')
                    ->icon('heroicon-o-calculator')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Calcular Liquidación')
                    ->modalDescription(
                        fn (Liquidacion $record) => "¿Calcular la liquidación de {$record->employee->full_name}? ".
                            'Tipo: '.Liquidacion::getTerminationTypeLabel($record->termination_type)
                    )
                    ->action(fn (Liquidacion $record, LiquidacionService $service) => static::performCalculation($record, $service, 'Liquidación calculada')
                    )
                    ->visible(fn (Liquidacion $record) => $record->isDraft()),

                Action::make('recalculate')
                    ->label('Recalcular')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Recalcular Liquidación')
                    ->modalDescription(
                        fn (Liquidacion $record) => "¿Recalcular la liquidación de {$record->employee->full_name}? ".
                            'Se eliminarán los conceptos actuales y se recalculará desde cero. '.
                            'Los cambios manuales en los items se perderán.'
                    )
                    ->action(fn (Liquidacion $record, LiquidacionService $service) => static::performCalculation($record, $service, 'Liquidación recalculada')
                    )
                    ->visible(fn (Liquidacion $record) => $record->isCalculated()),

                Action::make('close')
                    ->label('Cerrar')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cerrar Liquidación y Desactivar Empleado')
                    ->modalDescription(
                        fn (Liquidacion $record) => "¿Cerrar la liquidación de {$record->employee->full_name}? ".
                            'El empleado será marcado como INACTIVO y los préstamos pendientes serán cancelados. '.
                            'Esta acción no se puede deshacer.'
                    )
                    ->action(fn (Liquidacion $record, LiquidacionService $service) => static::performClose($record, $service)
                    )
                    ->visible(fn (Liquidacion $record) => $record->isCalculated()),

// Después
                Action::make('calculate')
                    ->label('Calcular')
                    ->icon('heroicon-o-calculator')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Calcular Liquidación')
                    ->modalDescription(
                        fn (Liquidacion $record) => "¿Calcular la liquidación de {$record->employee->full_name}? ".
                            'Tipo: '.Liquidacion::getTerminationTypeLabel($record->termination_type)
                    )
                    ->action(fn (Liquidacion $record, LiquidacionService $service) => static::performCalculation($record, $service, 'Liquidación calculada')
                    )
                    ->visible(fn (Liquidacion $record) => $record->isDraft() && auth()->user()->can('calculate_liquidacion')),

                Action::make('recalculate')
                    ->label('Recalcular')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Recalcular Liquidación')
                    ->modalDescription(
                        fn (Liquidacion $record) => "¿Recalcular la liquidación de {$record->employee->full_name}? ".
                            'Se eliminarán los conceptos actuales y se recalculará desde cero. '.
                            'Los cambios manuales en los items se perderán.'
                    )
                    ->action(fn (Liquidacion $record, LiquidacionService $service) => static::performCalculation($record, $service, 'Liquidación recalculada')
                    )
                    ->visible(fn (Liquidacion $record) => $record->isCalculated() && auth()->user()->can('calculate_liquidacion')),

                Action::make('close')
                    ->label('Cerrar')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cerrar Liquidación y Desactivar Empleado')
                    ->modalDescription(
                        fn (Liquidacion $record) => "¿Cerrar la liquidación de {$record->employee->full_name}? ".
                            'El empleado será marcado como INACTIVO y los préstamos pendientes serán cancelados. '.
                            'Esta acción no se puede deshacer.'
                    )
                    ->action(fn (Liquidacion $record, LiquidacionService $service) => static::performClose($record, $service)
                    )
                    ->visible(fn (Liquidacion $record) => $record->isCalculated() && auth()->user()->can('close_liquidacion')),
```

Editar `app/Filament/Resources/LiquidacionResource.php` (línea 720, `getExcelExportAction()`):

```php
// Antes
            ->tooltip('Exportar registros visibles (respeta filtros y tabs activos)')
            ->exports([

// Después
            ->tooltip('Exportar registros visibles (respeta filtros y tabs activos)')
            ->visible(fn () => auth()->user()->can('export_liquidacion'))
            ->exports([
```

Editar `app/Filament/Resources/LiquidacionResource/Pages/ViewLiquidacion.php` (líneas 21-68, header actions):

```php
// Antes
            Action::make('calculate')
                ->label('Calcular Liquidación')
                ->icon('heroicon-o-calculator')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Calcular Liquidación')
                ->modalDescription(
                    fn () => "¿Calcular la liquidación de {$this->record->employee->full_name}? ".
                        'Tipo: '.Liquidacion::getTerminationTypeLabel($this->record->termination_type)
                )
                ->action(function (LiquidacionService $service) {
                    LiquidacionResource::performCalculation($this->record, $service, 'Liquidación calculada');
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isDraft()),

            Action::make('recalculate')
                ->label('Recalcular')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Recalcular Liquidación')
                ->modalDescription(
                    fn () => "¿Recalcular la liquidación de {$this->record->employee->full_name}? ".
                        'Se eliminarán todos los conceptos actuales y se recalculará desde cero. '.
                        'Los cambios manuales en los items se perderán.'
                )
                ->action(function (LiquidacionService $service) {
                    LiquidacionResource::performCalculation($this->record, $service, 'Liquidación recalculada');
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isCalculated()),

            Action::make('close')
                ->label('Cerrar y Desactivar Empleado')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cerrar Liquidación')
                ->modalDescription(
                    fn () => "¿Cerrar la liquidación de {$this->record->employee->full_name}? ".
                        'El empleado será marcado como INACTIVO y los préstamos pendientes serán cancelados.'
                )
                ->action(function (LiquidacionService $service) {
                    LiquidacionResource::performClose($this->record, $service);
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isCalculated()),

// Después
            Action::make('calculate')
                ->label('Calcular Liquidación')
                ->icon('heroicon-o-calculator')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Calcular Liquidación')
                ->modalDescription(
                    fn () => "¿Calcular la liquidación de {$this->record->employee->full_name}? ".
                        'Tipo: '.Liquidacion::getTerminationTypeLabel($this->record->termination_type)
                )
                ->action(function (LiquidacionService $service) {
                    LiquidacionResource::performCalculation($this->record, $service, 'Liquidación calculada');
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isDraft() && auth()->user()->can('calculate_liquidacion')),

            Action::make('recalculate')
                ->label('Recalcular')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Recalcular Liquidación')
                ->modalDescription(
                    fn () => "¿Recalcular la liquidación de {$this->record->employee->full_name}? ".
                        'Se eliminarán todos los conceptos actuales y se recalculará desde cero. '.
                        'Los cambios manuales en los items se perderán.'
                )
                ->action(function (LiquidacionService $service) {
                    LiquidacionResource::performCalculation($this->record, $service, 'Liquidación recalculada');
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isCalculated() && auth()->user()->can('calculate_liquidacion')),

            Action::make('close')
                ->label('Cerrar y Desactivar Empleado')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cerrar Liquidación')
                ->modalDescription(
                    fn () => "¿Cerrar la liquidación de {$this->record->employee->full_name}? ".
                        'El empleado será marcado como INACTIVO y los préstamos pendientes serán cancelados.'
                )
                ->action(function (LiquidacionService $service) {
                    LiquidacionResource::performClose($this->record, $service);
                    $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                })
                ->visible(fn () => $this->record->isCalculated() && auth()->user()->can('close_liquidacion')),
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/LiquidacionBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/LiquidacionServiceTest.php tests/Feature/LiquidacionResourceFilterTest.php`
Expected: PASS — `LiquidacionResourceFilterTest` corre como Super Admin (bypass de `Gate::before`), no se ve afectado por el nuevo chequeo; `LiquidacionServiceTest` no toca Filament.

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/LiquidacionResource.php app/Filament/Resources/LiquidacionResource/Pages/ViewLiquidacion.php tests/Feature/Filament/LiquidacionBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a liquidación"
```

---

## Task 11: Permisos de negocio — Aguinaldo

**Files:**
- Modify: `app/Filament/Resources/AguinaldoResource/Pages/ViewAguinaldo.php:29-67` (header actions `mark_paid`, `unmark_paid`)
- Modify: `app/Filament/Resources/AguinaldoResource/Pages/ListAguinaldos.php:26-29` (header action `export_excel`)
- Test: `tests/Feature/Filament/AguinaldoBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `mark_paid_aguinaldo`, `export_aguinaldo` (ya sembrados por `BusinessActionPermissionSeeder` en Task 1)
- Produces: nada — tarea hoja (el permiso `mark_paid_aguinaldo` es reutilizado por AguinaldoPeriod en Task 12, pero eso no depende de código de esta tarea)

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Resources\AguinaldoResource\Pages\ListAguinaldos;
use App\Filament\Resources\AguinaldoResource\Pages\ViewAguinaldo;
use App\Models\Aguinaldo;
use App\Models\AguinaldoPeriod;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crea una empresa aislada por ID incremental para pruebas de permisos
 * de negocio de Aguinaldo.
 */
function makeAguinaldoPermTestCompany(): Company
{
    static $n = 8500000;
    $n++;

    return Company::create([
        'name' => "EmpAguPerm {$n}",
        'ruc' => "{$n}-1",
        'employer_number' => $n,
    ]);
}

/**
 * Crea un empleado con contrato activo dentro de la empresa dada.
 */
function makeAguinaldoPermTestEmployee(Company $company): Employee
{
    static $ci = 8600000;
    $n = $ci++;

    $branch = Branch::create(['name' => "SucAguPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepAguPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosAguPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'AguPerm',
        'ci' => (string) $n,
        'email' => "aguperm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYears(2),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/**
 * Crea un Aguinaldo en el estado indicado, dentro de un período 'processing',
 * siguiendo los campos requeridos reales (ver tests/Feature/AguinaldoServiceTest.php).
 */
function makeAguinaldoPermTest(string $status): Aguinaldo
{
    $company = makeAguinaldoPermTestCompany();
    $employee = makeAguinaldoPermTestEmployee($company);

    $period = AguinaldoPeriod::create([
        'company_id' => $company->id,
        'year' => 2026,
        'status' => 'processing',
    ]);

    return Aguinaldo::create([
        'aguinaldo_period_id' => $period->id,
        'employee_id' => $employee->id,
        'total_earned' => 2_550_000,
        'months_worked' => 12,
        'aguinaldo_amount' => 212_500,
        'status' => $status,
        'generated_at' => now(),
    ]);
}

/**
 * Crea un usuario autenticado con un rol de prueba que tiene exactamente
 * los permisos indicados (aislado, no depende de los roles sembrados).
 */
function actingAsWithAguinaldoPerms(array $permissions): User
{
    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

it('oculta marcar pagado en ViewAguinaldo sin el permiso mark_paid_aguinaldo', function () {
    actingAsWithAguinaldoPerms([]);
    $aguinaldo = makeAguinaldoPermTest('pending');

    Livewire::test(ViewAguinaldo::class, ['record' => $aguinaldo->getRouteKey()])
        ->assertActionHidden('mark_paid');
});

it('muestra marcar pagado en ViewAguinaldo con el permiso mark_paid_aguinaldo y estado pending', function () {
    actingAsWithAguinaldoPerms(['mark_paid_aguinaldo']);
    $aguinaldo = makeAguinaldoPermTest('pending');

    Livewire::test(ViewAguinaldo::class, ['record' => $aguinaldo->getRouteKey()])
        ->assertActionVisible('mark_paid');
});

it('oculta marcar pendiente en ViewAguinaldo sin el permiso mark_paid_aguinaldo', function () {
    actingAsWithAguinaldoPerms([]);
    $aguinaldo = makeAguinaldoPermTest('paid');

    Livewire::test(ViewAguinaldo::class, ['record' => $aguinaldo->getRouteKey()])
        ->assertActionHidden('unmark_paid');
});

it('muestra marcar pendiente en ViewAguinaldo con el permiso mark_paid_aguinaldo y estado paid', function () {
    actingAsWithAguinaldoPerms(['mark_paid_aguinaldo']);
    $aguinaldo = makeAguinaldoPermTest('paid');

    Livewire::test(ViewAguinaldo::class, ['record' => $aguinaldo->getRouteKey()])
        ->assertActionVisible('unmark_paid');
});

it('oculta exportar en ListAguinaldos sin el permiso export_aguinaldo', function () {
    actingAsWithAguinaldoPerms([]);

    Livewire::test(ListAguinaldos::class)
        ->assertActionHidden('export_excel');
});

it('muestra exportar en ListAguinaldos con el permiso export_aguinaldo', function () {
    actingAsWithAguinaldoPerms(['export_aguinaldo']);

    Livewire::test(ListAguinaldos::class)
        ->assertActionVisible('export_excel');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/AguinaldoBusinessPermissionsTest.php`
Expected: FAIL — hoy `mark_paid`, `unmark_paid` y `export_excel` son visibles solo por estado/tab, sin chequeo de permiso.

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/AguinaldoResource/Pages/ViewAguinaldo.php` (líneas 29-67):

```php
// Antes
            Action::make('mark_paid')
                ->label('Marcar Pagado')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Marcar como Pagado')
                ->modalDescription(fn () => "¿Confirmar pago del aguinaldo de {$this->record->employee->full_name} por ".Aguinaldo::formatCurrency($this->record->aguinaldo_amount).'?')
                ->action(function () {
                    $this->record->markAsPaid();

                    Notification::make()
                        ->success()
                        ->title('Aguinaldo marcado como pagado')
                        ->send();

                    $this->refreshFormData(['status', 'paid_at']);
                })
                ->visible(fn () => $this->record->isPending() && $this->record->period->isProcessing()),

            Action::make('unmark_paid')
                ->label('Marcar Pendiente')
                ->icon('heroicon-o-x-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('¿Marcar como Pendiente?')
                ->modalDescription(fn () => "Se revertirá el pago del aguinaldo de {$this->record->employee->full_name} por ".Aguinaldo::formatCurrency($this->record->aguinaldo_amount).' y volverá a estado Pendiente.')
                ->modalSubmitActionLabel('Sí, marcar como pendiente')
                ->action(function () {
                    $this->record->markAsPending();

                    Notification::make()
                        ->warning()
                        ->title('Pago revertido')
                        ->body("El aguinaldo de {$this->record->employee->full_name} volvió a estado Pendiente.")
                        ->send();

                    $this->refreshFormData(['status', 'paid_at']);
                })
                ->visible(fn () => $this->record->isPaid() && $this->record->period->isProcessing()),

// Después
            Action::make('mark_paid')
                ->label('Marcar Pagado')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Marcar como Pagado')
                ->modalDescription(fn () => "¿Confirmar pago del aguinaldo de {$this->record->employee->full_name} por ".Aguinaldo::formatCurrency($this->record->aguinaldo_amount).'?')
                ->action(function () {
                    $this->record->markAsPaid();

                    Notification::make()
                        ->success()
                        ->title('Aguinaldo marcado como pagado')
                        ->send();

                    $this->refreshFormData(['status', 'paid_at']);
                })
                ->visible(fn () => $this->record->isPending() && $this->record->period->isProcessing() && auth()->user()->can('mark_paid_aguinaldo')),

            Action::make('unmark_paid')
                ->label('Marcar Pendiente')
                ->icon('heroicon-o-x-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('¿Marcar como Pendiente?')
                ->modalDescription(fn () => "Se revertirá el pago del aguinaldo de {$this->record->employee->full_name} por ".Aguinaldo::formatCurrency($this->record->aguinaldo_amount).' y volverá a estado Pendiente.')
                ->modalSubmitActionLabel('Sí, marcar como pendiente')
                ->action(function () {
                    $this->record->markAsPending();

                    Notification::make()
                        ->warning()
                        ->title('Pago revertido')
                        ->body("El aguinaldo de {$this->record->employee->full_name} volvió a estado Pendiente.")
                        ->send();

                    $this->refreshFormData(['status', 'paid_at']);
                })
                ->visible(fn () => $this->record->isPaid() && $this->record->period->isProcessing() && auth()->user()->can('mark_paid_aguinaldo')),
```

Editar `app/Filament/Resources/AguinaldoResource/Pages/ListAguinaldos.php` (líneas 26-33):

```php
// Antes
            Action::make('export_excel')
                ->label('Exportar')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('¿Exportar aguinaldos a Excel?')
                ->modalDescription('Se exportarán todos los aguinaldos según el filtro de estado activo.')
                ->modalSubmitActionLabel('Sí, exportar')

// Después
            Action::make('export_excel')
                ->label('Exportar')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => auth()->user()->can('export_aguinaldo'))
                ->requiresConfirmation()
                ->modalHeading('¿Exportar aguinaldos a Excel?')
                ->modalDescription('Se exportarán todos los aguinaldos según el filtro de estado activo.')
                ->modalSubmitActionLabel('Sí, exportar')
```

Nota: `regenerate` (línea 69-95) y `download_pdf` (línea 97-103) de `ViewAguinaldo.php` **no se tocan** — quedan cubiertos implícitamente por `update_aguinaldo`/`view_aguinaldo` según el alcance del spec.

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/AguinaldoBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/AguinaldoServiceTest.php tests/Feature/AguinaldoResourceFilterTest.php tests/Feature/AguinaldosRelationManagerFilterTest.php`
Expected: PASS — estos tests no dependen del nuevo chequeo de permiso (corren como Super Admin o no tocan Filament).

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/AguinaldoResource/Pages/ViewAguinaldo.php app/Filament/Resources/AguinaldoResource/Pages/ListAguinaldos.php tests/Feature/Filament/AguinaldoBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a aguinaldo"
```

---

## Task 12: Permisos de negocio — AguinaldoPeriod

**Files:**
- Modify: `app/Filament/Resources/AguinaldoPeriodResource.php:179-208` (row action `generate_aguinaldos`)
- Modify: `app/Filament/Resources/AguinaldoPeriodResource/Pages/ViewAguinaldoPeriod.php:35-66` (header action `generate_aguinaldos`), `:68-95` (`mark_all_paid`), `:97-217` (`send_to_bank`), `:241-265` (`reopen_period`), `:273-302` (`close_period`), `:304-328` (`force_delete`)
- Test: `tests/Feature/Filament/AguinaldoPeriodBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `generate_aguinaldos_period`, `close_aguinaldo_period`, `reopen_aguinaldo_period` (nuevos, ya sembrados por `BusinessActionPermissionSeeder` en Task 1); `mark_paid_aguinaldo` (nuevo, ya sembrado, reutilizado desde el módulo Aguinaldo de Task 11); `create_disbursement_batch` y `delete_aguinaldo_period` (ya existentes de Plan 1)
- Produces: nada — tarea hoja

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Resources\AguinaldoPeriodResource\Pages\ViewAguinaldoPeriod;
use App\Models\Aguinaldo;
use App\Models\AguinaldoPeriod;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crea una empresa aislada por ID incremental para pruebas de permisos
 * de negocio de AguinaldoPeriod.
 */
function makeAguinaldoPeriodPermTestCompany(): Company
{
    static $n = 8700000;
    $n++;

    return Company::create([
        'name' => "EmpAguPerPerm {$n}",
        'ruc' => "{$n}-1",
        'employer_number' => $n,
    ]);
}

/**
 * Crea un empleado con contrato activo dentro de la empresa dada.
 */
function makeAguinaldoPeriodPermTestEmployee(Company $company): Employee
{
    static $ci = 8800000;
    $n = $ci++;

    $branch = Branch::create(['name' => "SucAguPerPerm {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepAguPerPerm {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosAguPerPerm {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Test',
        'last_name' => 'AguPerPerm',
        'ci' => (string) $n,
        'email' => "aguperperm{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    Contract::create([
        'employee_id' => $employee->id,
        'type' => 'indefinido',
        'start_date' => Carbon::now()->subYears(2),
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'position_id' => $position->id,
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    return $employee->fresh();
}

/**
 * Crea un AguinaldoPeriod en el estado indicado.
 */
function makeAguinaldoPeriodPermTest(string $status): AguinaldoPeriod
{
    $company = makeAguinaldoPeriodPermTestCompany();

    return AguinaldoPeriod::create([
        'company_id' => $company->id,
        'year' => 2026,
        'status' => $status,
    ]);
}

/**
 * Crea un usuario autenticado con un rol de prueba que tiene exactamente
 * los permisos indicados (aislado, no depende de los roles sembrados).
 */
function actingAsWithAguinaldoPeriodPerms(array $permissions): User
{
    $role = Role::create(['name' => 'Test Role '.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user);

    return $user;
}

it('oculta generar aguinaldos en ViewAguinaldoPeriod sin el permiso generate_aguinaldos_period', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('draft');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('generate_aguinaldos');
});

it('muestra generar aguinaldos en ViewAguinaldoPeriod con el permiso generate_aguinaldos_period y estado draft', function () {
    actingAsWithAguinaldoPeriodPerms(['generate_aguinaldos_period']);
    $period = makeAguinaldoPeriodPermTest('draft');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('generate_aguinaldos');
});

it('oculta pagar todos en ViewAguinaldoPeriod sin el permiso mark_paid_aguinaldo', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('processing');
    Aguinaldo::create([
        'aguinaldo_period_id' => $period->id,
        'employee_id' => makeAguinaldoPeriodPermTestEmployee($period->company)->id,
        'total_earned' => 2_550_000,
        'months_worked' => 12,
        'aguinaldo_amount' => 212_500,
        'status' => 'pending',
        'generated_at' => now(),
    ]);

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('mark_all_paid');
});

it('muestra pagar todos en ViewAguinaldoPeriod con el permiso mark_paid_aguinaldo, estado processing y pendientes', function () {
    actingAsWithAguinaldoPeriodPerms(['mark_paid_aguinaldo']);
    $period = makeAguinaldoPeriodPermTest('processing');
    Aguinaldo::create([
        'aguinaldo_period_id' => $period->id,
        'employee_id' => makeAguinaldoPeriodPermTestEmployee($period->company)->id,
        'total_earned' => 2_550_000,
        'months_worked' => 12,
        'aguinaldo_amount' => 212_500,
        'status' => 'pending',
        'generated_at' => now(),
    ]);

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('mark_all_paid');
});

it('oculta enviar al banco en ViewAguinaldoPeriod sin el permiso create_disbursement_batch', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('send_to_bank');
});

it('muestra enviar al banco en ViewAguinaldoPeriod con el permiso create_disbursement_batch y estado processing', function () {
    actingAsWithAguinaldoPeriodPerms(['create_disbursement_batch']);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('send_to_bank');
});

it('oculta cerrar período en ViewAguinaldoPeriod sin el permiso close_aguinaldo_period', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('close_period');
});

it('muestra cerrar período en ViewAguinaldoPeriod con el permiso close_aguinaldo_period y estado processing', function () {
    actingAsWithAguinaldoPeriodPerms(['close_aguinaldo_period']);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('close_period');
});

it('oculta reabrir período en ViewAguinaldoPeriod sin el permiso reopen_aguinaldo_period', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('closed');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('reopen_period');
});

it('muestra reabrir período en ViewAguinaldoPeriod con el permiso reopen_aguinaldo_period y estado closed', function () {
    actingAsWithAguinaldoPeriodPerms(['reopen_aguinaldo_period']);
    $period = makeAguinaldoPeriodPermTest('closed');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('reopen_period');
});

it('oculta eliminar período en ViewAguinaldoPeriod sin el permiso delete_aguinaldo_period', function () {
    actingAsWithAguinaldoPeriodPerms([]);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionHidden('force_delete');
});

it('muestra eliminar período en ViewAguinaldoPeriod con el permiso delete_aguinaldo_period y estado processing', function () {
    actingAsWithAguinaldoPeriodPerms(['delete_aguinaldo_period']);
    $period = makeAguinaldoPeriodPermTest('processing');

    Livewire::test(ViewAguinaldoPeriod::class, ['record' => $period->getRouteKey()])
        ->assertActionVisible('force_delete');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/AguinaldoPeriodBusinessPermissionsTest.php`
Expected: FAIL — `generate_aguinaldos`, `mark_all_paid`, `close_period`, `reopen_period` son visibles solo por estado (sin chequeo de permiso); `send_to_bank` y `force_delete` hoy no tienen NINGÚN chequeo de permiso (visibles a cualquier usuario autenticado en el estado correcto), así que las aserciones `assertActionHidden` para esos dos fallan de la misma forma.

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/AguinaldoPeriodResource.php` (línea 208, row action):

```php
// Antes
                    ->visible(fn (AguinaldoPeriod $record) => $record->isDraft()),

// Después
                    ->visible(fn (AguinaldoPeriod $record) => $record->isDraft() && auth()->user()->can('generate_aguinaldos_period')),
```

Editar `app/Filament/Resources/AguinaldoPeriodResource/Pages/ViewAguinaldoPeriod.php`:

```php
// Antes (línea 66, generate_aguinaldos)
                ->visible(fn () => $this->record->isDraft()),

// Después
                ->visible(fn () => $this->record->isDraft() && auth()->user()->can('generate_aguinaldos_period')),
```

```php
// Antes (línea 95, mark_all_paid)
                ->visible(fn () => $this->record->isProcessing() && $this->record->pending_aguinaldos_count > 0),

// Después
                ->visible(fn () => $this->record->isProcessing() && $this->record->pending_aguinaldos_count > 0 && auth()->user()->can('mark_paid_aguinaldo')),
```

```php
// Antes (línea 217, send_to_bank)
                ->visible(fn () => $this->record->isProcessing()),

// Después
                ->visible(fn () => $this->record->isProcessing() && auth()->user()->can('create_disbursement_batch')),
```

```php
// Antes (línea 265, reopen_period)
                ->visible(fn () => $this->record->isClosed()),

// Después
                ->visible(fn () => $this->record->isClosed() && auth()->user()->can('reopen_aguinaldo_period')),
```

```php
// Antes (línea 302, close_period)
                ->visible(fn () => $this->record->isProcessing()),

// Después
                ->visible(fn () => $this->record->isProcessing() && auth()->user()->can('close_aguinaldo_period')),
```

```php
// Antes (línea 304-328, force_delete — SIN chequeo de permiso previo)
            Action::make('force_delete')
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('¿Eliminar Período de Aguinaldo?')
                ->modalDescription(function () {
                    $count = $this->record->aguinaldos()->count();

                    return "Esta acción eliminará permanentemente el período {$this->record->year} de {$this->record->company->name} "
                        ."junto con {$count} aguinaldo(s) generado(s) y todos sus ítems. Esta acción no se puede deshacer.";
                })
                ->modalSubmitActionLabel('Sí, eliminar todo')
                ->action(function () {
                    $this->record->delete();

                    Notification::make()
                        ->success()
                        ->title('Período eliminado')
                        ->body("El período de aguinaldo {$this->record->year} de {$this->record->company->name} y todos sus aguinaldos fueron eliminados.")
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                })
                ->visible(fn () => $this->record->isProcessing() || $this->record->isClosed()),

// Después
            Action::make('force_delete')
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('¿Eliminar Período de Aguinaldo?')
                ->modalDescription(function () {
                    $count = $this->record->aguinaldos()->count();

                    return "Esta acción eliminará permanentemente el período {$this->record->year} de {$this->record->company->name} "
                        ."junto con {$count} aguinaldo(s) generado(s) y todos sus ítems. Esta acción no se puede deshacer.";
                })
                ->modalSubmitActionLabel('Sí, eliminar todo')
                ->action(function () {
                    $this->record->delete();

                    Notification::make()
                        ->success()
                        ->title('Período eliminado')
                        ->body("El período de aguinaldo {$this->record->year} de {$this->record->company->name} y todos sus aguinaldos fueron eliminados.")
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                })
                ->visible(fn () => ($this->record->isProcessing() || $this->record->isClosed()) && auth()->user()->can('delete_aguinaldo_period')),
```

Nota: `EditAction` (línea 267-271) y `provision_report` (línea 29-33) **no se tocan** — la primera ya está gateada por la Policy CRUD de Plan 1 (`update_aguinaldo_period`), la segunda es solo un link de navegación sin acción de negocio.

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/AguinaldoPeriodBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/AguinaldoPeriodResourceSearchTest.php tests/Feature/AguinaldoServiceTest.php`
Expected: PASS — corren como Super Admin (bypass de `Gate::before`) o no tocan Filament, sin regresión por el nuevo chequeo.

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/AguinaldoPeriodResource.php app/Filament/Resources/AguinaldoPeriodResource/Pages/ViewAguinaldoPeriod.php tests/Feature/Filament/AguinaldoPeriodBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a período de aguinaldo"
```

---

## Task 13: Permisos de negocio — EmployeeLeave

**Files:**
- Modify: `app/Filament/Resources/EmployeeLeaveResource.php:343` (acción `approve` de fila), `app/Filament/Resources/EmployeeLeaveResource.php:383` (acción `reject` de fila)
- Modify: `app/Filament/Resources/EmployeeLeaveResource/Pages/ViewEmployeeLeaves.php:58` (acción `approve` de header), `app/Filament/Resources/EmployeeLeaveResource/Pages/ViewEmployeeLeaves.php:100` (acción `reject` de header)
- Test: `tests/Feature/Filament/EmployeeLeaveBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `approve_employee_leave`, `reject_employee_leave` (ya sembrados por `BusinessActionPermissionSeeder` en Task 1)
- Produces: nada — tarea hoja

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Resources\EmployeeLeaveResource\Pages\ViewEmployeeLeaves;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();

    Permission::firstOrCreate(['name' => 'approve_employee_leave', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'reject_employee_leave', 'guard_name' => 'web']);
});

function makeLeaveTestEmployee(): Employee
{
    static $ci = 9200000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpLeave {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucLeave {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Leave',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'email' => "leave{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
}

function makePendingLeave(Employee $employee): EmployeeLeave
{
    return EmployeeLeave::create([
        'employee_id' => $employee->id,
        'type' => 'vacation',
        'start_date' => now()->addDays(5),
        'end_date' => now()->addDays(7),
        'status' => 'pending',
    ]);
}

it('oculta la acción approve sin el permiso approve_employee_leave y la muestra con él', function () {
    $leave = makePendingLeave(makeLeaveTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Aprobar Licencias', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_employee_leave', 'view_any_employee_leave']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewEmployeeLeaves::class, ['record' => $leave->getRouteKey()])
        ->assertActionHidden('approve');

    $roleWith = Role::create(['name' => 'Con Aprobar Licencias', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_employee_leave', 'view_any_employee_leave', 'approve_employee_leave']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewEmployeeLeaves::class, ['record' => $leave->getRouteKey()])
        ->assertActionVisible('approve');
});

it('oculta la acción reject sin el permiso reject_employee_leave y la muestra con él', function () {
    $leave = makePendingLeave(makeLeaveTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Rechazar Licencias', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_employee_leave', 'view_any_employee_leave']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewEmployeeLeaves::class, ['record' => $leave->getRouteKey()])
        ->assertActionHidden('reject');

    $roleWith = Role::create(['name' => 'Con Rechazar Licencias', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_employee_leave', 'view_any_employee_leave', 'reject_employee_leave']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewEmployeeLeaves::class, ['record' => $leave->getRouteKey()])
        ->assertActionVisible('reject');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/EmployeeLeaveBusinessPermissionsTest.php`
Expected: FAIL — las acciones `approve`/`reject` hoy solo dependen del estado (`status === 'pending'`), sin chequeo de permiso, así que el usuario `$userWithout` también las ve (el `assertActionHidden` inicial falla).

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/EmployeeLeaveResource.php` (línea 343, acción de fila `approve`):

```php
// Antes
->visible(fn (EmployeeLeave $record) => $record->status === 'pending')

// Después
->visible(fn (EmployeeLeave $record) => $record->status === 'pending' && auth()->user()->can('approve_employee_leave'))
```

Editar `app/Filament/Resources/EmployeeLeaveResource.php` (línea 383, acción de fila `reject`):

```php
// Antes
->visible(fn (EmployeeLeave $record) => $record->status === 'pending')

// Después
->visible(fn (EmployeeLeave $record) => $record->status === 'pending' && auth()->user()->can('reject_employee_leave'))
```

Editar `app/Filament/Resources/EmployeeLeaveResource/Pages/ViewEmployeeLeaves.php` (línea 58, acción de header `approve`):

```php
// Antes
->visible(fn () => $this->record->status === 'pending')

// Después
->visible(fn () => $this->record->status === 'pending' && auth()->user()->can('approve_employee_leave'))
```

Editar `app/Filament/Resources/EmployeeLeaveResource/Pages/ViewEmployeeLeaves.php` (línea 100, acción de header `reject`):

```php
// Antes
->visible(fn () => $this->record->status === 'pending')

// Después
->visible(fn () => $this->record->status === 'pending' && auth()->user()->can('reject_employee_leave'))
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/EmployeeLeaveBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/EmployeeLeaveResourceFilterTest.php`
Expected: PASS

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/EmployeeLeaveResource.php app/Filament/Resources/EmployeeLeaveResource/Pages/ViewEmployeeLeaves.php tests/Feature/Filament/EmployeeLeaveBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a permisos/licencias"
```

---

## Task 14: Permisos de negocio — Absence

**Verificación de `register_attendance`:** confirmado leyendo `AbsenceResource/Pages/ViewAbsence.php` y `EditAbsence.php` — la última línea del `->action()` de `register_attendance` llama `$this->record->justify(Auth::id(), $reviewNotes)`. Confirma lo que dice el diseño del spec: "marca asistencia Y auto-justifica en el mismo flujo". Comparte `justify_absence` según el diseño, sin discrepancia.

**Files:**
- Modify: `app/Filament/Resources/AbsenceResource.php:315-342` (`bulk_justify`), `:344-372` (`bulk_unjustify`), `:469-482` (`getExcelExportAction()`)
- Modify: `app/Filament/Resources/AbsenceResource/Pages/ViewAbsence.php:39` (`register_attendance`), `:159` (`justify`), `:310` (`mark_unjustified`)
- Modify: `app/Filament/Resources/AbsenceResource/Pages/EditAbsence.php:38` (`register_attendance`), `:157` (`justify`), `:307` (`mark_unjustified`)
- Test: `tests/Feature/Filament/AbsenceBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `justify_absence`, `mark_unjustified_absence`, `export_absence` (ya sembrados por `BusinessActionPermissionSeeder` en Task 1)
- Produces: nada — tarea hoja

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Resources\AbsenceResource\Pages\ListAbsences;
use App\Filament\Resources\AbsenceResource\Pages\ViewAbsence;
use App\Models\Absence;
use App\Models\AttendanceDay;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();

    Permission::firstOrCreate(['name' => 'justify_absence', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'mark_unjustified_absence', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'export_absence', 'guard_name' => 'web']);
});

function makeAbsenceTestEmployee(): Employee
{
    static $ci = 9300000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpAbs {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucAbs {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Abs',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'email' => "abstest{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
}

function makePendingAbsence(Employee $employee): Absence
{
    $day = AttendanceDay::create([
        'employee_id' => $employee->id,
        'date' => now()->subDay()->toDateString(),
        'status' => 'absent',
    ]);

    return Absence::create([
        'employee_id' => $employee->id,
        'attendance_day_id' => $day->id,
        'status' => 'pending',
        'reported_at' => now(),
    ]);
}

it('oculta justify y register_attendance sin justify_absence y las muestra con él', function () {
    $absence = makePendingAbsence(makeAbsenceTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Justificar Ausencias', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_absence', 'view_any_absence']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewAbsence::class, ['record' => $absence->getRouteKey()])
        ->assertActionHidden('justify')
        ->assertActionHidden('register_attendance');

    $roleWith = Role::create(['name' => 'Con Justificar Ausencias', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_absence', 'view_any_absence', 'justify_absence']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewAbsence::class, ['record' => $absence->getRouteKey()])
        ->assertActionVisible('justify')
        ->assertActionVisible('register_attendance');
});

it('oculta bulk_justify sin justify_absence y la muestra con él', function () {
    makePendingAbsence(makeAbsenceTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Bulk Justificar', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_absence']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListAbsences::class)
        ->assertTableBulkActionHidden('bulk_justify');

    $roleWith = Role::create(['name' => 'Con Bulk Justificar', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_absence', 'justify_absence']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListAbsences::class)
        ->assertTableBulkActionVisible('bulk_justify');
});

it('oculta mark_unjustified sin mark_unjustified_absence y la muestra con él', function () {
    $absence = makePendingAbsence(makeAbsenceTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Injustificar', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_absence', 'view_any_absence']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewAbsence::class, ['record' => $absence->getRouteKey()])
        ->assertActionHidden('mark_unjustified');

    $roleWith = Role::create(['name' => 'Con Injustificar', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_absence', 'view_any_absence', 'mark_unjustified_absence']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewAbsence::class, ['record' => $absence->getRouteKey()])
        ->assertActionVisible('mark_unjustified');
});

it('oculta bulk_unjustify sin mark_unjustified_absence y la muestra con él', function () {
    makePendingAbsence(makeAbsenceTestEmployee());

    $roleWithout = Role::create(['name' => 'Sin Bulk Injustificar', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_absence']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListAbsences::class)
        ->assertTableBulkActionHidden('bulk_unjustify');

    $roleWith = Role::create(['name' => 'Con Bulk Injustificar', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_absence', 'mark_unjustified_absence']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListAbsences::class)
        ->assertTableBulkActionVisible('bulk_unjustify');
});

it('oculta export_excel sin export_absence y la muestra con él', function () {
    $roleWithout = Role::create(['name' => 'Sin Exportar Ausencias', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_absence']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListAbsences::class)
        ->assertActionHidden('export_excel');

    $roleWith = Role::create(['name' => 'Con Exportar Ausencias', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_absence', 'export_absence']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListAbsences::class)
        ->assertActionVisible('export_excel');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/AbsenceBusinessPermissionsTest.php`
Expected: FAIL — `assertActionHidden`/`assertTableBulkActionHidden` fallan para el usuario sin permiso, porque hoy `justify`, `register_attendance`, `mark_unjustified` solo dependen del estado de la ausencia, y `bulk_justify`/`bulk_unjustify`/`export_excel` no tienen ningún `->visible()`.

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/AbsenceResource/Pages/ViewAbsence.php` (línea 39, `register_attendance`):

```php
// Antes
->visible(fn () => ! $this->record->isJustified())

// Después
->visible(fn () => ! $this->record->isJustified() && auth()->user()->can('justify_absence'))
```

Editar `app/Filament/Resources/AbsenceResource/Pages/ViewAbsence.php` (línea 159, `justify`):

```php
// Antes
->visible(fn () => ! $this->record->isJustified())

// Después
->visible(fn () => ! $this->record->isJustified() && auth()->user()->can('justify_absence'))
```

Editar `app/Filament/Resources/AbsenceResource/Pages/ViewAbsence.php` (línea 310, `mark_unjustified`):

```php
// Antes
->visible(fn () => ! $this->record->isUnjustified())

// Después
->visible(fn () => ! $this->record->isUnjustified() && auth()->user()->can('mark_unjustified_absence'))
```

Editar `app/Filament/Resources/AbsenceResource/Pages/EditAbsence.php` (línea 38, `register_attendance`):

```php
// Antes
->visible(fn () => ! $this->record->isJustified())

// Después
->visible(fn () => ! $this->record->isJustified() && auth()->user()->can('justify_absence'))
```

Editar `app/Filament/Resources/AbsenceResource/Pages/EditAbsence.php` (línea 157, `justify`):

```php
// Antes
->visible(fn () => ! $this->record->isJustified())

// Después
->visible(fn () => ! $this->record->isJustified() && auth()->user()->can('justify_absence'))
```

Editar `app/Filament/Resources/AbsenceResource/Pages/EditAbsence.php` (línea 307, `mark_unjustified`):

```php
// Antes
->visible(fn () => ! $this->record->isUnjustified())

// Después
->visible(fn () => ! $this->record->isUnjustified() && auth()->user()->can('mark_unjustified_absence'))
```

Editar `app/Filament/Resources/AbsenceResource.php` (línea ~315, `bulk_justify` — sin `->visible()` hoy, se agrega uno nuevo justo después de `->color('success')`):

```php
// Antes
BulkAction::make('bulk_justify')
    ->label('Justificar seleccionadas')
    ->icon('heroicon-o-check-circle')
    ->color('success')
    ->tooltip('Justifica todas las ausencias seleccionadas')

// Después
BulkAction::make('bulk_justify')
    ->label('Justificar seleccionadas')
    ->icon('heroicon-o-check-circle')
    ->color('success')
    ->visible(fn () => auth()->user()->can('justify_absence'))
    ->tooltip('Justifica todas las ausencias seleccionadas')
```

Editar `app/Filament/Resources/AbsenceResource.php` (línea ~344, `bulk_unjustify` — sin `->visible()` hoy):

```php
// Antes
BulkAction::make('bulk_unjustify')
    ->label('Marcar injustificadas')
    ->icon('heroicon-o-x-circle')
    ->color('danger')
    ->tooltip('Marca como injustificadas y genera deducciones para las seleccionadas')

// Después
BulkAction::make('bulk_unjustify')
    ->label('Marcar injustificadas')
    ->icon('heroicon-o-x-circle')
    ->color('danger')
    ->visible(fn () => auth()->user()->can('mark_unjustified_absence'))
    ->tooltip('Marca como injustificadas y genera deducciones para las seleccionadas')
```

Editar `app/Filament/Resources/AbsenceResource.php` (línea ~469, `getExcelExportAction()` — sin `->visible()` hoy):

```php
// Antes
public static function getExcelExportAction(): ExportAction
{
    return ExportAction::make('export_excel')
        ->label('Exportar a Excel')
        ->icon('heroicon-o-arrow-down-tray')
        ->color('info')
        ->tooltip('Exportar registros visibles respetando filtros y tabs activos')

// Después
public static function getExcelExportAction(): ExportAction
{
    return ExportAction::make('export_excel')
        ->label('Exportar a Excel')
        ->icon('heroicon-o-arrow-down-tray')
        ->color('info')
        ->visible(fn () => auth()->user()->can('export_absence'))
        ->tooltip('Exportar registros visibles respetando filtros y tabs activos')
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/AbsenceBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/AbsenceResourceFilterTest.php tests/Feature/AbsencePenaltyCalculatorTest.php`
Expected: PASS

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/AbsenceResource.php app/Filament/Resources/AbsenceResource/Pages/ViewAbsence.php app/Filament/Resources/AbsenceResource/Pages/EditAbsence.php tests/Feature/Filament/AbsenceBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a ausencias"
```

---

## Task 15: Permisos de negocio — Contract

**Discrepancia con el catálogo del spec:** la tabla dice "`activate_contract` | `activate` (row + view header), `bulk_activate`" pero `ContractResource.php` (tabla, `->actions([...])`) NO tiene una acción de fila `activate` — solo existe en `ViewContract.php` (header) y como `bulk_activate`. Se aplica el permiso a las dos ocurrencias reales (view header + bulk), sin inventar una fila que no existe.

**Files:**
- Modify: `app/Filament/Resources/ContractResource.php:750` (`renew`), `:865` (`suspend`), `:879` (`reactivate`), `:893` (`terminate`), `:928-951` (`bulk_activate`), `:953-976` (`bulk_suspend`), `:978-1001` (`bulk_terminate`)
- Modify: `app/Filament/Resources/ContractResource/Pages/ViewContract.php:42` (`activate`), `:106` (`renew`), `:170` (`suspend`), `:185` (`reactivate`), `:200` (`terminate`)
- Test: `tests/Feature/Filament/ContractBusinessPermissionsTest.php`

**Interfaces:**
- Consumes: permisos `activate_contract`, `renew_contract`, `suspend_contract`, `reactivate_contract`, `terminate_contract` (ya sembrados por `BusinessActionPermissionSeeder` en Task 1)
- Produces: nada — tarea hoja

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Filament\Resources\ContractResource\Pages\ListContracts;
use App\Filament\Resources\ContractResource\Pages\ViewContract;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();

    foreach (['activate_contract', 'renew_contract', 'suspend_contract', 'reactivate_contract', 'terminate_contract'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
});

/** @return array{employee: Employee, department: Department, position: Position} */
function makeContractTestSetup(): array
{
    static $ci = 9400000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpContract {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucContract {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "DepContract {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "PosContract {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Contract',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'email' => "contract{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);

    return ['employee' => $employee, 'department' => $department, 'position' => $position];
}

function makeTestContract(string $status, string $type = 'indefinido'): Contract
{
    $setup = makeContractTestSetup();

    return Contract::create([
        'employee_id' => $setup['employee']->id,
        'type' => $type,
        'start_date' => Carbon::now()->subMonths(6),
        'end_date' => $type === 'plazo_fijo' ? Carbon::now()->addMonths(6) : null,
        'salary_type' => 'mensual',
        'salary' => 2_550_000,
        'payroll_type' => 'monthly',
        'position_id' => $setup['position']->id,
        'department_id' => $setup['department']->id,
        'status' => $status,
    ]);
}

it('oculta activate sin activate_contract y la muestra con él', function () {
    $contract = makeTestContract('draft');

    $roleWithout = Role::create(['name' => 'Sin Activar Contratos', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_contract', 'view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('activate');

    $roleWith = Role::create(['name' => 'Con Activar Contratos', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_contract', 'view_any_contract', 'activate_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible('activate');
});

it('oculta bulk_activate sin activate_contract y la muestra con él', function () {
    makeTestContract('draft');

    $roleWithout = Role::create(['name' => 'Sin Bulk Activar', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionHidden('bulk_activate');

    $roleWith = Role::create(['name' => 'Con Bulk Activar', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_contract', 'activate_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionVisible('bulk_activate');
});

it('oculta renew sin renew_contract y la muestra con él', function () {
    $contract = makeTestContract('active', 'plazo_fijo');

    $roleWithout = Role::create(['name' => 'Sin Renovar Contratos', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_contract', 'view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('renew');

    $roleWith = Role::create(['name' => 'Con Renovar Contratos', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_contract', 'view_any_contract', 'renew_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible('renew');
});

it('oculta suspend sin suspend_contract y la muestra con él', function () {
    $contract = makeTestContract('active');

    $roleWithout = Role::create(['name' => 'Sin Suspender Contratos', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_contract', 'view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('suspend');

    $roleWith = Role::create(['name' => 'Con Suspender Contratos', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_contract', 'view_any_contract', 'suspend_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible('suspend');
});

it('oculta bulk_suspend sin suspend_contract y la muestra con él', function () {
    makeTestContract('active');

    $roleWithout = Role::create(['name' => 'Sin Bulk Suspender', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionHidden('bulk_suspend');

    $roleWith = Role::create(['name' => 'Con Bulk Suspender', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_contract', 'suspend_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionVisible('bulk_suspend');
});

it('oculta reactivate sin reactivate_contract y la muestra con él', function () {
    $contract = makeTestContract('suspended');

    $roleWithout = Role::create(['name' => 'Sin Reactivar Contratos', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_contract', 'view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('reactivate');

    $roleWith = Role::create(['name' => 'Con Reactivar Contratos', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_contract', 'view_any_contract', 'reactivate_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible('reactivate');
});

it('oculta terminate sin terminate_contract y la muestra con él', function () {
    $contract = makeTestContract('active');

    $roleWithout = Role::create(['name' => 'Sin Terminar Contratos', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_contract', 'view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionHidden('terminate');

    $roleWith = Role::create(['name' => 'Con Terminar Contratos', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_contract', 'view_any_contract', 'terminate_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertActionVisible('terminate');
});

it('oculta bulk_terminate sin terminate_contract y la muestra con él', function () {
    makeTestContract('active');

    $roleWithout = Role::create(['name' => 'Sin Bulk Terminar', 'guard_name' => 'web']);
    $roleWithout->syncPermissions(['view_any_contract']);
    $userWithout = User::factory()->create();
    $userWithout->assignRole($roleWithout);

    $this->actingAs($userWithout);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionHidden('bulk_terminate');

    $roleWith = Role::create(['name' => 'Con Bulk Terminar', 'guard_name' => 'web']);
    $roleWith->syncPermissions(['view_any_contract', 'terminate_contract']);
    $userWith = User::factory()->create();
    $userWith->assignRole($roleWith);

    $this->actingAs($userWith);
    Livewire::test(ListContracts::class)
        ->assertTableBulkActionVisible('bulk_terminate');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/ContractBusinessPermissionsTest.php`
Expected: FAIL — todas las aserciones `assertActionHidden`/`assertTableBulkActionHidden` para el usuario sin permiso fallan, porque hoy `activate`, `renew`, `suspend`, `reactivate`, `terminate` solo dependen del estado del contrato, y `bulk_activate`/`bulk_suspend`/`bulk_terminate` no tienen ningún `->visible()`.

- [ ] **Step 3: Agregar el chequeo de permiso a cada acción**

Editar `app/Filament/Resources/ContractResource/Pages/ViewContract.php` (línea 42, `activate`):

```php
// Antes
->visible(fn () => $this->record->status === 'draft')

// Después
->visible(fn () => $this->record->status === 'draft' && auth()->user()->can('activate_contract'))
```

Editar `app/Filament/Resources/ContractResource/Pages/ViewContract.php` (línea 106, `renew`):

```php
// Antes
->visible(fn () => $this->record->status === 'active' && $this->record->type !== 'indefinido')

// Después
->visible(fn () => $this->record->status === 'active' && $this->record->type !== 'indefinido' && auth()->user()->can('renew_contract'))
```

Editar `app/Filament/Resources/ContractResource/Pages/ViewContract.php` (línea 170, `suspend`):

```php
// Antes
->visible(fn () => $this->record->status === 'active')

// Después
->visible(fn () => $this->record->status === 'active' && auth()->user()->can('suspend_contract'))
```

Editar `app/Filament/Resources/ContractResource/Pages/ViewContract.php` (línea 185, `reactivate`):

```php
// Antes
->visible(fn () => $this->record->status === 'suspended')

// Después
->visible(fn () => $this->record->status === 'suspended' && auth()->user()->can('reactivate_contract'))
```

Editar `app/Filament/Resources/ContractResource/Pages/ViewContract.php` (línea 200, `terminate`):

```php
// Antes
->visible(fn () => $this->record->status === 'active')

// Después
->visible(fn () => $this->record->status === 'active' && auth()->user()->can('terminate_contract'))
```

Editar `app/Filament/Resources/ContractResource.php` (línea 750, acción de fila `renew`):

```php
// Antes
->visible(fn (Contract $record) => $record->status === 'active' && $record->type !== 'indefinido')

// Después
->visible(fn (Contract $record) => $record->status === 'active' && $record->type !== 'indefinido' && auth()->user()->can('renew_contract'))
```

Editar `app/Filament/Resources/ContractResource.php` (línea 865, acción de fila `suspend`):

```php
// Antes
Action::make('suspend')
    ->label('Suspender Contrato')
    ->icon('heroicon-o-pause-circle')
    ->color('warning')
    ->visible(fn (Contract $record) => $record->status === 'active')

// Después
Action::make('suspend')
    ->label('Suspender Contrato')
    ->icon('heroicon-o-pause-circle')
    ->color('warning')
    ->visible(fn (Contract $record) => $record->status === 'active' && auth()->user()->can('suspend_contract'))
```

Editar `app/Filament/Resources/ContractResource.php` (línea 879, acción de fila `reactivate`):

```php
// Antes
Action::make('reactivate')
    ->label('Reactivar Contrato')
    ->icon('heroicon-o-play-circle')
    ->color('success')
    ->visible(fn (Contract $record) => $record->status === 'suspended')

// Después
Action::make('reactivate')
    ->label('Reactivar Contrato')
    ->icon('heroicon-o-play-circle')
    ->color('success')
    ->visible(fn (Contract $record) => $record->status === 'suspended' && auth()->user()->can('reactivate_contract'))
```

Editar `app/Filament/Resources/ContractResource.php` (línea 893, acción de fila `terminate`):

```php
// Antes
Action::make('terminate')
    ->label('Terminar Contrato')
    ->icon('heroicon-o-x-circle')
    ->color('danger')
    ->visible(fn (Contract $record) => $record->status === 'active')

// Después
Action::make('terminate')
    ->label('Terminar Contrato')
    ->icon('heroicon-o-x-circle')
    ->color('danger')
    ->visible(fn (Contract $record) => $record->status === 'active' && auth()->user()->can('terminate_contract'))
```

Editar `app/Filament/Resources/ContractResource.php` (línea ~928, `bulk_activate` — sin `->visible()` hoy):

```php
// Antes
BulkAction::make('bulk_activate')
    ->label('Activar seleccionados')
    ->icon('heroicon-o-check-circle')
    ->color('success')
    ->requiresConfirmation()

// Después
BulkAction::make('bulk_activate')
    ->label('Activar seleccionados')
    ->icon('heroicon-o-check-circle')
    ->color('success')
    ->visible(fn () => auth()->user()->can('activate_contract'))
    ->requiresConfirmation()
```

Editar `app/Filament/Resources/ContractResource.php` (línea ~953, `bulk_suspend` — sin `->visible()` hoy):

```php
// Antes
BulkAction::make('bulk_suspend')
    ->label('Suspender seleccionados')
    ->icon('heroicon-o-pause-circle')
    ->color('warning')
    ->requiresConfirmation()

// Después
BulkAction::make('bulk_suspend')
    ->label('Suspender seleccionados')
    ->icon('heroicon-o-pause-circle')
    ->color('warning')
    ->visible(fn () => auth()->user()->can('suspend_contract'))
    ->requiresConfirmation()
```

Editar `app/Filament/Resources/ContractResource.php` (línea ~978, `bulk_terminate` — sin `->visible()` hoy):

```php
// Antes
BulkAction::make('bulk_terminate')
    ->label('Terminar seleccionados')
    ->icon('heroicon-o-x-circle')
    ->color('danger')
    ->requiresConfirmation()

// Después
BulkAction::make('bulk_terminate')
    ->label('Terminar seleccionados')
    ->icon('heroicon-o-x-circle')
    ->color('danger')
    ->visible(fn () => auth()->user()->can('terminate_contract'))
    ->requiresConfirmation()
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/ContractBusinessPermissionsTest.php`
Expected: PASS

- [ ] **Step 5: Correr la suite del recurso para descartar regresiones**

Run: `php artisan test --compact tests/Feature/ContractTest.php tests/Feature/ContractServiceTest.php tests/Feature/ContractResourceFilterTest.php`
Expected: PASS

- [ ] **Step 6: Formatear y commit**

```bash
vendor/bin/pint --dirty
git add app/Filament/Resources/ContractResource.php app/Filament/Resources/ContractResource/Pages/ViewContract.php tests/Feature/Filament/ContractBusinessPermissionsTest.php
git commit -m "feat: agregar permisos de negocio a contratos"
```

---

## Task 16: Enganchar el seeder, suite completa y ajuste de `CLAUDE.md`

**Files:**
- Modify: `database/seeders/ProductionSeeder.php`
- Modify: `database/seeders/DemoSeeder.php`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: todo lo anterior (Tasks 1-15).
- Produces: ninguna — tarea de cierre.

- [ ] **Step 1: Enganchar `BusinessActionPermissionSeeder` en `ProductionSeeder`**

Editar `database/seeders/ProductionSeeder.php`:

```php
// Antes
$this->createAdminUser();
$this->call([
    PermissionSeeder::class,
    RoleSeeder::class,
]);
$this->seedDeductions();

// Después
$this->createAdminUser();
$this->call([
    PermissionSeeder::class,
    BusinessActionPermissionSeeder::class,
    RoleSeeder::class,
]);
$this->seedDeductions();
```

- [ ] **Step 2: Enganchar `BusinessActionPermissionSeeder` en `DemoSeeder`**

Editar `database/seeders/DemoSeeder.php`:

```php
// Antes
$this->call([
    PermissionSeeder::class,
    RoleSeeder::class,

    // Configuración del sistema (debe ir primero: muchos servicios leen settings al iniciar)
    SettingsSeeder::class,

// Después
$this->call([
    PermissionSeeder::class,
    BusinessActionPermissionSeeder::class,
    RoleSeeder::class,

    // Configuración del sistema (debe ir primero: muchos servicios leen settings al iniciar)
    SettingsSeeder::class,
```

- [ ] **Step 3: Correr el test de integración de seeders existente**

Run: `php artisan test --compact tests/Feature/Seeders/RoleSeederIntegrationTest.php`
Expected: PASS (sigue verificando que `ProductionSeeder` deja los 4 roles sembrados y el admin con Super Admin — no se rompe con el seeder nuevo en el medio)

- [ ] **Step 4: Reconstruir la base de datos de testing y correr la suite completa, sin concurrencia**

Antes de la corrida final, reconstruir `nominapp_testing` para evitar el problema de estado corrupto por corridas interrumpidas ya documentado en Plan 1:

Run: `php artisan migrate:fresh --env=testing --force`

Luego correr la suite completa **en un solo proceso, sin nada más corriendo en paralelo contra la misma base**:

Run: `php artisan test --compact`
Expected: PASS — sin regresiones. Prestar especial atención a cualquier test existente que haga `actingAs(User::factory()->create())` sobre alguno de los 12 Resources tocados en este plan sin asignar rol/permiso — si alguno falla por acción oculta inesperadamente, revisar si ese test necesita el permiso de negocio nuevo asignado (mismo patrón que Plan 1 usó con `tap(User::factory()->create(), fn ($user) => $user->assignRole(...))`, pero acá puede hacer falta `syncPermissions()` puntual sobre un rol de prueba en vez de asignar un rol completo, según qué acción esté probando ese test).

- [ ] **Step 5: Actualizar `CLAUDE.md`**

En la sección "### Módulo de Roles y Permisos" (agregada en Plan 1), reemplazar el primer párrafo:

```
// Antes
Fundación de control de acceso vía `spatie/laravel-permission`. Cubre permisos CRUD por modelo y administración de roles — **no** cubre permisos de acciones de negocio (aprobar, cerrar, exportar, etc.) ni auditoría completa, que quedan para planes posteriores.

// Después
Control de acceso vía `spatie/laravel-permission`. Cubre permisos CRUD por modelo (`PermissionSeeder`) y permisos de acciones de negocio — aprobar, rechazar, cerrar, desembolsar, exportar, etc. (`BusinessActionPermissionSeeder`) — para los 12 módulos con workflow real: Loan, Advance, MerchandiseWithdrawal, DisbursementBatch, Payroll, PayrollPeriod, Liquidación, Aguinaldo, AguinaldoPeriod, EmployeeLeave, Absence, Contract. Warning queda fuera (sin lifecycle). **No** cubre auditoría completa, que queda para un plan posterior (Plan 3).
```

Agregar un párrafo nuevo después de la descripción de `RoleSeeder` (después del párrafo que empieza con "`RoleSeeder` también asigna Super Admin..."):

```
**Permisos de acciones de negocio (`BusinessActionPermissionSeeder`):** convención `{accion}_{modelo}` (ej. `approve_loan`, `close_payroll_period`), un permiso por recurso+verbo — no por cada ocurrencia del botón en fila/header/bulk, que comparten el mismo permiso. Se agrega como `&& auth()->user()->can('{permiso}')` a la condición de estado ya existente en cada `->visible()`. Acciones puramente administrativas (cambiar método de pago, editar borrador, descargar un archivo ya generado) quedan cubiertas por los permisos CRUD de Plan 1 (`update_{modelo}`/`view_{modelo}`), sin permiso de negocio propio. `RoleResource` muestra los permisos de negocio junto a los CRUD de cada modelo, dentro del mismo `CheckboxList` agrupado por módulo — no en una sección aparte.
```

- [ ] **Step 6: Formatear y commit final**

```bash
vendor/bin/pint --dirty
git add database/seeders/ProductionSeeder.php database/seeders/DemoSeeder.php CLAUDE.md
git commit -m "feat: sembrar permisos de negocio desde ProductionSeeder/DemoSeeder y documentar en CLAUDE.md"
```

---

## Fin del Plan 2

Al completar este plan: las 43 acciones de negocio de los 12 módulos con workflow real quedan gateadas por permiso, además del chequeo de estado que ya tenían. RRHH y Contador/Nómina tienen los permisos correspondientes a su dominio; Solo Lectura no tiene ninguno; Super Admin sigue bypasseando todo. `RoleResource` permite armar roles custom con permisos de negocio granulares junto a los CRUD. **La auditoría completa** queda para el Plan 3, a escribirse por separado.
