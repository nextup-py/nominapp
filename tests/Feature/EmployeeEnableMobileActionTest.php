<?php

use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\MobileLinkBlockedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Habilitar el celular de un empleado desde su ficha, con aviso por WhatsApp: antes había que entrar a Editar y buscar
 * el selector entre los demás campos, y el empleado no se enteraba.
 */
function enableMobileEmployee(array $attributes = []): Employee
{
    static $n = 9900000;
    $n++;

    $company = Company::create(['name' => "EmpMO {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "SucMO {$n}", 'company_id' => $company->id]);

    return Employee::create([
        'first_name' => 'Ana',
        'last_name' => "Mobil{$n}",
        'ci' => (string) $n,
        'email' => "mo{$n}@test.com",
        'phone' => '0981123456',
        'branch_id' => $branch->id,
        'status' => 'active',
        ...$attributes,
    ]);
}

function enableMobileSuperAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

/** Usuario que puede ver empleados pero no editarlos. */
function enableMobileReadOnlyUser(): void
{
    $role = Role::firstOrCreate(['name' => 'Consulta Mobil', 'guard_name' => 'web']);
    $role->syncPermissions(collect(['view_any_employee', 'view_employee'])->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'])));

    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole($role)));
}

it('el botón Habilitar celular lo habilita solo para ese empleado', function () {
    enableMobileSuperAdmin();
    $employee = enableMobileEmployee();
    $otro = enableMobileEmployee();

    expect($employee->canMarkFromMobile())->toBeFalse();

    Livewire::test(ViewEmployee::class, ['record' => $employee->getKey()])
        ->assertActionVisible('enable_mobile')
        ->callAction('enable_mobile')
        ->assertNotified('Celular habilitado');

    expect($employee->fresh()->mobile_marking_allowed)->toBeTrue()
        ->and($employee->fresh()->canMarkFromMobile())->toBeTrue()
        ->and($otro->fresh()->canMarkFromMobile())->toBeFalse();
});

it('el botón no aparece si el empleado ya puede marcar desde el celular', function () {
    enableMobileSuperAdmin();

    Livewire::test(ViewEmployee::class, ['record' => enableMobileEmployee(['mobile_marking_allowed' => true])->getKey()])
        ->assertActionHidden('enable_mobile');

    enableMobileMarking();

    Livewire::test(ViewEmployee::class, ['record' => enableMobileEmployee()->getKey()])
        ->assertActionHidden('enable_mobile');
});

it('el botón no aparece para un empleado inactivo', function () {
    enableMobileSuperAdmin();

    Livewire::test(ViewEmployee::class, ['record' => enableMobileEmployee(['status' => 'inactive'])->getKey()])
        ->assertActionHidden('enable_mobile');
});

it('quien no puede editar empleados no ve el botón ni puede ejecutarlo', function () {
    enableMobileReadOnlyUser();
    $employee = enableMobileEmployee();

    Livewire::test(ViewEmployee::class, ['record' => $employee->getKey()])
        ->assertActionHidden('enable_mobile');

    expect($employee->fresh()->canMarkFromMobile())->toBeFalse();
});

it('habilitar a un empleado cuya sucursal no puede marcar desde el celular le da un valor propio', function () {
    enableMobileSuperAdmin();
    $employee = enableMobileEmployee();
    $employee->branch->update(['mobile_marking_allowed' => false]);

    expect($employee->fresh()->mobileMarkingLabel())->toBe('No (de la sucursal)');

    Livewire::test(ViewEmployee::class, ['record' => $employee->getKey()])->callAction('enable_mobile');

    expect($employee->fresh()->mobileMarkingLabel())->toBe('Sí');
});

it('arma el enlace de WhatsApp con el teléfono y el enlace de vinculación', function () {
    $url = enableMobileEmployee(['phone' => '0981123456'])->mobileLinkWhatsappUrl();

    expect($url)->toStartWith('https://api.whatsapp.com/send?phone=595981123456&text=')
        ->and(urldecode($url))->toContain(route('device-link.show'))->toContain('Hola Ana');
});

it('sin teléfono no hay enlace de WhatsApp', function () {
    expect(enableMobileEmployee(['phone' => null])->mobileLinkWhatsappUrl())->toBeNull();
});

it('la ficha muestra el estado del celular', function () {
    enableMobileSuperAdmin();

    Livewire::test(ViewEmployee::class, ['record' => enableMobileEmployee()->getKey()])
        ->assertSee('Marcación: rostro y celular')
        ->assertSee('Estado del celular')
        ->assertSee('Puede marcar desde el celular');
});

it('el aviso de intento bloqueado lleva a la ficha y explica cómo habilitar', function () {
    $employee = enableMobileEmployee();
    $data = (new MobileLinkBlockedNotification($employee))->toDatabase(User::factory()->create());

    expect($data['body'])->toContain('Habilitar celular')
        ->and($data['actions'][0]['label'])->toBe('Ver empleado y habilitar')
        ->and($data['actions'][0]['url'])->toContain((string) $employee->getKey());
});

it('la ficha muestra el nombre como título y un avatar local con iniciales', function () {
    enableMobileSuperAdmin();
    $employee = enableMobileEmployee();

    Livewire::test(ViewEmployee::class, ['record' => $employee->getKey()])
        ->assertSeeHtml('<h1')
        ->assertSee($employee->full_name);

    $avatar = $employee->avatar_url;
    expect($avatar)->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,'))))
        ->toContain(mb_strtoupper(mb_substr($employee->first_name, 0, 1).mb_substr($employee->last_name, 0, 1)));
});
