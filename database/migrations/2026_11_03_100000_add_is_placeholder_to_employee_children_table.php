<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega el marcador `is_placeholder` y permite `birth_date` nulo para los hijos
     * de relleno, que no deben generar bonificación familiar hasta que RR.HH. cargue
     * los datos reales. Convierte en placeholders los que ya creó el comando anterior
     * (nombre «Hijo/a…», nacimiento 2015-01-01 y sin CI).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('employee_children', 'is_placeholder')) {
            Schema::table('employee_children', function (Blueprint $table) {
                $table->boolean('is_placeholder')->default(false)->after('birth_certificate_path');
            });
        }

        Schema::table('employee_children', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->change();
        });

        DB::table('employee_children')
            ->where('first_name', 'like', 'Hijo/a%')
            ->whereDate('birth_date', '2015-01-01')
            ->whereNull('ci')
            ->whereNull('birth_certificate_path')
            ->update(['is_placeholder' => true, 'birth_date' => null]);
    }

    public function down(): void
    {
        DB::table('employee_children')->whereNull('birth_date')->update(['birth_date' => '2015-01-01']);

        Schema::table('employee_children', function (Blueprint $table) {
            $table->date('birth_date')->nullable(false)->change();
        });

        if (Schema::hasColumn('employee_children', 'is_placeholder')) {
            Schema::table('employee_children', function (Blueprint $table) {
                $table->dropColumn('is_placeholder');
            });
        }
    }
};
