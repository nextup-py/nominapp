<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Permisos de pedidos de horas extra y su asignación a RR.HH. (el deploy no siembra permisos). En tests los siembran los seeders. */
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $names = [];
        foreach (['view_any', 'view', 'create', 'update', 'delete'] as $ability) {
            $names[] = "{$ability}_overtime_request";
        }
        array_push($names, 'approve_overtime_request', 'reject_overtime_request', 'cancel_overtime_request');

        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::where('name', 'RRHH')->where('guard_name', 'web')->first()?->givePermissionTo($names);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'like', '%_overtime_request')->delete();
    }
};
