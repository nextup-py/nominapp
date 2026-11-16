<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de gestión del equipo que ningún navegador entrega: quién responde por él (nombre y teléfono) y cuándo se
 * compró y hasta cuándo tiene garantía. Número de serie, MAC y notas ya existían (`device_serial`, `device_mac`,
 * `device_notes`).
 */
return new class extends Migration
{
    /** Agrega las columnas, sin tocar las existentes. */
    public function up(): void
    {
        foreach ([
            'responsible_name' => fn (Blueprint $table) => $table->string('responsible_name', 100)->nullable()->after('device_notes'),
            'responsible_phone' => fn (Blueprint $table) => $table->string('responsible_phone', 20)->nullable()->after('responsible_name'),
            'purchased_at' => fn (Blueprint $table) => $table->date('purchased_at')->nullable()->after('responsible_phone'),
            'warranty_until' => fn (Blueprint $table) => $table->date('warranty_until')->nullable()->after('purchased_at'),
        ] as $column => $definition) {
            if (! Schema::hasColumn('terminals', $column)) {
                Schema::table('terminals', $definition);
            }
        }
    }

    /** Quita las columnas agregadas. */
    public function down(): void
    {
        foreach (['warranty_until', 'purchased_at', 'responsible_phone', 'responsible_name'] as $column) {
            if (Schema::hasColumn('terminals', $column)) {
                Schema::table('terminals', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
