<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1b de la vinculación de terminales: ventana de vinculación (una solicitud
 * creada mientras está abierta se aprueba sola, quedando registrado quién la
 * abrió) y marca de consumo del enlace de configuración, para distinguir en la
 * pantalla de enlace inválido "venció" de "ya se usó". A partir de acá
 * `setup_token` guarda el hash sha256 del token, no el token en claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            if (! Schema::hasColumn('terminals', 'setup_token_consumed_at')) {
                $table->timestamp('setup_token_consumed_at')->nullable()->after('setup_token_expires_at');
            }
            if (! Schema::hasColumn('terminals', 'link_window_until')) {
                $table->timestamp('link_window_until')->nullable()->after('setup_token_consumed_at');
            }
            if (! Schema::hasColumn('terminals', 'link_window_opened_by_id')) {
                $table->foreignId('link_window_opened_by_id')->nullable()->after('link_window_until')
                    ->constrained('users')->nullOnDelete();
            }
        });

        // Los enlaces vigentes al desplegar estaban guardados en claro y ya no
        // serían reconocibles contra el hash: se invalidan (vencen en <=30 min).
        DB::table('terminals')->whereNotNull('setup_token')->update([
            'setup_token' => null,
            'setup_token_expires_at' => null,
        ]);
    }

    public function down(): void
    {
        Schema::table('terminals', function (Blueprint $table) {
            if (Schema::hasColumn('terminals', 'link_window_opened_by_id')) {
                $table->dropConstrainedForeignId('link_window_opened_by_id');
            }
            $table->dropColumn(array_filter(['link_window_until', 'setup_token_consumed_at'], fn ($c) => Schema::hasColumn('terminals', $c)));
        });
    }
};
