<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de cambios de estado de un terminal (vinculación, revocación, etc.).
 * No registra un evento por heartbeat: solo transiciones, para auditar quién
 * aprobó o revocó y cuándo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('terminal_events')) {
            return;
        }

        Schema::create('terminal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('payload')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['terminal_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_events');
    }
};
