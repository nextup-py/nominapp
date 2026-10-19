<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Aviso de privacidad que el terminal muestra a los empleados (texto, enlace y versión). */
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'privacy_notice')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->text('privacy_notice')->nullable()->after('logo_thumbnail');
                $table->string('privacy_policy_url', 500)->nullable()->after('privacy_notice');
                $table->unsignedSmallInteger('privacy_notice_version')->default(1)->after('privacy_policy_url');
            });
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['privacy_notice', 'privacy_policy_url', 'privacy_notice_version']);
        });
    }
};
