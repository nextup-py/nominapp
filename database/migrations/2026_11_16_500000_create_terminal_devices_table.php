<?php

use App\Models\Terminal;
use App\Services\DeviceHintsParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de dispositivos de cada terminal: un registro por vinculación (con el identificador que genera el propio
 * dispositivo, su IP, navegador y sistema, cuándo entró y cuándo salió y por qué). Hasta ahora el terminal solo
 * guardaba el último dispositivo, así que un reemplazo no dejaba rastro. Las solicitudes de vinculación pasan a
 * guardar el identificador del dispositivo y si es el mismo que el vinculado.
 *
 * El backfill crea un registro activo para cada terminal que ya tiene un acceso vigente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('terminal_devices')) {
            Schema::create('terminal_devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
                $table->string('device_id', 64)->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('device_brand', 60)->nullable();
                $table->string('device_model', 100)->nullable();
                $table->string('os', 60)->nullable();
                $table->string('browser', 60)->nullable();
                $table->string('linked_via', 10)->default('pairing');
                $table->timestamp('linked_at');
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('unlinked_at')->nullable();
                $table->string('unlink_reason', 20)->nullable();
                $table->foreignId('unlinked_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('replaced_by_id')->nullable();
                $table->timestamps();

                $table->index(['terminal_id', 'unlinked_at']);
            });
        }

        if (! Schema::hasColumn('terminal_pairing_requests', 'device_id')) {
            Schema::table('terminal_pairing_requests', function (Blueprint $table) {
                $table->string('device_id', 64)->nullable();
                $table->boolean('same_device')->nullable();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasColumn('terminal_pairing_requests', 'device_id')) {
            Schema::table('terminal_pairing_requests', function (Blueprint $table) {
                $table->dropColumn(['device_id', 'same_device']);
            });
        }

        Schema::dropIfExists('terminal_devices');
    }

    /** Un registro activo por cada terminal con un token `kiosk:%` vigente, con lo que el terminal ya guardaba. */
    private function backfill(): void
    {
        $withToken = DB::table('personal_access_tokens')
            ->where('tokenable_type', Terminal::class)
            ->where('name', 'like', 'kiosk:%')
            ->pluck('tokenable_id')
            ->unique();

        DB::table('terminals')
            ->whereIn('id', $withToken)
            ->whereNotIn('id', DB::table('terminal_devices')->select('terminal_id'))
            ->get(['id', 'user_agent', 'linked_ip', 'device_brand', 'device_model', 'linked_at', 'last_heartbeat_at', 'created_at'])
            ->each(function ($terminal) {
                $described = DeviceHintsParser::describe($terminal->user_agent);

                DB::table('terminal_devices')->insert([
                    'terminal_id' => $terminal->id,
                    'ip_address' => $terminal->linked_ip,
                    'user_agent' => $terminal->user_agent,
                    'device_brand' => $terminal->device_brand,
                    'device_model' => $terminal->device_model,
                    'os' => $described['os'],
                    'browser' => $described['browser'],
                    'linked_via' => 'pairing',
                    'linked_at' => $terminal->linked_at ?? $terminal->created_at ?? now(),
                    'last_seen_at' => $terminal->last_heartbeat_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }
};
