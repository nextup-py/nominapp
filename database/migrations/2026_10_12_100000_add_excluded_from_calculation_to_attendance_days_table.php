<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jornadas que RR.HH. excluye del cálculo (marcaciones de prueba o de instalación): se
     * conservan las marcaciones, pero no generan tardanza, extras, pago ni pendientes.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('attendance_days', 'excluded_from_calculation')) {
            Schema::table('attendance_days', function (Blueprint $table) {
                $table->boolean('excluded_from_calculation')->default(false)->after('second_shift_regular')
                    ->comment('Jornada excluida del cálculo (prueba/instalación)');
                $table->string('exclusion_reason')->nullable()->after('excluded_from_calculation');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attendance_days', 'excluded_from_calculation')) {
            Schema::table('attendance_days', function (Blueprint $table) {
                $table->dropColumn(['excluded_from_calculation', 'exclusion_reason']);
            });
        }
    }
};
