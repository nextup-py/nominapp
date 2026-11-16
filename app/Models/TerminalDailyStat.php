<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resumen de confiabilidad de un terminal en un día (ver `TerminalReliabilityService`): cuánto tiempo estuvo en línea
 * dentro de su horario de vigilancia, sus cortes y cómo le fue al identificar rostros.
 */
class TerminalDailyStat extends Model
{
    protected $fillable = [
        'terminal_id',
        'date',
        'expected_minutes',
        'online_minutes',
        'outages_count',
        'outage_minutes',
        'longest_outage_minutes',
        'identification_attempts',
        'identification_failures',
        'identification_successes',
        'identification_ms_total',
    ];

    protected $casts = [
        'date' => 'date',
        'expected_minutes' => 'integer',
        'online_minutes' => 'integer',
        'outages_count' => 'integer',
        'outage_minutes' => 'integer',
        'longest_outage_minutes' => 'integer',
        'identification_attempts' => 'integer',
        'identification_failures' => 'integer',
        'identification_successes' => 'integer',
        'identification_ms_total' => 'integer',
    ];

    /** Terminal al que pertenece el resumen. */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /** Porcentaje del horario de vigilancia en que estuvo en línea (null si ese día no tuvo minutos que medir). */
    public function availabilityPercent(): ?float
    {
        return self::percent($this->online_minutes, $this->expected_minutes);
    }

    /** Porcentaje de intentos de identificación que no reconocieron a nadie (null sin intentos). */
    public function identificationFailurePercent(): ?float
    {
        return self::percent($this->identification_failures, $this->identification_attempts);
    }

    /** Tiempo medio de identificación exitosa, en milisegundos (null sin identificaciones). */
    public function averageIdentificationMs(): ?int
    {
        return $this->identification_successes > 0
            ? (int) round($this->identification_ms_total / $this->identification_successes)
            : null;
    }

    /** Porcentaje con un decimal, o null si el denominador es 0. */
    public static function percent(int|float $part, int|float $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }
}
