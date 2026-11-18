<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Agrega el estado activo/inactivo a departamentos y cargos (todos los existentes quedan activos). */
    public function up(): void
    {
        foreach (['departments', 'positions'] as $table) {
            if (! Schema::hasColumn($table, 'is_active')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->boolean('is_active')->default(true);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['departments', 'positions'] as $table) {
            if (Schema::hasColumn($table, 'is_active')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('is_active');
                });
            }
        }
    }
};
