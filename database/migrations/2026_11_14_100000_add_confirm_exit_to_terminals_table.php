<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite definir por terminal si se pide confirmar la salida y los descansos (`null` = hereda el valor de
     * Configuración General, `terminal_confirm_exit`). Así se puede probar sin confirmación en una sucursal sin
     * cambiar el comportamiento de todas.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('terminals', 'confirm_exit')) {
            Schema::table('terminals', function (Blueprint $table) {
                $table->boolean('confirm_exit')->nullable()->after('stale_after_minutes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('terminals', 'confirm_exit')) {
            Schema::table('terminals', function (Blueprint $table) {
                $table->dropColumn('confirm_exit');
            });
        }
    }
};
