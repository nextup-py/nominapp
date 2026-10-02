<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Índice en created_at para que la purga por antigüedad no escanee toda la tabla. */
    public function up(): void
    {
        try {
            Schema::table('audits', function (Blueprint $table) {
                $table->index('created_at', 'audits_created_at_index');
            });
        } catch (Throwable $e) {
            // El índice ya existe por un deploy parcial anterior — continuar
        }
    }

    public function down(): void
    {
        try {
            Schema::table('audits', function (Blueprint $table) {
                $table->dropIndex('audits_created_at_index');
            });
        } catch (Throwable $e) {
            // El índice ya fue eliminado — continuar
        }
    }
};
