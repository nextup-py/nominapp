<?php

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\EmployeeResource\Pages\ViewEmployee;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\AttendanceInboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Estado del celular personal de cada empleado: no habilitado, habilitado sin vincular, vinculado sin conexión,
 * activo o sin uso reciente. La definición en PHP (`mobileStatus()`) y en SQL (`scopeMobileStatus()`) debe coincidir.
 */
function mobileStatusEmployee(array $attributes = [], ?Branch $branch = null): Employee
{
    static $n = 8800000;
    $n++;

    if (! $branch) {
        $company = Company::create(['name' => "EmpMS {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
        $branch = Branch::create(['name' => "SucMS {$n}", 'company_id' => $company->id]);
    }

    return Employee::create([
        'first_name' => 'Ana',
        'last_name' => "Cel{$n}",
        'ci' => (string) $n,
        'email' => "ms{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
        ...$attributes,
    ]);
}

function mobileStatusAdmin(): void
{
    test()->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
}

/** Un empleado por cada estado posible. @return array<string, Employee> */
function mobileStatusSet(): array
{
    return [
        'not_enabled' => mobileStatusEmployee(),
        'pending_link' => mobileStatusEmployee(['mobile_marking_allowed' => true]),
        'never_connected' => mobileStatusEmployee(['mobile_marking_allowed' => true, 'mobile_linked_at' => now()->subDays(2)]),
        'active' => mobileStatusEmployee(['mobile_marking_allowed' => true, 'mobile_linked_at' => now()->subDays(30), 'mobile_last_heartbeat_at' => now()->subDays(6)]),
        'inactive' => mobileStatusEmployee(['mobile_marking_allowed' => true, 'mobile_linked_at' => now()->subDays(30), 'mobile_last_heartbeat_at' => now()->subDays(8)]),
    ];
}

it('calcula el estado del celular de cada empleado', function () {
    foreach (mobileStatusSet() as $expected => $employee) {
        expect($employee->fresh()->mobileStatus())->toBe($expected);
    }
});

it('el scope SQL coincide con el estado calculado en PHP', function () {
    $set = mobileStatusSet();

    foreach (array_keys($set) as $status) {
        expect(Employee::query()->mobileStatus($status)->pluck('id')->all())->toBe([$set[$status]->id]);
    }
});

it('quien hereda el permiso de la sucursal queda habilitado sin vincular', function () {
    $branch = mobileStatusEmployee()->branch;
    $branch->update(['mobile_marking_allowed' => true]);

    $heredero = mobileStatusEmployee(branch: $branch);
    $bloqueado = mobileStatusEmployee(['mobile_marking_allowed' => false], $branch);

    expect($heredero->fresh()->mobileStatus())->toBe('pending_link')
        ->and($bloqueado->fresh()->mobileStatus())->toBe('not_enabled')
        ->and(Employee::query()->mobileStatus('pending_link')->pluck('id')->all())->toContain($heredero->id)->not->toContain($bloqueado->id);
});

it('el límite de uso reciente es de siete días', function () {
    $ayer = mobileStatusEmployee(['mobile_marking_allowed' => true, 'mobile_linked_at' => now()->subMonth(), 'mobile_last_heartbeat_at' => now()->subDay()]);
    $viejo = mobileStatusEmployee(['mobile_marking_allowed' => true, 'mobile_linked_at' => now()->subMonth(), 'mobile_last_heartbeat_at' => now()->subDays(Employee::MOBILE_INACTIVE_DAYS)->subMinute()]);

    expect($ayer->mobileStatus())->toBe('active')->and($viejo->mobileStatus())->toBe('inactive');
});

it('todos los estados tienen etiqueta y color', function () {
    expect(array_keys(Employee::getMobileStatusLabels()))->toBe(array_keys(Employee::getMobileStatusColors()))
        ->and(Employee::getMobileStatusLabels())->toHaveCount(5);
});

it('el listado filtra por el estado del celular', function () {
    mobileStatusAdmin();
    $set = mobileStatusSet();

    Livewire::test(ListEmployees::class)
        ->set('activeTab', 'active')
        ->filterTable('mobile_status', 'pending_link')
        ->assertCanSeeTableRecords([$set['pending_link']])
        ->assertCanNotSeeTableRecords([$set['not_enabled'], $set['active'], $set['inactive'], $set['never_connected']]);
});

it('el listado muestra la columna Celular con el estado', function () {
    mobileStatusAdmin();
    mobileStatusSet();

    Livewire::test(ListEmployees::class)
        ->set('activeTab', 'active')
        ->assertTableColumnExists('mobile_status')
        ->assertSee('Habilitado, sin vincular')
        ->assertSee('Sin uso reciente');
});

it('la ficha muestra el estado del celular', function () {
    mobileStatusAdmin();

    Livewire::test(ViewEmployee::class, ['record' => mobileStatusSet()['pending_link']->getKey()])
        ->assertSee('Estado del celular')
        ->assertSee('Habilitado, sin vincular');
});

it('la bandeja Por resolver cuenta a los activos habilitados sin vincular', function () {
    mobileStatusAdmin();
    mobileStatusEmployee(['mobile_marking_allowed' => true]);
    mobileStatusEmployee(['mobile_marking_allowed' => true]);
    mobileStatusEmployee(['mobile_marking_allowed' => true, 'status' => 'inactive']);
    mobileStatusEmployee(['mobile_marking_allowed' => true, 'mobile_linked_at' => now()]);
    mobileStatusEmployee();

    $section = collect(AttendanceInboxService::sections())->firstWhere('key', 'mobile_pending_link');

    expect($section['count'])->toBe(2)->and($section['url'])->toContain('mobile_status');
});
