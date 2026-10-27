<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de consentimiento: quién aceptó el aviso de privacidad de su empresa, cuándo, desde dónde
     * y qué versión (con una copia del texto, porque la empresa solo conserva la vigente).
     */
    public function up(): void
    {
        if (Schema::hasTable('privacy_consents')) {
            return;
        }

        Schema::create('privacy_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('context', 30);
            $table->unsignedInteger('notice_version');
            $table->text('notice_text')->nullable();
            $table->string('notice_url', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->index(['employee_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_consents');
    }
};
