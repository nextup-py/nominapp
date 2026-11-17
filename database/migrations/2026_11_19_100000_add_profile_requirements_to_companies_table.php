<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Requisitos de ficha completa por empresa (null = los de fábrica). */
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'profile_requirements')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->json('profile_requirements')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('profile_requirements');
        });
    }
};
