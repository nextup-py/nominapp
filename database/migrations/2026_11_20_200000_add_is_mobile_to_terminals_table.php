<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Marca los terminales que son un equipo móvil (celular o tablet): esos verifican su ubicación contra la de la sucursal. */
    public function up(): void
    {
        if (! Schema::hasColumn('terminals', 'is_mobile')) {
            Schema::table('terminals', function (Blueprint $table) {
                $table->boolean('is_mobile')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            $table->dropColumn('is_mobile');
        });
    }
};
