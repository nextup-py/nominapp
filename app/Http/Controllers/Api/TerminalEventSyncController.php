<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Terminal;
use App\Services\AttendanceEventSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Recepción en lote de eventos de marcación de un terminal — usado tanto en
 * línea (lote de 1) como para volcar la cola acumulada tras un período
 * offline. Cada evento trae un `client_event_id` (UUID generado en el
 * cliente al capturar) para deduplicar reintentos de forma segura.
 * Autenticado vía Sanctum (ability `terminal:sync`).
 */
class TerminalEventSyncController extends Controller
{
    public function __construct(private readonly AttendanceEventSyncService $syncService) {}

    public function store(Request $request): JsonResponse
    {
        try {
            // Solo se valida la forma del ARRAY acá (tamaño del lote) — la forma de cada
            // evento individual la valida AttendanceEventSyncService::syncOne() (ver
            // validateEventShape()). Antes esta validación cubría también cada campo de
            // cada evento (events.*.*) en un solo $request->validate(): un solo evento
            // malformado en el lote hacía fallar TODO el request con 422, sin resultados
            // por evento — el cliente lo interpretaba como una caída de red y reintentaba
            // indefinidamente, bloqueando también a todos los eventos encolados detrás.
            $data = $request->validate([
                'events' => ['required', 'array', 'min:1', 'max:200'],
                // 'array' acá (no 'events.*.campo') sigue siendo estructural, no de
                // contenido — sin esto, un elemento que no sea un objeto (ej. un string
                // suelto) rompería el type-hint `array $eventData` de syncOne() con un
                // TypeError antes de llegar a validateEventShape(), volviendo a tumbar
                // el batch entero.
                'events.*' => ['array'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Datos de entrada inválidos.',
                'errors' => $e->errors(),
            ], 422);
        }

        /** @var Terminal $terminal */
        $terminal = $request->user();

        $results = $this->syncService->syncBatch($terminal, $data['events']);

        $terminal->update(['last_event_sync_at' => now()]);

        return response()->json([
            'ok' => true,
            'results' => $results,
        ]);
    }
}
