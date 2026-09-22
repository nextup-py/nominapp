<?php

use App\Filament\Resources\DisbursementBatchResource\Pages\ViewDisbursementBatch;
use App\Models\Company;
use App\Models\DisbursementBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Crea un lote de pago pendiente, listo para confirmar/cancelar. */
function makeBizBatch(string $status = 'pending'): DisbursementBatch
{
    static $ci = 7000000;
    $n = $ci++;

    $company = Company::create(['name' => "EmpBiz {$n}", 'ruc' => "{$n}-1", 'employer_number' => $n]);

    $admin = User::firstOrCreate(
        ['email' => 'batchperm_admin@test.com'],
        ['name' => 'Batch Perm Admin', 'password' => bcrypt('password')],
    );

    return DisbursementBatch::create([
        'type' => 'loan',
        'company_id' => $company->id,
        'fecha_credito' => today()->addDays(3),
        'status' => $status,
        'created_by_id' => $admin->id,
    ]);
}

/**
 * Crea un usuario con un Role de prueba que tiene los permisos CRUD de
 * DisbursementBatch (necesarios para acceder al recurso) más los permisos
 * de negocio indicados.
 */
function actingAsBizUser(array $permissions): User
{
    static $roleN = 0;
    $roleN++;

    $allPermissions = array_merge(['view_any_disbursement_batch', 'view_disbursement_batch'], $permissions);

    foreach ($allPermissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::create(['name' => "Test Role Batch {$roleN}", 'guard_name' => 'web']);
    $role->syncPermissions($allPermissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('respeta el permiso confirm_disbursement_batch en la acción Confirmar Lote', function () {
    $batch = makeBizBatch('pending');

    $this->actingAs(actingAsBizUser([]));
    Livewire::test(ViewDisbursementBatch::class, ['record' => $batch->getRouteKey()])
        ->assertActionHidden('confirm_batch');

    $this->actingAs(actingAsBizUser(['confirm_disbursement_batch']));
    Livewire::test(ViewDisbursementBatch::class, ['record' => $batch->getRouteKey()])
        ->assertActionVisible('confirm_batch');
});

it('respeta el permiso cancel_disbursement_batch en la acción Cancelar Lote', function () {
    $batch = makeBizBatch('pending');

    $this->actingAs(actingAsBizUser([]));
    Livewire::test(ViewDisbursementBatch::class, ['record' => $batch->getRouteKey()])
        ->assertActionHidden('cancel_batch');

    $this->actingAs(actingAsBizUser(['cancel_disbursement_batch']));
    Livewire::test(ViewDisbursementBatch::class, ['record' => $batch->getRouteKey()])
        ->assertActionVisible('cancel_batch');
});
