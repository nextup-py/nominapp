<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Horario de vigilancia distinto en ciertos días (por ejemplo, el sábado hasta el mediodía) para sucursales y
     * terminales. Es una lista de `{day, from, to}`; los días que no figuran usan el horario general de siempre.
     */
    public function up(): void
    {
        foreach (['branches', 'terminals'] as $table) {
            if (! Schema::hasColumn($table, 'watch_day_hours')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->json('watch_day_hours')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['branches', 'terminals'] as $table) {
            if (Schema::hasColumn($table, 'watch_day_hours')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('watch_day_hours');
                });
            }
        }
    }
};
