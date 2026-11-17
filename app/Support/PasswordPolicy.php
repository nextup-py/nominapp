<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** Política de contraseñas de los usuarios del panel (perfil y alta de usuarios), definida una sola vez. */
class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    /** Regla de validación: 10 caracteres o más, con mayúsculas, minúsculas y números. */
    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->mixedCase()->numbers();
    }

    /** Texto de ayuda para los formularios. */
    public static function hint(): string
    {
        return 'Mínimo '.self::MIN_LENGTH.' caracteres, con mayúsculas, minúsculas y números.';
    }
}
