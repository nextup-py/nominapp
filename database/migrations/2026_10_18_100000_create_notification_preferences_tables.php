<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preferencias de notificaciones por usuario: qué avisos recibe, por qué canal y con qué
     * frecuencia (al instante o en un resumen diario), más la cola del resumen y la hora elegida.
     */
    public function up(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('key', 60);
                $table->boolean('enabled')->default(true);
                $table->json('channels');
                $table->string('frequency', 10)->default('instant');
                $table->timestamps();

                $table->unique(['user_id', 'key']);
            });
        }

        if (! Schema::hasTable('notification_digest_items')) {
            Schema::create('notification_digest_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('key', 60);
                $table->json('channels');
                $table->string('title');
                $table->text('body')->nullable();
                $table->string('url', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasColumn('users', 'notification_digest_hour')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedTinyInteger('notification_digest_hour')->default(8);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'notification_digest_hour')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('notification_digest_hour');
            });
        }

        Schema::dropIfExists('notification_digest_items');
        Schema::dropIfExists('notification_preferences');
    }
};
