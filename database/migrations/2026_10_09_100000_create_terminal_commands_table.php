<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comandos remotos para terminales (forzar sincronización, recargar, limpiar caché,
 * reenviar reporte). Se entregan en la respuesta del heartbeat y se confirman en el siguiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('terminal_commands')) {
            return;
        }

        Schema::create('terminal_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
            $table->string('command', 30);
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('result_message', 255)->nullable();
            $table->timestamps();

            $table->index(['terminal_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_commands');
    }
};
