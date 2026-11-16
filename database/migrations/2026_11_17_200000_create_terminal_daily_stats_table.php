<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resumen diario de confiabilidad por terminal: minutos esperados y en línea, cortes e identificaciones.
 * Una fila por terminal y día, alimentada por `terminals:evaluate-health` (cada minuto) y por el latido del dispositivo.
 * Las marcaciones y los conflictos no se duplican acá: se cuentan al consultar desde `attendance_events` y
 * `attendance_mark_failures`.
 */
return new class extends Migration
{
    /** Crea la tabla, sin tocar nada existente. */
    public function up(): void
    {
        if (Schema::hasTable('terminal_daily_stats')) {
            return;
        }

        Schema::create('terminal_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('expected_minutes')->default(0);
            $table->unsignedInteger('online_minutes')->default(0);
            $table->unsignedInteger('outages_count')->default(0);
            $table->unsignedInteger('outage_minutes')->default(0);
            $table->unsignedInteger('longest_outage_minutes')->default(0);
            $table->unsignedInteger('identification_attempts')->default(0);
            $table->unsignedInteger('identification_failures')->default(0);
            $table->unsignedInteger('identification_successes')->default(0);
            $table->unsignedBigInteger('identification_ms_total')->default(0);
            $table->timestamps();

            $table->unique(['terminal_id', 'date']);
            $table->index('date');
        });
    }

    /** Elimina la tabla. */
    public function down(): void
    {
        Schema::dropIfExists('terminal_daily_stats');
    }
};
