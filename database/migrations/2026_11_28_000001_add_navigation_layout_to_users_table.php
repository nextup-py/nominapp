<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Formato de menú del panel que eligió cada usuario (barra lateral por defecto). */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'navigation_layout')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('navigation_layout', 20)->default('sidebar');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'navigation_layout')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('navigation_layout');
            });
        }
    }
};
