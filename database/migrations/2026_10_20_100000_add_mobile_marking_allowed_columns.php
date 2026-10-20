<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permiso de marcación desde el celular personal, en tres niveles (null = heredar del siguiente).
     * Los empleados que ya tienen un celular vinculado quedan en «Sí» explícito para que nadie
     * pierda el acceso al desplegar; RR.HH. restringe después.
     */
    public function up(): void
    {
        foreach (['companies', 'branches', 'employees'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'mobile_marking_allowed')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->boolean('mobile_marking_allowed')->nullable();
                });
            }
        }

        DB::table('employees')->whereNotNull('mobile_linked_at')->update(['mobile_marking_allowed' => true]);
    }

    public function down(): void
    {
        foreach (['companies', 'branches', 'employees'] as $tableName) {
            if (Schema::hasColumn($tableName, 'mobile_marking_allowed')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('mobile_marking_allowed');
                });
            }
        }
    }
};
