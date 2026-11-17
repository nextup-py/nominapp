<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tipo de feriado: fijo (misma fecha cada año), móvil (cambia cada año) o día no laborable. */
    public function up(): void
    {
        if (! Schema::hasColumn('holidays', 'type')) {
            Schema::table('holidays', function (Blueprint $table) {
                $table->string('type', 20)->default('national_fixed');
            });

            DB::table('holidays')->where('name', 'like', '%Santo%')->update(['type' => 'national_moving']);
        }
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
