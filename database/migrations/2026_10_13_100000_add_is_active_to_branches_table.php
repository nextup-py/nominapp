<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Agrega el estado activa/inactiva a las sucursales (todas las existentes quedan activas). */
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'is_active')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('coordinates');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('branches', 'is_active')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
