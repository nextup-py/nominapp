<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Agrega la fecha en que RR.HH. descartó (sin pago ni descuento) las horas extra o la tardanza pendientes de una jornada. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_days', 'overtime_dismissed_at')) {
                $table->timestamp('overtime_dismissed_at')->nullable();
            }

            if (! Schema::hasColumn('attendance_days', 'tardiness_dismissed_at')) {
                $table->timestamp('tardiness_dismissed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn(['overtime_dismissed_at', 'tardiness_dismissed_at']);
        });
    }
};
