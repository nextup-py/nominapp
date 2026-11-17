<?php

namespace App\Support;

use App\Models\Holiday;

/**
 * Feriados nacionales de fecha fija de Paraguay. Los móviles (Jueves y Viernes Santo, traslados a lunes, días no
 * laborables decretados) NO se precargan: cambian cada año y los carga RR.HH. a mano. Verificar esta lista contra el
 * calendario oficial vigente.
 */
class HolidayCatalog
{
    /**
     * Feriados fijos: mes, día y nombre.
     *
     * @return list<array{0: int, 1: int, 2: string}>
     */
    public static function fixed(): array
    {
        return [
            [1, 1, 'Año Nuevo'],
            [3, 1, 'Día de los Héroes'],
            [5, 1, 'Día del Trabajador'],
            [5, 14, 'Independencia Nacional (día 1)'],
            [5, 15, 'Independencia Nacional (día 2)'],
            [6, 12, 'Paz del Chaco'],
            [8, 15, 'Fundación de Asunción'],
            [9, 29, 'Victoria de Boquerón'],
            [12, 8, 'Virgen de Caacupé'],
            [12, 25, 'Navidad'],
        ];
    }

    /**
     * Crea los feriados fijos de un año que todavía no existan (por fecha). Devuelve cuántos creó.
     */
    public static function load(int $year): int
    {
        $created = 0;

        foreach (self::fixed() as [$month, $day, $name]) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

            if (! Holiday::where('date', $date)->exists()) {
                Holiday::create(['date' => $date, 'name' => $name, 'type' => Holiday::TYPE_FIXED]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * Copia los feriados FIJOS de un año al siguiente (los móviles y no laborables no se copian: cambian de fecha).
     *
     * @return array{created: int, skipped_moving: list<string>}
     */
    public static function copyFixed(int $fromYear): array
    {
        $created = 0;

        foreach (Holiday::whereYear('date', $fromYear)->where('type', Holiday::TYPE_FIXED)->get() as $holiday) {
            $newDate = $holiday->date->copy()->addYear();

            if (! Holiday::where('date', $newDate->toDateString())->exists()) {
                Holiday::create(['date' => $newDate->toDateString(), 'name' => $holiday->name, 'type' => Holiday::TYPE_FIXED]);
                $created++;
            }
        }

        return [
            'created' => $created,
            'skipped_moving' => Holiday::whereYear('date', $fromYear)->where('type', '!=', Holiday::TYPE_FIXED)->orderBy('date')->pluck('name')->all(),
        ];
    }

    /** Mínimo de feriados cargados para considerar que un año «tiene calendario». */
    public const MIN_LOADED = 3;

    /** Si el año no tiene feriados cargados (o muy pocos). */
    public static function isMissing(int $year): bool
    {
        return Holiday::whereYear('date', $year)->count() < self::MIN_LOADED;
    }
}
