<?php

namespace App\Models;

use App\Notifications\SyncConflictPendingNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Registro de intentos fallidos de marcación de asistencia.
 *
 * Persiste en BD todos los fallos del proceso de marcación (facial, terminal y móvil)
 * para permitir auditoría, diagnóstico y visualización en el panel de administración.
 * Los registros se retienen 30 días y se limpian automáticamente.
 *
 * Los fallos con `employee_id` y `attempted_event_type` presentes (ver
 * `canBeResolved()`) admiten revisión manual desde Filament: un admin puede
 * `approve()` (reconstruye el `AttendanceEvent` correspondiente, revalidando
 * la secuencia contra el estado actual) o `dismiss()` (lo marca como
 * revisado sin crear ningún evento).
 */
class AttendanceMarkFailure extends Model
{
    protected $fillable = [
        'mode',
        'failure_type',
        'employee_id',
        'branch_id',
        'attempted_event_type',
        'failure_message',
        'metadata',
        'ip_address',
        'location',
        'occurred_at',
        'resolution_status',
        'resolved_at',
        'resolved_by_id',
        'resolution_notes',
        'resolved_event_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'location' => 'array',
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Default en PHP (no solo en la migración): `create()` no vuelve a leer
     * la fila insertada, así que sin esto la instancia recién creada queda
     * con `resolution_status` en `null` en memoria hasta que se recarga —
     * `isPending()`/`canBeResolved()` fallarían justo después de `record()`.
     */
    protected $attributes = [
        'resolution_status' => 'pending',
    ];

    // ──────────────────────────────────────────
    // Relaciones
    // ──────────────────────────────────────────

    /** Empleado involucrado en el intento fallido (puede ser null si no se identificó). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Sucursal desde donde se realizó el intento. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Admin que aprobó o descartó el fallo. */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /** Evento creado al aprobar el fallo (solo si `resolution_status === 'approved'`). */
    public function resolvedEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class, 'resolved_event_id');
    }

    // ──────────────────────────────────────────
    // Labels y colores para el panel
    // ──────────────────────────────────────────

    /**
     * Retorna el label legible del tipo de fallo.
     */
    public static function getFailureTypeLabel(string $type): string
    {
        return match ($type) {
            'face_no_match' => 'Rostro no reconocido',
            'face_ambiguous' => 'Rostro ambiguo',
            'face_no_candidates' => 'Sin empleados enrolados',
            'face_invalid_descriptor' => 'Descriptor facial inválido',
            'employee_not_found' => 'Empleado no encontrado',
            'employee_inactive' => 'Empleado inactivo',
            'employee_no_branch' => 'Sin sucursal asignada',
            'branch_no_coordinates' => 'Sucursal sin coordenadas',
            'invalid_event_sequence' => 'Secuencia de evento inválida',
            'invalid_location' => 'Ubicación inválida',
            'internal_error' => 'Error interno',
            'sync_conflict' => 'Conflicto al sincronizar (offline)',
            'invalid_payload' => 'Datos de marcación inválidos (offline)',
            default => $type,
        };
    }

    /**
     * Retorna el color del badge según el tipo de fallo.
     */
    public static function getFailureTypeColor(string $type): string
    {
        return match ($type) {
            'face_no_match', 'face_ambiguous' => 'warning',
            'face_no_candidates', 'internal_error' => 'danger',
            'employee_not_found', 'employee_inactive' => 'danger',
            'employee_no_branch', 'branch_no_coordinates' => 'warning',
            'invalid_event_sequence' => 'info',
            'invalid_location' => 'warning',
            'face_invalid_descriptor' => 'gray',
            'sync_conflict' => 'danger',
            'invalid_payload' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Retorna el label del modo de marcación.
     */
    public static function getModeLabel(string $mode): string
    {
        return match ($mode) {
            'terminal' => 'Terminal',
            'mobile' => 'Móvil',
            default => 'Desconocido',
        };
    }

    /**
     * Retorna el color del badge según el modo.
     */
    public static function getModeColor(string $mode): string
    {
        return match ($mode) {
            'terminal' => 'info',
            'mobile' => 'success',
            default => 'gray',
        };
    }

    /**
     * Retorna todas las opciones de tipo de fallo para filtros.
     *
     * @return array<string, string>
     */
    public static function getFailureTypeOptions(): array
    {
        return [
            'face_no_match' => 'Rostro no reconocido',
            'face_ambiguous' => 'Rostro ambiguo',
            'face_no_candidates' => 'Sin empleados enrolados',
            'face_invalid_descriptor' => 'Descriptor facial inválido',
            'employee_not_found' => 'Empleado no encontrado',
            'employee_inactive' => 'Empleado inactivo',
            'employee_no_branch' => 'Sin sucursal asignada',
            'branch_no_coordinates' => 'Sucursal sin coordenadas',
            'invalid_event_sequence' => 'Secuencia de evento inválida',
            'invalid_location' => 'Ubicación inválida',
            'internal_error' => 'Error interno',
            'sync_conflict' => 'Conflicto al sincronizar (offline)',
            'invalid_payload' => 'Datos de marcación inválidos (offline)',
        ];
    }

    /**
     * Crea y persiste un registro de fallo de marcación. Los conflictos de
     * sincronización (`sync_conflict`) además notifican a todos los admins —
     * es el único tipo de fallo que requiere revisión manual obligatoria
     * (ver `canBeResolved()`); el resto (`face_no_match`, etc.) son
     * demasiado frecuentes en el uso normal como para notificar cada uno.
     *
     * @param  array<string, mixed>  $data
     */
    public static function record(array $data): static
    {
        $failure = static::create(array_merge(
            ['occurred_at' => now()],
            $data,
        ));

        if ($failure->failure_type === 'sync_conflict') {
            User::all()->each(fn (User $user) => $user->notify(new SyncConflictPendingNotification($failure)));
        }

        return $failure;
    }

    /**
     * Opciones de estado de revisión para filtros.
     *
     * @return array<string, string>
     */
    public static function getResolutionStatusOptions(): array
    {
        return [
            'pending' => 'Pendiente',
            'approved' => 'Aprobado',
            'dismissed' => 'Descartado',
        ];
    }

    /**
     * Labels cortos para badges de estado de revisión.
     *
     * @return array<string, string>
     */
    public static function getResolutionStatusLabels(): array
    {
        return [
            'pending' => 'Pendiente',
            'approved' => 'Aprobado',
            'dismissed' => 'Descartado',
        ];
    }

    /**
     * Colores semánticos para badges de estado de revisión.
     *
     * @return array<string, string>
     */
    public static function getResolutionStatusColors(): array
    {
        return [
            'pending' => 'warning',
            'approved' => 'success',
            'dismissed' => 'gray',
        ];
    }

    // ──────────────────────────────────────────
    // Revisión manual (aprobar / descartar)
    // ──────────────────────────────────────────

    /** Indica si el fallo todavía no fue revisado. */
    public function isPending(): bool
    {
        return $this->resolution_status === 'pending';
    }

    /** Indica si el fallo fue aprobado (se creó un AttendanceEvent). */
    public function isApproved(): bool
    {
        return $this->resolution_status === 'approved';
    }

    /** Indica si el fallo fue descartado sin crear ningún evento. */
    public function isDismissed(): bool
    {
        return $this->resolution_status === 'dismissed';
    }

    /**
     * Indica si hay datos suficientes para reconstruir la marcación
     * intentada — requiere saber quién (`employee_id`) y qué tipo de evento
     * (`attempted_event_type`) se intentó. Sin esto (ej. `face_no_match`,
     * donde nunca se identificó a nadie) no hay nada que "aprobar".
     */
    public function canBeResolved(): bool
    {
        return $this->isPending()
            && $this->employee_id !== null
            && $this->attempted_event_type !== null;
    }

    /**
     * Aprueba el fallo y crea el `AttendanceEvent` correspondiente,
     * revalidando la secuencia contra el estado *actual* del empleado (puede
     * haber cambiado desde que se registró el fallo — ej. otra marcación
     * manual ya cubrió ese evento). Permite ajustar el tipo de evento y la
     * hora antes de reinsertar; sin overrides, usa lo que se intentó
     * originalmente (`attempted_event_type` + `recorded_at`/`occurred_at`).
     *
     * @return array{success: bool, message: string, event?: AttendanceEvent}
     */
    public function approve(
        int $approvedById,
        ?string $eventType = null,
        ?Carbon $recordedAt = null,
        ?string $notes = null,
    ): array {
        if (! $this->canBeResolved()) {
            return ['success' => false, 'message' => 'Este fallo ya fue revisado o no tiene datos suficientes para reconstruir la marcación.'];
        }

        $employee = $this->employee;

        if (! $employee || $employee->status !== 'active') {
            return ['success' => false, 'message' => 'El empleado no existe o no está activo.'];
        }

        $eventType ??= $this->attempted_event_type;

        if ($recordedAt === null) {
            $metadataRecordedAt = $this->metadata['recorded_at'] ?? null;
            $recordedAt = $metadataRecordedAt ? Carbon::parse($metadataRecordedAt) : $this->occurred_at->copy();
        }

        $date = $recordedAt->copy()->timezone(config('app.timezone'))->toDateString();

        return DB::transaction(function () use ($employee, $eventType, $recordedAt, $date, $approvedById, $notes) {
            // Resuelve a qué jornada asociar el evento — la de hoy, o la de ayer si sigue
            // abierta y la transición pedida solo tiene sentido ahí (turno nocturno que
            // cruza medianoche). Ver AttendanceDay::resolveForEvent(). Sin esto, un
            // checkout de turno nocturno rechazado por AttendanceFaceMarkController
            // nunca podía aprobarse acá tampoco — volvía a fallar con el mismo motivo.
            ['day' => $day, 'last' => $last, 'allowed' => $allowed] = AttendanceDay::resolveForEvent(
                $employee,
                $recordedAt,
                $eventType,
                lockForUpdate: true,
            );

            if (! $day) {
                $day = AttendanceDay::firstOrCreate(
                    ['employee_id' => $employee->id, 'date' => $date],
                    ['status' => 'present']
                );
            }

            if (! in_array($eventType, $allowed, true)) {
                $lastLabel = $last ? AttendanceEvent::getEventTypeLabel($last->event_type) : 'ninguno';

                return ['success' => false, 'message' => "La secuencia sigue sin ser válida (último evento registrado: {$lastLabel}). Elegí otro tipo de evento."];
            }

            if ($day->status !== 'present') {
                $day->update(['status' => 'present']);
            }

            $event = $day->events()->create([
                'event_type' => $eventType,
                'recorded_at' => $recordedAt,
                'synced_at' => in_array($this->mode, ['terminal', 'mobile'], true) ? now() : null,
                'source' => in_array($this->mode, ['terminal', 'mobile'], true) ? $this->mode : 'manual',
                'location' => $this->metadata['location'] ?? $this->location,
                'terminal_id' => $this->metadata['terminal_id'] ?? null,
                'branch_mismatch' => false,
            ]);

            $this->update([
                'resolution_status' => 'approved',
                'resolved_at' => now(),
                'resolved_by_id' => $approvedById,
                'resolution_notes' => $notes,
                'resolved_event_id' => $event->id,
            ]);

            return ['success' => true, 'message' => 'Marcación registrada correctamente.', 'event' => $event];
        });
    }

    /**
     * Descarta el fallo sin crear ningún evento — para cuando el conflicto
     * ya no aplica (ej. la marcación se cargó manualmente desde otro lado).
     *
     * @return array{success: bool, message: string}
     */
    public function dismiss(int $dismissedById, ?string $notes = null): array
    {
        if (! $this->isPending()) {
            return ['success' => false, 'message' => 'Este fallo ya fue revisado.'];
        }

        $this->update([
            'resolution_status' => 'dismissed',
            'resolved_at' => now(),
            'resolved_by_id' => $dismissedById,
            'resolution_notes' => $notes,
        ]);

        return ['success' => true, 'message' => 'Fallo marcado como revisado.'];
    }
}
