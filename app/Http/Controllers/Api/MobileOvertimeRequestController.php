<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMobileOvertimeRequest;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Services\OvertimeRequestService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pedidos de horas extra del propio empleado desde su celular (/marcar): lista de los recientes y alta de uno nuevo.
 * El empleado es el dueño del token (`$request->user()`); el pedido espera a su jefe o, sin jefe, a RR.HH.
 */
class MobileOvertimeRequestController extends Controller
{
    /** Pedidos recientes del empleado, motivos disponibles y si hoy ya tiene uno aprobado. */
    public function index(Request $request): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();

        $requests = OvertimeRequest::query()
            ->where('employee_id', $employee->id)
            ->latest('date_from')->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (OvertimeRequest $r): array => [
                'id' => $r->id,
                'range' => $r->rangeLabel(),
                'max_hours' => (float) $r->max_hours,
                'reason' => $r->reasonLabel(),
                'status' => $r->status,
                'status_label' => $r->statusLabel(),
                'rejection_reason' => $r->rejection_reason,
            ]);

        return response()->json([
            'ok' => true,
            'requests' => $requests,
            'reasons' => AttendanceEvent::getOvertimeReasonOptions(),
            'covered_today' => OvertimeRequest::covering($employee->id, today()->toDateString())->exists(),
        ]);
    }

    /** Crea un pedido (o una regularización de las horas extra de hoy). */
    public function store(StoreMobileOvertimeRequest $request, OvertimeRequestService $service): JsonResponse
    {
        /** @var Employee $employee */
        $employee = $request->user();
        $data = $request->validated();

        if (! array_key_exists($data['reason'], AttendanceEvent::getOvertimeReasonOptions())) {
            return response()->json(['ok' => false, 'message' => 'Elija un motivo de la lista.'], 422);
        }

        try {
            $overtimeRequest = $service->createForEmployee($employee, $data, (bool) ($data['regularization'] ?? false));
        } catch (DomainException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => $overtimeRequest->status === OvertimeRequest::STATUS_PENDING_BOSS ? 'Pedido enviado a su jefe.' : 'Pedido enviado a RR.HH.',
            'status' => $overtimeRequest->status,
        ], 201);
    }
}
