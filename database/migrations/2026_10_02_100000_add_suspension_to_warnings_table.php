<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Agrega los campos de suspensión disciplinaria a las amonestaciones. */
    public function up(): void
    {
        if (! Schema::hasColumn('warnings', 'suspension_start_date')) {
            Schema::table('warnings', function (Blueprint $table) {
                $table->date('suspension_start_date')->nullable()->after('issued_at');
            });
        }

        if (! Schema::hasColumn('warnings', 'suspension_days')) {
            Schema::table('warnings', function (Blueprint $table) {
                $table->unsignedTinyInteger('suspension_days')->default(0)->after('suspension_start_date');
            });
        }

        if (! Schema::hasColumn('warnings', 'suspension_summary_done')) {
            Schema::table('warnings', function (Blueprint $table) {
                $table->boolean('suspension_summary_done')->default(false)->after('suspension_days');
            });
        }
    }

    public function down(): void
    {
        Schema::table('warnings', function (Blueprint $table) {
            $table->dropColumn(['suspension_start_date', 'suspension_days', 'suspension_summary_done']);
        });
    }
};
