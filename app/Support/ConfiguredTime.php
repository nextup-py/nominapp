<?php

namespace App\Support;

use App\Settings\GeneralSettings;

/**
 * Decide si un trabajo programado cuya hora se configura en Configuración General le toca correr en este minuto.
 * Se evalúa dentro del programador (sin lanzar un proceso por minuto); si los ajustes todavía no existen
 * (por ejemplo, durante un deploy) responde que no.
 */
class ConfiguredTime
{
    /**
     * ¿Está activado el ajuste `$enabledKey` y la hora actual (zona de la app) coincide con `$timeKey` ("HH:MM")?
     */
    public static function isDue(string $enabledKey, string $timeKey): bool
    {
        try {
            $settings = app(GeneralSettings::class);

            return (bool) $settings->{$enabledKey}
                && now(config('app.timezone'))->format('H:i') === substr((string) $settings->{$timeKey}, 0, 5);
        } catch (\Throwable) {
            return false;
        }
    }
}
