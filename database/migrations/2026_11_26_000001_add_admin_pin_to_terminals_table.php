<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** PIN de administrador del terminal: solo se guarda derivado (PBKDF2) con su sal, nunca el PIN. */
    public function up(): void
    {
        if (Schema::hasColumn('terminals', 'admin_pin_hash')) {
            return;
        }

        Schema::table('terminals', function (Blueprint $table) {
            $table->string('admin_pin_salt', 64)->nullable();
            $table->string('admin_pin_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->dropColumn(['admin_pin_salt', 'admin_pin_hash']);
        });
    }
};
