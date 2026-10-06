<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fecha e IP de la última vinculación del dispositivo (token Sanctum emitido). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('terminals', 'linked_at')) {
            Schema::table('terminals', function (Blueprint $table) {
                $table->timestamp('linked_at')->nullable();
            });
        }

        if (! Schema::hasColumn('terminals', 'linked_ip')) {
            Schema::table('terminals', function (Blueprint $table) {
                $table->string('linked_ip', 45)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['linked_ip', 'linked_at'] as $column) {
            if (Schema::hasColumn('terminals', $column)) {
                Schema::table('terminals', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
