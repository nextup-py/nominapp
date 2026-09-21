<?php

use App\Filament\Resources\EmployeeDeviceResource\Pages\EditEmployeeDevice;
use App\Filament\Resources\EmployeeDeviceResource\Pages\ListEmployeeDevices;
use App\Filament\Resources\EmployeeDeviceResource\Pages\ViewEmployeeDevice;
use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Filament\Resources\EmployeeResource\RelationManagers\DevicesRelationManager;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDevice;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeDeviceTestEmployee(): Employee
{
    static $ci = 9600000;
    $n = $ci++;

    $company = Company::create(['name' => "Empresa Device {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Device {$n}", 'company_id' => $company->id]);
    $department = Department::create(['name' => "Depto Device {$n}", 'company_id' => $company->id]);
    $position = Position::create(['name' => "Cargo Device {$n}", 'department_id' => $department->id]);

    $employee = Employee::create([
        'first_name' => 'Device', 'last_name' => 'Test', 'ci' => (string) $n,
        'birth_date' => '1990-05-15', 'branch_id' => $branch->id, 'status' => 'active',
        'face_descriptor' => array_fill(0, 128, 0.1),
    ]);
    Contract::create([
        'employee_id' => $employee->id, 'type' => 'indefinido', 'start_date' => now()->subYear(),
        'salary_type' => 'mensual', 'salary' => 2_550_000, 'position_id' => $position->id,
        'department_id' => $department->id, 'status' => 'active',
    ]);

    return $employee->fresh();
}

// ─── Ciclo de vida en Employee ──────────────────────────────────────────────

it('claimMobileToken crea un EmployeeDevice activo', function () {
    $employee = makeDeviceTestEmployee();

    $employee->claimMobileToken('Mozilla/5.0 Device A');

    expect($employee->devices()->count())->toBe(1)
        ->and($employee->activeDevice)->not->toBeNull()
        ->and($employee->activeDevice->user_agent)->toBe('Mozilla/5.0 Device A')
        ->and($employee->activeDevice->isActive())->toBeTrue();
});

it('claimMobileToken sugiere marca/modelo a partir del User-Agent (best-effort, sin Client Hint)', function () {
    $employee = makeDeviceTestEmployee();

    $employee->claimMobileToken('Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36');

    expect($employee->activeDevice->device_brand)->toBe('Samsung')
        ->and($employee->activeDevice->device_model)->toBe('SM-S911B');
});

it('claimMobileToken prioriza el Client Hint del navegador sobre el User-Agent', function () {
    $employee = makeDeviceTestEmployee();

    $employee->claimMobileToken('Mozilla/5.0 (Linux; Android 14; SM-S911B)', 'Pixel 8 Pro');

    expect($employee->activeDevice->device_brand)->toBe('Google')
        ->and($employee->activeDevice->device_model)->toBe('Pixel 8 Pro');
});

it('claimMobileToken sin datos de dispositivo no revienta y deja marca/modelo en null', function () {
    $employee = makeDeviceTestEmployee();

    $employee->claimMobileToken(null);

    expect($employee->activeDevice->device_brand)->toBeNull()
        ->and($employee->activeDevice->device_model)->toBeNull();
});

it('re-vincular cierra el dispositivo anterior y abre uno nuevo', function () {
    $employee = makeDeviceTestEmployee();
    $employee->claimMobileToken('Device A');
    $firstDeviceId = $employee->activeDevice->id;

    $employee->claimMobileToken('Device B');

    expect($employee->devices()->count())->toBe(2)
        ->and(EmployeeDevice::find($firstDeviceId)->unlinked_at)->not->toBeNull()
        ->and($employee->activeDevice->user_agent)->toBe('Device B')
        ->and($employee->devices()->whereNull('unlinked_at')->count())->toBe(1);
});

it('revokeMobileToken cierra el dispositivo activo sin crear uno nuevo', function () {
    $employee = makeDeviceTestEmployee();
    $employee->claimMobileToken('Device A');
    $deviceId = $employee->activeDevice->id;

    $employee->revokeMobileToken();

    expect($employee->devices()->count())->toBe(1)
        ->and($employee->activeDevice)->toBeNull()
        ->and(EmployeeDevice::find($deviceId)->unlinked_at)->not->toBeNull()
        ->and($employee->mobile_linked_at)->toBeNull();
});

it('device_description combina marca y modelo', function () {
    $employee = makeDeviceTestEmployee();
    $employee->claimMobileToken('Device A');
    $device = $employee->activeDevice;
    $device->update(['device_brand' => 'Samsung', 'device_model' => 'Galaxy A54']);

    expect($device->fresh()->device_description)->toBe('Samsung Galaxy A54');
});

// ─── Recurso Filament ───────────────────────────────────────────────────────

it('la lista de dispositivos muestra al empleado vinculado', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = makeDeviceTestEmployee();
    $employee->claimMobileToken('Device A');

    Livewire::test(ListEmployeeDevices::class)
        ->assertOk()
        ->assertSee($employee->full_name);
});

it('el detalle del dispositivo renderiza', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = makeDeviceTestEmployee();
    $employee->claimMobileToken('Device A');

    Livewire::test(ViewEmployeeDevice::class, ['record' => $employee->activeDevice->id])
        ->assertOk();
});

it('editar el dispositivo guarda marca/modelo/mac sin tocar el ciclo de vinculación', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = makeDeviceTestEmployee();
    $employee->claimMobileToken('Device A');
    $device = $employee->activeDevice;

    Livewire::test(EditEmployeeDevice::class, ['record' => $device->id])
        ->fillForm([
            'device_brand' => 'Samsung',
            'device_model' => 'Galaxy A54',
            'device_mac' => 'AA:BB:CC:DD:EE:FF',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $device->fresh();
    expect($fresh->device_brand)->toBe('Samsung')
        ->and($fresh->device_model)->toBe('Galaxy A54')
        ->and($fresh->device_mac)->toBe('AA:BB:CC:DD:EE:FF')
        ->and($fresh->unlinked_at)->toBeNull();
});

it('la acción "Revocar" de la tabla cierra el dispositivo y limpia mobile_linked_at', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = makeDeviceTestEmployee();
    $employee->claimMobileToken('Device A');
    $device = $employee->activeDevice;

    Livewire::test(ListEmployeeDevices::class)
        ->mountTableAction('revoke', $device)
        ->callMountedTableAction();

    expect($device->fresh()->unlinked_at)->not->toBeNull()
        ->and($employee->fresh()->mobile_linked_at)->toBeNull();
});

it('la acción "Revocar" no aparece para un dispositivo ya desvinculado', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = makeDeviceTestEmployee();
    $employee->claimMobileToken('Device A');
    $employee->revokeMobileToken();
    $device = $employee->devices()->first();

    Livewire::test(ListEmployeeDevices::class)
        ->assertTableActionHidden('revoke', $device);
});

it('el RelationManager de dispositivos renderiza en la ficha del empleado', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = makeDeviceTestEmployee();
    $employee->claimMobileToken('Device A');

    Livewire::test(DevicesRelationManager::class, [
        'ownerRecord' => $employee,
        'pageClass' => ViewEmployee::class,
    ])->assertOk();
});
