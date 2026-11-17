<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Catálogo de tipos de documento por empresa y, en los documentos, tipo, vencimiento, disco privado y avisos. */
    public function up(): void
    {
        if (! Schema::hasTable('document_types')) {
            Schema::create('document_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->boolean('requires_expiry')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'name']);
            });
        }

        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'document_type_id')) {
                $table->foreignId('document_type_id')->nullable()->after('employee_id')->constrained('document_types')->nullOnDelete();
            }
            if (! Schema::hasColumn('documents', 'expires_at')) {
                $table->date('expires_at')->nullable()->after('file_path');
            }
            if (! Schema::hasColumn('documents', 'disk')) {
                $table->string('disk', 20)->default('public')->after('file_path');
            }
            if (! Schema::hasColumn('documents', 'uploaded_by_id')) {
                $table->foreignId('uploaded_by_id')->nullable()->after('expires_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('documents', 'expiring_notified_at')) {
                $table->timestamp('expiring_notified_at')->nullable();
            }
            if (! Schema::hasColumn('documents', 'expired_notified_at')) {
                $table->timestamp('expired_notified_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            foreach (['document_type_id', 'uploaded_by_id'] as $fk) {
                if (Schema::hasColumn('documents', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            foreach (['expires_at', 'disk', 'expiring_notified_at', 'expired_notified_at'] as $column) {
                if (Schema::hasColumn('documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('document_types');
    }
};
