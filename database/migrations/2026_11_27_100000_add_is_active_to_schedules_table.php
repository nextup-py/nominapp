<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado activo/inactivo de los horarios. Un horario con historial de asignaciones no se puede eliminar (borrarlo
 * arrastraría ese historial en cascada), así que se desactiva: deja de ofrecerse al asignar pero conserva todo.
 */
return new class extends Migration
{
    /** Agrega `schedules.is_active` (por defecto activo). */
    public function up(): void
    {
        if (! Schema::hasColumn('schedules', 'is_active')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('shift_type');
            });
        }
    }

    /** Quita la columna. */
    public function down(): void
    {
        if (Schema::hasColumn('schedules', 'is_active')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
