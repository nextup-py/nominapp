<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\AttendanceMarkFailure;
use App\Models\Employee;
use App\Models\Terminal;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Sincroniza en lote los eventos de marcación capturados por un terminal
 * (online o acumulados mientras estuvo offline), de forma idempotente vía
 * `client_event_id`. Reutiliza la misma máquina de estados que valida
 * AttendanceFaceMarkController::store() — ver AttendanceEvent::allowedNextEventTypes().
 *
 * A diferencia del flujo de marcación en línea (un evento por request, el
 * cliente ya sabe el estado actual porque acaba de consultarlo), acá el
 * terminal puede haber estado offline y traer varios eventos represados,
 * potencialmente en conflicto con lo que el servidor ya registró desde otro
 * origen mientras tanto. Por eso cada evento se re-valida contra el estado
 * *actual* del servidor en el momento de sincronizar, no contra lo que el
 * terminal creía que era el estado al capturar.
 */
class AttendanceEventSyncService
{
    /**
     * @param  array<int, array{client_event_id: string, employee_id: int, event_type: string, recorded_at: string, location?: array|null}>  $events
     * @return array<int, array{client_event_id: string, status: string, event_id?: int, conflict_reason?: string, message?: string}>
     */
    public function syncBatch(Terminal $terminal, array $events): array
    {
        $results = [];

        $employeeIds = collect($events)->pluck('employee_id')->unique();
        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        // Se procesa por empleado, en orden cronológico de captura, para que un lote
        // con varios eventos represados del mismo empleado se replaye en el orden en
        // que realmente ocurrieron (no en el orden de llegada del array).
        $byEmployee = collect($events)->groupBy('employee_id');

        foreach ($byEmployee as $employeeId => $employeeEvents) {
            $employee = $employees->get($employeeId);
            $ordered = $employeeEvents->sortBy('recorded_at')->values();

            foreach ($ordered as $eventData) {
                $results[] = $this->syncOne($terminal, $employee, $eventData);
            }
        }

        return $results;
    }

    /**
     * @param  array{client_event_id: string, employee_id: int, event_type: string, recorded_at: string, location?: array|null}  $eventData
     * @return array{client_event_id: string, status: string, event_id?: int, conflict_reason?: string, message?: string}
     */
    private function syncOne(Terminal $terminal, ?Employee $employee, array $eventData): array
    {
        // Se usa tal cual venga (aunque no pase la validación de tamaño de más abajo)
        // para que el cliente pueda igual encontrar este resultado en su cola local por
        // client_event_id — solo cae a null si el campo directamente no vino o no es un
        // string, caso en el que no hay forma de correlacionarlo del lado del cliente.
        $clientEventId = is_string($eventData['client_event_id'] ?? null) ? $eventData['client_event_id'] : null;

        // Antes de esto, el controller validaba el array COMPLETO de eventos en un solo
        // $request->validate() — un solo evento malformado (dato corrupto en IndexedDB,
        // event_type de una versión de cliente más nueva que el servidor, etc.) hacía
        // fallar el batch entero con un 422, sin resultados por evento. El cliente
        // (submitInChunks) interpretaba eso igual que una caída de red: reintentaba
        // indefinidamente sin nunca sacar el evento de la cola, bloqueando también a
        // TODOS los eventos encolados detrás de él. Ahora el controller solo valida la
        // forma del array (tamaño del lote); cada evento se valida acá individualmente,
        // y uno malformado se descarta con su propio resultado sin afectar al resto.
        if (($validationErrors = $this->validateEventShape($eventData)) !== null) {
            $this->recordSyncFailure($terminal, 'invalid_payload', 'El evento recibido no pasó la validación de formato y fue descartado.', [
                'client_event_id' => $clientEventId,
                'raw_event' => $eventData,
                'validation_errors' => $validationErrors,
            ]);

            return [
                'client_event_id' => $clientEventId ?? 'unknown',
                'status' => 'rejected',
                'conflict_reason' => 'invalid_payload',
                'message' => 'El evento tiene datos inválidos y fue descartado.',
            ];
        }

        if (! $employee) {
            $this->recordSyncFailure($terminal, 'employee_not_found', 'Empleado no encontrado o inactivo al sincronizar marcación offline.', [
                'client_event_id' => $clientEventId,
                'attempted_employee_id' => $eventData['employee_id'] ?? null,
            ]);

            return [
                'client_event_id' => $clientEventId,
                'status' => 'rejected',
                'conflict_reason' => 'employee_not_found',
                'message' => 'Empleado no encontrado o inactivo.',
            ];
        }

        // Idempotencia: reintentar el mismo lote (ej. la respuesta anterior se perdió
        // en el camino) no debe duplicar el evento ya sincronizado.
        $existing = AttendanceEvent::where('client_event_id', $clientEventId)->first();
        if ($existing) {
            return [
                'client_event_id' => $clientEventId,
                'status' => 'duplicate',
                'event_id' => $existing->id,
            ];
        }

        try {
            // El terminal manda recorded_at en UTC (Date.toISOString()) — convertir a la
            // timezone de la app ANTES de persistir, no solo para calcular $date. Sin esto
            // el evento queda guardado con la hora UTC "cruda" (ej. 3 horas adelantado en
            // America/Asuncion), porque el resto de la app asume que recorded_at ya está en
            // hora local.
            $recordedAt = Carbon::parse($eventData['recorded_at'])->timezone(config('app.timezone'));
        } catch (Throwable) {
            return [
                'client_event_id' => $clientEventId,
                'status' => 'rejected',
                'conflict_reason' => 'invalid_timestamp',
                'message' => 'Fecha/hora de marcación inválida.',
            ];
        }

        $date = $recordedAt->toDateString();

        try {
            return DB::transaction(fn () => $this->insertEvent($terminal, $employee, $eventData, $clientEventId, $recordedAt, $date));
        } catch (UniqueConstraintViolationException) {
            // Carrera entre dos sync concurrentes con el mismo client_event_id — el otro ganó, tratar como duplicado.
            $existing = AttendanceEvent::where('client_event_id', $clientEventId)->first();

            return [
                'client_event_id' => $clientEventId,
                'status' => $existing ? 'duplicate' : 'rejected',
                'event_id' => $existing?->id,
            ];
        }
    }

    /**
     * @param  array{client_event_id: string, employee_id: int, event_type: string, recorded_at: string, location?: array|null}  $eventData
     * @return array{client_event_id: string, status: string, event_id?: int, conflict_reason?: string, message?: string}
     */
    private function insertEvent(
        Terminal $terminal,
        Employee $employee,
        array $eventData,
        string $clientEventId,
        Carbon $recordedAt,
        string $date,
    ): array {
        // Resuelve a qué jornada asociar el evento — la de hoy, o la de ayer si sigue
        // abierta y la transición pedida solo tiene sentido ahí (turno nocturno que
        // cruza medianoche). Ver AttendanceDay::resolveForEvent().
        ['day' => $day, 'last' => $last, 'allowed' => $allowed] = AttendanceDay::resolveForEvent(
            $employee,
            $recordedAt,
            $eventData['event_type'],
            lockForUpdate: true,
        );

        if (! $day) {
            $day = AttendanceDay::firstOrCreate(
                ['employee_id' => $employee->id, 'date' => $date],
                ['status' => 'present']
            );
        }

        if (! in_array($eventData['event_type'], $allowed, true)) {
            Log::warning("Sync de terminal — evento '{$eventData['event_type']}' ya no es válido para el empleado {$employee->id} (último registrado: ".($last->event_type ?? 'ninguno').')', [
                'terminal_id' => $terminal->id,
                'client_event_id' => $clientEventId,
                'employee_id' => $employee->id,
                'attempted_event' => $eventData['event_type'],
                'last_event' => $last?->event_type,
                'allowed_events' => $allowed,
            ]);

            $this->recordSyncFailure(
                $terminal,
                'sync_conflict',
                'La secuencia de marcación ya no es válida en el servidor al sincronizar (último evento registrado: '.($last->event_type ?? 'ninguno').').',
                [
                    'client_event_id' => $clientEventId,
                    'attempted_event' => $eventData['event_type'],
                    'last_event' => $last?->event_type,
                    'allowed_events' => $allowed,
                    // Necesario para que AttendanceMarkFailure::approve() pueda reconstruir el
                    // AttendanceEvent con la hora/ubicación/terminal originales, no con "ahora".
                    'recorded_at' => $recordedAt->toIso8601String(),
                    'location' => $eventData['location'] ?? $this->resolveFallbackLocation($terminal, $employee),
                    'terminal_id' => $terminal->id,
                ],
                $employee,
                $eventData['event_type'],
            );

            return [
                'client_event_id' => $clientEventId,
                'status' => 'conflict',
                'conflict_reason' => 'invalid_sequence',
                'message' => 'La secuencia de marcación ya no es válida en el servidor — probablemente otro origen registró un evento mientras el terminal estaba offline.',
            ];
        }

        if ($day->status !== 'present') {
            $day->update(['status' => 'present']);
        }

        $branchMismatch = $terminal->branch_id
            && $employee->branch_id
            && $terminal->branch_id !== $employee->branch_id;

        $event = $day->events()->create([
            'client_event_id' => $clientEventId,
            'event_type' => $eventData['event_type'],
            'recorded_at' => $recordedAt,
            'synced_at' => now(),
            'source' => 'terminal',
            'location' => $eventData['location'] ?? $this->resolveFallbackLocation($terminal, $employee),
            'terminal_id' => $terminal->id,
            'branch_mismatch' => $branchMismatch,
        ]);

        return [
            'client_event_id' => $clientEventId,
            'status' => 'synced',
            'event_id' => $event->id,
        ];
    }

    /**
     * Persiste un intento fallido de sincronización para revisión en Filament
     * (AttendanceMarkFailureResource) — mismo patrón que
     * AttendanceFaceMarkController::recordFailure(), pero sin Request (esta
     * capa corre en background/batch, no hay IP de un usuario final que registrar).
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordSyncFailure(
        Terminal $terminal,
        string $failureType,
        string $message,
        array $metadata = [],
        ?Employee $employee = null,
        ?string $eventType = null,
    ): void {
        try {
            AttendanceMarkFailure::record([
                'mode' => 'terminal',
                'failure_type' => $failureType,
                'employee_id' => $employee?->id,
                'branch_id' => $terminal->branch_id,
                'attempted_event_type' => $eventType,
                'failure_message' => $message,
                'metadata' => ! empty($metadata) ? $metadata : null,
            ]);
        } catch (Throwable $e) {
            Log::error('No se pudo persistir el fallo de sincronización offline', [
                'failure_type' => $failureType,
                'terminal_id' => $terminal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Valida la forma de un evento individual — mismas reglas que antes vivían en
     * `TerminalEventSyncController` como validación de todo el array de una vez.
     * No valida `employee_id` acá: un empleado inexistente/inactivo ya se maneja
     * de forma resiliente más arriba en `syncOne()` (status `rejected` con
     * `employee_not_found`), sin necesidad de descartar el evento como payload
     * inválido.
     *
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>|null null si pasa; los errores de Validator si no.
     */
    private function validateEventShape(array $eventData): ?array
    {
        $validator = Validator::make($eventData, [
            'client_event_id' => ['required', 'string', 'size:36'],
            'event_type' => ['required', 'string', 'in:check_in,break_start,break_end,check_out'],
            'recorded_at' => ['required', 'date'],
            'location' => ['nullable', 'array'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
        ]);

        return $validator->fails() ? $validator->errors()->toArray() : null;
    }

    /**
     * El terminal no envía GPS (mismo criterio que AttendanceFaceMarkController::store()
     * en modo terminal) — se usan las coordenadas de la sucursal de la terminal, o
     * las de la sucursal del empleado si la terminal no tiene sucursal asignada.
     */
    private function resolveFallbackLocation(Terminal $terminal, Employee $employee): ?array
    {
        $branch = $terminal->branch ?? $employee->branch;
        $coordinates = $branch?->coordinates;

        if (! $coordinates || ! isset($coordinates['lat'], $coordinates['lng'])) {
            return null;
        }

        return [
            'lat' => (float) $coordinates['lat'],
            'lng' => (float) $coordinates['lng'],
        ];
    }
}
