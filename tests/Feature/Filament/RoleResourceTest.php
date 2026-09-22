<?php

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Models\User;
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

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
});

it('lists existing roles for Super Admin', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListRoles::class)
        ->assertCanSeeTableRecords(Role::all());
});

it('blocks a user without Super Admin from viewing the roles list', function () {
    $rrhh = User::factory()->create();
    $rrhh->assignRole('RRHH');
    $this->actingAs($rrhh);

    $this->get(RoleResource::getUrl('index'))->assertForbidden();
});

it('creates a role with the selected permissions grouped by module', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateRole::class)
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

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->assertFormSet(['group_empleados' => fn ($state) => in_array('update_employee', $state, true)]);
});

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
