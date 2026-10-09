<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca de RR.HH. para los días con más de un turno (varias entradas/salidas): las horas
     * por encima del horario se aceptan como jornada normal y no como horas extra.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('attendance_days', 'second_shift_regular')) {
            Schema::table('attendance_days', function (Blueprint $table) {
                $table->boolean('second_shift_regular')->default(false)->after('overtime_approved')
                    ->comment('Doble turno aceptado como jornada normal: no genera horas extra');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attendance_days', 'second_shift_regular')) {
            Schema::table('attendance_days', function (Blueprint $table) {
                $table->dropColumn('second_shift_regular');
            });
        }
    }
};
