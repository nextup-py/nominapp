<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renovaciones previas declaradas de un contrato a plazo fijo (Art. 53 CLT). Complementa el conteo por contratos
     * «Renovado»: sirve cuando el contrato anterior se creó a mano y el sistema no sabe que era una renovación.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'renewals_count')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->unsignedTinyInteger('renewals_count')->default(0)->after('trial_days');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contracts', 'renewals_count')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropColumn('renewals_count');
            });
        }
    }
};
