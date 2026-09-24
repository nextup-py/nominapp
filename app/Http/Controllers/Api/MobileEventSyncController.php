<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\MobileEventSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Recepción en lote de eventos de marcación del dispositivo personal de un
 * empleado — usado tanto en línea (lote de 1) como para volcar la cola
 * acumulada tras un período offline. A diferencia del equivalente de
 * terminal, no recibe `employee_id` por evento (el empleado es implícito:
 * el dueño del token Sanctum). Autenticado vía Sanctum (ability `mobile:sync`).
 */
class MobileEventSyncController extends Controller
{
    public function __construct(private readonly MobileEventSyncService $syncService) {}

    public function store(Request $request): JsonResponse
    {
        try {
            // Solo se valida la forma del ARRAY acá (tamaño del lote, y que cada elemento
            // sea un objeto) — la forma de cada evento individual la valida
            // MobileEventSyncService::syncOne() (ver validateEventShape()). Antes esta
            // validación cubría también cada campo de cada evento (events.*.*) en un solo
            // $request->validate(): un solo evento malformado en el lote hacía fallar TODO
            // el request con 422, sin resultados por evento — el cliente lo interpretaba
            // como una caída de red y reintentaba indefinidamente, bloqueando también a
            // todos los eventos encolados detrás.
            $data = $request->validate([
                'events' => ['required', 'array', 'min:1', 'max:200'],
                'events.*' => ['array'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Datos de entrada inválidos.',
                'errors' => $e->errors(),
            ], 422);
        }

        /** @var Employee $employee */
        $employee = $request->user();

        if ($employee->status !== 'active') {
            $employee->revokeMobileToken();

            return response()->json([
                'ok' => false,
                'message' => 'Tu acceso a la marcación por dispositivo fue desactivado. Consultá con RRHH.',
            ], 403);
        }

        $results = $this->syncService->syncBatch($employee, $data['events']);

        return response()->json([
            'ok' => true,
            'results' => $results,
        ]);
    }
}
