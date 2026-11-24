<?php

namespace App\Filament\Concerns;

use App\Support\NavigationLayout;

/**
 * Hace que un Resource o Page del panel tome su grupo y orden del formato de menú que eligió el usuario (barra lateral
 * o superior). La pantalla sigue declarando `$navigationGroup`/`$navigationSort` para la barra lateral.
 */
trait UsesNavigationLayout
{
    /** Grupo de menú según el formato elegido por el usuario. */
    public static function getNavigationGroup(): ?string
    {
        return NavigationLayout::group(static::$navigationGroup);
    }

    /** Orden dentro del grupo según el formato elegido por el usuario. */
    public static function getNavigationSort(): ?int
    {
        return NavigationLayout::sort(static::$navigationGroup, static::$navigationSort);
    }
}
