<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 de la gestión de terminales — monitoreo:
 * - Umbral de desconexión y horario de vigilancia por terminal (todos opcionales:
 *   sin valor se usa el umbral global y se vigila 24/7).
 * - Último reporte de estado del dispositivo enviado en el heartbeat.
 * - Estado ya evaluado (`health_snapshot`) para avisar solo en las transiciones, y
 *   desde cuándo la cola de marcaciones no está vacía (`queue_backlog_since`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            if (! Schema::hasColumn('terminals', 'stale_after_minutes')) {
                $table->unsignedSmallInteger('stale_after_minutes')->nullable()->after('last_conflict_events_count')
                    ->comment('Minutos sin heartbeat para considerarlo desconectado; null = umbral global');
            }
            if (! Schema::hasColumn('terminals', 'watch_days')) {
                $table->json('watch_days')->nullable()->after('stale_after_minutes')
                    ->comment('Días ISO (1=lunes..7=domingo) en los que se vigila; null = todos');
            }
            if (! Schema::hasColumn('terminals', 'watch_from')) {
                $table->time('watch_from')->nullable()->after('watch_days');
            }
            if (! Schema::hasColumn('terminals', 'watch_to')) {
                $table->time('watch_to')->nullable()->after('watch_from');
            }
            if (! Schema::hasColumn('terminals', 'device_report')) {
                $table->json('device_report')->nullable()->after('watch_to')
                    ->comment('Último reporte de estado del dispositivo (heartbeat)');
            }
            if (! Schema::hasColumn('terminals', 'device_report_at')) {
                $table->timestamp('device_report_at')->nullable()->after('device_report');
            }
            if (! Schema::hasColumn('terminals', 'queue_backlog_since')) {
                $table->timestamp('queue_backlog_since')->nullable()->after('device_report_at')
                    ->comment('Desde cuándo la cola offline no está vacía (pendientes o conflictos)');
            }
            if (! Schema::hasColumn('terminals', 'health_snapshot')) {
                $table->json('health_snapshot')->nullable()->after('queue_backlog_since')
                    ->comment('Último estado evaluado por terminals:evaluate-health, para avisar solo en transiciones');
            }
        });
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(
                ['health_snapshot', 'queue_backlog_since', 'device_report_at', 'device_report', 'watch_to', 'watch_from', 'watch_days', 'stale_after_minutes'],
                fn (string $column) => Schema::hasColumn('terminals', $column),
            )));
        });
    }
};
