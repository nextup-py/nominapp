<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de vinculación de terminales por código de emparejamiento: el
 * dispositivo muestra un código corto, un admin con permiso lo aprueba desde
 * el panel y recién entonces el servidor emite el token Sanctum. El secreto de
 * polling del dispositivo se guarda solo como hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('terminal_pairing_requests')) {
            return;
        }

        Schema::create('terminal_pairing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
            $table->string('code', 6)->comment('Código corto que el admin verifica contra la pantalla del terminal');
            $table->char('poll_secret_hash', 64)->unique()->comment('sha256 del secreto con el que el dispositivo consulta el estado');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('device_model_hint', 100)->nullable();
            $table->string('status', 20)->default('pending')->comment('pending|approved|denied|expired|claimed');
            $table->boolean('auto_approved')->default(false);
            $table->boolean('replaces_active_device')->default(false);
            $table->timestamp('expires_at');
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['terminal_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_pairing_requests');
    }
};
