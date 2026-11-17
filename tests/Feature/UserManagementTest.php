<?php

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ManageUsers;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\UserAccountService;
use Filament\Facades\Filament;
use Filament\Notifications\Auth\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function superAdmin(string $email = 'root@test.com'): User
{
    $user = User::factory()->create(['email' => $email]);
    $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

    return $user;
}

function roleUser(string $email, string $role = 'RRHH'): User
{
    $user = User::factory()->create(['email' => $email]);
    $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

    return $user;
}

it('un usuario inactivo no puede entrar al panel', function () {
    $user = roleUser('uno@test.com');
    expect($user->canAccessPanel(Filament::getCurrentPanel()))->toBeTrue();

    $user->update(['is_active' => false]);

    expect($user->canAccessPanel(Filament::getCurrentPanel()))->toBeFalse();
});

it('desactivar cierra las sesiones del usuario', function () {
    config(['session.driver' => 'database']);
    $admin = superAdmin();
    $user = roleUser('dos@test.com');
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);

    expect(app(UserAccountService::class)->deactivate($user, $admin))->toBeNull()
        ->and($user->fresh()->is_active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('no deja desactivarse a uno mismo ni al último Super Admin activo', function () {
    $root = superAdmin();
    $service = app(UserAccountService::class);

    expect($service->deactivate($root, $root))->toMatch('/propio usuario/');

    $other = roleUser('tres@test.com');
    expect($service->deactivate($root, $other))->toMatch('/último Super Admin/');

    $second = superAdmin('segundo@test.com');
    expect($service->deactivate($root, $second))->toBeNull();
});

it('invitar crea el usuario con rol y envía el enlace de contraseña', function () {
    Notification::fake();
    $role = Role::firstOrCreate(['name' => 'RRHH', 'guard_name' => 'web']);

    $result = app(UserAccountService::class)->invite(['name' => 'Ana Gómez', 'email' => 'ana@test.com', 'roles' => [$role->id]]);

    expect($result['sent'])->toBeTrue()
        ->and($result['user']->hasRole('RRHH'))->toBeTrue();
    Notification::assertSentTo($result['user'], ResetPassword::class);
});

it('el listado muestra pestañas con sus conteos y el último acceso', function () {
    $admin = superAdmin();
    $active = roleUser('activo@test.com');
    $inactive = roleUser('inactivo@test.com');
    $inactive->update(['is_active' => false]);
    User::factory()->create(['email' => 'sinrol@test.com']);
    LoginHistory::create(['user_id' => $active->id, 'ip_address' => '9.9.9.9', 'logged_in_at' => now()->subHour()]);

    $component = Livewire::actingAs($admin)->test(ManageUsers::class);
    $tabs = $component->instance()->getTabs();

    expect($tabs['all']->getBadge())->toBe(4)
        ->and($tabs['inactive']->getBadge())->toBe(1)
        ->and($tabs['no_role']->getBadge())->toBe(1);

    $component->set('activeTab', 'inactive')
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$active]);

    expect(UserResource::getEloquentQuery()->find($active->id)->last_login_at)->not->toBeNull();
});

it('desactivar desde la tabla respeta la protección del último Super Admin', function () {
    $root = superAdmin();

    Livewire::actingAs($root)->test(ManageUsers::class)
        ->callTableAction('toggle_active', $root);

    expect($root->fresh()->is_active)->toBeTrue();
});

it('crea el usuario de un empleado desde su ficha y lo vincula', function () {
    Notification::fake();
    $company = Company::create(['name' => 'Emp UM', 'ruc' => '1-1', 'employer_number' => 6600001]);
    $branch = Branch::create(['name' => 'Suc UM', 'company_id' => $company->id]);
    $employee = Employee::create(['first_name' => 'Luis', 'last_name' => 'Pérez', 'ci' => '4000001', 'branch_id' => $branch->id, 'status' => 'active', 'email' => 'luis@test.com']);

    $result = app(UserAccountService::class)->invite(['name' => $employee->full_name, 'email' => 'luis@test.com', 'employee_id' => $employee->id]);

    expect(app(UserAccountService::class)->userFor($employee)?->is($result['user']))->toBeTrue()
        ->and($result['user']->employee_id)->toBe($employee->id);
});

it('la acción de invitar exige el permiso create_user', function () {
    $user = roleUser('sinperm@test.com');
    $this->actingAs($user);
    expect($user->can('create_user'))->toBeFalse();

    $user->givePermissionTo(Permission::firstOrCreate(['name' => 'create_user', 'guard_name' => 'web']));
    expect($user->fresh()->can('create_user'))->toBeTrue();
});
