<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** Crea los permisos de documentos y tipos de documento y se los da a RR.HH. (los permisos CRUD no los siembra el deploy). En tests los siembran los seeders. */
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $names = [];
        foreach (['document', 'document_type'] as $model) {
            foreach (['view_any', 'view', 'create', 'update', 'delete'] as $ability) {
                $names[] = "{$ability}_{$model}";
                Permission::firstOrCreate(['name' => "{$ability}_{$model}", 'guard_name' => 'web']);
            }
        }

        $rrhh = Role::where('name', 'RRHH')->where('guard_name', 'web')->first();
        $rrhh?->givePermissionTo($names);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_map(
            fn (string $a) => $a,
            collect(['document', 'document_type'])->flatMap(fn ($m) => collect(['view_any', 'view', 'create', 'update', 'delete'])->map(fn ($a) => "{$a}_{$m}"))->all()
        ))->delete();
    }
};
