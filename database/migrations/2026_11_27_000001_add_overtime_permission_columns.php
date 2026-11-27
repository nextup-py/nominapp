<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Permiso de horas extra por empleado, sucursal y empresa (null = hereda) y marca de extra no autorizada en la jornada. */
    public function up(): void
    {
        foreach (['employees', 'branches', 'companies'] as $table) {
            if (! Schema::hasColumn($table, 'overtime_allowed')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->boolean('overtime_allowed')->nullable();
                });
            }
        }

        if (! Schema::hasColumn('attendance_days', 'overtime_unauthorized')) {
            Schema::table('attendance_days', function (Blueprint $blueprint) {
                $blueprint->boolean('overtime_unauthorized')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['employees', 'branches', 'companies'] as $table) {
            if (Schema::hasColumn($table, 'overtime_allowed')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('overtime_allowed'));
            }
        }

        if (Schema::hasColumn('attendance_days', 'overtime_unauthorized')) {
            Schema::table('attendance_days', fn (Blueprint $blueprint) => $blueprint->dropColumn('overtime_unauthorized'));
        }
    }
};
