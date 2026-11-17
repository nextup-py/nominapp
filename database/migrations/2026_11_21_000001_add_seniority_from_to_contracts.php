<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Fecha desde la que se cuenta la antigüedad cuando el reingreso la acumula (null = rige el inicio del contrato). */
    public function up(): void
    {
        if (! Schema::hasColumn('contracts', 'seniority_from')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->date('seniority_from')->nullable()->after('start_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contracts', 'seniority_from')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropColumn('seniority_from');
            });
        }
    }
};
