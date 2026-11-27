<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('overtime_requests', 'requested_by_employee_id')) {
                $table->foreignId('requested_by_employee_id')->nullable()->after('requested_by_id')->constrained('employees')->nullOnDelete();
            }
            if (! Schema::hasColumn('overtime_requests', 'regularization')) {
                $table->boolean('regularization')->default(false)->after('notes');
            }
            if (! Schema::hasColumn('overtime_requests', 'auto_approved')) {
                $table->boolean('auto_approved')->default(false)->after('hr_approved_at');
            }
            if (! Schema::hasColumn('overtime_requests', 'escalated_at')) {
                $table->timestamp('escalated_at')->nullable()->after('auto_approved');
            }
        });
    }

    public function down(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            if (Schema::hasColumn('overtime_requests', 'requested_by_employee_id')) {
                $table->dropConstrainedForeignId('requested_by_employee_id');
            }
            foreach (['regularization', 'auto_approved', 'escalated_at'] as $column) {
                if (Schema::hasColumn('overtime_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
