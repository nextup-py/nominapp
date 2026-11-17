<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tipo y motivo de la baja registrados en el contrato que se termina. */
    public function up(): void
    {
        foreach (['termination_type' => fn (Blueprint $t) => $t->string('termination_type', 40)->nullable(), 'termination_reason' => fn (Blueprint $t) => $t->text('termination_reason')->nullable()] as $column => $definition) {
            if (! Schema::hasColumn('contracts', $column)) {
                Schema::table('contracts', fn (Blueprint $table) => $definition($table));
            }
        }
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['termination_type', 'termination_reason']);
        });
    }
};
