<?php

use App\Models\User;
use Database\Seeders\BusinessActionPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new PermissionSeeder)->run();
    (new BusinessActionPermissionSeeder)->run();
});

it('creates the four base roles', function () {
    (new RoleSeeder)->run();

    expect(Role::pluck('name')->all())->toEqualCanonicalizing([
        'Super Admin', 'RRHH', 'Contador/Nómina', 'Solo Lectura',
    ]);
});

it('gives RRHH crud permissions on employee but not on payroll', function () {
    (new RoleSeeder)->run();

    $rrhh = Role::findByName('RRHH');

    expect($rrhh->hasPermissionTo('update_employee'))->toBeTrue();
    expect($rrhh->hasPermissionTo('update_payroll'))->toBeFalse();
});

it('gives Contador/Nómina full crud on payroll but only view on employee', function () {
    (new RoleSeeder)->run();

    $contador = Role::findByName('Contador/Nómina');

    expect($contador->hasPermissionTo('update_payroll'))->toBeTrue();
    expect($contador->hasPermissionTo('view_employee'))->toBeTrue();
    expect($contador->hasPermissionTo('update_employee'))->toBeFalse();
});

it('gives Solo Lectura only view permissions across every model', function () {
    (new RoleSeeder)->run();

    $readOnly = Role::findByName('Solo Lectura');

    foreach (PermissionSeeder::MODELS as $model) {
        expect($readOnly->hasPermissionTo("view_any_{$model}"))->toBeTrue();
        expect($readOnly->hasPermissionTo("create_{$model}"))->toBeFalse();
    }
});

it('assigns Super Admin to existing users without any role', function () {
    $user = User::factory()->create();

    (new RoleSeeder)->run();

    expect($user->fresh()->hasRole('Super Admin'))->toBeTrue();
});

it('does not touch a user that already has a role', function () {
    (new RoleSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('RRHH');

    (new RoleSeeder)->run();

    expect($user->fresh()->hasRole('Super Admin'))->toBeFalse();
    expect($user->fresh()->hasRole('RRHH'))->toBeTrue();
});

it('is idempotent on the permission matrix', function () {
    (new RoleSeeder)->run();
    (new RoleSeeder)->run();

    expect(Role::count())->toBe(4);
});

it('gives RRHH the business-action permissions for employee leave, absences and contracts', function () {
    (new RoleSeeder)->run();

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
    (new RoleSeeder)->run();

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
    (new RoleSeeder)->run();

    $readOnly = Role::findByName('Solo Lectura');

    foreach (BusinessActionPermissionSeeder::ACTIONS as $model => $actions) {
        foreach (array_keys($actions) as $action) {
            expect($readOnly->hasPermissionTo("{$action}_{$model}"))->toBeFalse();
        }
    }
});
