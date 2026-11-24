<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Asignación de horario a un empleado, con vigencia por fechas (válida desde / hasta). */
class EmployeeScheduleAssignment extends Model
{
    protected $fillable = [
        'employee_id',
        'schedule_id',
        'valid_from',
        'valid_until',
        'notes',
        'created_by',
    ];

    /** Forzar formato Y-m-d para que SQLite almacene solo la fecha (sin tiempo). */
    protected $dateFormat = 'Y-m-d';

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_ENDED = 'ended';

    /** Etiquetas del estado de una asignación. @return array<string, string> */
    public static function getStatusLabels(): array
    {
        return [self::STATUS_ACTIVE => 'Vigente', self::STATUS_UPCOMING => 'Futura', self::STATUS_ENDED => 'Finalizada'];
    }

    /** Colores del estado de una asignación. @return array<string, string> */
    public static function getStatusColors(): array
    {
        return [self::STATUS_ACTIVE => 'success', self::STATUS_UPCOMING => 'info', self::STATUS_ENDED => 'gray'];
    }

    /** Estado respecto de hoy: `ended` si ya tiene fecha de fin pasada, `upcoming` si todavía no empezó, si no `active`. */
    public function status(): string
    {
        $today = Carbon::today();

        return match (true) {
            $this->valid_until !== null && $this->valid_until->lt($today) => self::STATUS_ENDED,
            $this->valid_from->gt($today) => self::STATUS_UPCOMING,
            default => self::STATUS_ACTIVE,
        };
    }

    /** Empleado al que pertenece esta asignación. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Horario asignado. */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /** Usuario que creó la asignación. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope: asignaciones vigentes en una fecha dada (por defecto hoy).
     */
    public function scopeForDate(Builder $query, ?Carbon $date = null): Builder
    {
        $date ??= Carbon::today();

        return $query
            ->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>=', $date)
            );
    }

    /**
     * Scope: asignaciones activas hoy.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->forDate(Carbon::today());
    }
}
