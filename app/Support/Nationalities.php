<?php

namespace App\Support;

/** Catálogo de nacionalidades que ofrece el formulario de empleados. */
final class Nationalities
{
    /** Nacionalidad por defecto del alta. */
    public const DEFAULT = 'Paraguaya';

    /** Valor para quien no figura en la lista. */
    public const OTHER = 'Otra';

    /**
     * Nacionalidades disponibles (valor guardado => etiqueta), con la paraguaya primero y «Otra» al final.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $names = [
            'Alemana', 'Argentina', 'Boliviana', 'Brasileña', 'Chilena', 'China', 'Colombiana', 'Coreana',
            'Cubana', 'Ecuatoriana', 'Española', 'Estadounidense', 'Francesa', 'Italiana', 'Japonesa',
            'Mexicana', 'Peruana', 'Portuguesa', 'Uruguaya', 'Venezolana',
        ];

        return collect([self::DEFAULT, ...$names, self::OTHER])
            ->mapWithKeys(fn (string $name): array => [$name => $name])
            ->all();
    }

    /**
     * Opciones del formulario; si el empleado ya tiene un valor fuera del catálogo (carga anterior o
     * importación), se agrega para que el campo no quede vacío al editar.
     *
     * @return array<string, string>
     */
    public static function optionsWith(?string $current): array
    {
        $options = self::options();

        if (filled($current) && ! isset($options[$current])) {
            $options[$current] = $current;
        }

        return $options;
    }
}
