<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Foto y vínculo opcional con la ficha de empleado, más el historial de accesos de los usuarios. */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('avatar_path')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'employee_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('login_histories')) {
            Schema::create('login_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->boolean('is_new_ip')->default(false);
                $table->timestamp('logged_in_at')->useCurrent()->index();
                $table->index(['user_id', 'logged_in_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('avatar_path');
        });
    }
};
