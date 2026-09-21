<?php

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

// ─── Helpers ────────────────────────────────────────────────────────────────

function makeMobileLinkedEmployee(): Employee
{
    static $n = 8600000;
    $n++;

    $company = Company::create(['name' => "Empresa Revoc {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);
    $branch = Branch::create(['name' => "Sucursal Revoc {$n}", 'company_id' => $company->id]);

    $employee = Employee::create([
        'first_name' => 'Revoc',
        'last_name' => 'Test',
        'ci' => (string) $n,
        'birth_date' => '1990-05-15',
        'email' => "revoc{$n}@test.com",
        'branch_id' => $branch->id,
        'status' => 'active',
        'face_descriptor' => array_fill(0, 128, 0.1),
        'mobile_linked_at' => now()->subDays(3),
        'mobile_last_heartbeat_at' => now()->subHours(2),
    ]);

    return $employee->fresh();
}

// ─── Tests ──────────────────────────────────────────────────────────────────

/**
 * El modal de "Revocar sesión móvil" debe mostrar cuándo se vinculó el
 * dispositivo y cuándo sincronizó por última vez, para que el admin no revoque
 * a ciegas — antes solo describía el efecto de la acción, sin contexto del
 * dispositivo en sí.
 */
it('el modal de revocar sesión móvil muestra la fecha de vinculación y el último sync', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = makeMobileLinkedEmployee();

    $test = Livewire::test(ListEmployees::class)
        ->mountTableAction('revoke_mobile_session', $employee);

    $description = $test->instance()->getMountedTableAction()->getModalDescription();

    expect($description)
        ->toContain($employee->mobile_linked_at->format('d/m/Y H:i'))
        ->toContain('Último sync');
});

it('el modal de revocar sesión móvil indica "nunca sincronizó" si no hubo heartbeat', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = makeMobileLinkedEmployee();
    $employee->update(['mobile_last_heartbeat_at' => null]);

    $test = Livewire::test(ListEmployees::class)
        ->mountTableAction('revoke_mobile_session', $employee);

    $description = $test->instance()->getMountedTableAction()->getModalDescription();

    expect($description)->toContain('nunca sincronizó');
});

it('revocar la sesión móvil desde el modal limpia mobile_linked_at y borra el token', function () {
    $this->actingAs(tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']))));
    $employee = makeMobileLinkedEmployee();
    $employee->createToken('mobile:'.$employee->id, [Employee::MOBILE_SYNC_ABILITY]);

    Livewire::test(ListEmployees::class)
        ->mountTableAction('revoke_mobile_session', $employee)
        ->callMountedTableAction();

    $fresh = $employee->fresh();
    expect($fresh->mobile_linked_at)->toBeNull()
        ->and($fresh->tokens()->count())->toBe(0);
});
