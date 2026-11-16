<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Permiso de marcación desde el celular por cargo (null = hereda de la sucursal, la empresa o el valor general). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'mobile_marking_allowed')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->boolean('mobile_marking_allowed')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('positions', 'mobile_marking_allowed')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->dropColumn('mobile_marking_allowed');
            });
        }
    }
};
