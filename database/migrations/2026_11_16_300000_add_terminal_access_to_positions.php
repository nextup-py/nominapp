<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcación en terminales de otras sucursales por cargo: `own` (solo la sucursal propia), `all` (cualquier terminal)
 * o `selected` (la propia más las sucursales de la tabla pivote).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'terminal_access')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->string('terminal_access', 10)->default('own');
            });
        }

        if (! Schema::hasTable('position_terminal_branch')) {
            Schema::create('position_terminal_branch', function (Blueprint $table) {
                $table->foreignId('position_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $table->primary(['position_id', 'branch_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('position_terminal_branch');

        if (Schema::hasColumn('positions', 'terminal_access')) {
            Schema::table('positions', fn (Blueprint $table) => $table->dropColumn('terminal_access'));
        }
    }
};
