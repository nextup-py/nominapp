# Roles y Permisos — Fundación Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Instalar `spatie/laravel-permission`, bloquear el panel Filament por rol, generar el catálogo de ~165 permisos CRUD sobre los 33 modelos existentes, sembrar 4 roles iniciales, y dar de alta la UI de administración (`RoleResource`, selector de roles en `UserResource`).

**Architecture:** Una `App\Policies\BasePolicy` genérica resuelve el nombre de permiso por convención a partir del nombre de la clase Policy (`EmployeePolicy` → `*_employee`), así cada una de las 33 Policies reales es una clase vacía que solo extiende `BasePolicy`. Laravel las descubre automáticamente por convención de nombres, sin registro manual. El rol Super Admin bypassa cualquier chequeo vía `Gate::before`, evitando tener que sincronizarle los ~165 permisos.

**Tech Stack:** Laravel 12, Filament 3.3, `spatie/laravel-permission`, Pest v3.

**Spec:** `docs/superpowers/specs/2026-09-19-roles-permisos-auditoria-design.md` (secciones "1. Roles y permisos" — este plan NO cubre la sección "2. Auditoría completa", que es un plan separado).

## Global Constraints

- Los permisos CRUD siguen la convención `{ability}_{modelo}` con abilities exactas `view_any`, `view`, `create`, `update`, `delete` (copiado del spec).
- Un usuario puede tener varios roles simultáneamente (relación muchos-a-muchos estándar del paquete).
- El rol Super Admin NO se sincroniza con permisos explícitos — usa `Gate::before`.
- Este plan NO implementa permisos de acciones de negocio (aprobar, cerrar, exportar, etc.) — eso es el Plan 2. Los roles RRHH/Contador/Solo Lectura sembrados aquí solo tienen permisos CRUD; el Plan 2 extenderá `RoleSeeder` para sumarles los permisos de acción.
- Este plan NO implementa scoping por sucursal/empresa (fuera de alcance del spec).
- Todos los textos de UI van en español, siguiendo las convenciones de `CLAUDE.md` (colores semánticos válidos, `placeholder()` en vez de `default('—')`, `->modalSubmitActionLabel()` en confirmaciones, etc.).

---

## Task 1: Instalar spatie/laravel-permission y preparar el entorno de test

**Files:**
- Modify: `composer.json` (vía `composer require`)
- Create: `config/permission.php` (publicado por el paquete)
- Create: `database/migrations/xxxx_xx_xx_xxxxxx_create_permission_tables.php` (publicado por el paquete)
- Modify: `tests/TestCase.php`

**Interfaces:**
- Produces: tabla `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` migradas; `Spatie\Permission\Models\Role` y `Spatie\Permission\Models\Permission` disponibles para toda tarea posterior.

- [ ] **Step 1: Instalar el paquete**

```bash
composer require spatie/laravel-permission
```

- [ ] **Step 2: Publicar config y migraciones**

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

- [ ] **Step 3: Migrar**

```bash
php artisan migrate
```

Expected: crea las tablas `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` sin errores.

- [ ] **Step 4: Limpiar caché de permisos en cada test**

Editar `tests/TestCase.php`:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
```

Esto evita que la caché en memoria de Spatie sirva permisos/roles con IDs de una tabla ya truncada por `RefreshDatabase` de un test anterior corrido en el mismo proceso.

- [ ] **Step 5: Verificar que la suite sigue pasando**

Run: `php artisan test --compact`
Expected: PASS (sin tests nuevos todavía, solo confirma que la instalación no rompió nada).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock config/permission.php database/migrations tests/TestCase.php
git commit -m "feat: instalar spatie/laravel-permission"
```

---

## Task 2: `HasRoles` en `User` y bloqueo de acceso al panel sin rol

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Auth/PanelAccessTest.php`

**Interfaces:**
- Consumes: `Spatie\Permission\Traits\HasRoles` (Task 1).
- Produces: `User::canAccessPanel()` ahora requiere al menos un rol asignado; `$user->roles()`, `$user->assignRole()`, `$user->hasRole()` disponibles en todo el codebase desde acá en adelante.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('blocks panel access for a user without any role', function () {
    $user = User::factory()->create();

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('allows panel access for a user with at least one role', function () {
    Role::create(['name' => 'RRHH', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('RRHH');

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Auth/PanelAccessTest.php`
Expected: FAIL — `canAccessPanel` retorna `true` siempre (implementación vieja) y `assignRole`/`Role` no existen en `User` (falta `HasRoles`).

- [ ] **Step 3: Implementar**

Editar `app/Models/User.php`:

```php
<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->roles()->exists();
    }
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Auth/PanelAccessTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php tests/Feature/Auth/PanelAccessTest.php
git commit -m "feat: bloquear acceso al panel a usuarios sin rol asignado"
```

---

## Task 3: `BasePolicy` genérica y Policies para los 33 modelos

**Files:**
- Create: `app/Policies/BasePolicy.php`
- Create: `app/Policies/AbsencePolicy.php`
- Create: `app/Policies/AdvancePolicy.php`
- Create: `app/Policies/AguinaldoPolicy.php`
- Create: `app/Policies/AguinaldoPeriodPolicy.php`
- Create: `app/Policies/AttendanceDayPolicy.php`
- Create: `app/Policies/AttendanceEventPolicy.php`
- Create: `app/Policies/AttendanceMarkFailurePolicy.php`
- Create: `app/Policies/BranchPolicy.php`
- Create: `app/Policies/CompanyPolicy.php`
- Create: `app/Policies/ContractPolicy.php`
- Create: `app/Policies/ContractTemplatePolicy.php`
- Create: `app/Policies/DeductionPolicy.php`
- Create: `app/Policies/DepartmentPolicy.php`
- Create: `app/Policies/DisbursementBatchPolicy.php`
- Create: `app/Policies/EmployeePolicy.php`
- Create: `app/Policies/EmployeeDevicePolicy.php`
- Create: `app/Policies/EmployeeLeavePolicy.php`
- Create: `app/Policies/FaceEnrollmentPolicy.php`
- Create: `app/Policies/HolidayPolicy.php`
- Create: `app/Policies/LiquidacionPolicy.php`
- Create: `app/Policies/LoanPolicy.php`
- Create: `app/Policies/MerchandiseWithdrawalPolicy.php`
- Create: `app/Policies/PayrollPolicy.php`
- Create: `app/Policies/PayrollPeriodPolicy.php`
- Create: `app/Policies/PerceptionPolicy.php`
- Create: `app/Policies/PositionPolicy.php`
- Create: `app/Policies/RotationPatternPolicy.php`
- Create: `app/Policies/SchedulePolicy.php`
- Create: `app/Policies/ShiftTemplatePolicy.php`
- Create: `app/Policies/TerminalPolicy.php`
- Create: `app/Policies/UserPolicy.php`
- Create: `app/Policies/VacationPolicy.php`
- Create: `app/Policies/WarningPolicy.php`
- Test: `tests/Unit/Policies/BasePolicyTest.php`

**Interfaces:**
- Consumes: `$user->can(string $permission)` (de `HasRoles`, Task 2).
- Produces: cada `{Modelo}Policy` resuelve `viewAny`/`view`/`create`/`update`/`delete` contra los permisos `view_any_{modelo_snake}`, etc. Laravel las descubre automáticamente por convención `App\Policies\{Modelo}Policy` para `App\Models\{Modelo}` — no requieren registro en ningún Provider.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Models\User;
use App\Policies\EmployeePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('resolves the permission name from the policy class name by convention', function () {
    Permission::create(['name' => 'view_any_employee', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->givePermissionTo('view_any_employee');

    expect((new EmployeePolicy())->viewAny($user))->toBeTrue();
});

it('blocks viewAny when the user lacks the permission', function () {
    Permission::create(['name' => 'view_any_employee', 'guard_name' => 'web']);
    $user = User::factory()->create();

    expect((new EmployeePolicy())->viewAny($user))->toBeFalse();
});

it('checks create/update/delete against their own permission names', function () {
    Permission::create(['name' => 'create_employee', 'guard_name' => 'web']);
    Permission::create(['name' => 'update_employee', 'guard_name' => 'web']);
    Permission::create(['name' => 'delete_employee', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->givePermissionTo(['create_employee', 'update_employee']);

    $policy = new EmployeePolicy();
    $employee = new \App\Models\Employee();

    expect($policy->create($user))->toBeTrue();
    expect($policy->update($user, $employee))->toBeTrue();
    expect($policy->delete($user, $employee))->toBeFalse();
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Unit/Policies/BasePolicyTest.php`
Expected: FAIL — la clase `App\Policies\EmployeePolicy` no existe.

- [ ] **Step 3: Implementar `BasePolicy`**

```php
<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Policy CRUD genérica: resuelve el nombre del permiso por convención a
 * partir del nombre de la clase hija (ej. `EmployeePolicy` → `*_employee`),
 * para no repetir la misma lógica en las 33 Policies del proyecto.
 */
abstract class BasePolicy
{
    protected function permissionName(string $ability): string
    {
        $model = Str::snake(Str::replaceLast('Policy', '', class_basename(static::class)));

        return "{$ability}_{$model}";
    }

    public function viewAny(User $user): bool
    {
        return $user->can($this->permissionName('view_any'));
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can($this->permissionName('view'));
    }

    public function create(User $user): bool
    {
        return $user->can($this->permissionName('create'));
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can($this->permissionName('update'));
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can($this->permissionName('delete'));
    }
}
```

- [ ] **Step 4: Crear las 33 Policies vacías**

Cada archivo tiene el mismo contenido, cambiando solo el nombre de clase y el modelo importado. Ejemplo completo (`app/Policies/EmployeePolicy.php`):

```php
<?php

namespace App\Policies;

class EmployeePolicy extends BasePolicy
{
}
```

Repetir exactamente ese patrón (`class {Modelo}Policy extends BasePolicy {}`, mismo namespace `App\Policies`) para cada uno de los 33 archivos listados en **Files** arriba: `AbsencePolicy`, `AdvancePolicy`, `AguinaldoPolicy`, `AguinaldoPeriodPolicy`, `AttendanceDayPolicy`, `AttendanceEventPolicy`, `AttendanceMarkFailurePolicy`, `BranchPolicy`, `CompanyPolicy`, `ContractPolicy`, `ContractTemplatePolicy`, `DeductionPolicy`, `DepartmentPolicy`, `DisbursementBatchPolicy`, `EmployeePolicy`, `EmployeeDevicePolicy`, `EmployeeLeavePolicy`, `FaceEnrollmentPolicy`, `HolidayPolicy`, `LiquidacionPolicy`, `LoanPolicy`, `MerchandiseWithdrawalPolicy`, `PayrollPolicy`, `PayrollPeriodPolicy`, `PerceptionPolicy`, `PositionPolicy`, `RotationPatternPolicy`, `SchedulePolicy`, `ShiftTemplatePolicy`, `TerminalPolicy`, `UserPolicy`, `VacationPolicy`, `WarningPolicy`.

No hace falta importar el modelo (la Policy no lo referencia directamente, `BasePolicy` trabaja solo con `class_basename(static::class)`).

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Unit/Policies/BasePolicyTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Policies tests/Unit/Policies
git commit -m "feat: agregar BasePolicy genérica y Policies para los 33 modelos"
```

---

## Task 4: `PermissionSeeder` — catálogo de permisos CRUD

**Files:**
- Create: `database/seeders/PermissionSeeder.php`
- Test: `tests/Feature/Seeders/PermissionSeederTest.php`

**Interfaces:**
- Produces: constantes públicas `PermissionSeeder::MODELS` (33 claves snake_case), `PermissionSeeder::ABILITIES` (5 abilities), `PermissionSeeder::ABILITY_LABELS`, `PermissionSeeder::MODEL_LABELS`, `PermissionSeeder::GROUPS` (agrupación por módulo) — usadas por `RoleSeeder` (Task 5) y `RoleResource` (Task 8).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('creates the 5 crud permissions for every model in the catalog', function () {
    (new PermissionSeeder())->run();

    $expected = count(PermissionSeeder::MODELS) * count(PermissionSeeder::ABILITIES);

    expect(Permission::count())->toBe($expected);
    expect(Permission::where('name', 'view_any_employee')->exists())->toBeTrue();
    expect(Permission::where('name', 'delete_user')->exists())->toBeTrue();
});

it('is idempotent', function () {
    (new PermissionSeeder())->run();
    (new PermissionSeeder())->run();

    expect(Permission::count())->toBe(count(PermissionSeeder::MODELS) * count(PermissionSeeder::ABILITIES));
});

it('every group in GROUPS only references models present in MODELS', function () {
    $modelsInGroups = collect(PermissionSeeder::GROUPS)->flatten()->all();

    expect($modelsInGroups)->toEqualCanonicalizing(PermissionSeeder::MODELS);
});

it('has a label for every model and every ability', function () {
    foreach (PermissionSeeder::MODELS as $model) {
        expect(PermissionSeeder::MODEL_LABELS)->toHaveKey($model);
    }
    foreach (PermissionSeeder::ABILITIES as $ability) {
        expect(PermissionSeeder::ABILITY_LABELS)->toHaveKey($ability);
    }
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Seeders/PermissionSeederTest.php`
Expected: FAIL — la clase no existe.

- [ ] **Step 3: Implementar**

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/**
 * Siembra el catálogo de permisos CRUD (view_any/view/create/update/delete)
 * para cada uno de los 33 modelos con Resource en Filament.
 *
 * No incluye permisos de acciones de negocio (aprobar, cerrar, exportar...)
 * — esos se agregan en un seeder posterior (ver Plan 2 de roles/permisos).
 */
class PermissionSeeder extends Seeder
{
    public const MODELS = [
        'absence', 'advance', 'aguinaldo', 'aguinaldo_period',
        'attendance_day', 'attendance_event', 'attendance_mark_failure',
        'branch', 'company', 'contract', 'contract_template',
        'deduction', 'department', 'disbursement_batch',
        'employee', 'employee_device', 'employee_leave',
        'face_enrollment', 'holiday', 'liquidacion', 'loan',
        'merchandise_withdrawal', 'payroll', 'payroll_period',
        'perception', 'position', 'rotation_pattern', 'schedule',
        'shift_template', 'terminal', 'user', 'vacation', 'warning',
    ];

    public const ABILITIES = ['view_any', 'view', 'create', 'update', 'delete'];

    public const ABILITY_LABELS = [
        'view_any' => 'Ver listado',
        'view' => 'Ver detalle',
        'create' => 'Crear',
        'update' => 'Editar',
        'delete' => 'Eliminar',
    ];

    public const MODEL_LABELS = [
        'absence' => 'Ausencia',
        'advance' => 'Adelanto',
        'aguinaldo' => 'Aguinaldo',
        'aguinaldo_period' => 'Período de Aguinaldo',
        'attendance_day' => 'Día de Asistencia',
        'attendance_event' => 'Marcación de Asistencia',
        'attendance_mark_failure' => 'Falla de Marcación',
        'branch' => 'Sucursal',
        'company' => 'Empresa',
        'contract' => 'Contrato',
        'contract_template' => 'Plantilla de Contrato',
        'deduction' => 'Deducción',
        'department' => 'Departamento',
        'disbursement_batch' => 'Lote Bancario',
        'employee' => 'Empleado',
        'employee_device' => 'Dispositivo de Empleado',
        'employee_leave' => 'Permiso/Licencia',
        'face_enrollment' => 'Enrolamiento Facial',
        'holiday' => 'Feriado',
        'liquidacion' => 'Liquidación',
        'loan' => 'Préstamo',
        'merchandise_withdrawal' => 'Retiro de Mercadería',
        'payroll' => 'Nómina',
        'payroll_period' => 'Período de Nómina',
        'perception' => 'Percepción',
        'position' => 'Cargo',
        'rotation_pattern' => 'Patrón de Rotación',
        'schedule' => 'Horario',
        'shift_template' => 'Plantilla de Turno',
        'terminal' => 'Terminal',
        'user' => 'Usuario',
        'vacation' => 'Vacación',
        'warning' => 'Amonestación',
    ];

    public const GROUPS = [
        'Organización' => ['company', 'branch', 'department', 'position', 'contract_template'],
        'Empleados' => ['employee', 'contract', 'employee_device', 'face_enrollment', 'vacation', 'warning', 'employee_leave', 'holiday'],
        'Asistencia' => ['attendance_day', 'attendance_event', 'attendance_mark_failure', 'terminal', 'schedule', 'shift_template', 'rotation_pattern'],
        'Nómina y Créditos' => ['payroll', 'payroll_period', 'deduction', 'perception', 'loan', 'advance', 'merchandise_withdrawal', 'liquidacion', 'aguinaldo', 'aguinaldo_period', 'disbursement_batch', 'absence'],
        'Configuración' => ['user'],
    ];

    public function run(): void
    {
        foreach (self::MODELS as $model) {
            foreach (self::ABILITIES as $ability) {
                Permission::firstOrCreate([
                    'name' => "{$ability}_{$model}",
                    'guard_name' => 'web',
                ]);
            }
        }
    }
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Seeders/PermissionSeederTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/seeders/PermissionSeeder.php tests/Feature/Seeders/PermissionSeederTest.php
git commit -m "feat: agregar PermissionSeeder con catálogo CRUD de 33 modelos"
```

---

## Task 5: `RoleSeeder` — 4 roles iniciales y migración de usuarios existentes

**Files:**
- Create: `database/seeders/RoleSeeder.php`
- Test: `tests/Feature/Seeders/RoleSeederTest.php`

**Interfaces:**
- Consumes: `PermissionSeeder::MODELS`, `::ABILITIES` (Task 4).
- Produces: roles `Super Admin`, `RRHH`, `Contador/Nómina`, `Solo Lectura` sembrados con su matriz CRUD; usuarios existentes sin rol quedan con `Super Admin`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder())->run();
});

it('creates the four base roles', function () {
    (new RoleSeeder())->run();

    expect(Role::pluck('name')->all())->toEqualCanonicalizing([
        'Super Admin', 'RRHH', 'Contador/Nómina', 'Solo Lectura',
    ]);
});

it('gives RRHH crud permissions on employee but not on payroll', function () {
    (new RoleSeeder())->run();

    $rrhh = Role::findByName('RRHH');

    expect($rrhh->hasPermissionTo('update_employee'))->toBeTrue();
    expect($rrhh->hasPermissionTo('update_payroll'))->toBeFalse();
});

it('gives Contador/Nómina full crud on payroll but only view on employee', function () {
    (new RoleSeeder())->run();

    $contador = Role::findByName('Contador/Nómina');

    expect($contador->hasPermissionTo('update_payroll'))->toBeTrue();
    expect($contador->hasPermissionTo('view_employee'))->toBeTrue();
    expect($contador->hasPermissionTo('update_employee'))->toBeFalse();
});

it('gives Solo Lectura only view permissions across every model', function () {
    (new RoleSeeder())->run();

    $readOnly = Role::findByName('Solo Lectura');

    foreach (PermissionSeeder::MODELS as $model) {
        expect($readOnly->hasPermissionTo("view_any_{$model}"))->toBeTrue();
        expect($readOnly->hasPermissionTo("create_{$model}"))->toBeFalse();
    }
});

it('assigns Super Admin to existing users without any role', function () {
    $user = User::factory()->create();

    (new RoleSeeder())->run();

    expect($user->fresh()->hasRole('Super Admin'))->toBeTrue();
});

it('does not touch a user that already has a role', function () {
    (new RoleSeeder())->run();

    $user = User::factory()->create();
    $user->assignRole('RRHH');

    (new RoleSeeder())->run();

    expect($user->fresh()->hasRole('Super Admin'))->toBeFalse();
    expect($user->fresh()->hasRole('RRHH'))->toBeTrue();
});

it('is idempotent on the permission matrix', function () {
    (new RoleSeeder())->run();
    (new RoleSeeder())->run();

    expect(Role::count())->toBe(4);
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Seeders/RoleSeederTest.php`
Expected: FAIL — la clase no existe.

- [ ] **Step 3: Implementar**

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
        $rrhh->syncPermissions($this->crudPermissionsFor([
            'employee', 'contract', 'contract_template', 'department', 'position',
            'attendance_day', 'attendance_event', 'attendance_mark_failure',
            'employee_leave', 'warning', 'vacation', 'schedule', 'shift_template',
            'rotation_pattern', 'holiday', 'face_enrollment', 'employee_device',
            'terminal', 'branch', 'absence',
        ]));

        $contador = Role::firstOrCreate(['name' => 'Contador/Nómina', 'guard_name' => 'web']);
        $contador->syncPermissions(array_merge(
            $this->crudPermissionsFor([
                'payroll', 'payroll_period', 'loan', 'advance',
                'merchandise_withdrawal', 'liquidacion', 'aguinaldo',
                'aguinaldo_period', 'disbursement_batch', 'deduction', 'perception',
            ]),
            $this->viewOnlyPermissionsFor(['employee', 'contract']),
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

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Seeders/RoleSeederTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/seeders/RoleSeeder.php tests/Feature/Seeders/RoleSeederTest.php
git commit -m "feat: agregar RoleSeeder con 4 roles y migración de usuarios existentes"
```

---

## Task 6: `Gate::before` para Super Admin

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Auth/SuperAdminBypassTest.php`

**Interfaces:**
- Consumes: rol `Super Admin` (Task 5), `$user->can()` (Task 2).
- Produces: cualquier `$user->can('cualquier_permiso')` retorna `true` para un usuario con rol Super Admin, sin importar si ese permiso existe o está asignado al rol.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lets Super Admin bypass any permission check, even one that is not assigned to the role', function () {
    Permission::create(['name' => 'delete_employee', 'guard_name' => 'web']);
    Role::create(['name' => 'Super Admin', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->assignRole('Super Admin');

    expect($user->can('delete_employee'))->toBeTrue();
});

it('still blocks a regular role without the permission', function () {
    Permission::create(['name' => 'delete_employee', 'guard_name' => 'web']);
    Role::create(['name' => 'RRHH', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->assignRole('RRHH');

    expect($user->can('delete_employee'))->toBeFalse();
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Auth/SuperAdminBypassTest.php`
Expected: FAIL — el primer test falla porque Super Admin todavía no bypassa nada.

- [ ] **Step 3: Implementar**

Editar `app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Terminal;
use App\Models\User;
use App\Observers\AttendanceDayObserver;
use App\Observers\AttendanceEventObserver;
use App\Observers\CompanyObserver;
use App\Observers\ContractObserver;
use App\Observers\EmployeeObserver;
use App\Observers\TerminalObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AttendanceDay::observe(AttendanceDayObserver::class);
        AttendanceEvent::observe(AttendanceEventObserver::class);
        Company::observe(CompanyObserver::class);
        Contract::observe(ContractObserver::class);
        Employee::observe(EmployeeObserver::class);
        Terminal::observe(TerminalObserver::class);

        Gate::before(fn (User $user) => $user->hasRole('Super Admin') ? true : null);
    }
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Auth/SuperAdminBypassTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/Auth/SuperAdminBypassTest.php
git commit -m "feat: Super Admin bypassa cualquier permiso vía Gate::before"
```

---

## Task 7: Enganchar los seeders en `DatabaseSeeder`, `ProductionSeeder` y `DemoSeeder`

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `database/seeders/ProductionSeeder.php`
- Modify: `database/seeders/DemoSeeder.php`
- Test: `tests/Feature/Seeders/RoleSeederIntegrationTest.php`

**Interfaces:**
- Consumes: `PermissionSeeder`, `RoleSeeder` (Tasks 4-5).
- Produces: correr `php artisan db:seed --class=ProductionSeeder` o `--class=DemoSeeder` deja siempre los roles/permisos sembrados y el usuario admin recién creado con Super Admin.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds roles and assigns Super Admin to the freshly created admin user', function () {
    config(['app.env' => 'testing']);
    putenv('ADMIN_EMAIL=admin@example.com');

    $this->artisan('db:seed', ['--class' => ProductionSeeder::class])->assertSuccessful();

    expect(Role::pluck('name')->all())->toEqualCanonicalizing([
        'Super Admin', 'RRHH', 'Contador/Nómina', 'Solo Lectura',
    ]);

    $admin = User::where('email', 'admin@example.com')->first();
    expect($admin)->not->toBeNull();
    expect($admin->hasRole('Super Admin'))->toBeTrue();
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Seeders/RoleSeederIntegrationTest.php`
Expected: FAIL — `ProductionSeeder` no siembra roles todavía, así que `Role::pluck('name')` viene vacío.

- [ ] **Step 3: Implementar**

En `database/seeders/ProductionSeeder.php`, dentro de `run()`, agregar la llamada justo después de `$this->createAdminUser();`:

```php
    public function run(): void
    {
        $this->createAdminUser();
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);
        $this->seedDeductions();

        $this->call([
            ParaguayRegionsSeeder::class,
            HolidaySeeder::class,
            ContractTemplateSeeder::class,
        ]);

        $this->printChecklist();
    }
```

En `database/seeders/DemoSeeder.php`, dentro de `run()`, agregar la misma llamada justo después de `$this->createAdminUser();` (antes del `$this->call([...])` existente de `SettingsSeeder`, etc.):

```php
    public function run(): void
    {
        $this->truncateTables();
        $this->createAdminUser();

        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,

            // Configuración del sistema (debe ir primero: muchos servicios leen settings al iniciar)
            SettingsSeeder::class,

            // Catálogo geográfico (requerido por EmployeeAddressSeeder)
            ParaguayRegionsSeeder::class,
            // ... el resto del array existente queda igual
        ]);
    }
```

`DatabaseSeeder.php` no necesita cambios — ya delega en `ProductionSeeder`/`DemoSeeder`, que ahora siembran roles por sí mismos.

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Seeders/RoleSeederIntegrationTest.php`
Expected: PASS

- [ ] **Step 5: Correr toda la suite de seeders para descartar regresiones**

Run: `php artisan test --compact tests/Feature/Seeders`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/seeders/ProductionSeeder.php database/seeders/DemoSeeder.php tests/Feature/Seeders/RoleSeederIntegrationTest.php
git commit -m "feat: sembrar roles y permisos desde ProductionSeeder y DemoSeeder"
```

---

## Task 8: `RoleResource` — CRUD de roles con permisos agrupados por módulo

**Files:**
- Create: `app/Filament/Resources/RoleResource.php`
- Create: `app/Filament/Resources/RoleResource/Pages/ListRoles.php`
- Create: `app/Filament/Resources/RoleResource/Pages/CreateRole.php`
- Create: `app/Filament/Resources/RoleResource/Pages/EditRole.php`
- Test: `tests/Feature/Filament/RoleResourceTest.php`

**Interfaces:**
- Consumes: `PermissionSeeder::GROUPS`, `::ABILITIES`, `::ABILITY_LABELS`, `::MODEL_LABELS` (Task 4).
- Produces: pantalla en el panel (grupo Configuración) donde Super Admin arma roles custom marcando permisos agrupados por módulo.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder())->run();
    (new RoleSeeder())->run();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
});

it('lists existing roles for Super Admin', function () {
    $this->actingAs($this->admin);

    Livewire::test(\App\Filament\Resources\RoleResource\Pages\ListRoles::class)
        ->assertCanSeeTableRecords(Role::all());
});

it('blocks a user without Super Admin from viewing the roles list', function () {
    $rrhh = User::factory()->create();
    $rrhh->assignRole('RRHH');
    $this->actingAs($rrhh);

    $this->get(\App\Filament\Resources\RoleResource::getUrl('index'))->assertForbidden();
});

it('creates a role with the selected permissions grouped by module', function () {
    $this->actingAs($this->admin);

    Livewire::test(\App\Filament\Resources\RoleResource\Pages\CreateRole::class)
        ->fillForm([
            'name' => 'Supervisor',
            'group_empleados' => ['view_any_employee', 'view_employee'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::findByName('Supervisor');
    expect($role->hasPermissionTo('view_any_employee'))->toBeTrue();
    expect($role->hasPermissionTo('view_employee'))->toBeTrue();
    expect($role->hasPermissionTo('create_employee'))->toBeFalse();
});

it('preloads the current permissions when editing a role', function () {
    $this->actingAs($this->admin);

    $role = Role::findByName('RRHH');

    Livewire::test(\App\Filament\Resources\RoleResource\Pages\EditRole::class, ['record' => $role->getRouteKey()])
        ->assertFormSet(['group_empleados' => fn ($state) => in_array('update_employee', $state, true)]);
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/RoleResourceTest.php`
Expected: FAIL — el Resource no existe.

- [ ] **Step 3: Implementar `RoleResource`**

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Database\Seeders\PermissionSeeder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $label = 'rol';

    protected static ?string $pluralLabel = 'roles';

    protected static ?string $slug = 'roles';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('Super Admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        $sections = [
            Section::make('Datos del Rol')
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre del rol')
                        ->required()
                        ->unique(table: Role::class, column: 'name', ignoreRecord: true)
                        ->validationMessages(['unique' => 'Ya existe un rol con ese nombre.'])
                        ->maxLength(255),
                ]),
        ];

        foreach (PermissionSeeder::GROUPS as $groupName => $models) {
            $options = [];
            foreach ($models as $model) {
                foreach (PermissionSeeder::ABILITIES as $ability) {
                    $options["{$ability}_{$model}"] = PermissionSeeder::ABILITY_LABELS[$ability].' — '.PermissionSeeder::MODEL_LABELS[$model];
                }
            }

            $sections[] = Section::make($groupName)
                ->collapsible()
                ->schema([
                    CheckboxList::make(self::groupFieldKey($groupName))
                        ->label('')
                        ->options($options)
                        ->columns(2)
                        ->dehydrated(false),
                ]);
        }

        return $form->schema($sections)->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Rol')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('permissions_count')
                    ->label('Permisos')
                    ->counts('permissions')
                    ->badge(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->actions([
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square'),
                DeleteAction::make()
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->modalHeading('¿Eliminar rol?')
                    ->modalDescription('Los usuarios que tengan este rol perderán los permisos asociados.')
                    ->modalSubmitActionLabel('Sí, eliminar'),
            ])
            ->bulkActions([])
            ->paginationPageOptions([10, 25, 50, 100])
            ->emptyStateHeading('No hay roles registrados')
            ->emptyStateDescription('Creá el primer rol para empezar a asignar permisos.')
            ->emptyStateIcon('heroicon-o-shield-check');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/crear'),
            'edit' => Pages\EditRole::route('/{record}/editar'),
        ];
    }

    public static function groupFieldKey(string $groupName): string
    {
        return 'group_'.Str::slug($groupName, '_');
    }

    /**
     * Reconstruye la lista plana de nombres de permiso seleccionados a
     * partir de los campos virtuales `group_*` (uno por módulo).
     *
     * @param  array<string, mixed>  $state
     * @return array<int, string>
     */
    public static function collectPermissions(array $state): array
    {
        $names = [];
        foreach (array_keys(PermissionSeeder::GROUPS) as $groupName) {
            $names = array_merge($names, $state[self::groupFieldKey($groupName)] ?? []);
        }

        return $names;
    }
}
```

- [ ] **Step 4: Implementar las Pages**

`app/Filament/Resources/RoleResource/Pages/ListRoles.php`:

```php
<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo Rol')
                ->icon('heroicon-o-plus'),
        ];
    }
}
```

`app/Filament/Resources/RoleResource/Pages/CreateRole.php`:

```php
<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['guard_name'] = 'web';

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncPermissions(RoleResource::collectPermissions($this->data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
```

`app/Filament/Resources/RoleResource/Pages/EditRole.php`:

```php
<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Eliminar')
                ->modalHeading('¿Eliminar rol?')
                ->modalSubmitActionLabel('Sí, eliminar'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $currentPermissionNames = $this->record->permissions->pluck('name')->all();

        foreach (PermissionSeeder::GROUPS as $groupName => $models) {
            $groupPermissionNames = [];
            foreach ($models as $model) {
                foreach (PermissionSeeder::ABILITIES as $ability) {
                    $groupPermissionNames[] = "{$ability}_{$model}";
                }
            }

            $data[RoleResource::groupFieldKey($groupName)] = array_values(
                array_intersect($currentPermissionNames, $groupPermissionNames)
            );
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncPermissions(RoleResource::collectPermissions($this->data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
```

- [ ] **Step 5: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/RoleResourceTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/RoleResource.php app/Filament/Resources/RoleResource tests/Feature/Filament/RoleResourceTest.php
git commit -m "feat: agregar RoleResource con permisos agrupados por módulo"
```

---

## Task 9: `UserResource` — selector de roles

**Files:**
- Modify: `app/Filament/Resources/UserResource.php`
- Test: `tests/Feature/Filament/UserResourceRolesTest.php`

**Interfaces:**
- Consumes: relación `roles()` de `HasRoles` (Task 2).
- Produces: desde el formulario de usuario, solo Super Admin puede asignar/quitar roles a otros usuarios.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder())->run();
    (new RoleSeeder())->run();
});

it('lets Super Admin assign roles to another user', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Admin');
    $this->actingAs($admin);

    $target = User::factory()->create();

    Livewire::test(\App\Filament\Resources\UserResource\Pages\ManageUsers::class)
        ->mountTableAction('edit', $target)
        ->setTableActionData(['name' => $target->name, 'email' => $target->email, 'roles' => [Role::findByName('RRHH')->id]])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($target->fresh()->hasRole('RRHH'))->toBeTrue();
});

it('hides the roles field from a non Super Admin user', function () {
    $rrhh = User::factory()->create();
    $rrhh->assignRole('RRHH');
    $this->actingAs($rrhh);

    Livewire::test(\App\Filament\Resources\UserResource\Pages\ManageUsers::class)
        ->assertFormFieldIsHidden('roles', 'mountedTableActionForm');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact tests/Feature/Filament/UserResourceRolesTest.php`
Expected: FAIL — el campo `roles` no existe en el formulario todavía.

- [ ] **Step 3: Implementar**

Agregar el import y el campo en `app/Filament/Resources/UserResource.php`, dentro de `form()`, como una tercera `Section` después de "Seguridad":

```php
use Filament\Forms\Components\Select;
```

```php
                Section::make('Roles')
                    ->schema([
                        Select::make('roles')
                            ->label('Roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->visible(fn () => auth()->user()?->hasRole('Super Admin') ?? false)
                            ->helperText('Solo Super Admin puede asignar roles.'),
                    ]),
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact tests/Feature/Filament/UserResourceRolesTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/UserResource.php tests/Feature/Filament/UserResourceRolesTest.php
git commit -m "feat: agregar selector de roles a UserResource, restringido a Super Admin"
```

---

## Task 10: Suite completa y ajuste de `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md`

**Interfaces:**
- Ninguna — tarea de cierre y documentación.

- [ ] **Step 1: Correr toda la suite**

Run: `php artisan test --compact`
Expected: PASS — sin regresiones en ningún test existente (especialmente los que crean/editan `User`, `Employee`, o interactúan con cualquiera de los 33 Resources, que ahora pasan por Policies).

- [ ] **Step 2: Si algún test existente falla por falta de rol**

Los tests existentes que hacen `actingAs(User::factory()->create())` sobre un Resource ahora fallan porque ese usuario no tiene rol. Ajustar esos tests agregando, antes del `actingAs`, la asignación del rol correspondiente:

```php
$user = User::factory()->create();
$user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
```

(No se listan archivos puntuales acá — depende de qué falle al correr la suite completa en el Step 1; el ejecutor de esta tarea debe revisar la salida y aplicar el ajuste donde corresponda.)

- [ ] **Step 3: Documentar en `CLAUDE.md`**

Agregar una nueva sección después de "### Módulo de Contratos" (o donde el ejecutor considere que encaja mejor cronológicamente con el resto de módulos), describiendo brevemente el sistema de roles (paquete, patrón `BasePolicy`, los 4 roles, y que las acciones de negocio granulares llegan en un plan posterior). Agregar también, en la sección "### Important Notes", esta línea:

```
- Auditoría (`audits` table): sin política de retención/purga por ahora — la tabla crece sin límite. Deuda técnica conocida, pendiente de definir si el volumen lo justifica.
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: documentar sistema de roles y permisos en CLAUDE.md"
```

---

## Fin del Plan 1

Al completar este plan: el panel bloquea usuarios sin rol, los 33 Resources respetan permisos CRUD por rol, existen 4 roles funcionales, y hay UI para administrar roles y asignarlos a usuarios. **Los permisos de acciones de negocio** (aprobar, cerrar, exportar, etc.) y **la auditoría completa** quedan para los Planes 2 y 3, a escribirse por separado.
