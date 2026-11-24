<?php

namespace App\Support;

/**
 * Arma el texto legible de los motivos que impiden eliminar o desactivar un registro, con la
 * concordancia de número correcta ("1 sucursal", "2 sucursales").
 */
class BlockerSummary
{
    /**
     * Une los motivos en una frase: "1 cargo, 2 contratos y 1 terminal".
     *
     * Cada clave del arreglo es "singular|plural" (por ejemplo "sucursal|sucursales") y su valor la cantidad;
     * una clave sin "|" se usa tal cual en ambos casos.
     *
     * @param  array<string, int>  $blockers
     */
    public static function format(array $blockers): string
    {
        $parts = collect($blockers)
            ->map(fn (int $count, string $label) => $count.' '.self::label($label, $count))
            ->values();

        return $parts->count() > 1
            ? $parts->slice(0, -1)->implode(', ').' y '.$parts->last()
            : (string) $parts->first();
    }

    /**
     * Elige la forma singular o plural de una etiqueta "singular|plural" según la cantidad.
     */
    public static function label(string $label, int $count): string
    {
        [$singular, $plural] = array_pad(explode('|', $label, 2), 2, null);

        return $count === 1 ? $singular : ($plural ?? $singular);
    }
}
