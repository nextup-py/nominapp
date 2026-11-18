<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Departamentos: responsable. Cargos: descripción, rango salarial, plazas autorizadas, jefatura,
     * permiso de horas extra (null = heredar) y horario por defecto.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('departments', 'manager_id')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            });
        }

        Schema::table('positions', function (Blueprint $table) {
            if (! Schema::hasColumn('positions', 'description')) {
                $table->text('description')->nullable();
            }
            if (! Schema::hasColumn('positions', 'salary_min')) {
                $table->decimal('salary_min', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('positions', 'salary_max')) {
                $table->decimal('salary_max', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('positions', 'headcount')) {
                $table->unsignedSmallInteger('headcount')->nullable();
            }
            if (! Schema::hasColumn('positions', 'is_managerial')) {
                $table->boolean('is_managerial')->default(false);
            }
            if (! Schema::hasColumn('positions', 'overtime_allowed')) {
                $table->boolean('overtime_allowed')->nullable();
            }
            if (! Schema::hasColumn('positions', 'default_schedule_id')) {
                $table->foreignId('default_schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('positions', 'default_schedule_id')) {
            Schema::table('positions', fn (Blueprint $table) => $table->dropConstrainedForeignId('default_schedule_id'));
        }

        Schema::table('positions', function (Blueprint $table) {
            foreach (['description', 'salary_min', 'salary_max', 'headcount', 'is_managerial', 'overtime_allowed'] as $column) {
                if (Schema::hasColumn('positions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('departments', 'manager_id')) {
            Schema::table('departments', fn (Blueprint $table) => $table->dropConstrainedForeignId('manager_id'));
        }
    }
};
