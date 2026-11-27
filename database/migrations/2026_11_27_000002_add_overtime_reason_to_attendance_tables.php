<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Motivo de las horas extra que el empleado elige al marcar la salida (clave de `AttendanceEvent::OVERTIME_REASONS`). */
    public function up(): void
    {
        foreach (['attendance_events', 'attendance_days'] as $table) {
            if (! Schema::hasColumn($table, 'overtime_reason')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->string('overtime_reason', 32)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['attendance_events', 'attendance_days'] as $table) {
            if (Schema::hasColumn($table, 'overtime_reason')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('overtime_reason'));
            }
        }
    }
};
