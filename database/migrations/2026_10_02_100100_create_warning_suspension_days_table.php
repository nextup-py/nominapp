<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Crea la tabla con los días laborables efectivamente suspendidos por amonestación. */
    public function up(): void
    {
        if (Schema::hasTable('warning_suspension_days')) {
            return;
        }

        Schema::create('warning_suspension_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warning_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('employee_deduction_id')->nullable()->constrained('employee_deductions')->nullOnDelete();
            $table->timestamps();

            $table->unique(['warning_id', 'date']);
            $table->index(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warning_suspension_days');
    }
};
