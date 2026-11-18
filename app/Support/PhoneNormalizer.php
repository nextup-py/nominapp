<?php

namespace App\Support;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Set;

/**
 * Normaliza teléfonos de Paraguay al formato único del sistema: solo dígitos con el 0 inicial
 * (móvil `09XXXXXXXX`, fijo `021XXXXXX` / `0XXXXXXXX`), sin espacios, guiones, paréntesis ni prefijo +595.
 */
class PhoneNormalizer
{
    /** Formato válido: 0 inicial y 8 o 9 dígitos más. */
    public const PATTERN = '/^0\d{8,9}$/';

    /**
     * Devuelve el teléfono limpio, o null si queda vacío. No inventa dígitos: si el número no se puede
     * llevar al formato (por ejemplo, le falta un dígito) lo devuelve limpio pero inválido, y `isValid()` lo detecta.
     */
    public static function normalize(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '595') && strlen($digits) >= 11) {
            $digits = substr($digits, 3);
        }

        // Móvil escrito sin el 0 inicial (981123456) o fijo de Asunción sin él (21123456).
        if (strlen($digits) === 9 && $digits[0] === '9') {
            $digits = '0'.$digits;
        } elseif (strlen($digits) === 8 && $digits[0] === '2') {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /** Indica si el valor ya cumple el formato del sistema. */
    public static function isValid(?string $phone): bool
    {
        return $phone !== null && preg_match(self::PATTERN, $phone) === 1;
    }

    /**
     * Closure para `afterStateUpdated()` de un campo de teléfono (usar con `->live(onBlur: true)`): normaliza lo
     * escrito al salir del campo, antes de que corra la regla de formato.
     *
     * @return \Closure(?string, Set, TextInput): void
     */
    public static function onBlur(): \Closure
    {
        return fn (?string $state, Set $set, TextInput $component) => $set($component->getName(), self::normalize($state));
    }
}
