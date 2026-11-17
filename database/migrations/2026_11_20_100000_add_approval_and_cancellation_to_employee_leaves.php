<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quién aprobó la licencia y cuándo, el estado «Anulada» (con quién, cuándo y por qué) y la tabla de descuentos
     * por día de las licencias de día completo con descuento.
     */
    public function up(): void
    {
        Schema::table('employee_leaves', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_leaves', 'approved_by_id')) {
                $table->foreignId('approved_by_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable()->after('approved_by_id');
                $table->foreignId('cancelled_by_id')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by_id');
                $table->text('cancel_reason')->nullable()->after('cancelled_at');
            }
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE employee_leaves MODIFY status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending'");
        }

        if (! Schema::hasTable('employee_leave_deduction_days')) {
            Schema::create('employee_leave_deduction_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_leave_id')->constrained()->cascadeOnDelete();
                $table->date('date');
                $table->foreignId('employee_deduction_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();

                $table->unique(['employee_leave_id', 'date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leave_deduction_days');

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("UPDATE employee_leaves SET status = 'rejected' WHERE status = 'cancelled'");
            DB::statement("ALTER TABLE employee_leaves MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('employee_leaves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_id');
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn(['approved_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
