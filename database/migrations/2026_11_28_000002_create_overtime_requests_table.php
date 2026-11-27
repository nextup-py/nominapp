<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('overtime_requests')) {
            Schema::create('overtime_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('date_from');
                $table->date('date_to');
                $table->time('time_from')->nullable();
                $table->time('time_to')->nullable();
                $table->decimal('max_hours', 4, 2);
                $table->string('reason', 60)->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('pending_hr')->index();
                $table->foreignId('boss_approved_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('boss_approved_at')->nullable();
                $table->foreignId('hr_approved_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('hr_approved_at')->nullable();
                $table->foreignId('rejected_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->index(['employee_id', 'date_from', 'date_to']);
            });
        }

        foreach (['employees', 'positions', 'branches', 'companies'] as $table) {
            if (! Schema::hasColumn($table, 'overtime_request_required')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->boolean('overtime_request_required')->nullable());
            }
        }

        if (! Schema::hasColumn('attendance_days', 'overtime_request_id')) {
            Schema::table('attendance_days', function (Blueprint $table) {
                $table->foreignId('overtime_request_id')->nullable()->constrained('overtime_requests')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attendance_days', 'overtime_request_id')) {
            Schema::table('attendance_days', function (Blueprint $table) {
                $table->dropConstrainedForeignId('overtime_request_id');
            });
        }
        foreach (['employees', 'positions', 'branches', 'companies'] as $table) {
            if (Schema::hasColumn($table, 'overtime_request_required')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('overtime_request_required'));
            }
        }
        Schema::dropIfExists('overtime_requests');
    }
};
