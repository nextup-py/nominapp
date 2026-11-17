<?php

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Resources\RoleResource\Pages\ViewRole;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\SuggestedRoles;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();
    (new RoleSeeder)->run();

    app(GeneralSettings::class)->setup_completed = true;
    app(GeneralSettings::class)->save();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
    $this->actingAs($this->admin);
});

it('Super Admin no se puede renombrar ni eliminar', function () {
    $superAdmin = Role::findByName('Super Admin');

    expect(RoleResource::canEdit($superAdmin))->toBeFalse()
        ->and(RoleResource::canDelete($superAdmin))->toBeFalse();

    expect(fn () => $superAdmin->update(['name' => 'Otro']))->toThrow(DomainException::class);
    expect(fn () => $superAdmin->delete())->toThrow(DomainException::class);
    expect(Role::findByName('Super Admin'))->not->toBeNull();
});

it('un rol con usuarios no se puede eliminar y uno sin usuarios sí', function () {
    $used = Role::create(['name' => 'Con usuarios', 'guard_name' => 'web']);
    $free = Role::create(['name' => 'Sin usuarios', 'guard_name' => 'web']);
    User::factory()->create()->assignRole($used);

    expect(RoleResource::canDelete($used))->toBeFalse()
        ->and(RoleResource::canDelete($free))->toBeTrue()
        ->and(RoleResource::canEdit($used))->toBeTrue();
});

it('el listado muestra cuántos usuarios tiene cada rol y su descripción', function () {
    $role = Role::create(['name' => 'Rol Probado', 'guard_name' => 'web', 'description' => 'Hace cosas de prueba']);
    User::factory()->count(2)->create()->each->assignRole($role);

    Livewire::test(ListRoles::class)
        ->assertCanSeeTableRecords([$role])
        ->assertSee('Hace cosas de prueba')
        ->assertTableColumnExists('users_count');

    expect(Role::withCount('users')->find($role->id)->users_count)->toBe(2);
});

it('duplica un rol con sus permisos y su descripción bajo otro nombre', function () {
    $source = Role::findByName('RRHH');

    Livewire::test(ListRoles::class)
        ->callTableAction('duplicate', $source, ['name' => 'RRHH (copia)', 'description' => 'Copia de prueba'])
        ->assertHasNoTableActionErrors();

    $copy = Role::findByName('RRHH (copia)');

    expect($copy->description)->toBe('Copia de prueba')
        ->and($copy->permissions->pluck('name')->sort()->values()->all())->toBe($source->permissions->pluck('name')->sort()->values()->all());

    Livewire::test(ListRoles::class)
        ->callTableAction('duplicate', $source, ['name' => 'RRHH (copia)'])
        ->assertHasTableActionErrors(['name']);
});

it('crea los roles sugeridos que faltan sin tocar los existentes', function () {
    // El seeder ya los crea: se borran para simular una instalación anterior y se marca uno existente personalizado.
    Role::whereIn('name', ['Jefe de equipo', 'Supervisor de sucursal'])->delete();
    $custom = Role::findByName('Encargado de asistencia');
    $custom->update(['description' => 'Personalizado por el cliente']);
    $custom->syncPermissions(['view_any_attendance_day']);

    Livewire::test(ListRoles::class)
        ->callAction('create_suggested_roles')
        ->assertNotified();

    expect(Role::findByName('Jefe de equipo')->permissions->pluck('name')->all())->toBe(['view_team'])
        ->and(Role::findByName('Supervisor de sucursal')->permissions->pluck('name')->sort()->values()->all())->toBe(['view_branch_team', 'view_team'])
        ->and($custom->fresh()->description)->toBe('Personalizado por el cliente')
        ->and($custom->fresh()->permissions->pluck('name')->all())->toBe(['view_any_attendance_day'])
        ->and(SuggestedRoles::createMissing())->toBe([]);
});

it('el detalle muestra los permisos del rol por módulo y compara con otro rol', function () {
    $rrhh = Role::findByName('RRHH');
    $reader = Role::findByName('Solo Lectura');

    Livewire::test(ViewRole::class, ['record' => $rrhh->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Editar — Empleado')
        ->mountAction('compare')
        ->setActionData(['other_id' => $reader->id])
        ->assertSee('permisos en común');
});

it('el formulario de rol ofrece los permisos de «Mi equipo» y marcar todo por módulo', function () {
    $options = collect(RoleResource::permissionOptions())->collapse();

    expect($options)->toHaveKeys(['view_team', 'view_branch_team'])
        ->and($options['view_team'])->toBe('Ver su equipo — Mi Equipo');
});
