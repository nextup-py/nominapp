<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargos que no marcan descanso (ej. repartidores en ruta): con `marks_breaks = false` no se ofrece «Inicio descanso»
 * aunque su horario tenga descanso, y las horas netas descuentan el descanso programado. Por defecto todos marcan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'marks_breaks')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->boolean('marks_breaks')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('positions', 'marks_breaks')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->dropColumn('marks_breaks');
            });
        }
    }
};
