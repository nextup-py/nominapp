<?php

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\MobileLinkBlockedNotification;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Fija el valor global de marcación móvil. */
function setMobileDefault(bool $value): void
{
    $settings = app(GeneralSettings::class);
    $settings->attendance_mobile_marking_default = $value;
    $settings->save();
}

/** Empleado activo con rostro, con la jerarquía empresa → sucursal → empleado. */
function mobileEmployee(?bool $company = null, ?bool $branch = null, ?bool $employee = null): Employee
{
    $companyModel = Company::create(['name' => 'Empresa', 'ruc' => '80012345-6', 'employer_number' => '90012345', 'mobile_marking_allowed' => $company]);
    $branchModel = Branch::create(['company_id' => $companyModel->id, 'name' => 'Centro', 'mobile_marking_allowed' => $branch]);

    return Employee::create([
        'branch_id' => $branchModel->id,
        'first_name' => 'Ana',
        'last_name' => 'Gómez',
        'ci' => '1234567',
        'birth_date' => '1990-05-20',
        'status' => 'active',
        'face_descriptor' => json_encode(array_fill(0, 128, 0.1)),
        'mobile_marking_allowed' => $employee,
    ]);
}

it('por defecto solo permite marcar desde el terminal', function () {
    setMobileDefault(false);

    expect(mobileEmployee()->canMarkFromMobile())->toBeFalse();
});

it('resuelve el permiso en orden: empleado, sucursal, empresa y global', function (?bool $company, ?bool $branch, ?bool $employee, bool $default, bool $expected, string $source) {
    setMobileDefault($default);
    $model = mobileEmployee($company, $branch, $employee);

    expect($model->canMarkFromMobile())->toBe($expected)
        ->and($model->mobileMarkingSource()['source'])->toBe($source);
})->with([
    'global' => [null, null, null, true, true, 'general'],
    'empresa gana al global' => [false, null, null, true, false, 'company'],
    'sucursal gana a la empresa' => [false, true, null, false, true, 'branch'],
    'empleado gana a la sucursal' => [true, true, false, true, false, 'employee'],
]);

it('el scope mobileMarking coincide con canMarkFromMobile', function () {
    setMobileDefault(false);
    $allowed = mobileEmployee(null, true, null);

    expect(Employee::query()->mobileMarking(true)->pluck('id')->all())->toBe([$allowed->id])
        ->and(Employee::query()->mobileMarking(false)->count())->toBe(0);
});

it('bloquea la vinculación con un mensaje claro y avisa a RR.HH. sin repetir', function () {
    Notification::fake();
    setMobileDefault(false);
    mobileEmployee();
    $admin = User::factory()->create();
    $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'update_employee', 'guard_name' => 'web']));

    $payload = ['ci' => '1234567', 'birth_date' => '1990-05-20'];

    $this->postJson(route('device-link.claim'), $payload)
        ->assertForbidden()
        ->assertJsonPath('code', 'mobile_not_allowed');

    Notification::assertSentTo($admin, MobileLinkBlockedNotification::class);
});

it('permite vincular si el empleado fue habilitado', function () {
    setMobileDefault(false);
    mobileEmployee(null, null, true);

    $this->postJson(route('device-link.claim'), ['ci' => '1234567', 'birth_date' => '1990-05-20'])
        ->assertSuccessful()
        ->assertJsonPath('ok', true);
});

it('revoca el celular vinculado al deshabilitar al empleado', function () {
    setMobileDefault(false);
    $employee = mobileEmployee(null, null, true);
    $employee->claimMobileToken();
    expect($employee->fresh()->hasMobileLinked())->toBeTrue();

    $employee->update(['mobile_marking_allowed' => false]);

    expect($employee->fresh()->hasMobileLinked())->toBeFalse()
        ->and($employee->tokens()->count())->toBe(0);
});

it('revoca los celulares de la sucursal al deshabilitarla, salvo quien tiene permiso propio', function () {
    setMobileDefault(false);
    $a = mobileEmployee(null, true, null);
    $b = Employee::create(['branch_id' => $a->branch_id, 'first_name' => 'Luis', 'last_name' => 'Paz', 'ci' => '7654321', 'status' => 'active', 'mobile_marking_allowed' => true]);
    $a->claimMobileToken();
    $b->claimMobileToken();

    $a->branch->update(['mobile_marking_allowed' => false]);

    expect($a->fresh()->hasMobileLinked())->toBeFalse()
        ->and($b->fresh()->hasMobileLinked())->toBeTrue();
});

it('la API del celular corta con 403 cuando el empleado dejó de estar habilitado', function () {
    setMobileDefault(true);
    $employee = mobileEmployee();
    Sanctum::actingAs($employee, [Employee::MOBILE_SYNC_ABILITY]);
    $this->postJson('/api/v1/mobile/heartbeat')->assertOk();

    setMobileDefault(false);

    $this->postJson('/api/v1/mobile/heartbeat')
        ->assertForbidden()
        ->assertJsonPath('code', 'mobile_not_allowed');
});

it('el filtro y la acción masiva del listado aplican el permiso', function () {
    setMobileDefault(false);
    $employee = mobileEmployee();
    $employee->claimMobileToken();
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));

    Livewire::test(ListEmployees::class)
        ->filterTable('mobile_marking', true)
        ->assertCanNotSeeTableRecords([$employee]);

    Livewire::test(ListEmployees::class)
        ->callTableBulkAction('set_mobile_marking', [$employee], data: ['value' => 'yes']);

    expect($employee->fresh()->mobile_marking_allowed)->toBeTrue()
        ->and($employee->fresh()->hasMobileLinked())->toBeTrue();
});
