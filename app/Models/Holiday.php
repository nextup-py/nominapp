<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    // Campos asignables
    protected $fillable = [
        'date',
        'name',
        'type',
    ];

    public const TYPE_FIXED = 'national_fixed';

    public const TYPE_MOVING = 'national_moving';

    public const TYPE_NON_WORKING = 'non_working';

    /**
     * Tipos de feriado para formularios.
     *
     * @return array<string, string>
     */
    public static function getTypeOptions(): array
    {
        return [
            self::TYPE_FIXED => 'Nacional fijo (misma fecha cada año)',
            self::TYPE_MOVING => 'Nacional móvil (cambia cada año)',
            self::TYPE_NON_WORKING => 'Día no laborable o puente',
        ];
    }

    /**
     * Etiquetas cortas de los tipos para badges.
     *
     * @return array<string, string>
     */
    public static function getTypeLabels(): array
    {
        return [self::TYPE_FIXED => 'Fijo', self::TYPE_MOVING => 'Móvil', self::TYPE_NON_WORKING => 'No laborable'];
    }

    /**
     * Colores de los tipos para badges.
     *
     * @return array<string, string>
     */
    public static function getTypeColors(): array
    {
        return [self::TYPE_FIXED => 'primary', self::TYPE_MOVING => 'warning', self::TYPE_NON_WORKING => 'info'];
    }

    // Casts para atributos
    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Verificar si una fecha es feriado
     */
    public static function isHoliday(\DateTimeInterface|string $date): bool
    {
        return static::where('date', $date)->exists();
    }

    /**
     * Obtener feriado por fecha
     *
     * @return Holiday|null
     */
    public static function forDate(\DateTimeInterface|string $date)
    {
        return static::where('date', $date)->first();
    }
}
