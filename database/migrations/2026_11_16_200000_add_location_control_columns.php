<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control de ubicación de la marcación desde el celular: punto y radio por sucursal o empleado, y el resultado
 * (distancia, señalamiento y revisión de RR.HH.) en cada marcación.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'location_control')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->boolean('location_control')->default(false);
                $table->unsignedSmallInteger('location_radius_m')->nullable();
            });
        }

        if (! Schema::hasColumn('employees', 'work_location')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->json('work_location')->nullable();
                $table->unsignedSmallInteger('location_radius_m')->nullable();
            });
        }

        if (! Schema::hasColumn('attendance_events', 'location_flag')) {
            Schema::table('attendance_events', function (Blueprint $table) {
                $table->unsignedInteger('location_distance_m')->nullable();
                $table->string('location_flag', 20)->nullable();
                $table->string('location_review', 12)->nullable()->index();
                $table->foreignId('location_reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('location_reviewed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attendance_events', 'location_flag')) {
            Schema::table('attendance_events', function (Blueprint $table) {
                $table->dropConstrainedForeignId('location_reviewed_by_id');
                $table->dropColumn(['location_distance_m', 'location_flag', 'location_review', 'location_reviewed_at']);
            });
        }
        if (Schema::hasColumn('employees', 'work_location')) {
            Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['work_location', 'location_radius_m']));
        }
        if (Schema::hasColumn('branches', 'location_control')) {
            Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['location_control', 'location_radius_m']));
        }
    }
};
