<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Descripción del rol, para saber qué hace sin abrir sus permisos. */
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'description')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->text('description')->nullable()->after('guard_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'description')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
