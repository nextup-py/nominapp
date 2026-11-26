<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/** Override puntual del turno planificado de un empleado para una fecha específica. */
class ShiftOverride extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    /**
     * Campos que se auditan: quién cambió el turno de un empleado en una fecha y por qué.
     *
     * @var array<int, string>
     */
    protected array $auditInclude = ['employee_id', 'override_date', 'shift_id', 'reason_type', 'notes'];

    protected $fillable = [
        'employee_id',
        'override_date',
        'shift_id',
        'reason_type',
        'notes',
        'created_by_id',
    ];

    protected $casts = [
        'override_date' => 'date',
    ];

    /** Empleado afectado por el override. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Turno asignado para ese día específico. */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_id');
    }

    /** Usuario que autorizó el cambio. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return array<string, string> */
    public static function getReasonTypeLabels(): array
    {
        return [
            'cambio_turno' => 'Cambio de turno',
            'guardia_extra' => 'Guardia extra',
            'permiso' => 'Permiso',
            'reposo' => 'Reposo médico',
            'otro' => 'Otro',
        ];
    }

    /** @return array<string, string> */
    public static function getReasonTypeColors(): array
    {
        return [
            'cambio_turno' => 'info',
            'guardia_extra' => 'warning',
            'permiso' => 'success',
            'reposo' => 'danger',
            'otro' => 'gray',
        ];
    }

    /**
     * Resumen legible de un evento del historial: «Mañana · Cambio de turno (nota)».
     *
     * @param  array<string, mixed>  $values  Valores anteriores o nuevos del evento de auditoría.
     */
    public static function describeAuditValues(array $values): string
    {
        $parts = [];

        if (array_key_exists('shift_id', $values)) {
            $parts[] = ShiftTemplate::find((int) $values['shift_id'])?->name ?? 'Turno eliminado';
        }

        if (array_key_exists('reason_type', $values)) {
            $parts[] = self::getReasonTypeLabels()[$values['reason_type']] ?? (string) $values['reason_type'];
        }

        if (! empty($values['notes'])) {
            $parts[] = '«'.$values['notes'].'»';
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }
}
