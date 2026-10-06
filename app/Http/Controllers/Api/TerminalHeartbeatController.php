<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Terminal;
use App\Settings\GeneralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Heartbeat del terminal — se llama periódicamente mientras hay conexión
 * (y desde el botón "Forzar sincronización") para mantener `last_seen_at`/
 * `last_heartbeat_at` vivos y entregar la configuración vigente de
 * reconocimiento facial, así el terminal no depende de un sync completo de
 * empleados para tener el umbral actualizado. Autenticado vía Sanctum
 * (ability `terminal:sync`).
 *
 * `last_heartbeat_at` (a diferencia de `last_seen_at`, que también se
 * actualiza con cada carga de página vía sesión) solo se actualiza acá —
 * es la señal que usa `Terminal::connectivity_status` para el badge de
 * conectividad en Filament (ver TerminalResource).
 *
 * También recibe opcionalmente `pending_events`/`conflict_events` — el
 * tamaño de la cola offline del terminal (`outbound_events` en IndexedDB, ver
 * terminal-offline/queue.js) al momento del heartbeat. Sin esto, un
 * terminal con la cola atascada (ej. eventos en conflicto que requieren
 * revisión) se ve "en línea" igual que uno sano, porque el heartbeat en sí
 * sigue llegando — ver `Terminal::sync_queue_status` para el badge en Filament.
 *
 * Opcionalmente trae `device`: reporte de estado del dispositivo (versión de la app,
 * batería, cámara, empleados en caché, desfase de reloj, almacenamiento) que se
 * muestra en el detalle del terminal y alimenta las alertas (ver
 * `terminals:evaluate-health`).
 *
 * Canal de comandos remotos (Fase 3): el cliente envía `command_acks` (confirmaciones de
 * comandos ejecutados) y recibe `commands` (los pendientes, ver `TerminalCommand`).
 */
class TerminalHeartbeatController extends Controller
{
    /**
     * Campos del reporte de estado del dispositivo que se guardan (todos opcionales:
     * dependen de lo que el navegador informe; los clientes viejos no envían `device`).
     *
     * @var array<int, string>
     */
    private const DEVICE_REPORT_KEYS = [
        'app_version', 'standalone', 'sw_active', 'battery_level', 'battery_charging',
        'camera', 'cached_employees', 'clock_skew_seconds', 'storage_used_mb', 'storage_quota_mb',
    ];

    public function store(Request $request, GeneralSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'pending_events' => ['nullable', 'integer', 'min:0'],
            'conflict_events' => ['nullable', 'integer', 'min:0'],
            'command_acks' => ['nullable', 'array', 'max:20'],
            'command_acks.*.id' => ['required', 'integer'],
            'command_acks.*.status' => ['required', 'string', 'in:done,failed'],
            'command_acks.*.message' => ['nullable', 'string', 'max:255'],
            'device' => ['nullable', 'array'],
            'device.app_version' => ['nullable', 'string', 'max:40'],
            'device.standalone' => ['nullable', 'boolean'],
            'device.sw_active' => ['nullable', 'boolean'],
            'device.battery_level' => ['nullable', 'integer', 'between:0,100'],
            'device.battery_charging' => ['nullable', 'boolean'],
            'device.camera' => ['nullable', 'string', 'in:granted,denied,prompt,unavailable'],
            'device.cached_employees' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'device.clock_skew_seconds' => ['nullable', 'integer', 'between:-31536000,31536000'],
            'device.storage_used_mb' => ['nullable', 'integer', 'min:0'],
            'device.storage_quota_mb' => ['nullable', 'integer', 'min:0'],
        ]);

        /** @var Terminal $terminal */
        $terminal = $request->user();

        $terminal->recordHeartbeat(
            $data['pending_events'] ?? null,
            $data['conflict_events'] ?? null,
            isset($data['device']) ? array_intersect_key($data['device'], array_flip(self::DEVICE_REPORT_KEYS)) : null,
        );

        // `command_acks` (aunque vacío) indica que el cliente entiende comandos remotos: los
        // clientes viejos no lo envían y por eso nunca reciben uno que no podrían ejecutar.
        $supportsCommands = array_key_exists('command_acks', $data);
        if (! empty($data['command_acks'])) {
            $terminal->acknowledgeCommands($data['command_acks']);
        }

        return response()->json([
            'ok' => true,
            'commands' => $supportsCommands ? $terminal->deliverPendingCommands() : [],
            'server_time' => now()->toIso8601String(),
            'config' => [
                'face_threshold' => (float) $settings->face_threshold,
                'face_min_confidence_gap' => (float) $settings->face_min_confidence_gap,
            ],
        ]);
    }
}
