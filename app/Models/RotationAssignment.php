<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Asignación de un patrón de rotación a un empleado, con vigencia por fechas. */
class RotationAssignment extends Model
{
    protected $fillable = [
        'employee_id',
        'pattern_id',
        'start_index',
        'valid_from',
        'valid_until',
        'notes',
        'created_by_id',
    ];

    protected $casts = [
        'start_index' => 'integer',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_ENDED = 'ended';

    /** @return array<string, string> */
    public static function getStatusLabels(): array
    {
        return [self::STATUS_ACTIVE => 'Vigente', self::STATUS_UPCOMING => 'Futura', self::STATUS_ENDED => 'Finalizada'];
    }

    /** @return array<string, string> */
    public static function getStatusColors(): array
    {
        return [self::STATUS_ACTIVE => 'success', self::STATUS_UPCOMING => 'info', self::STATUS_ENDED => 'gray'];
    }

    /** Estado de la asignación según hoy: vigente, futura o finalizada. */
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

    /** Patrón de rotación asignado. */
    public function pattern(): BelongsTo
    {
        return $this->belongsTo(RotationPattern::class, 'pattern_id');
    }

    /** Usuario que creó la asignación. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Scope: asignaciones vigentes en una fecha dada.
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

    /**
     * Calcula el turno correspondiente a una fecha dada, considerando start_index.
     * Retorna el ShiftTemplate ID, o null si el patrón no tiene secuencia.
     */
    public function shiftIdForDate(Carbon $date): ?int
    {
        $pattern = $this->pattern;

        if (! $pattern || empty($pattern->sequence)) {
            return null;
        }

        $diff = $this->valid_from->diffInDays($date); // date - valid_from, siempre positivo
        $offset = ($diff + $this->start_index) % $pattern->cycle_length;

        return $pattern->sequence[$offset] ?? null;
    }
}
